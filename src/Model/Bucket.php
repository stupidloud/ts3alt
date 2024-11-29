<?php

namespace S3Server\Model;

class Bucket
{
    public static function create(string $name, int $userId): ?int
    {
        if (!self::isValidBucketName($name)) {
            return null;
        }

        $db = Database::getConnection();
        try {
            $db->beginTransaction();

            // 检查是否已存在
            $stmt = $db->prepare('SELECT id FROM buckets WHERE name = ?');
            $stmt->execute([$name]);
            if ($stmt->fetch()) {
                $db->rollBack();
                return null;
            }

            // 创建存储桶记录
            $stmt = $db->prepare('
                INSERT INTO buckets (name, user_id, created_at)
                VALUES (?, ?, datetime("now"))
            ');
            $stmt->execute([$name, $userId]);
            $bucketId = $db->lastInsertId();

            $db->commit();
            return $bucketId;
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Error creating bucket: " . $e->getMessage());
            return null;
        }
    }

    public static function delete(string $name, int $userId): bool
    {
        $db = Database::getConnection();
        try {
            $db->beginTransaction();

            // 检查存储桶是否存在且属于该用户
            $stmt = $db->prepare('
                SELECT id FROM buckets 
                WHERE name = ? AND user_id = ?
            ');
            $stmt->execute([$name, $userId]);
            $bucket = $stmt->fetch();
            
            if (!$bucket) {
                $db->rollBack();
                return false;
            }

            // 检查存储桶是否为空
            $stmt = $db->prepare('SELECT COUNT(*) FROM objects WHERE bucket_id = ?');
            $stmt->execute([$bucket['id']]);
            if ($stmt->fetchColumn() > 0) {
                $db->rollBack();
                return false;
            }

            // 检查是否有未完成的分片上传
            $stmt = $db->prepare('
                SELECT COUNT(*) FROM multipart_uploads 
                WHERE bucket_id = ? AND status = "initiated"
            ');
            $stmt->execute([$bucket['id']]);
            if ($stmt->fetchColumn() > 0) {
                $db->rollBack();
                return false;
            }

            // 删除存储桶记录
            $stmt = $db->prepare('DELETE FROM buckets WHERE id = ?');
            $stmt->execute([$bucket['id']]);

            $db->commit();
            return true;
        } catch (\Exception $e) {
            $db->rollBack();
            error_log("Error deleting bucket: " . $e->getMessage());
            return false;
        }
    }

    public static function get(string $name): ?array
    {
        $cacheKey = Cache::makeKey('bucket', 'name', $name);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $db = Database::getConnection();
        try {
            $stmt = $db->prepare('
                SELECT b.*, u.username 
                FROM buckets b 
                JOIN users u ON b.user_id = u.id 
                WHERE b.name = ?
            ');
            $stmt->execute([$name]);
            $bucket = $stmt->fetch() ?: null;
            
            if ($bucket) {
                Cache::set($cacheKey, $bucket, 3600); // 1小时过期
            }
            
            return $bucket;
        } catch (\Exception $e) {
            error_log("Error getting bucket: " . $e->getMessage());
            return null;
        }
    }

    public static function listByUser(int $userId): array
    {
        $cacheKey = Cache::makeKey('bucket', 'user', $userId);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $db = Database::getConnection();
        try {
            $stmt = $db->prepare('
                SELECT b.*, 
                       (SELECT COUNT(*) FROM objects WHERE bucket_id = b.id) as object_count,
                       (SELECT COALESCE(SUM(size), 0) FROM objects WHERE bucket_id = b.id) as total_size
                FROM buckets b 
                WHERE b.user_id = ?
                ORDER BY b.created_at DESC
            ');
            $stmt->execute([$userId]);
            $buckets = $stmt->fetchAll();
            
            Cache::set($cacheKey, $buckets, 300); // 5分钟过期
            return $buckets;
        } catch (\Exception $e) {
            error_log("Error listing buckets: " . $e->getMessage());
            return [];
        }
    }

    public static function validateAccess(string $name, int $userId): bool
    {
        $cacheKey = Cache::makeKey('bucket', 'access', $name, $userId);
        if ($cached = Cache::get($cacheKey)) {
            return (bool)$cached;
        }

        $db = Database::getConnection();
        try {
            $stmt = $db->prepare('
                SELECT COUNT(*) FROM buckets 
                WHERE name = ? AND user_id = ?
            ');
            $stmt->execute([$name, $userId]);
            $hasAccess = $stmt->fetchColumn() > 0;
            
            Cache::set($cacheKey, $hasAccess, 300); // 5分钟过期
            return $hasAccess;
        } catch (\Exception $e) {
            error_log("Error validating bucket access: " . $e->getMessage());
            return false;
        }
    }

    private static function isValidBucketName(string $name): bool
    {
        // 存储桶命名规则：
        // 1. 长度在 3-63 个字符之间
        // 2. 只能包含小写字母、数字、点(.)和连字符(-)
        // 3. 必须以字母或数字开头和结尾
        // 4. 不能是 IP 地址格式
        // 5. 不能包含两个相邻的点
        // 6. 不能包含连字符相邻的点

        if (strlen($name) < 3 || strlen($name) > 63) {
            return false;
        }

        // 检查是否只包含允许的字符
        if (!preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/', $name)) {
            return false;
        }

        // 检查是否包含连续的点或点相邻的连字符
        if (strpos($name, '..') !== false || 
            strpos($name, '.-') !== false || 
            strpos($name, '-.') !== false) {
            return false;
        }

        // 检查是否是 IP 地址格式
        if (preg_match('/^\d+\.\d+\.\d+\.\d+$/', $name)) {
            return false;
        }

        return true;
    }
}
