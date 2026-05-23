<?php
/**
 * 余额/积分服务
 * 
 * 封装余额调整、流水记录、通知发送等逻辑。
 * 使用构造器注入 PDO。
 */
readonly class BalanceService {
    public function __construct(
        private PDO $pdo,
    ) {}

    /**
     * 调整用户余额（加/扣积分）
     * @param int    $userId  用户ID
     * @param string $type    变动类型（consume / refund / admin_add / admin_sub）
     * @param float  $amount  变动金额（正数增加，负数减少）
     * @param array  $options 可选参数：remark, related_order_id, related_order_no, notify
     * @return array ['before', 'after', 'amount', 'transaction_id']
     */
    public function adjust(int $userId, string $type, float $amount, array $options = []): array {
        if ($userId <= 0 || abs($amount) < 0.00001) {
            throw new InvalidArgumentException('invalid balance params');
        }
        $stmt = $this->pdo->prepare('SELECT id, credit_balance FROM users WHERE id = ? FOR UPDATE');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new RuntimeException('user not found');
        }
        $before = round((float)($user['credit_balance'] ?? 0), 2);
        $after = round($before + $amount, 2);
        if ($after < 0) {
            throw new RuntimeException('insufficient balance');
        }
        $this->pdo->prepare('UPDATE users SET credit_balance = ? WHERE id = ?')->execute([$after, $userId]);

        $txId = 0;
        if (commerceTableExists($this->pdo, 'credit_transactions')) {
            $stmt = $this->pdo->prepare(
                'INSERT INTO credit_transactions (user_id, type, amount, balance_before, balance_after,
                    related_order_id, related_order_no, remark, operator_admin_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
            );
            $stmt->execute([
                $userId, $type, round($amount, 2), $before, $after,
                $options['related_order_id'] ?? null,
                $options['related_order_no'] ?? null,
                $options['remark'] ?? null,
                !empty($_SESSION['admin_id'])
                    ? (int)$_SESSION['admin_id']
                    : ($options['operator_admin_id'] ?? null),
            ]);
            $txId = (int)$this->pdo->lastInsertId();
        }
        if (!empty($options['notify'])) {
            $title = $options['notify_title'] ?? '余额变动通知';
            $content = $options['notify_content'] ?? ('您的账户余额已变动，当前余额：' . number_format($after, 2) . ' 积分');
            createNotification($this->pdo, $userId, $options['notify_type'] ?? 'balance_change', $title, $content, $options['related_order_no'] ?? null);
        }
        return ['before' => $before, 'after' => $after, 'amount' => round($amount, 2), 'transaction_id' => $txId];
    }

    /** 获取用户余额摘要 */
    public function summary(int $userId): array {
        $user = commerceGetUserById($this->pdo, $userId);
        if (!$user) {
            return ['balance' => 0.0, 'income_total' => 0.0, 'expense_total' => 0.0, 'transaction_count' => 0, 'last_change_at' => null];
        }
        $summary = [
            'balance' => round((float)($user['credit_balance'] ?? 0), 2),
            'income_total' => 0.0, 'expense_total' => 0.0,
            'transaction_count' => 0, 'last_change_at' => null,
        ];
        if (commerceTableExists($this->pdo, 'credit_transactions')) {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) AS cnt,
                    COALESCE(SUM(CASE WHEN amount > 0 THEN amount ELSE 0 END), 0) AS income_total,
                    COALESCE(SUM(CASE WHEN amount < 0 THEN ABS(amount) ELSE 0 END), 0) AS expense_total,
                    MAX(created_at) AS last_change_at
                 FROM credit_transactions WHERE user_id = ?'
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $summary['transaction_count'] = (int)($row['cnt'] ?? 0);
            $summary['income_total'] = round((float)($row['income_total'] ?? 0), 2);
            $summary['expense_total'] = round((float)($row['expense_total'] ?? 0), 2);
            $summary['last_change_at'] = $row['last_change_at'] ?? null;
        }
        return $summary;
    }
}
