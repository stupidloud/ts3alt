<?php

namespace S3Server\Model;

use S3Server\Util\Cache;

class User
{
    public static function findByAccessKey(string $accessKey): ?array
    {
        $cacheKey = Cache::makeKey('user', 'access_key', $accessKey);
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $conn = Database::getConnection();
        $stmt = $conn->prepare('SELECT * FROM users WHERE access_key = ?');
        $result = $stmt->executeQuery([$accessKey]);
        $user = $result->fetchAssociative() ?: null;
        
        if ($user) {
            Cache::set($cacheKey, $user, 3600); // 1小时过期
        }
        
        return $user;
    }

    public static function validateCredentials(string $accessKey, string $secretKey): bool
    {
        $cacheKey = Cache::makeKey('user', 'credentials', $accessKey, $secretKey);
        if ($cached = Cache::get($cacheKey)) {
            return (bool)$cached;
        }

        $conn = Database::getConnection();
        $stmt = $conn->prepare('SELECT COUNT(*) as count FROM users WHERE access_key = ? AND secret_key = ?');
        $result = $stmt->executeQuery([$accessKey, $secretKey]);
        $isValid = ($result->fetchAssociative()['count'] ?? 0) > 0;
        
        Cache::set($cacheKey, $isValid, 300); // 5分钟过期
        return $isValid;
    }
}
