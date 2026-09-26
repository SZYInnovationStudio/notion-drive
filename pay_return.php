<?php

declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

use NotionDrive\Auth;
use NotionDrive\Database;

$user = Auth::requireLogin();
$userId = (int) $user['id'];

// 易支付的 return_url 不支持带参数，因此回跳后由本页自行定位订单并向网关对账
$statement = Database::connection()->prepare(
    'SELECT * FROM orders WHERE user_id = :user_id ORDER BY id DESC LIMIT 5'
);
$statement->execute(['user_id' => $userId]);
$orders = $statement->fetchAll();

$target = null;
foreach ($orders as $order) {
    // 优先处理最近一笔待支付订单；没有待支付订单时回落到最新一笔，用于展示最终状态
    if ((string) $order['status'] === 'pending') {
        $target = $order;
        break;
    }
    if ($target === null) {
        $target = $order;
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
    flash('error', '该订单已关闭，如已完成支付请联系管理员核对。');
}

header('Location: pay.php?order=' . rawurlencode((string) $target['order_no']));
exit;