<?php

declare(strict_types=1);

namespace NotionDrive;

use RuntimeException;

/**
 * 易支付（Epay）客户端：采用「页面跳转收款」，服务端按易支付协议计算 MD5 签名并拼装提单 URL。
 *
 * 本站 CSP 的 form-action 限定为 'self'：表单不能直接 POST 到外部网关，下单也不能直接 302 过去
 * （Chrome / Safari 会把「表单 POST → 跨站重定向」按 form-action 拦截）。因此下单后先同源 302 回
 * 本站收银台过渡页，再由该页脚本导航打开提单 URL（脚本导航不属于表单提交，不受 form-action 约束）。
 *
 * 配置项（存于 config 表）：epay_gateway / epay_pid / epay_key（密文）。
 */
final class Epay
{
    public function __construct(
        private readonly string $gateway,
        private readonly string $pid,
        private readonly string $key,
    ) {
    }

    /** 从数据库配置构造客户端；配置不完整时返回 null。 */
    public static function fromConfig(): ?self
    {
        $gateway = trim((string) Database::config('epay_gateway', ''));
        $pid = trim((string) Database::config('epay_pid', ''));
        $encrypted = (string) Database::config('epay_key', '');

        if ($gateway === '' || $pid === '' || $encrypted === '') {
            return null;
        }

        try {
            $key = Security::decrypt($encrypted, (string) appConfig()['app_key']);
        } catch (RuntimeException) {
            return null;
        }

        if ($key === '') {
            return null;
        }

        return new self($gateway, $pid, $key);
    }

    public static function configured(): bool
    {
        return self::fromConfig() instanceof self;
    }

    public function pid(): string
    {
        return $this->pid;
    }

    /**
     * 计算易支付 MD5 签名。
     *
     * 规则：剔除 sign / sign_type 与空值，参数名按 ASCII 升序排序，
     * 拼成 k=v&k=v（值不做 urlencode），末尾直接拼接商户密钥，取 md5 小写。
     *
     * @param array<string,mixed> $params
     */
    public function sign(array $params): string
    {
        unset($params['sign'], $params['sign_type']);

        $filtered = [];
        foreach ($params as $name => $value) {
            if ($value === null || $value === '' || is_array($value)) {
                continue;
            }
            $filtered[(string) $name] = (string) $value;
        }

        // 不能依赖传入数组的顺序，必须按参数名 ASCII 升序排序后再拼接
        ksort($filtered, SORT_STRING);

        $pairs = [];
        foreach ($filtered as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }

        return md5(implode('&', $pairs) . $this->key);
    }

    /**
     * 校验网关回调的签名，并核对商户号是否为本站配置的商户。
     *
     * @param array<string,mixed> $params
     */
    public function verify(array $params): bool
    {
        $sign = (string) ($params['sign'] ?? '');
        if ($sign === '') {
            return false;
        }

        if (isset($params['pid']) && (string) $params['pid'] !== $this->pid) {
            return false;
        }

        $expected = $this->sign($params);

        return hash_equals($expected, strtolower($sign));
    }

    /**
     * 拼装收银台提单 URL（GET，含签名）。
     *
     * @param array<string,mixed> $params 业务参数：type / out_trade_no / notify_url / return_url / name / money / param
     */
    public function submitUrl(array $params): string
    {
        $params['pid'] = $this->pid;
        $params['sign_type'] = 'MD5';
        $params['sign'] = $this->sign($params);

        return $this->baseDir() . '/submit.php?' . http_build_query($params);
    }

    /**
     * 主动查单（用于回调丢失时对账）。查询失败时返回 null。
     *
     * @return array<string,mixed>|null
     */
    public function queryOrder(string $outTradeNo): ?array
    {
        $url = $this->baseDir() . '/api.php?' . http_build_query([
            'act' => 'order',
            'pid' => $this->pid,
            'key' => $this->key,
            'out_trade_no' => $outTradeNo,
        ]);

        $body = $this->httpGet($url);
        if ($body === null) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return null;
        }

        // 响应必须与本次查询的订单/商户绑定：网关返回了不匹配的单号或商户号时一律视为无效，
        // 避免被伪造或串单的响应误导对账而错误结算
        if (isset($decoded['out_trade_no']) && (string) $decoded['out_trade_no'] !== $outTradeNo) {
            return null;
        }
        if (isset($decoded['pid']) && (string) $decoded['pid'] !== $this->pid) {
            return null;
        }

        return $decoded;
    }

    /**
     * 查单结果是否表示已支付。
     *
     * 不同版本网关返回的字段不一致，这里同时兼容 status=1 与 trade_status=TRADE_SUCCESS。
     *
     * @param array<string,mixed> $result
     */
    public static function isPaidResult(array $result): bool
    {
        $status = $result['status'] ?? null;
        if ($status === 1 || $status === '1') {
            return true;
        }

        return strtoupper((string) ($result['trade_status'] ?? '')) === 'TRADE_SUCCESS';
    }

    /** 取网关所在目录：既支持配置到目录（.../GatewayV1/），也支持配置到具体 php 文件。 */
    private function baseDir(): string
    {
        $gateway = trim($this->gateway);
        if (str_ends_with(strtolower($gateway), '.php')) {
            $pos = strrpos($gateway, '/');
            $gateway = $pos === false ? '' : substr($gateway, 0, $pos);
        }

        return rtrim($gateway, '/');
    }

    private function httpGet(string $url): ?string
    {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            // 查单地址由本站按配置拼装，不跟随跳转，避免被网关（或被劫持的网关）引导到任意地址
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if (!is_string($body) || $status !== 200) {
            return null;
        }

        return $body;
    }
}