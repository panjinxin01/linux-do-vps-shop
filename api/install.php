<?php
// 安装向导 API - 纯 JSON 接口
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/schema.php';

function jsonOut(int $code, string $msg = '', $data = null): void {
    echo json_encode(['code' => $code, 'msg' => $msg, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 解析 MySQL DSN 连接字符串
 * 支持格式: mysql://user:pass@host:port/dbname?params
 */
function parseDsn(string $dsn): array {
    $result = [
        'db_host' => 'localhost',
        'db_port' => 3306,
        'db_user' => 'root',
        'db_pass' => '',
        'db_name' => 'vps_shop',
    ];
    $dsn = trim($dsn);
    if ($dsn === '') {
        return $result;
    }
    $parsed = parse_url($dsn);
    if (!$parsed || !isset($parsed['scheme']) || $parsed['scheme'] !== 'mysql') {
        return $result;
    }
    $result['db_host'] = $parsed['host'] ?? 'localhost';
    $result['db_port'] = isset($parsed['port']) ? (int)$parsed['port'] : 3306;
    $result['db_user'] = isset($parsed['user']) ? urldecode($parsed['user']) : 'root';
    $result['db_pass'] = isset($parsed['pass']) ? urldecode($parsed['pass']) : '';
    $result['db_name'] = isset($parsed['path']) ? trim($parsed['path'], '/') : 'vps_shop';
    return $result;
}

function getConfigPath(): string {
    return __DIR__ . '/config.php';
}

function getLocalConfigPath(): string {
    return __DIR__ . '/config.local.php';
}

function getCurrentConfig(): array {
    $defaults = [
        'DB_HOST' => 'localhost',
        'DB_PORT' => 3306,
        'DB_USER' => 'root',
        'DB_PASS' => '',
        'DB_NAME' => 'vps_shop',
        'DATA_ENCRYPTION_KEY' => '',
        'ADMIN_RECOVERY_ENABLED' => false,
        'ADMIN_RECOVERY_KEY' => '',
    ];
    $path = getConfigPath();
    if (file_exists($path)) {
        @include $path;
        foreach (array_keys($defaults) as $key) {
            if (defined($key)) {
                $defaults[$key] = constant($key);
            }
        }
    }
    return $defaults;
}

/**
 * 将配置写入 config.local.php（而非覆盖框架文件 config.php）
 *
 * 读写均使用 return-array 格式，AppConfig::fromEnv() 会自动载入。
 * 已存在的键（如 OAuth 配置）会被保留合并，不会丢失。
 */
function writeConfigFile(array $cfg): bool {
    $path = getLocalConfigPath();

    // 读取现有 config.local.php，保留已有配置（如 OAuth）
    $existing = [];
    if (file_exists($path)) {
        $existing = (array)(require $path);
    }

    // 安装向导可写入的键
    $installKeys = ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASS', 'DB_NAME',
                    'DATA_ENCRYPTION_KEY', 'ADMIN_RECOVERY_ENABLED', 'ADMIN_RECOVERY_KEY'];
    foreach ($installKeys as $key) {
        if (array_key_exists($key, $cfg)) {
            $existing[$key] = $cfg[$key];
        }
    }

    // 确保 SITE_NAME 存在
    if (!isset($existing['SITE_NAME'])) {
        $existing['SITE_NAME'] = 'VPS积分商城';
    }

    // 写入 return-array 格式
    $c = "<?php\n";
    $c .= "// 部署私有配置（由安装向导自动生成，config.php 引导加载器会自动载入本文件）\n";
    $c .= "return " . var_export($existing, true) . ";\n";

    $written = file_put_contents($path, $c) !== false;
    clearstatcache(true, $path);
    if ($written && function_exists('opcache_invalidate')) {
        @opcache_invalidate($path, true);
    }
    return $written;
}

function seedInstallSettings(PDO $pdo): void {
    $stmt = $pdo->prepare('INSERT INTO settings (key_name, key_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)');
    foreach (getProjectDefaultSettings() as $key => $value) {
        $stmt->execute([$key, $value]);
    }
}

// 安装锁定检查（安装完成后创建 .install_lock 文件禁止危险操作）
$installLockFile = __DIR__ . '/../.install_lock';
$isInstalled = file_exists($installLockFile);
$dangerousActions = ['run_install', 'save_config', 'test_db', 'generate_key'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

if ($isInstalled && in_array($action, $dangerousActions, true)) {
    jsonOut(0, '安装已完成，危险操作已被锁定。如需重新安装，请手动删除 .install_lock 文件（位于项目根目录）。');
}

switch ($action) {
    case 'get_config':
        $cfg = getCurrentConfig();
        $cfg['DB_PASS'] = $cfg['DB_PASS'] !== '' ? '********' : '';
        $cfg['has_encryption_key'] = $cfg['DATA_ENCRYPTION_KEY'] !== '';
        unset($cfg['DATA_ENCRYPTION_KEY']);
        jsonOut(1, '', $cfg);
        break;

    case 'parse_dsn':
        $dsn = trim($_POST['dsn'] ?? '');
        if ($dsn === '') {
            jsonOut(0, 'DSN 连接字符串不能为空');
        }
        $parsed = parseDsn($dsn);
        jsonOut(1, 'DSN 解析成功', $parsed);
        break;

    case 'test_db':
        $dsn = trim($_POST['db_dsn'] ?? '');
        if ($dsn !== '') {
            $parsed = parseDsn($dsn);
            $host = $parsed['db_host'];
            $port = $parsed['db_port'];
            $user = $parsed['db_user'];
            $pass = $parsed['db_pass'];
        } else {
            $host = trim($_POST['db_host'] ?? 'localhost');
            $port = (int)($_POST['db_port'] ?? 3306);
            $user = trim($_POST['db_user'] ?? '');
            $pass = $_POST['db_pass'] ?? '';
        }

        if ($host === '' || $user === '') {
            jsonOut(0, '地址和用户名不能为空');
        }
        try {
            new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
            jsonOut(1, '连接成功');
        } catch (PDOException $e) {
            jsonOut(0, '连接失败: ' . $e->getMessage());
        }
        break;

    case 'save_config':
        $cfg = getCurrentConfig();

        $dsn = trim($_POST['db_dsn'] ?? '');
        if ($dsn !== '') {
            $parsed = parseDsn($dsn);
            $cfg['DB_HOST'] = $parsed['db_host'];
            $cfg['DB_PORT'] = $parsed['db_port'];
            $cfg['DB_USER'] = $parsed['db_user'];
            $cfg['DB_PASS'] = $parsed['db_pass'];
            $cfg['DB_NAME'] = $parsed['db_name'];
        } else {
            $cfg['DB_HOST'] = trim($_POST['db_host'] ?? 'localhost');
            $cfg['DB_PORT'] = (int)($_POST['db_port'] ?? 3306);
            $cfg['DB_USER'] = trim($_POST['db_user'] ?? 'root');
            $pass = $_POST['db_pass'] ?? '';
            if ($pass !== '' && $pass !== '********') {
                $cfg['DB_PASS'] = $pass;
            }
            $cfg['DB_NAME'] = trim($_POST['db_name'] ?? 'vps_shop');
        }

        if ($cfg['DB_HOST'] === '' || $cfg['DB_USER'] === '' || $cfg['DB_NAME'] === '') {
            jsonOut(0, '地址、用户名、数据库名不能为空');
        }

        try {
            new PDO("mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};charset=utf8mb4", $cfg['DB_USER'], $cfg['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);
        } catch (PDOException $e) {
            jsonOut(0, '数据库连接失败: ' . $e->getMessage());
        }

        if (!writeConfigFile($cfg)) {
            jsonOut(0, '写入配置文件失败，请检查 api/config.local.php 权限');
        }
        jsonOut(1, '配置已保存');
        break;

    case 'generate_key':
        $key = bin2hex(random_bytes(32));
        $cfg = getCurrentConfig();
        $cfg['DATA_ENCRYPTION_KEY'] = $key;
        $written = writeConfigFile($cfg);
        jsonOut(1, $written ? '密钥已生成并写入配置' : '密钥已生成但写入失败，请手动配置', [
            'key' => $key,
            'written' => $written,
        ]);
        break;

    case 'run_install':
        $cfg = getCurrentConfig();
        try {
            $pdo = new PDO("mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};charset=utf8mb4", $cfg['DB_USER'], $cfg['DB_PASS'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', $cfg['DB_NAME']);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");
            foreach (getProjectTableDefinitions() as $sql) {
                $pdo->exec($sql);
            }
            seedInstallSettings($pdo);
            // 安装成功，写入锁文件
            @file_put_contents($installLockFile, date('Y-m-d H:i:s') . "\n");
            jsonOut(1, '数据库初始化成功');
        } catch (PDOException $e) {
            jsonOut(0, '安装失败: ' . $e->getMessage());
        }
        break;

    default:
        header('Location: ../admin/install.html');
        exit;
}
