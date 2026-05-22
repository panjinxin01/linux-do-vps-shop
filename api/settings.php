<?php
require_once __DIR__ . '/../includes/security.php';
startSecureSession();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/commerce.php';

$action = requestValue('action', '');
$pdo = getDB();

$csrfActions = ['save', 'save_ldcpay', 'save_oauth', 'save_smtp', 'test_smtp', 'save_notification'];
if (in_array($action, $csrfActions, true)) {
    requireCsrf();
}

function saveSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare('INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)');
    $stmt->execute([$key, $value]);
}

try {
    switch ($action) {
        case 'get':
            checkAdmin($pdo);
            $stmt = $pdo->query('SELECT key_name, key_value FROM settings');
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $settings = [];
            foreach ($rows as $row) {
                $settings[$row['key_name']] = $row['key_value'];
            }
            jsonResponse(1, '', $settings);
            break;

        case 'save':
            checkAdmin($pdo);
            $keys = ['epay_pid', 'epay_key', 'notify_url', 'return_url'];
            foreach ($keys as $key) {
                saveSetting($pdo, $key, normalizeString(requestValue($key, ''), 500));
            }
            logAudit($pdo, 'settings.save', ['keys' => $keys]);
            jsonResponse(1, '保存成功');
            break;

        case 'get_ldcpay':
            checkAdmin($pdo);
            jsonResponse(1, '', [
                'client_id' => commerceGetSetting($pdo, 'ldcpay_client_id'),
                'client_secret' => commerceGetSetting($pdo, 'ldcpay_client_secret'),
                'private_key' => commerceGetSetting($pdo, 'ldcpay_private_key'),
                'public_key' => commerceGetSetting($pdo, 'ldcpay_public_key'),
                'notify_url' => commerceGetSetting($pdo, 'ldcpay_notify_url'),
                'return_url' => commerceGetSetting($pdo, 'ldcpay_return_url'),
                'ed25519_available' => file_exists(__DIR__ . '/../includes/ldcpay.php') && function_exists('ldcpay_has_ed25519') ? ldcpay_has_ed25519() : false,
            ]);
            break;

        case 'save_ldcpay':
            checkAdmin($pdo);
            $keys = ['ldcpay_client_id', 'ldcpay_client_secret', 'ldcpay_private_key', 'ldcpay_public_key', 'ldcpay_notify_url', 'ldcpay_return_url'];
            foreach ($keys as $key) {
                saveSetting($pdo, $key, normalizeString(requestValue($key, ''), 5000));
            }
            logAudit($pdo, 'settings.save_ldcpay', ['keys' => $keys]);
            jsonResponse(1, 'LDC Pay 配置保存成功');
            break;

        case 'get_oauth':
            checkAdmin($pdo);
            // 优先从数据库读取，兜底用常量（兼容旧配置）
            $clientId = commerceGetSetting($pdo, 'oauth_client_id');
            $clientSecret = commerceGetSetting($pdo, 'oauth_client_secret');
            $redirectUri = commerceGetSetting($pdo, 'oauth_redirect_uri');
            if ($clientId === '' && defined('LINUXDO_CLIENT_ID')) $clientId = LINUXDO_CLIENT_ID;
            if ($clientSecret === '' && defined('LINUXDO_CLIENT_SECRET')) $clientSecret = LINUXDO_CLIENT_SECRET;
            if ($redirectUri === '' && defined('LINUXDO_REDIRECT_URI')) $redirectUri = LINUXDO_REDIRECT_URI;
            jsonResponse(1, '', [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
            ]);
            break;

        case 'save_oauth':
            checkAdmin($pdo);
            $clientId = normalizeString(requestValue('client_id', ''), 200);
            $clientSecret = normalizeString(requestValue('client_secret', ''), 200);
            $redirectUri = normalizeString(requestValue('redirect_uri', ''), 500);

            // 保存到数据库
            saveSetting($pdo, 'oauth_client_id', $clientId);
            saveSetting($pdo, 'oauth_client_secret', $clientSecret);
            saveSetting($pdo, 'oauth_redirect_uri', $redirectUri);

            logAudit($pdo, 'settings.save_oauth', ['client_id_set' => $clientId !== '', 'redirect_uri_set' => $redirectUri !== '']);
            jsonResponse(1, 'OAuth 配置保存成功');
            break;

        case 'get_smtp':
            checkAdmin($pdo);
            $stmt = $pdo->query("SELECT key_name, key_value FROM settings WHERE key_name LIKE 'smtp_%'");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $smtp = [];
            foreach ($rows as $row) {
                $smtp[$row['key_name']] = $row['key_value'];
            }
            jsonResponse(1, '', $smtp);
            break;

        case 'save_smtp':
            checkAdmin($pdo);
            $smtpKeys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_name', 'smtp_secure'];
            foreach ($smtpKeys as $key) {
                saveSetting($pdo, $key, normalizeString(requestValue($key, ''), 500));
            }
            logAudit($pdo, 'settings.save_smtp', ['host' => requestValue('smtp_host', '')]);
            jsonResponse(1, 'SMTP配置保存成功');
            break;
