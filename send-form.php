<?php
/**
 * Request form endpoint for the Botanical Extracts Pro website.
 * Receives a request from the page and forwards it to Telegram.
 *
 * The bot token lives in config.php: it is never committed and never sent to
 * the browser. The page talks only to this script.
 *
 *   browser  ->  send-form.php  ->  api.telegram.org
 *                     ^
 *                config.php (token, server only)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// A fatal error (missing extension, broken config) would otherwise return an
// empty 500. Turn it into JSON plus a line in the error log.
register_shutdown_function(static function (): void {
    $e = error_get_last();
    if (!$e || !in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    error_log('send-form fatal: ' . $e['message'] . ' in ' . $e['file'] . ':' . $e['line']);
    echo json_encode(['ok' => false, 'error' => 'server_error']);
});

/** Send JSON and stop. */
function respond(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---------- self-test ---------- */

// GET /send-form.php?selftest=1 shows whether the server can handle the form.
// The token is never printed, only whether it exists and how long it is.
if (isset($_GET['selftest'])) {
    $cfgPath = __DIR__ . '/config.php';
    $cfg     = is_file($cfgPath) ? @require $cfgPath : null;

    respond(200, [
        'ok'              => true,
        'php'             => PHP_VERSION,
        'php_ok'          => version_compare(PHP_VERSION, '7.4', '>='),
        'config_present'  => is_file($cfgPath),
        'config_is_array' => is_array($cfg),
        'token_present'   => is_array($cfg) && !empty($cfg['bot_token']) && strpos((string)$cfg['bot_token'], 'PASTE') !== 0,
        'token_length'    => is_array($cfg) ? strlen((string)($cfg['bot_token'] ?? '')) : 0,
        'chat_id'         => is_array($cfg) ? (string)($cfg['chat_id'] ?? '') : '',
        'ext_curl'        => function_exists('curl_init'),
        'allow_url_fopen' => (bool)ini_get('allow_url_fopen'),
        'can_send'        => function_exists('curl_init') || (bool)ini_get('allow_url_fopen'),
        'ext_mbstring'    => function_exists('mb_substr'),
        'ext_json'        => function_exists('json_encode'),
        'can_write_tmp'   => is_writable(sys_get_temp_dir()),
    ]);
}

/* ---------- method ---------- */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

/* ---------- config ---------- */

$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    error_log('send-form: config.php is missing');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

$config = require $configPath;
if (!is_array($config)) {
    error_log('send-form: config.php did not return an array');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

$token  = trim((string)($config['bot_token'] ?? ''));
$chatId = trim((string)($config['chat_id'] ?? ''));

if ($token === '' || $chatId === '' || strpos($token, 'PASTE') === 0 || strpos($chatId, 'PASTE') === 0) {
    error_log('send-form: bot_token or chat_id not filled in');
    respond(500, ['ok' => false, 'error' => 'server_not_configured']);
}

/* ---------- input ---------- */

$raw   = file_get_contents('php://input') ?: '';
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

/** Trim, collapse repeated spaces, limit length. */
function field(array $src, string $key, int $max): string
{
    $value = $src[$key] ?? '';
    $value = is_scalar($value) ? (string)$value : '';
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/[ \t]+/u', ' ', $value) ?? '';
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
}

$name     = field($input, 'name', 120);
$company  = field($input, 'company', 160);
$email    = field($input, 'email', 160);
$phone    = field($input, 'phone', 80);
$interest = field($input, 'interest', 120);
$page     = field($input, 'page', 200);
$trap     = field($input, 'website', 200); // honeypot: people never see this field
$consent  = !empty($input['consent']);

/* ---------- honeypot ---------- */

// A bot that fills in every field gets "success" but nothing is sent, so it has
// no signal to keep retrying.
if ($trap !== '') {
    respond(200, ['ok' => true]);
}

/* ---------- validation ---------- */

$errors = [];
$len = static fn(string $v): int => function_exists('mb_strlen') ? mb_strlen($v) : strlen($v);

if ($len($name) < 2) {
    $errors['name'] = 'required';
}
if ($len($company) < 2) {
    $errors['company'] = 'required';
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'invalid';
}

// Phone / WhatsApp is optional. If given, it must look like a phone number.
if ($phone !== '') {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if (strlen($digits) < 7 || !preg_match('/^[0-9+()\-.\s]+$/', $phone)) {
        $errors['phone'] = 'invalid';
    }
}

// Header-injection guard: these values also go into the optional email copy.
if (preg_match('/[\r\n]/', $name . $company . $email . $phone . $interest)) {
    $errors['name'] = 'invalid';
}

// The form requires consent in the browser too; check again because a request
// can arrive without going through the page.
if (!$consent) {
    $errors['consent'] = 'required';
}

if ($errors) {
    respond(422, ['ok' => false, 'error' => 'validation_failed', 'fields' => $errors]);
}

if ($interest === '') {
    $interest = 'General enquiry';
}

/* ---------- rate limit ---------- */

$limit = (int)($config['rate_limit_per_hour'] ?? 5);
if ($limit > 0) {
    $ip     = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $bucket = sys_get_temp_dir() . '/botanical-form-' . hash('sha256', $ip) . '.txt';
    $now    = time();
    $hits   = [];

    if (is_file($bucket)) {
        $stored = json_decode((string)file_get_contents($bucket), true);
        if (is_array($stored)) {
            // keep only the hits from the last hour
            $hits = array_values(array_filter(
                $stored,
                static fn($t) => is_int($t) && $t > $now - 3600
            ));
        }
    }

    if (count($hits) >= $limit) {
        respond(429, ['ok' => false, 'error' => 'too_many_requests']);
    }

    $hits[] = $now;
    @file_put_contents($bucket, json_encode($hits), LOCK_EX);
}

/* ---------- message ---------- */

/** Escape for Telegram parse_mode=HTML. */
function tg(string $text): string
{
    return htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$lines   = [];
$lines[] = '<b>🌿 New request · Botanical Extracts Pro</b>';
$lines[] = '';
$lines[] = '<b>Name:</b> ' . tg($name);
$lines[] = '<b>Company:</b> ' . tg($company);
// <code> is copied with one tap in Telegram: handy for an email or a number
$lines[] = '<b>Email:</b> <code>' . tg($email) . '</code>';
$lines[] = '<b>Phone / WhatsApp:</b> ' . ($phone !== '' ? '<code>' . tg($phone) . '</code>' : '—');
$lines[] = '';
$lines[] = '<b>Interested in:</b> ' . tg($interest);
$lines[] = '';
if ($page !== '') {
    $lines[] = '<i>Page: ' . tg($page) . '</i>';
}
$lines[] = '<i>' . tg(gmdate('d.m.Y H:i')) . ' UTC</i>';

$text = implode("\n", $lines);

/* ---------- send ---------- */

$payload = [
    'chat_id'                  => $chatId,
    'text'                     => $text,
    'parse_mode'               => 'HTML',
    'disable_web_page_preview' => true,
];

/**
 * POST form-encoded. Uses cURL, or a stream if the extension is missing
 * (some hosting plans ship without ext-curl).
 *
 * @return array{body: ?string, status: int, error: string}
 */
function httpPost(string $url, array $fields): array
{
    $body = http_build_query($fields);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        $response = curl_exec($ch);
        $status   = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error    = curl_error($ch);
        curl_close($ch);

        return [
            'body'   => $response === false ? null : (string)$response,
            'status' => $status,
            'error'  => $error,
        ];
    }

    if (!ini_get('allow_url_fopen')) {
        return ['body' => null, 'status' => 0, 'error' => 'no curl and allow_url_fopen is off'];
    }

    $context = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/x-www-form-urlencoded\r\n"
                             . 'Content-Length: ' . strlen($body) . "\r\n",
            'content'       => $body,
            'timeout'       => 15,
            // read the body even on 4xx so the reason can be logged
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    $status   = 0;

    // $http_response_header is created by the stream wrapper itself
    if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }

    return [
        'body'   => $response === false ? null : (string)$response,
        'status' => $status,
        'error'  => $response === false ? 'stream request failed' : '',
    ];
}

$apiBase = rtrim((string)($config['api_base'] ?? 'https://api.telegram.org'), '/');
$sent    = httpPost($apiBase . '/bot' . $token . '/sendMessage', $payload);

if ($sent['body'] === null || $sent['status'] !== 200) {
    // The reason goes to the log but not to the browser: an API error text can
    // quote the request, and the token must never reach the response.
    error_log('send-form: telegram failed, http ' . $sent['status'] . ' ' . $sent['error']
        . ' ' . substr((string)$sent['body'], 0, 300));
    respond(502, ['ok' => false, 'error' => 'delivery_failed']);
}

$result = json_decode((string)$sent['body'], true);
if (!is_array($result) || empty($result['ok'])) {
    error_log('send-form: telegram rejected the message: ' . substr((string)$sent['body'], 0, 300));
    respond(502, ['ok' => false, 'error' => 'delivery_failed']);
}

/* ---------- optional email copy ---------- */

$notify = trim((string)($config['notify_email'] ?? ''));
if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL)) {
    $body = "Name: $name\nCompany: $company\nEmail: $email\n"
          . 'Phone / WhatsApp: ' . ($phone !== '' ? $phone : '-') . "\n"
          . "Interested in: $interest\nPage: $page\n";
    @mail(
        $notify,
        'New request - Botanical Extracts Pro',
        $body,
        "Content-Type: text/plain; charset=UTF-8\r\nFrom: web@" . preg_replace('/[^A-Za-z0-9.\-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'))
            . "\r\nReply-To: $email"
    );
}

respond(200, ['ok' => true]);
