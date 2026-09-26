<?php

declare(strict_types=1);

namespace NotionDrive;

use RuntimeException;

/**
 * 轻量 SMTP 客户端：支持 ssl（465）、tls（587 STARTTLS）与 none（25）三种连接方式，
 * 使用 AUTH LOGIN 认证，正文按 UTF-8 base64 编码发送。
 */
final class Mailer
{
    private const TIMEOUT = 15;

    /** @var resource|null */
    private $socket = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $encryption,
        private string $username,
        private string $password,
        private string $fromAddress,
        private string $fromName
    ) {
    }

    public static function fromConfig(): self
    {
        $host = trim((string) Database::config('smtp_host', ''));
        if ($host === '') {
            throw new RuntimeException('尚未配置 SMTP 服务器，请先在管理后台完成邮件设置。');
        }

        $encryption = (string) Database::config('smtp_encryption', 'tls');
        if (!in_array($encryption, ['ssl', 'tls', 'none'], true)) {
            $encryption = 'tls';
        }

        $password = (string) Database::config('smtp_password', '');
        if ($password !== '') {
            $password = Security::decrypt($password, (string) (appConfig()['app_key'] ?? ''));
        }

        return new self(
            $host,
            (int) Database::config('smtp_port', $encryption === 'ssl' ? '465' : '587'),
            $encryption,
            trim((string) Database::config('smtp_username', '')),
            $password,
            trim((string) Database::config('smtp_from', '')),
            trim((string) Database::config('smtp_from_name', 'Notion Drive'))
        );
    }

    /** 发送纯文本邮件；失败抛出 RuntimeException。 */
    public function send(string $to, string $subject, string $body): void
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('收件邮箱地址无效。');
        }
        if ($this->fromAddress === '') {
            throw new RuntimeException('尚未配置发件人邮箱地址。');
        }

        $this->open();
        try {
            $this->authenticate();
            $this->write('MAIL FROM:<' . $this->fromAddress . '>');
            $this->expect([250]);
            $this->write('RCPT TO:<' . $to . '>');
            $this->expect([250, 251]);
            $this->write('DATA');
            $this->expect([354]);
            $this->write($this->buildMessage($to, $subject, $body), false);
            $this->write('.');
            $this->expect([250]);
            $this->write('QUIT');
        } finally {
            $this->close();
        }
    }

    private function open(): void
    {
        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true],
        ]);
        $transport = $this->encryption === 'ssl' ? 'ssl' : 'tcp';
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $transport . '://' . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new RuntimeException('无法连接 SMTP 服务器：' . ($errstr !== '' ? $errstr : '连接超时'));
        }

        stream_set_timeout($socket, self::TIMEOUT);
        $this->socket = $socket;
        $this->expect([220]);
        $this->ehlo();

        if ($this->encryption === 'tls') {
            $this->write('STARTTLS');
            $this->expect([220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('无法与 SMTP 服务器建立 TLS 加密连接。');
            }
            $this->ehlo();
        }
    }

    private function ehlo(): void
    {
        $name = (string) ($_SERVER['SERVER_NAME'] ?? '');
        $this->write('EHLO ' . ($name === '' ? 'localhost' : $name));
        $this->expect([250]);
    }

    private function authenticate(): void
    {
        if ($this->username === '') {
            return;
        }

        $this->write('AUTH LOGIN');
        $this->expect([334]);
        $this->write(base64_encode($this->username));
        $this->expect([334]);
        $this->write(base64_encode($this->password));
        $this->expect([235]);
    }

    private function buildMessage(string $to, string $subject, string $body): string
    {
        $senderName = $this->fromName === '' ? $this->fromAddress : $this->fromName;
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($senderName) . '?= <' . $this->fromAddress . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n"
            . chunk_split(base64_encode($normalized), 76, "\r\n");
    }

    private function write(string $command, bool $appendCrlf = true): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('SMTP 连接尚未建立。');
        }
        $payload = $appendCrlf ? $command . "\r\n" : $command;
        if (@fwrite($this->socket, $payload) === false) {
            throw new RuntimeException('与 SMTP 服务器的通信已中断。');
        }
    }

    private function expect(array $expectedCodes): string
    {
        $reply = $this->readReply();
        $code = (int) substr($reply, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            // 原始响应对管理员有诊断价值，但不能直接回显给用户（可能含服务器信息）
            Logger::warning('mail', 'SMTP 服务器返回异常响应。', ['code' => $code, 'reply' => $reply]);
            throw new RuntimeException(sprintf('SMTP 服务器返回异常响应（%d）。', $code));
        }

        return $reply;
    }

    private function readReply(): string
    {
        $lastLine = '';
        while (true) {
            if (!is_resource($this->socket)) {
                throw new RuntimeException('SMTP 连接尚未建立。');
            }
            $line = @fgets($this->socket, 2048);
            if ($line === false) {
                throw new RuntimeException('SMTP 服务器未响应或响应超时。');
            }
            $lastLine = rtrim($line, "\r\n");
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        return $lastLine;
    }

    private function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }
}
