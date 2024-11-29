<?php

require_once __DIR__ . '/../vendor/autoload.php';

use S3Server\Task\TaskScheduler;
use S3Server\Task\CleanupTask;

// 设置错误处理
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// 注册信号处理器
pcntl_signal(SIGTERM, 'handleSignal');
pcntl_signal(SIGINT, 'handleSignal');

function handleSignal($signal) {
    global $scheduler;
    error_log("[Scheduler] Received signal $signal, stopping...");
    $scheduler->stop();
}

// 创建调度器
$scheduler = new TaskScheduler();

// 注册任务
$scheduler->addTask(new CleanupTask());

// 启动调度器
error_log("[Scheduler] Starting scheduler...");
$scheduler->run();
