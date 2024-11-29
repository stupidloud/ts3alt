<?php

namespace S3Server\Util;

use Redis;

class Cache
{
    private static $instance = null;
    private $redis;
    
    private function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect(
            getenv('REDIS_HOST') ?: 'localhost',
            getenv('REDIS_PORT') ?: 6379
        );
        
        if ($password = getenv('REDIS_PASSWORD')) {
            $this->redis->auth($password);
        }
        
        // 选择数据库
        $this->redis->select(getenv('REDIS_DB') ?: 0);
        
        // 设置序列化
        $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_PHP);
    }
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function get(string $key)
    {
        $instance = self::getInstance();
        return $instance->redis->get($key);
    }
    
    public static function set(string $key, $value, int $ttl = 3600): bool
    {
        $instance = self::getInstance();
        return $instance->redis->setex($key, $ttl, $value);
    }
    
    public static function delete(string $key): bool
    {
        $instance = self::getInstance();
        return $instance->redis->del($key) > 0;
    }
    
    public static function clear(): bool
    {
        $instance = self::getInstance();
        return $instance->redis->flushDB();
    }
    
    public static function invalidatePattern(string $pattern): void
    {
        $instance = self::getInstance();
        $keys = $instance->redis->keys($pattern);
        if (!empty($keys)) {
            $instance->redis->del($keys);
        }
    }
    
    // 缓存键前缀管理
    private static function getPrefix(): string
    {
        return getenv('REDIS_PREFIX') ?: 's3server:';
    }
    
    public static function makeKey(string $type, string ...$parts): string
    {
        return self::getPrefix() . $type . ':' . implode(':', $parts);
    }
    
    // 缓存事务支持
    public static function transaction(callable $callback)
    {
        $instance = self::getInstance();
        $instance->redis->multi();
        try {
            $result = $callback($instance);
            $instance->redis->exec();
            return $result;
        } catch (\Exception $e) {
            $instance->redis->discard();
            error_log("Cache transaction failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    // 缓存预热
    public static function warmup(array $keys): void
    {
        foreach ($keys as $key => $callback) {
            if (!self::get($key)) {
                $value = $callback();
                if ($value !== null) {
                    self::set($key, $value);
                }
            }
        }
    }
    
    // 缓存统计
    public static function getStats(): array
    {
        $instance = self::getInstance();
        return $instance->redis->info();
    }
    
    // 分布式锁支持
    public static function lock(string $key, int $ttl = 30): bool
    {
        $instance = self::getInstance();
        return (bool)$instance->redis->set(
            "lock:{$key}",
            1,
            ['NX', 'EX' => $ttl]
        );
    }
    
    public static function unlock(string $key): bool
    {
        return self::delete("lock:{$key}");
    }
    
    // 计数器支持
    public static function increment(string $key, int $value = 1): int
    {
        $instance = self::getInstance();
        return $instance->redis->incrBy($key, $value);
    }
    
    public static function decrement(string $key, int $value = 1): int
    {
        $instance = self::getInstance();
        return $instance->redis->decrBy($key, $value);
    }
    
    // 列表操作
    public static function listPush(string $key, $value): int
    {
        $instance = self::getInstance();
        return $instance->redis->rPush($key, $value);
    }
    
    public static function listPop(string $key)
    {
        $instance = self::getInstance();
        return $instance->redis->lPop($key);
    }
    
    // 集合操作
    public static function setAdd(string $key, $value): int
    {
        $instance = self::getInstance();
        return $instance->redis->sAdd($key, $value);
    }
    
    public static function setRemove(string $key, $value): int
    {
        $instance = self::getInstance();
        return $instance->redis->sRem($key, $value);
    }
    
    // 有序集合操作
    public static function sortedSetAdd(string $key, float $score, $value): int
    {
        $instance = self::getInstance();
        return $instance->redis->zAdd($key, $score, $value);
    }
    
    public static function sortedSetRemove(string $key, $value): int
    {
        $instance = self::getInstance();
        return $instance->redis->zRem($key, $value);
    }
    
    // 哈希表操作
    public static function hashSet(string $key, string $field, $value): bool
    {
        $instance = self::getInstance();
        return $instance->redis->hSet($key, $field, $value);
    }
    
    public static function hashGet(string $key, string $field)
    {
        $instance = self::getInstance();
        return $instance->redis->hGet($key, $field);
    }
}
