<?php

namespace S3Server\Model;

use Ramsey\Uuid\Uuid;
use PDO;

class MultipartUpload
{
    private const MAX_PART_SIZE = 5368709120; // 5GB
    private const MIN_PART_SIZE = 5242880;    // 5MB
    private const MAX_PARTS = 10000;
    private const CLEANUP_THRESHOLD = 86400;   // 24 hours
    private const LOCK_TIMEOUT = 30;    // 30 seconds

    public static function initiate(int $bucketId, string $keyName, int $userId, ?string $contentType = null): ?string
    {
        $db = Database::getConnection();
        try {
            $db->beginTransaction();

            // 使用 UUID 作为 uploadId
            $uploadId = Uuid::uuid4()->toString();

            // 检查 uploadId 是否已存在
            $stmt = $db->prepare('SELECT id FROM multipart_uploads WHERE upload_id = ?');
            $stmt->execute([$uploadId]);
            if ($stmt->fetch()) {
                $db->rollBack();
                return null;
            }

            $stmt = $db->prepare('
                INSERT INTO multipart_uploads (bucket_id, key_name, upload_id, user_id, content_type, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, datetime("now"))
            ');

            $stmt->execute([$bucketId, $keyName, $uploadId, $userId, $contentType, 'initiated']);
            $db->commit();

            return $uploadId;
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Error initiating upload: " . $e->getMessage());
            return null;
        }
    }

    public static function uploadPart(string $uploadId, int $partNumber, string $content, int $size, string $etag): bool
    {
        if ($partNumber < 1 || $partNumber > self::MAX_PARTS) {
            error_log("Invalid part number: $partNumber");
            return false;
        }

        if ($size > self::MAX_PART_SIZE) {
            error_log("Part size exceeds maximum allowed: $size");
            return false;
        }

        if ($partNumber > 1 && $size < self::MIN_PART_SIZE) {
            error_log("Part size below minimum allowed: $size");
            return false;
        }

        $db = Database::getConnection();
        $lock = null;
        try {
            $db->beginTransaction();

            // 验证上传状态
            $upload = self::getUpload($uploadId);
            if (!$upload) {
                throw new \Exception("Upload $uploadId not found or not in initiated state");
            }

            // 生成唯一的存储文件名
            $uuid = Uuid::uuid4()->toString();
            $storagePath = dirname(dirname(__DIR__)) . '/storage/' . $uuid;
            $tempPath = $storagePath . '.tmp';
            $lockPath = $storagePath . '.lock';

            // 获取文件锁
            $lock = self::acquireLock($lockPath);

            try {
                // 确保存储目录存在并可写
                self::ensureStorageDirectory($storagePath);

                // 写入临时文件
                if (file_put_contents($tempPath, $content) === false) {
                    throw new \Exception("Failed to write part content to temporary file");
                }

                // 验证文件大小
                if (filesize($tempPath) !== $size) {
                    throw new \Exception("File size mismatch");
                }

                // 原子重命名
                if (!rename($tempPath, $storagePath)) {
                    throw new \Exception("Failed to rename temporary file");
                }

                // 检查是否已存在相同的分片
                $stmt = $db->prepare('SELECT id, storage_path FROM parts WHERE upload_id = ? AND part_number = ?');
                $stmt->execute([$uploadId, $partNumber]);
                $existingPart = $stmt->fetch();

                if ($existingPart) {
                    // 更新现有分片
                    $stmt = $db->prepare('
                        UPDATE parts 
                        SET storage_path = ?, size = ?, etag = ?, updated_at = datetime("now")
                        WHERE upload_id = ? AND part_number = ?
                    ');
                    $stmt->execute([$storagePath, $size, $etag, $uploadId, $partNumber]);

                    // 删除旧文件
                    if (file_exists($existingPart['storage_path'])) {
                        @unlink($existingPart['storage_path']);
                    }
                } else {
                    // 插入新分片
                    $stmt = $db->prepare('
                        INSERT INTO parts (upload_id, part_number, storage_path, size, etag, created_at)
                        VALUES (?, ?, ?, ?, ?, datetime("now"))
                    ');
                    $stmt->execute([$uploadId, $partNumber, $storagePath, $size, $etag]);
                }

                $db->commit();

                // 释放锁
                self::releaseLock($lock, $lockPath);

                return true;
            } catch (\Exception $e) {
                // 清理资源
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                }
                throw $e;
            }
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Error uploading part: " . $e->getMessage());
            if (isset($lock)) {
                self::releaseLock($lock, $lockPath);
            }
            return false;
        }
    }

    public static function complete(string $uploadId): array
    {
        $db = Database::getConnection();
        $tempFile = null;
        $tempLock = null;
        $outFile = null;

        try {
            $db->beginTransaction();

            // 获取上传信息
            $upload = self::getUpload($uploadId);
            if (!$upload) {
                throw new \Exception('Upload not found or not in initiated state');
            }

            // 获取所有分片
            $stmt = $db->prepare('
                SELECT * FROM parts 
                WHERE upload_id = ? 
                ORDER BY part_number ASC
            ');
            $stmt->execute([$uploadId]);
            $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($parts)) {
                throw new \Exception('No parts found');
            }

            // 验证分片完整性
            $expectedPartNumber = 1;
            foreach ($parts as $part) {
                if ($part['part_number'] !== $expectedPartNumber) {
                    throw new \Exception("Missing part number $expectedPartNumber");
                }
                if (!file_exists($part['storage_path'])) {
                    throw new \Exception("Part file missing: " . $part['part_number']);
                }
                $expectedPartNumber++;
            }

            // 计算最终文件的 ETag
            $etags = array_map(function($part) {
                return $part['etag'];
            }, $parts);
            $finalEtag = md5(implode('', $etags)) . '-' . count($parts);

            // 生成唯一的存储文件名
            $uuid = Uuid::uuid4()->toString();
            $storagePath = dirname(dirname(__DIR__)) . '/storage/' . $uuid;
            $tempPath = $storagePath . '.tmp';
            $lockPath = $storagePath . '.lock';

            // 获取文件锁
            $tempLock = self::acquireLock($lockPath);

            // 创建临时文件
            $outFile = fopen($tempPath, 'wb');
            if ($outFile === false) {
                throw new \Exception('Cannot create temporary file');
            }

            // 合并文件
            $totalSize = 0;
            foreach ($parts as $part) {
                $in = fopen($part['storage_path'], 'rb');
                if ($in === false) {
                    throw new \Exception('Cannot read part file: ' . $part['part_number']);
                }

                try {
                    $copySize = stream_copy_to_stream($in, $outFile);
                    if ($copySize === false || $copySize !== $part['size']) {
                        throw new \Exception('Failed to copy part ' . $part['part_number']);
                    }
                    $totalSize += $copySize;
                } finally {
                    fclose($in);
                }
            }

            // 关闭输出文件
            fclose($outFile);
            $outFile = null;

            // 验证文件大小
            if (filesize($tempPath) !== $totalSize) {
                throw new \Exception('File size mismatch');
            }

            // 原子重命名
            if (!rename($tempPath, $storagePath)) {
                throw new \Exception('Failed to rename temporary file');
            }
            $tempFile = null;

            // 更新数据库
            $stmt = $db->prepare('
                UPDATE multipart_uploads 
                SET status = "completed", completed_at = datetime("now")
                WHERE upload_id = ?
            ');
            $stmt->execute([$uploadId]);

            // 创建对象记录
            $stmt = $db->prepare('
                INSERT INTO objects (bucket_id, key_name, size, etag, content_type, storage_path, created_at)
                VALUES (?, ?, ?, ?, ?, ?, datetime("now"))
            ');

            $stmt->execute([
                $upload['bucket_id'],
                $upload['key_name'],
                $totalSize,
                $finalEtag,
                $upload['content_type'],
                $storagePath
            ]);

            $db->commit();

            // 清理分片文件
            foreach ($parts as $part) {
                if (file_exists($part['storage_path'])) {
                    @unlink($part['storage_path']);
                }
            }

            // 释放锁
            self::releaseLock($tempLock, $lockPath);

            return [
                'success' => true,
                'etag' => $finalEtag,
                'size' => $totalSize
            ];
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Error completing upload: " . $e->getMessage());

            // 清理资源
            if ($outFile) {
                fclose($outFile);
            }
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
            if ($tempLock) {
                self::releaseLock($tempLock, $lockPath);
            }

            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public static function abort(string $uploadId): bool
    {
        $db = Database::getConnection();
        $parts = [];
        
        try {
            $db->beginTransaction();

            // 验证上传状态
            $upload = self::getUpload($uploadId);
            if (!$upload) {
                throw new \Exception("Upload $uploadId not found or not in initiated state");
            }

            // 获取所有分片
            $stmt = $db->prepare('SELECT storage_path FROM parts WHERE upload_id = ?');
            $stmt->execute([$uploadId]);
            $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 删除数据库记录
            $stmt = $db->prepare('DELETE FROM parts WHERE upload_id = ?');
            $stmt->execute([$uploadId]);

            $stmt = $db->prepare('
                UPDATE multipart_uploads 
                SET status = "aborted", completed_at = datetime("now")
                WHERE upload_id = ?
            ');
            $stmt->execute([$uploadId]);

            $db->commit();

            // 在事务提交后删除文件
            foreach ($parts as $part) {
                if (file_exists($part['storage_path'])) {
                    @unlink($part['storage_path']);
                }
            }

            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Error aborting upload: " . $e->getMessage());

            // 即使事务回滚，也尝试清理文件
            foreach ($parts as $part) {
                if (file_exists($part['storage_path'])) {
                    @unlink($part['storage_path']);
                }
            }

            return false;
        }
    }

    public static function getUpload(string $uploadId): ?array
    {
        $cacheKey = Cache::makeKey('upload', 'id', $uploadId);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT * FROM multipart_uploads 
            WHERE upload_id = ? AND status = "initiated"
            LIMIT 1
        ');
        $stmt->execute([$uploadId]);
        $upload = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        
        if ($upload) {
            Cache::set($cacheKey, $upload, 600); // 10分钟过期
        }
        
        return $upload;
    }

    public static function listParts(string $uploadId): array
    {
        $cacheKey = Cache::makeKey('upload', 'parts', $uploadId);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT part_number, size, etag, created_at 
            FROM parts 
            WHERE upload_id = ?
            ORDER BY part_number ASC
        ');
        $stmt->execute([$uploadId]);
        $parts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        Cache::set($cacheKey, $parts, 300); // 5分钟过期
        return $parts;
    }

    public static function listUploads(int $bucketId): array
    {
        $cacheKey = Cache::makeKey('upload', 'list', $bucketId);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare('
            SELECT m.*, COUNT(p.id) as part_count, COALESCE(SUM(p.size), 0) as total_size
            FROM multipart_uploads m
            LEFT JOIN parts p ON m.upload_id = p.upload_id
            WHERE m.bucket_id = ? AND m.status = "initiated"
            GROUP BY m.upload_id
            ORDER BY m.created_at DESC
        ');
        $stmt->execute([$bucketId]);
        $uploads = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        Cache::set($cacheKey, $uploads, 300); // 5分钟过期
        return $uploads;
    }

    private static function acquireLock(string $lockPath): mixed
    {
        $startTime = time();
        while (true) {
            $lock = @fopen($lockPath, 'w+');
            if ($lock && flock($lock, LOCK_EX | LOCK_NB)) {
                return $lock;
            }
            if ($lock) {
                fclose($lock);
            }
            
            // 检查超时
            if (time() - $startTime > self::LOCK_TIMEOUT) {
                throw new \Exception("Failed to acquire lock: timeout");
            }
            
            // 检查旧的锁文件
            if (file_exists($lockPath) && time() - filemtime($lockPath) > self::LOCK_TIMEOUT) {
                @unlink($lockPath);
            }
            
            usleep(100000); // 100ms
        }
    }

    private static function releaseLock($lock, string $lockPath): void
    {
        if ($lock) {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        @unlink($lockPath);
    }

    private static function ensureStorageDirectory(string $path): void
    {
        $dir = dirname($path);
        if (!file_exists($dir)) {
            // 设置安全的 umask
            $oldUmask = umask(0022);
            try {
                if (!mkdir($dir, 0755, true)) {
                    throw new \Exception("Failed to create directory: $dir");
                }
            } finally {
                umask($oldUmask);
            }
        }
        
        // 验证目录权限
        if (!is_writable($dir)) {
            throw new \Exception("Directory not writable: $dir");
        }
    }
}
