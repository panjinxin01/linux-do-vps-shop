<?php
/**
 * 交付服务
 *
 * 处理订单交付状态流转、自动交付判断、交付信息更新。
 */
readonly class DeliveryService {
    public function __construct(
        private PDO $pdo,
    ) {}

    /** 更新订单交付状态 */
    public function updateStatus(string $orderNo, string $status, string $note = '', string $error = ''): bool {
        $allowed = ['pending', 'paid_waiting', 'provisioning', 'delivered', 'exception', 'cancelled', 'refunded'];
        if (!in_array($status, $allowed, true)) { return false; }
        $parts = ['delivery_status = ?', 'delivery_updated_at = NOW()'];
        $params = [$status];
        if (commerceColumnExists($this->pdo, 'orders', 'delivery_note')) { $parts[] = 'delivery_note = ?'; $params[] = $note !== '' ? $note : null; }
        if (commerceColumnExists($this->pdo, 'orders', 'delivery_error')) { $parts[] = 'delivery_error = ?'; $params[] = $error !== '' ? $error : null; }
        if ($status === 'delivered' && commerceColumnExists($this->pdo, 'orders', 'delivered_at')) { $parts[] = 'delivered_at = COALESCE(delivered_at, NOW())'; }
        if (commerceColumnExists($this->pdo, 'orders', 'handled_admin_id') && !empty($_SESSION['admin_id'])) { $parts[] = 'handled_admin_id = ?'; $params[] = (int)$_SESSION['admin_id']; }
        $params[] = $orderNo;
        $stmt = $this->pdo->prepare('UPDATE orders SET ' . implode(', ', $parts) . ' WHERE order_no = ?');
        return $stmt->execute($params);
    }

    /** 判断订单是否有交付数据载荷 */
    public function hasPayload(array $order): bool {
        foreach (['delivery_info', 'ip_address', 'ssh_user', 'ssh_password',
                  'ip_address_snapshot', 'ssh_user_snapshot', 'ssh_password_snapshot'] as $field) {
            if (!empty($order[$field])) { return true; }
        }
        return false;
    }

    /** 自动解析交付状态 */
    public function resolveAutoStatus(array $order, string $fallback = 'paid_waiting'): string {
        if (!empty($order['delivery_note']) && strpos((string)$order['delivery_note'], '人工审核') !== false) {
            return 'exception';
        }
        return $this->hasPayload($order) ? 'delivered' : $fallback;
    }
}
