<?php
declare(strict_types=1);

const BN_MAX_LIBRARY = 25;   // badges max en bibliothèque (identique au plugin)
const BN_MAX_ENABLED = 10;   // badges max affichés (identique au plugin)
const BN_MAX_UPLOADS_PER_USER = 100;
const BN_MAX_DIMENSION = 2048;

/* ---------- Config ---------- */

function cfg(string $key, ?string $default = null): ?string
{
    static $file = null;
    if ($file === null) {
        $path = __DIR__ . '/../config.php';
        $file = is_file($path) ? (array) require $path : [];
    }
    if (array_key_exists($key, $file) && $file[$key] !== '') {
        return (string) $file[$key];
    }
    $env = getenv($key);
    return ($env !== false && $env !== '') ? $env : $default;
}

function base_url(): string
{
    $u = rtrim((string) cfg('BASE_URL', ''), '/');
    if ($u === '') fail(500, 'BASE_URL non configuré');
    return $u;
}

function jwt_secret(): string
{
    $s = (string) cfg('JWT_SECRET', '');
    if (strlen($s) < 32) fail(500, 'JWT_SECRET manquant ou trop court (32 caractères minimum)');
    return $s;
}

function max_upload_bytes(): int
{
    return (int) cfg('MAX_UPLOAD_BYTES', (string) (10 * 1024 * 1024));
}

function uploads_dir(): string
{
    return __DIR__ . '/../public/uploads';
}

/* ---------- Réponses ---------- */

function send_common_headers(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    // Le plugin tourne dans Discord (origine discord.com) et utilise Authorization (pas de cookies) => * est sûr.
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    header('Access-Control-Max-Age: 86400');
}

function json_out(int $status, array $data, array $headers = []): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    if (!isset($headers['Cache-Control'])) header('Cache-Control: no-store');
    foreach ($headers as $k => $v) header("$k: $v");
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(int $status, string $message): never
{
    json_out($status, ['error' => $message]);
}

/* ---------- Base de données (SQLite) ---------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $dir = __DIR__ . '/../data';
    if (!is_dir($dir)) mkdir($dir, 0750, true);

    $pdo = new PDO('sqlite:' . $dir . '/badges.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS users (
            id TEXT PRIMARY KEY,
            username TEXT NOT NULL,
            created_at INTEGER NOT NULL,
            badges_updated_at INTEGER
        );
        CREATE TABLE IF NOT EXISTS badges (
            user_id TEXT NOT NULL,
            id TEXT NOT NULL,
            name TEXT NOT NULL,
            icon_file TEXT NOT NULL,
            enabled INTEGER NOT NULL DEFAULT 0,
            position INTEGER NOT NULL,
            PRIMARY KEY (user_id, id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
        CREATE TABLE IF NOT EXISTS uploads (
            file TEXT PRIMARY KEY,
            user_id TEXT NOT NULL,
            created_at INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS rate (
            k TEXT NOT NULL,
            w INTEGER NOT NULL,
            n INTEGER NOT NULL,
            exp INTEGER NOT NULL,
            PRIMARY KEY (k, w)
        );
    SQL);
    return $pdo;
}

/* ---------- JWT HS256 ---------- */

function b64url_encode(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64url_decode(string $s): string|false
{
    return base64_decode(strtr($s, '-_', '+/'), true);
}

function jwt_sign(array $claims): string
{
    $h = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = b64url_encode(json_encode($claims));
    $sig = b64url_encode(hash_hmac('sha256', "$h.$p", jwt_secret(), true));
    return "$h.$p.$sig";
}

function jwt_verify(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;

    $header = json_decode((string) b64url_decode($h), true);
    if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') return null;

    $expected = b64url_encode(hash_hmac('sha256', "$h.$p", jwt_secret(), true));
    if (!hash_equals($expected, $s)) return null;

    $claims = json_decode((string) b64url_decode($p), true);
    if (!is_array($claims) || !isset($claims['sub'], $claims['exp'])) return null;
    if ((int) $claims['exp'] < time()) return null;
    return $claims;
}

function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if ($h === null && function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) { $h = $v; break; }
        }
    }
    if ($h && preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) return $m[1];
    return null;
}

/** @return array{id:string,username:string} */
function require_auth(): array
{
    $token = bearer_token();
    $claims = $token ? jwt_verify($token) : null;
    if (!$claims) fail(401, 'Token invalide ou expiré');

    $st = db()->prepare('SELECT id, username FROM users WHERE id = ?');
    $st->execute([(string) $claims['sub']]);
    $user = $st->fetch();
    if (!$user) fail(401, 'Utilisateur inconnu');
    return $user;
}

/* ---------- Rate limit (IP hachée, jamais stockée en clair) ---------- */

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    if (cfg('TRUST_PROXY') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $list = array_map('trim', explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']));
        $last = end($list);
        if (filter_var($last, FILTER_VALIDATE_IP)) $ip = $last;
    }
    return $ip;
}

function rate_limit(string $bucket, int $max, int $windowSec): void
{
    $pdo = db();
    $now = time();
    $w = intdiv($now, $windowSec);
    $k = $bucket . '|' . hash_hmac('sha256', client_ip(), jwt_secret());

    $pdo->prepare(
        'INSERT INTO rate (k, w, n, exp) VALUES (?, ?, 1, ?)
         ON CONFLICT (k, w) DO UPDATE SET n = n + 1'
    )->execute([$k, $w, ($w + 1) * $windowSec + 60]);

    $st = $pdo->prepare('SELECT n FROM rate WHERE k = ? AND w = ?');
    $st->execute([$k, $w]);
    $n = (int) $st->fetchColumn();

    if (random_int(1, 200) === 1) {
        $pdo->prepare('DELETE FROM rate WHERE exp < ?')->execute([$now]);
    }
    if ($n > $max) {
        header('Retry-After: ' . ($windowSec - ($now % $windowSec)));
        fail(429, 'Trop de requêtes, réessaie dans un instant');
    }
}

/* ---------- Badges ---------- */

function icon_url(string $file): string
{
    return base_url() . '/uploads/' . $file;
}

function load_badges(string $userId, bool $onlyEnabled): array
{
    $sql = 'SELECT id, name, icon_file, enabled FROM badges WHERE user_id = ?'
         . ($onlyEnabled ? ' AND enabled = 1' : '') . ' ORDER BY position';
    $st = db()->prepare($sql);
    $st->execute([$userId]);

    return array_map(fn ($r) => [
        'id' => $r['id'],
        'name' => $r['name'],
        'iconUrl' => icon_url($r['icon_file']),
        'enabled' => (bool) $r['enabled'],
    ], $st->fetchAll());
}

function badges_response(string $userId, bool $onlyEnabled): array
{
    $st = db()->prepare('SELECT badges_updated_at FROM users WHERE id = ?');
    $st->execute([$userId]);
    $ts = $st->fetchColumn();

    return [
        'userId' => $userId,
        'updatedAt' => $ts ? gmdate(DATE_ATOM, (int) $ts) : null,
        'badges' => load_badges($userId, $onlyEnabled),
    ];
}
