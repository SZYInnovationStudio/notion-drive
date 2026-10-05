<?php

declare(strict_types=1);

namespace NotionDrive\Mail;

/**
 * 邮件模板：内联样式的 HTML 外壳（兼容主流邮箱客户端；邮件里不能用 CSS 变量/外链样式）。
 * 统一在这里维护标题、卡片、验证码高亮与页脚，避免各调用点各写一套。
 */
final class MailTemplate
{
    private const ACCENT = '#4f46e5';
    private const TEXT   = '#1f2430';
    private const MUTED  = '#6b7280';
    private const LINE   = '#e5e7eb';
    private const BG     = '#f4f5f8';
    private const SOFT   = '#f7f8fb';

    /** 统一外壳：站点名 + 标题 + 正文卡片 + 自动发送页脚。 */
    public static function layout(string $title, string $bodyHtml, string $siteName, string $subtitle = ''): string
    {
        $sub = $subtitle === ''
            ? ''
            : '<p style="margin:6px 0 0;color:' . self::MUTED . ';font-size:13px;line-height:1.6">' . self::e($subtitle) . '</p>';

        return '<!doctype html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            . '<body style="margin:0;padding:24px 12px;background:' . self::BG . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,\'PingFang SC\',\'Microsoft YaHei\',Arial,sans-serif;">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="border-collapse:collapse"><tr><td align="center">'
            . '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:560px;background:#ffffff;border:1px solid ' . self::LINE . ';border-radius:14px;">'
            . '<tr><td style="padding:22px 26px 0;">'
            . '<div style="font-size:12px;letter-spacing:.04em;color:' . self::ACCENT . ';font-weight:700">' . self::e($siteName) . '</div>'
            . '<h1 style="margin:10px 0 0;font-size:19px;line-height:1.45;color:' . self::TEXT . '">' . self::e($title) . '</h1>'
            . $sub
            . '</td></tr>'
            . '<tr><td style="padding:16px 26px 24px;color:' . self::TEXT . ';font-size:14px;line-height:1.75">' . $bodyHtml . '</td></tr>'
            . '<tr><td style="padding:14px 26px 20px;border-top:1px solid ' . self::LINE . ';color:' . self::MUTED . ';font-size:12px;line-height:1.7">'
            . '本邮件由系统自动发送，请勿直接回复。<br>由 SZY创新工作室 提供技术支持'
            . '</td></tr></table></td></tr></table></body></html>';
    }

    /** 验证码邮件：高亮展示 6 位验证码。 */
    public static function code(string $code, string $siteName): string
    {
        $block = '<div style="margin:18px 0;padding:14px 18px;text-align:center;background:' . self::SOFT . ';border:1px dashed ' . self::ACCENT . ';border-radius:10px;font-size:26px;letter-spacing:6px;font-weight:700;color:' . self::ACCENT . ';font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace">' . self::e($code) . '</div>';

        $body = '<p style="margin:0">你正在进行邮箱验证，请在页面中填写以下验证码：</p>' . $block
            . '<p style="margin:0;color:' . self::MUTED . ';font-size:13px">验证码 10 分钟内有效，请勿转发或告知他人。若并非本人操作，请忽略本邮件。</p>';

        return self::layout('邮箱验证码', $body, $siteName, '用于完成注册、绑定邮箱或找回密码');
    }

    /** 纯文本通知（自动转义并保留换行）。 */
    public static function notice(string $title, string $text, string $siteName): string
    {
        return self::layout($title, '<div style="margin:0;white-space:pre-wrap">' . self::e($text) . '</div>', $siteName);
    }

    /** 后台告警邮件。 */
    public static function alert(string $level, string $message, array $context, string $siteName): string
    {
        $color = $level === 'error' ? '#b42318' : '#8a6100';

        $body = '<p style="margin:0"><strong style="color:' . $color . '">' . self::e($level === 'error' ? '严重告警' : '提醒') . '</strong></p>'
            . '<div style="margin:10px 0 0;padding:12px 14px;background:' . self::SOFT . ';border:1px solid ' . self::LINE . ';border-radius:10px;white-space:pre-wrap">' . self::e($message) . '</div>';

        if ($context !== []) {
            $json = (string) json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            $body .= '<p style="margin:14px 0 6px;color:' . self::MUTED . ';font-size:13px">上下文</p>'
                . '<pre style="margin:0;padding:12px 14px;background:' . self::SOFT . ';border:1px solid ' . self::LINE . ';border-radius:10px;font-size:12px;line-height:1.6;overflow:auto">' . self::e($json) . '</pre>';
        }

        $body .= '<p style="margin:14px 0 0;color:' . self::MUTED . ';font-size:13px">请登录管理后台查看详情。</p>';

        return self::layout('后台告警', $body, $siteName);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
