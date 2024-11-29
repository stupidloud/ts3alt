<?php

namespace S3Server\Controller;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use S3Server\Model\Bucket;
use S3Server\Model\S3Object;
use S3Server\Model\MultipartUpload;
use S3Server\Util\SignatureUtil;

class S3Controller
{
    private string $storagePath;

    public function __construct()
    {
        $this->storagePath = dirname(__DIR__, 2) . '/storage';
    }

    public function listBuckets(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('userId');
        $buckets = Bucket::listByUser($userId);

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/"></ListAllMyBucketsResult>');
        
        $owner = $xml->addChild('Owner');
        $owner->addChild('ID', $userId);
        $owner->addChild('DisplayName', 'admin');

        $bucketList = $xml->addChild('Buckets');
        foreach ($buckets as $bucket) {
            $b = $bucketList->addChild('Bucket');
            $b->addChild('Name', $bucket['name']);
            $b->addChild('CreationDate', date('c', strtotime($bucket['created_at'])));
        }

        $response->getBody()->write($xml->asXML());
        return $response->withHeader('Content-Type', 'application/xml');
    }

    public function createBucket(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('userId');
        $bucketName = $args['bucket'];

        $bucketId = Bucket::create($bucketName, $userId);
        if ($bucketId === null) {
            return $response->withStatus(409)->withHeader('Content-Type', 'text/plain')
                ->write('Bucket already exists or name is invalid');
        }

        return $response->withStatus(200)->withHeader('Location', '/' . $bucketName);
    }

    public function deleteBucket(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('userId');
        $bucketName = $args['bucket'];

        if (!Bucket::delete($bucketName, $userId)) {
            return $response->withStatus(409)->withHeader('Content-Type', 'text/plain')
                ->write('Bucket is not empty or does not exist');
        }

        return $response->withStatus(204);
    }

    public function putObject(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('userId');
        $bucketName = $args['bucket'];
        $key = $args['key'];

        // 验证存储桶访问权限
        if (!Bucket::validateAccess($bucketName, $userId)) {
            return $response->withStatus(403);
        }

        $bucket = Bucket::get($bucketName);
        if (!$bucket) {
            return $response->withStatus(404);
        }

        // 生成唯一的存储文件名
        $uuid = Uuid::uuid4()->toString();
        $storagePath = dirname(dirname(__DIR__)) . '/storage/' . $uuid;
        $tempPath = $storagePath . '.tmp';
        $lockPath = $storagePath . '.lock';

        try {
            // 创建文件锁
            $lock = fopen($lockPath, 'w+');
            if (!$lock || !flock($lock, LOCK_EX)) {
                error_log("Failed to acquire lock for file upload");
                return $response->withStatus(500);
            }

            // 确保存储目录存在
            $storageDir = dirname($storagePath);
            if (!file_exists($storageDir)) {
                if (!mkdir($storageDir, 0755, true)) {
                    error_log("Failed to create storage directory");
                    return $response->withStatus(500);
                }
            }

            // 读取请求体到临时文件
            $body = $request->getBody();
            $out = fopen($tempPath, 'wb');
            if ($out === false) {
                throw new \Exception('Cannot create temporary file');
            }

            $size = 0;
            while (!$body->eof()) {
                $chunk = $body->read(8192);
                $writeSize = fwrite($out, $chunk);
                if ($writeSize === false) {
                    fclose($out);
                    throw new \Exception('Failed to write to temporary file');
                }
                $size += $writeSize;
            }
            fclose($out);

            // 计算 ETag
            $etag = md5_file($tempPath);

            // 原子重命名
            if (!rename($tempPath, $storagePath)) {
                throw new \Exception('Failed to rename temporary file');
            }

            // 更新数据库
            $db = Database::getConnection();
            $db->beginTransaction();

            try {
                // 删除可能存在的旧对象
                $stmt = $db->prepare('
                    SELECT storage_path FROM objects 
                    WHERE bucket_id = ? AND key_name = ?
                ');
                $stmt->execute([$bucket['id'], $key]);
                $oldObject = $stmt->fetch();

                if ($oldObject) {
                    $stmt = $db->prepare('
                        DELETE FROM objects 
                        WHERE bucket_id = ? AND key_name = ?
                    ');
                    $stmt->execute([$bucket['id'], $key]);

                    // 删除旧文件
                    if (file_exists($oldObject['storage_path'])) {
                        @unlink($oldObject['storage_path']);
                    }
                }

                // 插入新对象
                $stmt = $db->prepare('
                    INSERT INTO objects (bucket_id, key_name, size, etag, content_type, storage_path, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, datetime("now"))
                ');

                $stmt->execute([
                    $bucket['id'],
                    $key,
                    $size,
                    $etag,
                    $request->getHeaderLine('Content-Type'),
                    $storagePath
                ]);

                $db->commit();
            } catch (\Exception $e) {
                $db->rollBack();
                throw $e;
            }

            // 释放锁
            flock($lock, LOCK_UN);
            fclose($lock);
            @unlink($lockPath);

            return $response
                ->withHeader('ETag', '"' . $etag . '"')
                ->withStatus(200);

        } catch (\Exception $e) {
            error_log("Error putting object: " . $e->getMessage());

            // 清理资源
            if (isset($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
                @unlink($lockPath);
            }

            if (file_exists($tempPath)) {
                @unlink($tempPath);
            }

            return $response->withStatus(500);
        }
    }

    public function getObject(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('userId');
        $bucketName = $args['bucket'];
        $key = $args['key'];

        // 验证存储桶访问权限
        if (!Bucket::validateAccess($bucketName, $userId)) {
            return $response->withStatus(403);
        }

        $bucket = Bucket::get($bucketName);
        if (!$bucket) {
            return $response->withStatus(404);
        }

        $object = S3Object::get($bucket['id'], $key);
        if (!$object) {
            return $response->withStatus(404);
        }

        // 检查 If-Match 头
        $ifMatch = $request->getHeaderLine('If-Match');
        if ($ifMatch) {
            $matches = array_map(function($etag) {
                return trim($etag, '" ');
            }, explode(',', $ifMatch));
            
            if (!in_array($object['etag'], $matches)) {
                return $response->withStatus(412);
            }
        }

        // 检查 If-None-Match 头
        $ifNoneMatch = $request->getHeaderLine('If-None-Match');
        if ($ifNoneMatch) {
            $matches = array_map(function($etag) {
                return trim($etag, '" ');
            }, explode(',', $ifNoneMatch));
            
            if (in_array($object['etag'], $matches) || in_array('*', $matches)) {
                return $response->withStatus(304);
            }
        }

        // 检查 If-Modified-Since 头
        $ifModifiedSince = $request->getHeaderLine('If-Modified-Since');
        if ($ifModifiedSince) {
            $modifiedTime = strtotime($object['created_at']);
            $checkTime = strtotime($ifModifiedSince);
            if ($checkTime && $modifiedTime <= $checkTime) {
                return $response->withStatus(304);
            }
        }

        // 检查 If-Unmodified-Since 头
        $ifUnmodifiedSince = $request->getHeaderLine('If-Unmodified-Since');
        if ($ifUnmodifiedSince) {
            $modifiedTime = strtotime($object['created_at']);
            $checkTime = strtotime($ifUnmodifiedSince);
            if ($checkTime && $modifiedTime > $checkTime) {
                return $response->withStatus(412);
            }
        }

        if (!file_exists($object['storage_path'])) {
            error_log("Storage file not found: " . $object['storage_path']);
            return $response->withStatus(404);
        }

        // 处理 Range 请求
        $range = $request->getHeaderLine('Range');
        if ($range && preg_match('/bytes=(\d*)-(\d*)/', $range, $matches)) {
            $start = $matches[1] === '' ? 0 : (int)$matches[1];
            $end = $matches[2] === '' ? $object['size'] - 1 : (int)$matches[2];

            if ($start >= $object['size'] || $end >= $object['size'] || $start > $end) {
                return $response->withStatus(416)
                    ->withHeader('Content-Range', 'bytes */' . $object['size']);
            }

            return $response->withStatus(206)
                ->withHeader('Content-Type', $object['content_type'] ?? 'application/octet-stream')
                ->withHeader('Content-Length', $end - $start + 1)
                ->withHeader('Content-Range', "bytes $start-$end/" . $object['size'])
                ->withHeader('ETag', '"' . $object['etag'] . '"')
                ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($object['created_at'])) . ' GMT')
                ->withHeader('Accept-Ranges', 'bytes')
                ->withHeader('X-Sendfile', $object['storage_path'])
                ->withHeader('X-Sendfile-Start', $start)
                ->withHeader('X-Sendfile-End', $end);
        }

        // 返回完整文件
        return $response
            ->withHeader('Content-Type', $object['content_type'] ?? 'application/octet-stream')
            ->withHeader('Content-Length', $object['size'])
            ->withHeader('ETag', '"' . $object['etag'] . '"')
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($object['created_at'])) . ' GMT')
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('X-Sendfile', $object['storage_path']);
    }

    public function deleteObject(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('userId');
        $bucketName = $args['bucket'];
        $key = $args['key'];

        // 验证存储桶访问权限
        if (!Bucket::validateAccess($bucketName, $userId)) {
            return $response->withStatus(403);
        }

        $bucket = Bucket::get($bucketName);
        if (!$bucket) {
            return $response->withStatus(404);
        }

        if (S3Object::delete($bucket['id'], $key)) {
            return $response->withStatus(204);
        }

        return $response->withStatus(404);
    }

    public function headObject(Request $request, Response $response, array $args): Response
    {
        $userId = $request->getAttribute('userId');
        $bucketName = $args['bucket'];
        $key = $args['key'];

        // 验证存储桶访问权限
        if (!Bucket::validateAccess($bucketName, $userId)) {
            return $response->withStatus(403);
        }

        $bucket = Bucket::get($bucketName);
        if (!$bucket) {
            return $response->withStatus(404);
        }

        $object = S3Object::get($bucket['id'], $key);
        if (!$object) {
            return $response->withStatus(404);
        }

        return $response
            ->withHeader('Content-Type', $object['content_type'] ?? 'application/octet-stream')
            ->withHeader('Content-Length', $object['size'])
            ->withHeader('ETag', '"' . $object['etag'] . '"')
            ->withHeader('Last-Modified', gmdate('D, d M Y H:i:s', strtotime($object['created_at'])) . ' GMT')
            ->withStatus(200);
    }

    public function initiateMultipartUpload(Request $request, Response $response, array $args): Response
    {
        $bucket = $args['bucket'];
        $key = $args['key'];
        $user = $request->getAttribute('user');

        $bucketInfo = Bucket::findByName($bucket);
        if (!$bucketInfo) {
            return $response->withStatus(404);
        }

        $contentType = $request->getHeaderLine('Content-Type');
        $uploadId = MultipartUpload::initiate($bucketInfo['id'], $key, $user['id'], $contentType);

        if (!$uploadId) {
            return $response->withStatus(500);
        }

        $xml = $this->generateInitiateMultipartUploadXml($bucket, $key, $uploadId);
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml');
    }

    public function uploadPart(Request $request, Response $response, array $args): Response
    {
        $bucket = $args['bucket'];
        $key = $args['key'];
        $uploadId = $request->getQueryParams()['uploadId'] ?? '';
        $partNumber = (int)($request->getQueryParams()['partNumber'] ?? 0);

        if (!$uploadId || $partNumber < 1 || $partNumber > 10000) {
            return $response->withStatus(400);
        }

        $upload = MultipartUpload::getUpload($uploadId);
        if (!$upload || $upload['status'] !== 'initiated') {
            return $response->withStatus(404);
        }

        // Create temporary file for this part
        $partDir = $this->storagePath . '/' . $bucket . '/_multipart/' . $uploadId;
        if (!file_exists($partDir)) {
            mkdir($partDir, 0777, true);
        }

        $partPath = $partDir . '/part.' . $partNumber;
        
        // 使用流式写入来处理大文件
        $in = $request->getBody();
        $out = @fopen($partPath, 'wb');
        
        if ($out === false) {
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain')->write('Cannot create part file');
        }

        // 分块读取并写入
        $size = 0;
        $hash = hash_init('md5');
        while (!$in->eof()) {
            $chunk = $in->read(8192);
            $size += strlen($chunk);
            hash_update($hash, $chunk);
            fwrite($out, $chunk);
        }
        fclose($out);

        $etag = hash_final($hash);

        if (MultipartUpload::uploadPart($uploadId, $partNumber, $partPath, $size, $etag)) {
            return $response
                ->withHeader('ETag', '"' . $etag . '"')
                ->withStatus(200);
        }

        // 如果保存失败，清理临时文件
        if (file_exists($partPath)) {
            unlink($partPath);
        }
        return $response->withStatus(500);
    }

    public function completeMultipartUpload(Request $request, Response $response, array $args): Response
    {
        $bucket = $args['bucket'];
        $key = $args['key'];
        $uploadId = $request->getQueryParams()['uploadId'] ?? '';

        if (!$uploadId) {
            return $response->withStatus(400);
        }

        $upload = MultipartUpload::getUpload($uploadId);
        if (!$upload || $upload['status'] !== 'initiated') {
            return $response->withStatus(404);
        }

        // 验证请求体中的 ETag 列表
        $body = (string)$request->getBody();
        if (empty($body)) {
            return $response->withStatus(400)->withHeader('Content-Type', 'text/plain')->write('Empty request body');
        }

        try {
            $xml = new \SimpleXMLElement($body);
            $parts = $xml->xpath('//Part');
            if (empty($parts)) {
                return $response->withStatus(400)->withHeader('Content-Type', 'text/plain')->write('No parts specified');
            }

            // 验证所有分片都已上传
            foreach ($parts as $part) {
                $partNumber = (int)$part->PartNumber;
                $etag = (string)$part->ETag;
                $etag = trim($etag, '"'); // 移除引号

                $dbPart = MultipartUpload::getPart($uploadId, $partNumber);
                if (!$dbPart || $dbPart['etag'] !== $etag) {
                    return $response->withStatus(400)->withHeader('Content-Type', 'text/plain')
                        ->write("Part $partNumber not found or ETag mismatch");
                }
            }
        } catch (\Exception $e) {
            return $response->withStatus(400)->withHeader('Content-Type', 'text/plain')
                ->write('Invalid XML format: ' . $e->getMessage());
        }

        $result = MultipartUpload::complete($uploadId);
        if (!$result['success']) {
            return $response->withStatus(500)->withHeader('Content-Type', 'text/plain')
                ->write($result['error'] ?? 'Failed to complete multipart upload');
        }

        $xml = $this->generateCompleteMultipartUploadXml($bucket, $key, $result['etag']);
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml');
    }

    public function abortMultipartUpload(Request $request, Response $response, array $args): Response
    {
        $uploadId = $request->getQueryParams()['uploadId'] ?? '';

        if (!$uploadId) {
            return $response->withStatus(400);
        }

        $upload = MultipartUpload::getUpload($uploadId);
        if (!$upload || $upload['status'] !== 'initiated') {
            return $response->withStatus(404);
        }

        if (MultipartUpload::abort($uploadId)) {
            return $response->withStatus(204);
        }

        return $response->withStatus(500);
    }

    public function listMultipartUploads(Request $request, Response $response, array $args): Response
    {
        $bucket = $args['bucket'];
        
        $bucketInfo = Bucket::findByName($bucket);
        if (!$bucketInfo) {
            return $response->withStatus(404);
        }

        $uploads = MultipartUpload::listUploads($bucketInfo['id']);
        $xml = $this->generateListMultipartUploadsXml($bucket, $uploads);
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml');
    }

    public function listParts(Request $request, Response $response, array $args): Response
    {
        $uploadId = $request->getQueryParams()['uploadId'] ?? '';

        if (!$uploadId) {
            return $response->withStatus(400);
        }

        $upload = MultipartUpload::getUpload($uploadId);
        if (!$upload) {
            return $response->withStatus(404);
        }

        $parts = MultipartUpload::listParts($uploadId);
        $xml = $this->generateListPartsXml($upload['key_name'], $uploadId, $parts);
        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml');
    }

    public function getPresignedUrl(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('userId');
        $params = $request->getQueryParams();
        
        // 验证必需参数
        $required = ['bucket', 'key', 'method'];
        foreach ($required as $param) {
            if (!isset($params[$param])) {
                return $response->withStatus(400)->withJson([
                    'error' => "Missing required parameter: {$param}"
                ]);
            }
        }
        
        // 获取参数
        $bucket = $params['bucket'];
        $key = $params['key'];
        $method = strtoupper($params['method']);
        $expires = isset($params['expires']) ? (int)$params['expires'] : 3600;
        
        // 验证方法
        if (!in_array($method, ['GET', 'PUT', 'DELETE'])) {
            return $response->withStatus(400)->withJson([
                'error' => 'Invalid method. Allowed methods: GET, PUT, DELETE'
            ]);
        }
        
        // 验证过期时间
        if ($expires < 1 || $expires > 604800) { // 最长7天
            return $response->withStatus(400)->withJson([
                'error' => 'Expires must be between 1 and 604800 seconds'
            ]);
        }
        
        // 验证桶权限
        $bucket = Bucket::getByName($bucket);
        if (!$bucket || $bucket['user_id'] !== $userId) {
            return $response->withStatus(404)->withJson([
                'error' => 'Bucket not found or access denied'
            ]);
        }
        
        // 获取用户凭证
        $credentials = $request->getAttribute('credentials');
        
        // 生成预签名URL
        try {
            $url = SignatureUtil::generatePresignedUrl(
                $method,
                $params['bucket'],
                $key,
                $credentials['accessKey'],
                $credentials['secretKey'],
                $expires,
                [],  // 额外的头部
                $params['query'] ?? []  // 额外的查询参数
            );
            
            return $response->withJson([
                'url' => $url,
                'expires' => time() + $expires
            ]);
        } catch (\Exception $e) {
            return $response->withStatus(500)->withJson([
                'error' => 'Failed to generate presigned URL'
            ]);
        }
    }

    private function generateListBucketsXml(array $buckets): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<ListAllMyBucketsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        $xml .= '<Buckets>';
        foreach ($buckets as $bucket) {
            $xml .= '<Bucket>';
            $xml .= '<Name>' . htmlspecialchars($bucket['Name']) . '</Name>';
            $xml .= '<CreationDate>' . $bucket['CreationDate'] . '</CreationDate>';
            $xml .= '</Bucket>';
        }
        $xml .= '</Buckets>';
        $xml .= '</ListAllMyBucketsResult>';
        return $xml;
    }

    private function generateListObjectsXml(string $bucket, array $objects): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<ListBucketResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        $xml .= '<Name>' . htmlspecialchars($bucket) . '</Name>';
        foreach ($objects as $object) {
            $xml .= '<Contents>';
            $xml .= '<Key>' . htmlspecialchars($object['Key']) . '</Key>';
            $xml .= '<Size>' . $object['Size'] . '</Size>';
            $xml .= '<LastModified>' . $object['LastModified'] . '</LastModified>';
            $xml .= '<ETag>"' . $object['ETag'] . '"</ETag>';
            $xml .= '<StorageClass>STANDARD</StorageClass>';
            $xml .= '</Contents>';
        }
        $xml .= '</ListBucketResult>';
        return $xml;
    }

    private function generateInitiateMultipartUploadXml(string $bucket, string $key, string $uploadId): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<InitiateMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        $xml .= '<Bucket>' . htmlspecialchars($bucket) . '</Bucket>';
        $xml .= '<Key>' . htmlspecialchars($key) . '</Key>';
        $xml .= '<UploadId>' . htmlspecialchars($uploadId) . '</UploadId>';
        $xml .= '</InitiateMultipartUploadResult>';
        return $xml;
    }

    private function generateCompleteMultipartUploadXml(string $bucket, string $key, string $etag): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        $xml .= '<Location>/' . htmlspecialchars($bucket) . '/' . htmlspecialchars($key) . '</Location>';
        $xml .= '<Bucket>' . htmlspecialchars($bucket) . '</Bucket>';
        $xml .= '<Key>' . htmlspecialchars($key) . '</Key>';
        $xml .= '<ETag>"' . $etag . '"</ETag>';
        $xml .= '</CompleteMultipartUploadResult>';
        return $xml;
    }

    private function generateListMultipartUploadsXml(string $bucket, array $uploads): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<ListMultipartUploadsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        $xml .= '<Bucket>' . htmlspecialchars($bucket) . '</Bucket>';
        $xml .= '<Uploads>';
        foreach ($uploads as $upload) {
            $xml .= '<Upload>';
            $xml .= '<Key>' . htmlspecialchars($upload['key_name']) . '</Key>';
            $xml .= '<UploadId>' . htmlspecialchars($upload['upload_id']) . '</UploadId>';
            $xml .= '<Initiated>' . $upload['initiated_at'] . '</Initiated>';
            $xml .= '</Upload>';
        }
        $xml .= '</Uploads>';
        $xml .= '</ListMultipartUploadsResult>';
        return $xml;
    }

    private function generateListPartsXml(string $key, string $uploadId, array $parts): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<ListPartsResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">';
        $xml .= '<Key>' . htmlspecialchars($key) . '</Key>';
        $xml .= '<UploadId>' . htmlspecialchars($uploadId) . '</UploadId>';
        foreach ($parts as $part) {
            $xml .= '<Part>';
            $xml .= '<PartNumber>' . $part['part_number'] . '</PartNumber>';
            $xml .= '<LastModified>' . $part['uploaded_at'] . '</LastModified>';
            $xml .= '<ETag>"' . $part['etag'] . '"</ETag>';
            $xml .= '<Size>' . $part['size'] . '</Size>';
            $xml .= '</Part>';
        }
        $xml .= '</ListPartsResult>';
        return $xml;
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!file_exists($dir)) {
            return true;
        }

        if (!is_dir($dir)) {
            return unlink($dir);
        }

        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }

            if (!$this->deleteDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }

        return rmdir($dir);
    }
}
