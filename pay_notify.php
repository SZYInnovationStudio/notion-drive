<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Epay;
use NotionDrive\Logger;

/**
 * 输出纯文本结果并结束请求。
 *
 * 易支付要求异步通知的响应体为纯文本 success，否则会按策略反复重推。
 */
function notifyRespond(string $text): never
{
    header('Content-Type: text/plain; charset=utf-8');
    echo $text;
    exit;
}

// 异步通知不需要登录态，安全性完全依赖签名校验与金额核对
$params = array_merge($_GET, $_POST);

$epay = Epay::fromConfig();
if ($epay === null) {
    Logger::error('payment', '收到支付回调，但支付功能未配置或密钥无法解密。', ['params' => $params]);
    raiseAdminAlert('error', '收到支付回调但支付配置不完整，无法自动开通，请检查易支付设置。');
    notifyRespond('fail');
}

if (!$epay->verify($params)) {
    Logger::error('payment', '支付回调验签失败。', ['params' => $params]);
    raiseAdminAlert('error', '支付回调验签失败，请检查商户密钥是否正确。');
    notifyRespond('fail');
}

$orderNo = trim((string) ($params['out_trade_no'] ?? ''));
$tradeStatus = strtoupper(trim((string) ($params['trade_status'] ?? '')));

// 只有支付成功才需要开通；其它状态（如已关闭）直接应答，避免网关反复重推
if ($tradeStatus !== 'TRADE_SUCCESS') {
    Logger::info('payment', '收到非成功状态的支付回调，已忽略。', [
        'order_no' => $orderNo,
        'trade_status' => $tradeStatus,
    ]);
    notifyRespond('success');
}

$order = findOrderByNo($orderNo);
if ($order === null) {
    Logger::error('payment', '支付回调对应的订单不存在。', ['order_no' => $orderNo, 'params' => $params]);
    raiseAdminAlert('warning', '收到未知订单的支付回调：' . ($orderNo === '' ? '(订单号为空)' : $orderNo));
    notifyRespond('fail');
}

// 金额核对：回调必须带金额且与订单金额一致，缺失或不等都绝不开通
// （缺失时不能当成 0 元直接判定「金额不符」，但也不能放行，一并写告警供人工核对）
$receivedMoney = $params['money'] ?? null;
if ($receivedMoney === null || $receivedMoney === '' || abs((float) $receivedMoney - (float) $order['amount']) >= 0.01) {
    Logger::error('payment', '支付回调金额缺失或与订单金额不一致，已拒绝开通。', [
        'order_no' => $orderNo,
        'expected' => (string) $order['amount'],
        'received' => $receivedMoney === null ? '(缺失)' : (string) $receivedMoney,
    ]);
    raiseAdminAlert('error', '支付回调金额缺失或不一致，请人工核对：' . $orderNo);
    notifyRespond('fail');
}

try {
    settleOrderPayment($order, (string) ($params['trade_no'] ?? ''), $params);
} catch (Throwable $exception) {
    Logger::error('payment', '支付回调结算失败。', [
        'order_no' => $orderNo,
        'reason' => $exception->getMessage(),
    ]);
    raiseAdminAlert('error', '订单结算失败，请人工核对：' . $orderNo);
    notifyRespond('fail');
}

notifyRespond('success');