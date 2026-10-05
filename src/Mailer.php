<?php

declare(strict_types=1);

namespace NotionDrive;

use RuntimeException;
use Throwable;

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

    /**
     * 发送邮件（$html 不为 null 时发 multipart/alternative，客户端优先显示 HTML 版）；失败抛出 RuntimeException。
     */
    public function send(string $to, string $subject, string $body, ?string $html = null): void
    {
        if (!$this->isValidRecipient($to)) {
            throw new RuntimeException('收件邮箱地址无效。');
        }
        if ($this->fromAddress === '') {
            throw new RuntimeException('尚未配置发件人邮箱地址。');
        }

        $this->open();
        try {
            $this->authenticate();
            $this->deliver($to, $this->buildMessage($to, $subject, $body, $html));
            // 邮件已在 DATA 阶段被服务器接收，此时 QUIT 失败不算发送失败；
            // 否则调用方（api/verify.php）会误删刚发出的验证码，用户收到码却永远验不过。
            try {
                $this->write('QUIT');
            } catch (Throwable) {
                // 忽略：仅是关闭连接异常
            }
        } finally {
            $this->close();
        }
    }

    /**
     * 批量发送同一封邮件：复用同一条 SMTP 连接（只握手/认证一次），单个收件人失败不影响其余。
     * 返回发送失败的地址列表；连接或认证整体失败时抛出 RuntimeException。
     *
     * @param list<string> $recipients
     * @return list<string>
     */
    public function sendMany(array $recipients, string $subject, string $body, ?string $html = null): array
    {
        $valid = [];
        $failed = [];
        foreach ($recipients as $to) {
            $to = trim((string) $to);
            if ($to === '') {
                continue;
            }
            if ($this->isValidRecipient($to)) {
                $valid[$to] = true;
            } else {
                $failed[$to] = true;
            }
        }
        if ($valid === []) {
            return array_keys($failed);
        }
        if ($this->fromAddress === '') {
            throw new RuntimeException('尚未配置发件人邮箱地址。');
        }

        $this->open();
        try {
            $this->authenticate();
            $targets = array_keys($valid);
            $total = count($targets);
            for ($index = 0; $index < $total; $index++) {
                $to = $targets[$index];
                try {
                    $this->deliver($to, $this->buildMessage($to, $subject, $body, $html));
                } catch (Throwable $exception) {
                    Logger::warning('mail', '批量发信时单个收件人失败。', ['to' => $to, 'reason' => $exception->getMessage()]);
                    $failed[$to] = true;
                    // 该失败可能源于连接被中断，重连后继续发送剩余收件人
                    try {
                        $this->close();
                        $this->open();
                        $this->authenticate();
                    } catch (Throwable) {
                        // 重连失败：剩余收件人一个都发不出去，全部记为失败，避免调用方误以为已送达
                        for ($rest = $index + 1; $rest < $total; $rest++) {
                            $failed[$targets[$rest]] = true;
                        }
                        break;
                    }
                }
            }
            try {
                $this->write('QUIT');
            } catch (Throwable) {
                // 忽略退出阶段的异常，结果以已投递的收件人为准
            }
        } finally {
            $this->close();
        }

        return array_keys($failed);
    }

    private function isValidRecipient(string $to): bool
    {
        return (bool) filter_var($to, FILTER_VALIDATE_EMAIL);
    }

    /** 投递一封已构建好的邮件（MAIL FROM → RCPT TO → DATA）。 */
    private function deliver(string $to, string $message): void
    {
        $this->write('MAIL FROM:<' . $this->fromAddress . '>');
        $this->expect([250]);
        $this->write('RCPT TO:<' . $to . '>');
        $this->expect([250, 251]);
        $this->write('DATA');
        $this->expect([354]);
        $this->write($message, false);
        $this->write('.');
        $this->expect([250]);
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

    private function buildMessage(string $to, string $subject, string $body, ?string $html = null): string
    {
        $senderName = $this->fromName === '' ? $this->fromAddress : $this->fromName;
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $domain = (string) ($_SERVER['SERVER_NAME'] ?? '');

        $headers = [
            'Date: ' . date('r'),
            'From: =?UTF-8?B?' . base64_encode($senderName) . '?= <' . $this->fromAddress . '>',
            'To: <' . $to . '>',
            'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
            'MIME-Version: 1.0',
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . ($domain === '' ? 'localhost' : $domain) . '>',
        ];

        if ($html === null) {
            $headers[] = 'Content-Type: text/plain; charset=UTF-8';
            $headers[] = 'Content-Transfer-Encoding: base64';

            return implode("\r\n", $headers) . "\r\n\r\n"
                . chunk_split(base64_encode($normalized), 76, "\r\n");
        }

        // 同时携带纯文本与 HTML 两个版本，由客户端选择展示
        $boundary = 'b1_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundary . '"';

        $parts = [
            '--' . $boundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($normalized), 76, "\r\n"),
            '--' . $boundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($html), 76, "\r\n"),
            '--' . $boundary . '--',
            '',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
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
