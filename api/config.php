<?php
// 加载私有配置覆盖（config.local.php）
// 所有在 config.local.php 中定义的常量会覆盖上方默认值
// 示例：在 config.local.php 中 define('LINUXDO_CLIENT_SECRET', 'xxxx');
$localConfigFile = __DIR__ . '/config.local.php';
if (file_exists($localConfigFile)) {
    $localCfg = (array)(require $localConfigFile);
    foreach ($localCfg as $k => $v) {
        if (!defined($k)) define($k, $v);
    }
}

// 数据库配置 - 请修改为实际配置
//
// 优先级：config.local.php > DATABASE_URL (Zeabur) > DB_HOST/DB_PORT/DB_USER/DB_PASS/DB_NAME 环境变量 > 默认值
// Zeabur 会自动注入 DATABASE_URL=mysql://user:pass@host:port/database，无需手动配置

// 先尝试 DATABASE_URL 环境变量（Zeabur 自动注入）
$dbUrl = getenv('DATABASE_URL');
if ($dbUrl && !defined('DB_HOST')) {
    $parsed = parse_url($dbUrl);
    if ($parsed && isset($parsed['scheme']) && $parsed['scheme'] === 'mysql') {
        define('DB_HOST', $parsed['host'] ?? 'localhost');
        define('DB_PORT', isset($parsed['port']) ? (int)$parsed['port'] : 3306);
        define('DB_USER', $parsed['user'] ?? 'root');
        define('DB_PASS', $parsed['pass'] ?? '');
        define('DB_NAME', isset($parsed['path']) ? trim($parsed['path'], '/') : 'vps_shop');
    }
}

// 兜底：逐个读取独立环境变量（仅当未通过上方 DATABASE_URL 定义时）
if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ? (int)getenv('DB_PORT') : 3306);
if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') ?: '');
if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'vps_shop');

// 站点配置
define('SITE_NAME', 'VPS积分商城');

// 加密密钥（用于敏感字段加密，请替换为32位以上随机字符串）
// 示例: openssl rand -base64 32
define('DATA_ENCRYPTION_KEY', getenv('DATA_ENCRYPTION_KEY') ?: '');

// 管理员恢复模式（仅用于忘记管理员账号时的紧急恢复）
// 操作位置：项目根目录 /api/config.php
// 使用方法：临时将 ADMIN_RECOVERY_ENABLED 改为 true，并设置 ADMIN_RECOVERY_KEY；恢复完成后请立即改回 false
// 示例：define('ADMIN_RECOVERY_ENABLED', true);
define('ADMIN_RECOVERY_ENABLED', filter_var(getenv('ADMIN_RECOVERY_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('ADMIN_RECOVERY_KEY', getenv('ADMIN_RECOVERY_KEY') ?: '');

// Linux DO Connect OAuth2 配置
// 请在 https://connect.linux.do 申请接入后填写以下信息
define('LINUXDO_CLIENT_ID', '');
define('LINUXDO_CLIENT_SECRET', '');
define('LINUXDO_REDIRECT_URI', '');

// Linux DO OAuth2 端点
define('LINUXDO_AUTH_URL', 'https://connect.linux.do/oauth2/authorize');
define('LINUXDO_TOKEN_URL', 'https://connect.linux.do/oauth2/token');
define('LINUXDO_USER_URL', 'https://connect.linux.do/api/user');

