<?php

declare(strict_types=1);

// 仅允许命令行调用：防止被 Web 直接触发（无鉴权执行全量巡检）
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * R13 空间巡检 CLI 入口：供服务器计划任务（如每天 12:00）显式调用，不依赖网页懒触发。
 *
 * 用法：
 *   php bin/scan-space.php
 *
 * 退出码：0 正常（无论是否有修复动作）；1 未安装或巡检抛出未捕获异常。
 */

use NotionDrive\Space\SpacePatrol;

require dirname(__DIR__) . '/src/bootstrap.php';

$lockFile = dirname(__DIR__) . '/storage/installed.lock';
if (!is_file($lockFile)) {
    fwrite(STDERR, "系统尚未安装，跳过空间巡检。\n");
    exit(1);
}

try {
    $stats = SpacePatrol::run();
    printf(
        "[%s] 空间巡检完成：扫描空间 %d，恢复归档 %d，重建页面 %d，修复失败 %d，补齐副本 %d，标记丢失 %d。\n",
        date('Y-m-d H:i:s'),
        $stats['spaces'],
        $stats['restored'],
        $stats['recreated'],
        $stats['failed'],
        $stats['filled'],
        $stats['lost']
    );
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, '空间巡检失败：' . $exception->getMessage() . "\n");
    exit(1);
}
