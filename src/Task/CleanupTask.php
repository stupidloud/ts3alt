<?php

namespace S3Server\Task;

use S3Server\Model\MultipartUpload;

class CleanupTask extends BaseTask
{
    private const TEMP_FILE_TTL = 3600; // 1 hour
    private const LOCK_TIMEOUT = 30;    // 30 seconds
    
    public function __construct()
    {
        parent::__construct('cleanup', 300); // 每5分钟运行一次
    }
    
    protected function run(): void
    {
        $this->cleanupTempFiles();
        $this->cleanupExpiredUploads();
    }
    
    private function cleanupTempFiles(): void
    {
        $storageDir = dirname(dirname(__DIR__)) . '/storage';
        if (!is_dir($storageDir)) {
            return;
        }

        $count = 0;
        if ($handle = opendir($storageDir)) {
            while (false !== ($entry = readdir($handle))) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                
                $path = $storageDir . '/' . $entry;
                if (!is_file($path)) {
                    continue;
                }

                // 清理临时文件
                if (strpos($entry, '.tmp') !== false && 
                    time() - filemtime($path) > self::TEMP_FILE_TTL) {
                    if (@unlink($path)) {
                        $count++;
                    }
                }
                
                // 清理过期的锁文件
                if (strpos($entry, '.lock') !== false && 
                    time() - filemtime($path) > self::LOCK_TIMEOUT) {
                    if (@unlink($path)) {
                        $count++;
                    }
                }
            }
            closedir($handle);
        }

        if ($count > 0) {
            error_log("[Cleanup] Removed $count temporary files");
        }
    }
    
    private function cleanupExpiredUploads(): void
    {
        try {
            MultipartUpload::cleanupExpiredUploads();
        } catch (\Exception $e) {
            error_log("[Cleanup] Failed to cleanup expired uploads: " . $e->getMessage());
        }
    }
}
