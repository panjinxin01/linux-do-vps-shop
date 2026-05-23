<?php
/**
 * 订单交付状态枚举
 * 
 * 对应 orders.delivery_status 字段：
 *   pending      → 待处理
 *   paid_waiting → 已支付待开通
 *   provisioning → 开通中
 *   delivered    → 已交付
 *   exception    → 异常
 *   cancelled    → 已取消
 *   refunded     → 已退款
 */
enum DeliveryStatus: string {
    case Pending      = 'pending';
    case PaidWaiting  = 'paid_waiting';
    case Provisioning = 'provisioning';
    case Delivered    = 'delivered';
    case Exception    = 'exception';
    case Cancelled    = 'cancelled';
    case Refunded     = 'refunded';

    public function label(): string {
        return match($this) {
            self::Pending      => '待支付',
            self::PaidWaiting  => '待开通',
            self::Provisioning => '处理中',
            self::Delivered    => '已交付',
            self::Exception    => '异常',
            self::Cancelled    => '已取消',
            self::Refunded     => '已退款',
        };
    }
}
