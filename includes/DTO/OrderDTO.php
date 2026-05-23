<?php
require_once __DIR__ . '/../Enum/OrderStatus.php';
require_once __DIR__ . '/../Enum/PaymentMethod.php';
require_once __DIR__ . '/../Enum/DeliveryStatus.php';

/**
 * 订单数据传输对象
 * 
 * 替代 process 式关联数组，使用类型安全的 Enum 替代魔法字符串。
 * 提供 shouldShowCredentials() 方法判断是否需要隐藏凭据。
 */
readonly class OrderDTO {
    public function __construct(
        public int            $id,
        public string         $orderNo,
        public int            $userId,
        public int            $productId,
        public float          $price,
        public float          $originalPrice,
        public float          $trustDiscountAmount,
        public float          $couponDiscount,
        public ?string        $couponCode,
        public OrderStatus    $status,
        public PaymentMethod  $paymentMethod,
        public DeliveryStatus $deliveryStatus,
        public ?string        $productName,
        public ?string        $cpu,
        public ?string        $memory,
        public ?string        $disk,
        public ?string        $bandwidth,
        public ?string        $region,
        public ?string        $lineType,
        public ?string        $osType,
        public ?string        $description,
        public ?string        $ipAddress,
        public ?int           $sshPort,
        public ?string        $sshUser,
        public ?string        $sshPassword,
        public ?string        $extraInfo,
        public ?string        $tradeNo,
        public ?string        $createdAt,
        public ?string        $paidAt,
        public ?string        $deliveredAt,
        public ?string        $deliveryInfo,
        public ?string        $adminNote,
        public ?string        $buyerName,
        public ?string        $buyerEmail,
        // 退款策略（计算字段）
        public float          $refundableAmount   = 0.0,
        public int            $remainingSeconds   = 0,
        public float          $refundRatio        = 0.0,
    ) {}

    public static function fromRow(array $row): self {
        return new self(
            id:                  (int)($row['id'] ?? 0),
            orderNo:             (string)($row['order_no'] ?? ''),
            userId:              (int)($row['user_id'] ?? 0),
            productId:           (int)($row['product_id'] ?? 0),
            price:               round((float)($row['price'] ?? 0), 2),
            originalPrice:       round((float)($row['original_price'] ?? $row['price'] ?? 0), 2),
            trustDiscountAmount: round((float)($row['trust_discount_amount'] ?? 0), 2),
            couponDiscount:      round((float)($row['coupon_discount'] ?? 0), 2),
            couponCode:          self::nullableStr($row, 'coupon_code'),
            status:              OrderStatus::from((int)($row['status'] ?? 0)),
            paymentMethod:       PaymentMethod::from((string)($row['payment_method'] ?? 'pending')),
            deliveryStatus:      DeliveryStatus::from((string)($row['delivery_status'] ?? 'pending')),
            productName:         self::nullableStr($row, 'product_name'),
            cpu:                 self::nullableStr($row, 'cpu'),
            memory:              self::nullableStr($row, 'memory'),
            disk:                self::nullableStr($row, 'disk'),
            bandwidth:           self::nullableStr($row, 'bandwidth'),
            region:              self::nullableStr($row, 'region'),
            lineType:            self::nullableStr($row, 'line_type'),
            osType:              self::nullableStr($row, 'os_type'),
            description:         self::nullableStr($row, 'description'),
            ipAddress:           self::nullableStr($row, 'ip_address'),
            sshPort:             self::nullableInt($row, 'ssh_port'),
            sshUser:             self::nullableStr($row, 'ssh_user'),
            sshPassword:         self::nullableStr($row, 'ssh_password'),
            extraInfo:           self::nullableStr($row, 'extra_info'),
            tradeNo:             self::nullableStr($row, 'trade_no'),
            createdAt:           self::nullableStr($row, 'created_at'),
            paidAt:              self::nullableStr($row, 'paid_at'),
            deliveredAt:         self::nullableStr($row, 'delivered_at'),
            deliveryInfo:        self::nullableStr($row, 'delivery_info'),
            adminNote:           self::nullableStr($row, 'admin_note'),
            buyerName:           self::nullableStr($row, 'buyer_name'),
            buyerEmail:          self::nullableStr($row, 'buyer_email'),
        );
    }

    /** 判断是否需要隐藏凭据（已支付且交付状态正常） */
    public function shouldShowCredentials(): bool {
        return $this->status === OrderStatus::Paid
            && !in_array($this->deliveryStatus, [
                DeliveryStatus::Exception,
                DeliveryStatus::Cancelled,
                DeliveryStatus::Refunded,
            ], true);
    }

    private static function nullableStr(array $row, string $key): ?string {
        return isset($row[$key]) && $row[$key] !== '' ? (string)$row[$key] : null;
    }

    private static function nullableInt(array $row, string $key): ?int {
        return isset($row[$key]) ? (int)$row[$key] : null;
    }
}