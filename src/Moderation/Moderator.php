<?php

declare(strict_types=1);

namespace NotionDrive\Moderation;

/**
 * 审核器接口：任何可插拔的审核实现都需满足该契约。
 *
 * $subject 支持的键：
 *   filename (string) 原始文件名
 *   mime     (string) 探测到的 MIME
 *   path     (string) 待审文件在磁盘上的绝对路径（可能不存在）
 *   size     (int)    文件字节数
 *   text     (string) 已提取的纯文本内容（可选）
 */
interface Moderator
{
    public function name(): string;

    /**
     * @param array<string, mixed> $subject
     */
    public function review(array $subject): Verdict;
}
