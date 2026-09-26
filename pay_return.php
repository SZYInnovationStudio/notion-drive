<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;

$user = Auth::requireLogin();
$userId = (int) $user['id'];

// 用 out_trade_no 精确定位本次订单，避免多笔待支付时对错账；定位仅用于找订单，真实性由 reconcileOrder() 主动查单确认
$candidateNo = trim((string) ($_GET['out_trade_no'] ?? ''));
$target = null;
if ($candidateNo !== '') {
    $statement = Database::connection()->prepare(
        'SELECT * FROM orders WHERE user_id = :user_id AND order_no = :order_no LIMIT 1'
    );
    $statement->execute(['user_id' => $userId, 'order_no' => $candidateNo]);
    $found = $statement->fetch();
    if (is_array($found)) {
        $target = $found;
    }
}

// 回跳参数缺失或不匹配时退化为「最近 5 笔订单」：优先最近一笔待支付，否则回落到最新一笔用于展示状态
if ($target === null) {
    $statement = Database::connection()->prepare(
        'SELECT * FROM orders WHERE user_id = :user_id ORDER BY id DESC LIMIT 5'
    );
    $statement->execute(['user_id' => $userId]);
    foreach ($statement->fetchAll() as $order) {
        if ((string) $order['status'] === 'pending') {
            $target = $order;
            break;
        }
        if ($target === null) {
            $target = $order;
        }
    }
}

if ($target === null) {
    flash('error', '没有找到与本次支付对应的订单，如已扣款请联系管理员。');
    header('Location: pay.php');
    exit;
}

if ((string) $target['status'] === 'pending') {
    $result = reconcileOrder($target);
    flash($result['paid'] ? 'success' : 'error', $result['message']);
} elseif ((string) $target['status'] === 'paid') {
    flash('success', '该订单已完成支付。');
} else {
    // 已关闭与已作废的文案不同，用统一的状态文案拼装，避免都提示「已关闭」造成误解
    flash('error', sprintf('该订单%s，如已完成支付请联系管理员核对。', orderStatusLabel((string) $target['status'])));
}

header('Location: pay.php?order=' . rawurlencode((string) $target['order_no']));
exit;