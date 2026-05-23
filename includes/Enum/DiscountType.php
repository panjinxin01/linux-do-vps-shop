<?php
/**
 * 折扣类型枚举
 * 
 * 对应 coupons.type / trust_level_discounts.discount_type 字段
 */
enum DiscountType: string {
    case Percent = 'percent';
    case Fixed   = 'fixed';

    public function label(): string {
        return match($this) {
            self::Percent => '百分比折扣',
            self::Fixed   => '固定金额折扣',
        };
    }
}
