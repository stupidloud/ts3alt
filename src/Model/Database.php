<?php

namespace S3Server\Model;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $connection = null;
    private const DB_PATH = '/data/s3server.db';
    private const SCHEMA_VERSION = 1;

    public static function getConnection(): PDO
    {
        if (self::$connection === null) {
            self::initialize();
        }
        return self::$connection;
    }

    private static function initialize(): void
    {
        $dbDir = dirname(self::DB_PATH);
        if (!file_exists($dbDir)) {
            if (!mkdir($dbDir, 0777, true)) {
                throw new \RuntimeException("Failed to create database directory: $dbDir");
            }
        }

        $isNewDb = !file_exists(self::DB_PATH);

        try {
            self::$connection = new PDO('sqlite:' . self::DB_PATH);
            self::$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$connection->exec('PRAGMA foreign_keys = ON');
            self::$connection->exec('PRAGMA journal_mode = WAL');
            self::$connection->exec('PRAGMA synchronous = NORMAL');
            self::$connection->exec('PRAGMA temp_store = MEMORY');
            self::$connection->exec('PRAGMA cache_size = -2000'); // 2MB cache

            if ($isNewDb) {
                self::createTables();
                self::createDefaultUser();
            } else {
                self::checkSchema();
            }
        } catch (PDOException $e) {
            throw new \RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    private static function createTables(): void
    {
        try {
            self::$connection->beginTransaction();

            // 创建架构版本表
            self::$connection->exec('
                CREATE TABLE schema_version (
                    version INTEGER PRIMARY KEY,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');

            // 创建用户表
            self::$connection->exec('
                CREATE TABLE users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT NOT NULL UNIQUE,
                    access_key TEXT NOT NULL UNIQUE,
                    secret_key TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ');

            // 创建存储桶表
            self::$connection->exec('
                CREATE TABLE buckets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    user_id INTEGER NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ');

            // 创建对象表
            self::$connection->exec('
                CREATE TABLE objects (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    bucket_id INTEGER NOT NULL,
                    key_name TEXT NOT NULL,
                    size INTEGER NOT NULL,
                    etag TEXT NOT NULL,
                    content_type TEXT,
                    storage_path TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
                    UNIQUE(bucket_id, key_name)
                )
            ');

            // 创建多部分上传表
            self::$connection->exec('
                CREATE TABLE multipart_uploads (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    bucket_id INTEGER NOT NULL,
                    key_name TEXT NOT NULL,
                    upload_id TEXT NOT NULL UNIQUE,
                    user_id INTEGER NOT NULL,
                    content_type TEXT,
                    status TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    completed_at DATETIME,
                    FOREIGN KEY (bucket_id) REFERENCES buckets(id) ON DELETE CASCADE,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                )
            ');

            // 创建分片表
            self::$connection->exec('
                CREATE TABLE parts (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    upload_id TEXT NOT NULL,
                    part_number INTEGER NOT NULL,
                    size INTEGER NOT NULL,
                    etag TEXT NOT NULL,
                    storage_path TEXT NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME,
                    FOREIGN KEY (upload_id) REFERENCES multipart_uploads(upload_id) ON DELETE CASCADE,
                    UNIQUE(upload_id, part_number)
                )
            ');

            // 创建索引
            self::$connection->exec('CREATE INDEX idx_objects_bucket_key ON objects(bucket_id, key_name)');
            self::$connection->exec('CREATE INDEX idx_multipart_uploads_bucket ON multipart_uploads(bucket_id)');
            self::$connection->exec('CREATE INDEX idx_parts_upload ON parts(upload_id)');
            self::$connection->exec('CREATE INDEX idx_multipart_uploads_status ON multipart_uploads(status)');

            // 插入架构版本
            self::$connection->exec('INSERT INTO schema_version (version) VALUES (' . self::SCHEMA_VERSION . ')');

            self::$connection->commit();
        } catch (PDOException $e) {
            self::$connection->rollBack();
            throw new \RuntimeException("Failed to create tables: " . $e->getMessage());
        }
    }

    private static function createDefaultUser(): void
    {
        try {
            self::$connection->beginTransaction();

            $stmt = self::$connection->prepare('
                INSERT INTO users (username, access_key, secret_key)
                VALUES (?, ?, ?)
            ');

            $stmt->execute([
                'admin',
                'minioadmin',
                'minioadmin'
            ]);

            self::$connection->commit();
        } catch (PDOException $e) {
            self::$connection->rollBack();
            throw new \RuntimeException("Failed to create default user: " . $e->getMessage());
        }
    }

    private static function checkSchema(): void
    {
        try {
            $stmt = self::$connection->query('SELECT version FROM schema_version ORDER BY version DESC LIMIT 1');
            $currentVersion = $stmt->fetchColumn();

            if ($currentVersion < self::SCHEMA_VERSION) {
                self::upgradeTables($currentVersion);
            }
        } catch (PDOException $e) {
            throw new \RuntimeException("Failed to check schema version: " . $e->getMessage());
        }
    }

    private static function upgradeTables(int $fromVersion): void
    {
        try {
            self::$connection->beginTransaction();

            // 在这里添加架构升级逻辑
            switch ($fromVersion) {
                case 0:
                    // 从版本0升级到版本1的逻辑
                    break;
                // 添加更多版本升级逻辑
            }

            // 更新架构版本
            self::$connection->exec('
                INSERT INTO schema_version (version)
                VALUES (' . self::SCHEMA_VERSION . ')
            ');

            self::$connection->commit();
        } catch (PDOException $e) {
            self::$connection->rollBack();
            throw new \RuntimeException("Failed to upgrade schema: " . $e->getMessage());
        }
    }

    public static function cleanup(): void
    {
        try {
            // 清理过期的分片上传（例如 24 小时前未完成的）
            $db = self::getConnection();
            $db->beginTransaction();

            // 获取过期的上传
            $stmt = $db->prepare('
                SELECT * FROM multipart_uploads 
                WHERE status = "initiated" 
                AND created_at < datetime("now", "-1 day")
            ');
            $stmt->execute();
            $expiredUploads = $stmt->fetchAll();

            foreach ($expiredUploads as $upload) {
                // 获取分片文件路径
                $stmt = $db->prepare('SELECT storage_path FROM parts WHERE upload_id = ?');
                $stmt->execute([$upload['upload_id']]);
                $parts = $stmt->fetchAll();

                // 删除分片文件
                foreach ($parts as $part) {
                    if (file_exists($part['storage_path'])) {
                        unlink($part['storage_path']);
                    }
                }

                // 删除分片记录
                $stmt = $db->prepare('DELETE FROM parts WHERE upload_id = ?');
                $stmt->execute([$upload['upload_id']]);

                // 更新上传状态
                $stmt = $db->prepare('
                    UPDATE multipart_uploads 
                    SET status = "expired", completed_at = datetime("now")
                    WHERE upload_id = ?
                ');
                $stmt->execute([$upload['upload_id']]);
            }

            $db->commit();
        } catch (\Exception $e) {
            if (isset($db)) {
                $db->rollBack();
            }
            error_log("Database cleanup error: " . $e->getMessage());
        }
    }
}
