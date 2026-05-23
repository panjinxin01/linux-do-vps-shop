<?php
/**
 * 订单服务
 *
 * 封装订单创建、退款、输出处理等核心流程。
 * 使用构造器注入 PDO 及依赖的其他 Service。
 */
readonly class OrderService {
    public function __construct(
        private PDO             $pdo,
        private BalanceService  $balanceService,
    ) {}

    /** 按剩余时长计算退款策略 */
    public function buildRefundPolicy(array $order): array {
        $status = (int)($order['status'] ?? 0);
        $delivery = (string)($order['delivery_status'] ?? '');
        $price = round((float)($order['price'] ?? 0), 2);
        $durationDays = max(1, (int)($order['service_period_days'] ?? 30));
        $baseTime = (string)($order['delivered_at'] ?: ($order['paid_at'] ?: ($order['created_at'] ?: 'now')));
        $startTs = strtotime($baseTime) ?: time();
        $endTs = $startTs + ($durationDays * 86400);
        if (!empty($order['service_end_at'])) {
            $customEnd = strtotime((string)$order['service_end_at']);
            if ($customEnd) { $endTs = $customEnd; }
        }
        $totalSeconds = max(1, $endTs - $startTs);
        $remainingSeconds = max(0, $endTs - time());
        $ratio = min(1, max(0, $remainingSeconds / $totalSeconds));
        $amount = round($price * $ratio, 2);
        if ($status !== 1 || in_array($delivery, ['refunded', 'cancelled'], true)) {
            $remainingSeconds = 0; $ratio = 0; $amount = 0.00;
        }
        if ($price > 0 && $ratio > 0 && $amount <= 0) { $amount = 0.01; }
        if ($amount > $price) { $amount = $price; }
        return [
            'service_start_at' => date('Y-m-d H:i:s', $startTs),
            'service_end_at' => date('Y-m-d H:i:s', $endTs),
            'service_period_days' => $durationDays,
            'remaining_seconds' => (int)$remainingSeconds,
            'remaining_days' => round($remainingSeconds / 86400, 2),
            'refund_ratio' => round($ratio, 6),
            'refundable_amount' => round($amount, 2),
        ];
    }

    /** 执行订单退款 */
    public function refund(array $order, string $refundTarget = 'original', string $refundReason = '人工退款'): array {
        if (!$order || empty($order['order_no'])) {
            throw new InvalidArgumentException('order missing');
        }
        if ((int)($order['status'] ?? 0) !== 1) {
            throw new RuntimeException('只能退款已支付的订单');
        }
        $policy = $this->buildRefundPolicy($order);
        $refundTotal = round((float)($policy['refundable_amount'] ?? 0), 2);
        if ($refundTotal <= 0) {
            throw new RuntimeException('当前订单剩余时长为 0，可退金额为 0');
        }
        $refundTarget = trim((string)$refundTarget);
        if (!in_array($refundTarget, ['original', 'balance', 'auto'], true)) {
            $refundTarget = 'original';
        }
        $orderNo = (string)$order['order_no'];
        $externalPaid = round((float)($order['external_pay_amount'] ?? ((($order['payment_method'] ?? '') === 'epay') ? $order['price'] : 0)), 2);
        $balancePaid = round((float)($order['balance_paid_amount'] ?? ((($order['payment_method'] ?? '') === 'balance') ? $order['price'] : 0)), 2);
        $totalPaid = round($externalPaid + $balancePaid, 2);
        if ($totalPaid <= 0) {
            $totalPaid = round((float)($order['price'] ?? 0), 2);
            if (($order['payment_method'] ?? '') === 'balance') { $balancePaid = $totalPaid; $externalPaid = 0.00; }
            else { $externalPaid = $totalPaid; $balancePaid = 0.00; }
        }
        if ($refundTarget === 'auto') { $refundTarget = $externalPaid > 0 ? 'original' : 'balance'; }

        $externalRatio = $totalPaid > 0 ? ($externalPaid / $totalPaid) : 0;
        $externalRefundAmount = min($externalPaid, round($refundTotal * $externalRatio, 2));
        $refundToBalanceAmount = round($refundTotal - $externalRefundAmount, 2);
        if ($refundToBalanceAmount > $balancePaid) {
            $overflow = round($refundToBalanceAmount - $balancePaid, 2);
            $refundToBalanceAmount = $balancePaid;
            $externalRefundAmount = min($externalPaid, round($externalRefundAmount + $overflow, 2));
        }
        if ($refundTarget === 'balance') { $refundToBalanceAmount = $refundTotal; $externalRefundAmount = 0.00; }

        $refundTradeNo = null;
        if ($externalRefundAmount > 0) {
            if (empty($order['trade_no'])) { throw new RuntimeException('订单缺少平台交易号，无法发起外部退款'); }
            $refundOk = false;
            require_once __DIR__ . '/../ldcpay.php';
            $ldcClientId = commerceGetSetting($this->pdo, 'ldcpay_client_id');
            $ldcClientSecret = commerceGetSetting($this->pdo, 'ldcpay_client_secret');
            if ($ldcClientId !== '' && $ldcClientSecret !== '') {
                $refundResult = ldcpay_refund($this->pdo, $order['trade_no'], $externalRefundAmount, $orderNo);
                if ((int)($refundResult['code'] ?? 0) === 1) {
                    $refundTradeNo = $refundResult['trade_no'] ?? $order['trade_no']; $refundOk = true;
                }
            }
            if (!$refundOk) {
                $pid = commerceGetSetting($this->pdo, 'epay_pid');
                $key = commerceGetSetting($this->pdo, 'epay_key');
                if ($pid === '' || $key === '') { throw new RuntimeException('退款配置不完整'); }
                $refundData = ['pid' => $pid, 'key' => $key, 'trade_no' => $order['trade_no'], 'money' => $externalRefundAmount, 'out_trade_no' => $orderNo];
                $refundResponse = httpRequest('https://credit.linux.do/epay/api.php', ['method' => 'POST', 'data' => $refundData, 'timeout' => 30]);
                if (!$refundResponse['ok']) { throw new RuntimeException('请求退款接口失败: ' . ($refundResponse['error'] ?: 'network error')); }
                $result = json_decode((string)$refundResponse['body'], true);
                if (!$result || (int)($result['code'] ?? 0) !== 1) { throw new RuntimeException($result['msg'] ?? '退款失败'); }
                $refundTradeNo = $result['trade_no'] ?? $order['trade_no'];
            }
        }

        try {
            $this->pdo->beginTransaction();
            $lockStmt = $this->pdo->prepare('SELECT status FROM orders WHERE order_no = ? FOR UPDATE');
            $lockStmt->execute([$orderNo]);
            $lockedOrder = $lockStmt->fetch(PDO::FETCH_ASSOC);
            if (!$lockedOrder || (int)$lockedOrder['status'] !== 1) { $this->pdo->rollBack(); throw new RuntimeException('订单已退款或状态已变更'); }
            if ($refundToBalanceAmount > 0) {
                $this->balanceService->adjust((int)$order['user_id'], 'refund', $refundToBalanceAmount, [
                    'related_order_id' => (int)$order['id'], 'related_order_no' => $orderNo,
                    'remark' => $refundTarget === 'balance' ? '按剩余时长退款退回站内余额' : '按剩余时长退款返还余额',
                ]);
            }
            releaseCouponByOrder($this->pdo, $orderNo);
            $parts = ['status = 2'];
            $params = [];
            if (commerceColumnExists($this->pdo, 'orders', 'refund_reason')) { $parts[] = 'refund_reason = ?'; $params[] = $refundReason; }
            if (commerceColumnExists($this->pdo, 'orders', 'refund_trade_no')) { $parts[] = 'refund_trade_no = ?'; $params[] = $refundTradeNo; }
            if (commerceColumnExists($this->pdo, 'orders', 'refund_amount')) { $parts[] = 'refund_amount = ?'; $params[] = $refundTotal; }
            if (commerceColumnExists($this->pdo, 'orders', 'refund_at')) { $parts[] = 'refund_at = NOW()'; }
            if (commerceColumnExists($this->pdo, 'orders', 'delivery_status')) { $parts[] = "delivery_status = 'refunded'"; $parts[] = 'delivery_updated_at = NOW()'; }
            $params[] = $orderNo;
            $this->pdo->prepare('UPDATE orders SET ' . implode(', ', $parts) . ' WHERE order_no = ? AND status = 1')->execute($params);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $e;
        }
        $refundMsg = $refundTarget === 'balance' ? '已按剩余时长退回站内余额' : '已按剩余时长原路退款';
        createNotification($this->pdo, (int)$order['user_id'], 'order_refund', '订单已退款',
            '您的订单 ' . $orderNo . ' 已退款成功，退款金额：' . number_format($refundTotal, 2) . ' 积分，' . $refundMsg, $orderNo);
        return [
            'order_no' => $orderNo, 'refund_total' => $refundTotal, 'refund_target' => $refundTarget,
            'refund_reason' => $refundReason, 'refund_trade_no' => $refundTradeNo,
            'refund_to_balance_amount' => round($refundToBalanceAmount, 2),
            'external_refund_amount' => round($externalRefundAmount, 2),
            'refund_ratio' => (float)$policy['refund_ratio'], 'remaining_days' => (float)$policy['remaining_days'],
            'service_end_at' => $policy['service_end_at'], 'message' => $refundMsg,
        ];
    }

    /** 判断订单是否需要隐藏凭据 */
    public function shouldShowCredentials(array $order): bool {
        $status = (int)($order['status'] ?? 0);
        $delivery = (string)($order['delivery_status'] ?? '');
        return $status === 1 && !in_array($delivery, ['exception', 'cancelled', 'refunded'], true);
    }

    /** 准备订单输出（标准化 + 解密 + 隐藏凭据） */
    public function prepareForOutput(array &$order, bool $hideRestrictedCredentials = false): void {
        $this->normalizePaymentMethod($order);
        $this->normalizeDeliveryStatus($order);
        $policy = $this->buildRefundPolicy($order);
        foreach ($policy as $k => $v) { $order[$k] = $v; }
        if (isset($order['ssh_password'])) { $order['ssh_password'] = decryptSensitive($order['ssh_password']); }
        if (!empty($order['delivery_info'])) { $order['delivery_info'] = $this->decryptDeliveryInfo((string)$order['delivery_info']); }
        if ($hideRestrictedCredentials && !$this->shouldShowCredentials($order)) {
            unset($order['ip_address'], $order['ssh_port'], $order['ssh_user'], $order['ssh_password'], $order['extra_info']);
        }
    }

    private function decryptDeliveryInfo(string $info): string {
        return preg_replace_callback('/enc:[A-Za-z0-9+\/=]+/', static fn(array $m): string => (string)decryptSensitive($m[0]), $info);
    }

    private function normalizePaymentMethod(array &$order): void {
        $status = (int)($order['status'] ?? 0);
        $method = (string)($order['payment_method'] ?? '');
        if ($method === '' || $method === 'pending') {
            $order['payment_method'] = match (true) {
                $status === 0 => 'pending',
                (float)($order['balance_paid_amount'] ?? 0) > 0 => 'balance',
                $status === 1 => 'epay',
                default => 'pending',
            };
        }
    }

    private function normalizeDeliveryStatus(array &$order): void {
        $status = (int)($order['status'] ?? 0);
        $delivery = (string)($order['delivery_status'] ?? '');
        if ($delivery === '' || ($delivery === 'pending' && $status === 1)) {
            $order['delivery_status'] = match ($status) {
                0 => 'pending',
                1 => !empty($order['delivered_at']) ? 'delivered' : 'paid_waiting',
                2 => 'refunded',
                default => 'cancelled',
            };
        }
        $map = commerceGetDeliveryStatuses();
        $order['delivery_status_text'] = $map[$order['delivery_status']] ?? $order['delivery_status'];
    }
}
