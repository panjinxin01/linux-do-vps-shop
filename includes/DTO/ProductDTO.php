<?php
/**
 * 商品数据传输对象
 * 
 * 替代 process 式关联数组，提供类型安全与命名参数构造。
 * 构造后不可变，通过 withTrustDiscount() 返回带折扣的新实例。
 */
readonly class ProductDTO {
    public function __construct(
        public int     $id,
        public string  $name,
        public float   $price,
        public ?string $cpu,
        public ?string $memory,
        public ?string $disk,
        public ?string $bandwidth,
        public ?string $region,
        public ?string $lineType,
        public ?string $osType,
        public ?string $description,
        public string  $ipAddress,
        public int     $sshPort,
        public string  $sshUser,
        public ?string $sshPassword,
        public ?string $extraInfo,
        public int     $minTrustLevel,
        public bool    $riskReviewRequired,
        public bool    $allowWhitelistOnly,
        public bool    $status,
        public ?int    $templateId,
        public ?string $templateName,
        // 计算字段（不来自数据库）
        public float   $basePrice           = 0.0,
        public float   $trustDiscountAmount = 0.0,
        public string  $trustDiscountLabel  = '',
        public bool    $canBuy              = true,
        public string  $buyBlockReason      = '',
        public bool    $riskReview          = false,
    ) {}

    public static function fromRow(array $row): self {
        return new self(
            id:                  (int)($row['id'] ?? 0),
            name:                (string)($row['name'] ?? ''),
            price:               round((float)($row['price'] ?? 0), 2),
            cpu:                 self::nullableStr($row, 'cpu'),
            memory:              self::nullableStr($row, 'memory'),
            disk:                self::nullableStr($row, 'disk'),
            bandwidth:           self::nullableStr($row, 'bandwidth'),
            region:              self::nullableStr($row, 'region'),
            lineType:            self::nullableStr($row, 'line_type'),
            osType:              self::nullableStr($row, 'os_type'),
            description:         self::nullableStr($row, 'description'),
            ipAddress:           (string)($row['ip_address'] ?? ''),
            sshPort:             (int)($row['ssh_port'] ?? 22),
            sshUser:             (string)($row['ssh_user'] ?? 'root'),
            sshPassword:         self::nullableStr($row, 'ssh_password'),
            extraInfo:           self::nullableStr($row, 'extra_info'),
            minTrustLevel:       (int)($row['min_trust_level'] ?? 0),
            riskReviewRequired:  (bool)($row['risk_review_required'] ?? false),
            allowWhitelistOnly:  (bool)($row['allow_whitelist_only'] ?? false),
            status:              (bool)($row['status'] ?? true),
            templateId:          self::nullableInt($row, 'template_id'),
            templateName:        self::nullableStr($row, 'template_name'),
        );
    }

    private static function nullableStr(array $row, string $key): ?string {
        return isset($row[$key]) && $row[$key] !== '' ? (string)$row[$key] : null;
    }

    private static function nullableInt(array $row, string $key): ?int {
        return isset($row[$key]) ? (int)$row[$key] : null;
    }

    /** 应用信任等级折扣后返回新实例 */
    public function withTrustDiscount(float $amount, string $label): self {
        return new self(
            ...$this->toArray(),
            basePrice:           $this->price,
            price:               round(max(0, $this->price - $amount), 2),
            trustDiscountAmount: $amount,
            trustDiscountLabel:  $label,
        );
    }

    private function toArray(): array {
        return get_object_vars($this);
    }
}