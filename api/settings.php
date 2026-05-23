<?php
require_once __DIR__ . '/../includes/security.php';
startSecureSession();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/commerce.php';

$action = requestValue('action', '');
$pdo = getDB();

$csrfActions = ['save', 'save_ldcpay', 'save_oauth', 'save_smtp', 'test_smtp', 'save_notification', 'save_ai'];
if (in_array($action, $csrfActions, true)) {
    requireCsrf();
}

function saveSetting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare('INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)');
    $stmt->execute([$key, $value]);
}

try {
    match ($action) {
        'get'              => handleSettingGet($pdo),
        'save'             => handleSettingSave($pdo),
        'get_ldcpay'       => handleSettingGetLdcpay($pdo),
        'save_ldcpay'      => handleSettingSaveLdcpay($pdo),
        'get_oauth'        => handleSettingGetOauth(),
        'save_oauth'       => handleSettingSaveOauth($pdo),
        'get_smtp'         => handleSettingGetSmtp($pdo),
        'save_smtp'        => handleSettingSaveSmtp($pdo),
        'get_notification' => handleSettingGetNotification($pdo),
        'save_notification' => handleSettingSaveNotification($pdo),
        'test_smtp'        => handleSettingTestSmtp($pdo),
        'get_ai'           => handleSettingGetAi($pdo),
        'save_ai'          => handleSettingSaveAi($pdo),
        default            => jsonResponse(0, '未知操作'),
    };
} catch (Throwable $e) {
    logError($pdo, 'api.settings', $e->getMessage());
    jsonResponse(0, '服务器错误');
}

function handleSettingGet(PDO $pdo): void {
    checkAdmin($pdo);
    $stmt = $pdo->query('SELECT key_name, key_value FROM settings');
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['key_name']] = $row['key_value'];
    }
    jsonResponse(1, '', $settings);
}
function handleSettingSave(PDO $pdo): void {
    checkAdmin($pdo);
    $keys = ['epay_pid', 'epay_key', 'notify_url', 'return_url'];
    foreach ($keys as $key) {
        saveSetting($pdo, $key, normalizeString(requestValue($key, ''), 500));
    }
    logAudit($pdo, 'settings.save', ['keys' => $keys]);
    jsonResponse(1, '保存成功');
}
function handleSettingGetLdcpay(PDO $pdo): void {
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
}
function handleSettingSaveLdcpay(PDO $pdo): void {
    checkAdmin($pdo);
    $keys = ['ldcpay_client_id', 'ldcpay_client_secret', 'ldcpay_private_key', 'ldcpay_public_key', 'ldcpay_notify_url', 'ldcpay_return_url'];
    foreach ($keys as $key) {
        saveSetting($pdo, $key, normalizeString(requestValue($key, ''), 5000));
    }
    logAudit($pdo, 'settings.save_ldcpay', ['keys' => $keys]);
    jsonResponse(1, 'LDC Pay 配置保存成功');
}
function handleSettingGetOauth(): void {
    $pdo = getDB();
    checkAdmin($pdo);
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
}
function handleSettingSaveOauth(PDO $pdo): void {
    checkAdmin($pdo);
    $clientId = normalizeString(requestValue('client_id', ''), 200);
    $clientSecret = normalizeString(requestValue('client_secret', ''), 200);
    $redirectUri = normalizeString(requestValue('redirect_uri', ''), 500);
    saveSetting($pdo, 'oauth_client_id', $clientId);
    saveSetting($pdo, 'oauth_client_secret', $clientSecret);
    saveSetting($pdo, 'oauth_redirect_uri', $redirectUri);
    logAudit($pdo, 'settings.save_oauth', ['client_id_set' => $clientId !== '', 'redirect_uri_set' => $redirectUri !== '']);
    jsonResponse(1, 'OAuth 配置保存成功');
}
function handleSettingGetSmtp(PDO $pdo): void {
    checkAdmin($pdo);
    $stmt = $pdo->query("SELECT key_name, key_value FROM settings WHERE key_name LIKE 'smtp_%'");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $smtp = [];
    foreach ($rows as $row) {
        $smtp[$row['key_name']] = $row['key_value'];
    }
    jsonResponse(1, '', $smtp);
}
function handleSettingSaveSmtp(PDO $pdo): void {
    checkAdmin($pdo);
    $keys = ['smtp_host', 'smtp_port', 'smtp_user', 'smtp_pass', 'smtp_from', 'smtp_name', 'smtp_secure'];
    foreach ($keys as $key) {
        saveSetting($pdo, $key, normalizeString(requestValue($key, ''), 500));
    }
    logAudit($pdo, 'settings.save_smtp', ['host' => requestValue('smtp_host', '')]);
    jsonResponse(1, 'SMTP配置保存成功');
}
function handleSettingGetNotification(PDO $pdo): void {
    checkAdmin($pdo);
    jsonResponse(1, '', [
        'notification_email_enabled' => commerceGetSetting($pdo, 'notification_email_enabled', '0'),
        'notification_webhook_enabled' => commerceGetSetting($pdo, 'notification_webhook_enabled', '0'),
        'notification_webhook_url' => commerceGetSetting($pdo, 'notification_webhook_url', ''),
        'linuxdo_silenced_order_mode' => commerceGetSetting($pdo, 'linuxdo_silenced_order_mode', 'review'),
    ]);
}
function handleSettingSaveNotification(PDO $pdo): void {
    checkAdmin($pdo);
    $items = [
        'notification_email_enabled' => (string)(validateInt(requestValue('notification_email_enabled', 0), 0, 1) ?? 0),
        'notification_webhook_enabled' => (string)(validateInt(requestValue('notification_webhook_enabled', 0), 0, 1) ?? 0),
        'notification_webhook_url' => normalizeString(requestValue('notification_webhook_url', ''), 500),
        'linuxdo_silenced_order_mode' => in_array(requestValue('linuxdo_silenced_order_mode', 'review'), ['review', 'block'], true) ? requestValue('linuxdo_silenced_order_mode', 'review') : 'review',
    ];
    foreach ($items as $key => $value) {
        saveSetting($pdo, $key, $value);
    }
    logAudit($pdo, 'settings.save_notification', $items);
    jsonResponse(1, '通知配置保存成功');
}
function handleSettingTestSmtp(PDO $pdo): void {
    checkAdmin($pdo);
    $email = normalizeString(requestValue('email', ''), 100);
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(0, '请输入有效的邮箱地址');
    }
    $subject = (defined('SITE_NAME') ? SITE_NAME : 'VPS商城') . ' - SMTP测试';
    $body = '<div style="font-family:Arial,sans-serif;padding:20px"><h2>SMTP配置测试成功</h2><p>如果您收到这封邮件，说明SMTP配置正确。</p><p style="color:#666">发送时间：' . date('Y-m-d H:i:s') . '</p></div>';
    if (sendSmtpEmail($pdo, $email, $subject, $body)) {
        jsonResponse(1, '测试邮件已发送到' . $email);
    }
    jsonResponse(0, '发送失败，请检查SMTP配置');
}
function handleSettingGetAi(PDO $pdo): void {
    checkAdmin($pdo);
    jsonResponse(1, '', [
        'ai_api_endpoint' => commerceGetSetting($pdo, 'ai_api_endpoint', ''),
        'ai_api_key' => commerceGetSetting($pdo, 'ai_api_key', ''),
        'ai_model' => commerceGetSetting($pdo, 'ai_model', ''),
    ]);
}
function handleSettingSaveAi(PDO $pdo): void {
    checkAdmin($pdo);
    $keys = ['ai_api_endpoint', 'ai_api_key', 'ai_model'];
    foreach ($keys as $key) {
        saveSetting($pdo, $key, normalizeString(requestValue($key, ''), 1000));
    }
    logAudit($pdo, 'settings.save_ai', ['keys' => $keys]);
    jsonResponse(1, 'AI 配置保存成功');
}