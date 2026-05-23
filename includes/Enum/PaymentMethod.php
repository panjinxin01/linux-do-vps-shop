<?php
/**
 * 支付方式枚举
 * 
 * 对应 orders.payment_method 字段
 */
enum PaymentMethod: string {
    case Pending = 'pending';
    case Balance = 'balance';
    case Epay    = 'epay';

    public function label(): string {
        return match($this) {
            self::Pending => '未支付',
            self::Balance => '余额支付',
            self::Epay    => 'EasyPay',
        };
    }
}
