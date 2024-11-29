<?php

namespace S3Server\Model;

use S3Server\Util\Cache;

class S3Object
{
    public static function findByKey(int $bucketId, string $keyName): ?array
    {
        $cacheKey = Cache::makeKey('object', 'key', $bucketId, $keyName);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $conn = Database::getConnection();
        $stmt = $conn->prepare('SELECT * FROM objects WHERE bucket_id = ? AND key_name = ?');
        $result = $stmt->executeQuery([$bucketId, $keyName]);
        $object = $result->fetchAssociative() ?: null;
        
        if ($object) {
            Cache::set($cacheKey, $object, 1800); // 30分钟过期
        }
        
        return $object;
    }

    public static function listObjects(int $bucketId): array
    {
        $cacheKey = Cache::makeKey('object', 'list', $bucketId);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $conn = Database::getConnection();
        $stmt = $conn->prepare('SELECT * FROM objects WHERE bucket_id = ? ORDER BY key_name');
        $result = $stmt->executeQuery([$bucketId]);
        $objects = $result->fetchAllAssociative();
        
        Cache::set($cacheKey, $objects, 300); // 5分钟过期
        return $objects;
    }

    public static function create(int $bucketId, string $keyName, int $size, string $etag, string $contentType, string $storagePath): bool
    {
        $conn = Database::getConnection();
        try {
            $conn->executeStatement(
                'INSERT INTO objects (bucket_id, key_name, size, etag, content_type, storage_path) VALUES (?, ?, ?, ?, ?, ?)',
                [$bucketId, $keyName, $size, $etag, $contentType, $storagePath]
            );
            
            // 清除相关缓存
            Cache::delete(Cache::makeKey('object', 'key', $bucketId, $keyName));
            Cache::delete(Cache::makeKey('object', 'list', $bucketId));
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function delete(int $bucketId, string $keyName): bool
    {
        $conn = Database::getConnection();
        try {
            $conn->executeStatement(
                'DELETE FROM objects WHERE bucket_id = ? AND key_name = ?',
                [$bucketId, $keyName]
            );
            
            // 清除相关缓存
            Cache::delete(Cache::makeKey('object', 'key', $bucketId, $keyName));
            Cache::delete(Cache::makeKey('object', 'list', $bucketId));
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
}
