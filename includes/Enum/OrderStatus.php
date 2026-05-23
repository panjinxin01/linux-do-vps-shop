<?php
/**
 * 订单支付状态枚举
 * 
 * 对应 orders.status 字段：
 *   0 = 待支付
 *   1 = 已支付
 *   2 = 已退款
 *   3 = 已取消（系统会自动取消超过 15 分钟未支付的订单）
 */
enum OrderStatus: int {
    case Pending   = 0;
    case Paid      = 1;
    case Refunded  = 2;
    case Cancelled = 3;

    public function label(): string {
        return match($this) {
            self::Pending   => '待支付',
            self::Paid      => '已支付',
            self::Refunded  => '已退款',
            self::Cancelled => '已取消',
        };
    }
}
