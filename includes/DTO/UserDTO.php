<?php
/**
 * 用户数据传输对象
 * 
 * 替代 process 式关联数组传递，提供类型安全与 IDE 自动补全。
 * 构造后不可变（readonly class），推荐在 Service 层使用。
 */
readonly class UserDTO {
    public function __construct(
        public int     $id,
        public string  $username,
        public ?string $email,
        public ?int    $linuxdoId,
        public ?string $linuxdoUsername,
        public int     $trustLevel,
        public bool    $linuxdoActive,
        public bool    $linuxdoSilenced,
        public float   $creditBalance,
        public ?string $createdAt,
    ) {}

    public static function fromRow(array $row): self {
        return new self(
            id:              (int)($row['id'] ?? 0),
            username:        (string)($row['username'] ?? ''),
            email:           isset($row['email']) ? (string)$row['email'] : null,
            linuxdoId:       isset($row['linuxdo_id']) ? (int)$row['linuxdo_id'] : null,
            linuxdoUsername: isset($row['linuxdo_username']) ? (string)$row['linuxdo_username'] : null,
            trustLevel:      (int)($row['linuxdo_trust_level'] ?? 0),
            linuxdoActive:   (bool)($row['linuxdo_active'] ?? true),
            linuxdoSilenced: (bool)($row['linuxdo_silenced'] ?? false),
            creditBalance:   round((float)($row['credit_balance'] ?? 0), 2),
            createdAt:       isset($row['created_at']) ? (string)$row['created_at'] : null,
        );
    }
}