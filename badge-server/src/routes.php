<?php
declare(strict_types=1);

function handle_request(): void
{
    send_common_headers();

    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $path = rtrim($path, '/') ?: '/';

    if ($method === 'GET' && $path === '/') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "BadgeNetwork API\n";
        exit;
    }

    if ($method === 'GET' && $path === '/auth/discord')          { rate_limit('auth', 30, 60);  route_auth_start(); }
    if ($method === 'GET' && $path === '/auth/discord/callback') { rate_limit('auth', 30, 60);  route_auth_callback(); }
    if ($method === 'GET' && $path === '/auth/me')               { rate_limit('read', 120, 60); route_auth_me(); }

    if ($method === 'GET' && $path === '/badges/me')             { rate_limit('read', 120, 60); route_badges_me(); }
    if ($method === 'PUT' && $path === '/badges')                { rate_limit('write', 30, 60); route_badges_put(); }
    if ($method === 'POST' && $path === '/badges/upload')        { rate_limit('upload', 15, 60); route_upload(); }

    if ($method === 'GET' && preg_match('#^/badges/(\d{15,25})$#', $path, $m)) {
        rate_limit('read', 300, 60);
        route_badges_public($m[1]);
    }

    fail(404, 'Route introuvable');
}

/* ---------- Auth Discord (scope identify uniquement) ---------- */

function http_json(string $method, string $url, array $headers, ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => array_merge(['User-Agent: BadgeNetwork/1.0', 'Accept: application/json'], $headers),
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$code, is_string($raw) ? json_decode($raw, true) : null];
}

function route_auth_start(): never
{
    $clientId = (string) cfg('DISCORD_CLIENT_ID', '');
    if ($clientId === '' || !cfg('DISCORD_CLIENT_SECRET')) fail(500, 'OAuth Discord non configuré');

    $state = bin2hex(random_bytes(16));
    setcookie('bn_state', $state, [
        'expires' => time() + 600,
        'path' => '/auth/',
        'secure' => str_starts_with(base_url(), 'https://'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    $qs = http_build_query([
        'client_id' => $clientId,
        'redirect_uri' => base_url() . '/auth/discord/callback',
        'response_type' => 'code',
        'scope' => 'identify',
        'state' => $state,
        'prompt' => 'none',
    ]);
    header('Cache-Control: no-store');
    header('Location: https://discord.com/oauth2/authorize?' . $qs);
    http_response_code(302);
    exit;
}

function html_page(int $status, string $title, string $bodyHtml, ?string $script = null): never
{
    $nonce = base64_encode(random_bytes(12));
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-$nonce'; base-uri 'none'; form-action 'none'");
    header('X-Frame-Options: DENY');
    $t = htmlspecialchars($title, ENT_QUOTES);
    echo "<!doctype html><html lang=\"fr\"><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\">"
       . "<title>$t</title><style>body{font-family:system-ui,sans-serif;background:#1e1f22;color:#dbdee1;max-width:560px;margin:10vh auto;padding:0 16px}"
       . "textarea{width:100%;height:140px;background:#111214;color:#dbdee1;border:1px solid #3f4147;border-radius:6px;padding:8px;font-family:monospace;box-sizing:border-box}"
       . "button{background:#5865f2;color:#fff;border:0;border-radius:6px;padding:10px 16px;cursor:pointer;margin-top:8px}</style>"
       . "<h1>$t</h1>$bodyHtml";
    if ($script) echo "<script nonce=\"$nonce\">$script</script>";
    exit;
}

function route_auth_callback(): never
{
    if (isset($_GET['error'])) {
        html_page(400, 'Connexion annulée', '<p>Autorisation refusée. Tu peux fermer cet onglet.</p>');
    }

    $code = (string) ($_GET['code'] ?? '');
    $state = (string) ($_GET['state'] ?? '');
    $cookie = (string) ($_COOKIE['bn_state'] ?? '');
    setcookie('bn_state', '', ['expires' => time() - 3600, 'path' => '/auth/']);

    if ($code === '' || $state === '' || $cookie === '' || !hash_equals($cookie, $state)) {
        html_page(400, 'Session invalide', '<p>État OAuth invalide ou expiré. Relance « Se connecter » depuis le plugin.</p>');
    }

    [$c1, $tok] = http_json('POST', 'https://discord.com/api/v10/oauth2/token', [
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query([
        'client_id' => cfg('DISCORD_CLIENT_ID'),
        'client_secret' => cfg('DISCORD_CLIENT_SECRET'),
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => base_url() . '/auth/discord/callback',
    ]));
    if ($c1 !== 200 || empty($tok['access_token'])) {
        html_page(502, 'Erreur Discord', '<p>Impossible de valider le code auprès de Discord.</p>');
    }

    [$c2, $me] = http_json('GET', 'https://discord.com/api/v10/users/@me', [
        'Authorization: Bearer ' . $tok['access_token'],
    ]);
    if ($c2 !== 200 || empty($me['id']) || !preg_match('/^\d{15,25}$/', (string) $me['id'])) {
        html_page(502, 'Erreur Discord', '<p>Impossible de lire ton profil Discord.</p>');
    }

    $username = mb_substr((string) ($me['global_name'] ?? $me['username'] ?? 'user'), 0, 64);
    db()->prepare(
        'INSERT INTO users (id, username, created_at) VALUES (?, ?, ?)
         ON CONFLICT (id) DO UPDATE SET username = excluded.username'
    )->execute([(string) $me['id'], $username, time()]);

    $days = max(1, (int) cfg('JWT_TTL_DAYS', '30'));
    $jwt = jwt_sign(['sub' => (string) $me['id'], 'iat' => time(), 'exp' => time() + $days * 86400]);

    $u = htmlspecialchars($username, ENT_QUOTES);
    html_page(200, 'Connecté', <<<HTML
        <p>Bonjour <strong>$u</strong>. Copie ce token et colle-le dans le champ « Token API » des réglages du plugin BadgeNetwork.</p>
        <p><strong>Ne le partage avec personne</strong> : il donne accès à tes badges.</p>
        <textarea id="t" readonly>$jwt</textarea><br><button id="b">Copier</button>
        HTML,
        "document.getElementById('b').onclick=function(){var t=document.getElementById('t');t.select();navigator.clipboard.writeText(t.value);this.textContent='Copié ✔';}"
    );
}

function route_auth_me(): never
{
    $u = require_auth();
    json_out(200, ['user' => ['id' => $u['id'], 'username' => $u['username']]]);
}

/* ---------- Badges ---------- */

function route_badges_public(string $userId): never
{
    json_out(200, badges_response($userId, true), ['Cache-Control' => 'public, max-age=30']);
}

function route_badges_me(): never
{
    $u = require_auth();
    json_out(200, badges_response($u['id'], false));
}

function route_badges_put(): never
{
    $u = require_auth();

    $raw = file_get_contents('php://input', false, null, 0, 262144 + 1);
    if ($raw === false || strlen($raw) > 262144) fail(413, 'Requête trop volumineuse');
    $in = json_decode($raw, true);
    if (!is_array($in) || !isset($in['badges']) || !is_array($in['badges']) || !array_is_list($in['badges'])) {
        fail(400, 'Corps invalide : { "badges": [...] } attendu');
    }
    if (count($in['badges']) > BN_MAX_LIBRARY) fail(400, 'Maximum ' . BN_MAX_LIBRARY . ' badges en bibliothèque');

    $pdo = db();
    $own = $pdo->prepare('SELECT 1 FROM uploads WHERE file = ? AND user_id = ?');
    $prefix = base_url() . '/uploads/';
    $rows = [];
    $seen = [];
    $enabledCount = 0;

    foreach ($in['badges'] as $i => $b) {
        if (!is_array($b)) fail(400, "Badge #$i invalide");

        $id = $b['id'] ?? null;
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $id)) fail(400, "Badge #$i : id invalide");
        if (isset($seen[$id])) fail(400, "Badge #$i : id en double");
        $seen[$id] = true;

        $name = $b['name'] ?? null;
        if (!is_string($name)) fail(400, "Badge #$i : nom invalide");
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $name));
        if ($name === '' || mb_strlen($name) > 32) fail(400, "Badge #$i : nom de 1 à 32 caractères requis");

        $icon = $b['iconUrl'] ?? null;
        if (!is_string($icon) || !str_starts_with($icon, $prefix)) fail(400, "Badge #$i : iconUrl invalide");
        $file = substr($icon, strlen($prefix));
        if (!preg_match('/^[a-f0-9]{32}\.(png|jpg|gif|webp)$/', $file)) fail(400, "Badge #$i : iconUrl invalide");
        $own->execute([$file, $u['id']]);
        if (!$own->fetchColumn()) fail(400, "Badge #$i : image inconnue (uploade-la d'abord)");

        $enabled = !empty($b['enabled']);
        if ($enabled && ++$enabledCount > BN_MAX_ENABLED) fail(400, 'Maximum ' . BN_MAX_ENABLED . ' badges affichés');

        $rows[] = [$id, $name, $file, $enabled ? 1 : 0, $i];
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM badges WHERE user_id = ?')->execute([$u['id']]);
        $ins = $pdo->prepare('INSERT INTO badges (user_id, id, name, icon_file, enabled, position) VALUES (?, ?, ?, ?, ?, ?)');
        foreach ($rows as $r) $ins->execute([$u['id'], ...$r]);
        $pdo->prepare('UPDATE users SET badges_updated_at = ? WHERE id = ?')->execute([time(), $u['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    purge_orphan_uploads($u['id']);
    json_out(200, badges_response($u['id'], false));
}

/** Supprime les images uploadées il y a plus d'1 h et qui ne sont plus utilisées par aucun badge. */
function purge_orphan_uploads(string $userId): void
{
    $pdo = db();
    $st = $pdo->prepare(
        'SELECT file FROM uploads WHERE user_id = ? AND created_at < ?
         AND file NOT IN (SELECT icon_file FROM badges WHERE user_id = ?)'
    );
    $st->execute([$userId, time() - 3600, $userId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $file) {
        $pdo->prepare('DELETE FROM uploads WHERE file = ?')->execute([$file]);
        @unlink(uploads_dir() . '/' . $file);
    }
}

function route_upload(): never
{
    $u = require_auth();
    $max = max_upload_bytes();

    $isRaw = !str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'multipart/form-data');

    if ($isRaw) {
        // Corps brut (Content-Type: image/*) : lu en flux et bridé en débit.
        $tmp = receive_raw_upload($max);
    } else {
        // Multipart : PHP a déjà tout reçu avant d'exécuter ce code (pas de bridage possible ici).
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || is_array($f['error'] ?? null)) fail(400, 'Champ "file" manquant');

        switch ($f['error']) {
            case UPLOAD_ERR_OK: break;
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                fail(413, 'Fichier trop gros (max ' . round($max / 1048576) . ' Mo)');
            case UPLOAD_ERR_NO_FILE:
                fail(400, 'Aucun fichier reçu');
            default:
                fail(500, 'Erreur pendant l\'upload');
        }
        if ($f['size'] > $max) fail(413, 'Fichier trop gros (max ' . round($max / 1048576) . ' Mo)');
        if (!is_uploaded_file($f['tmp_name'])) fail(400, 'Upload invalide');
        $tmp = $f['tmp_name'];
    }

    // Le type est détecté par le contenu, jamais d'après le nom ou le Content-Type du client.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext) fail(415, 'Format accepté : PNG, JPEG, GIF, WebP');

    $info = @getimagesize($tmp);
    if (!$info || $info['mime'] !== $mime || $info[0] < 1 || $info[1] < 1) fail(415, 'Image invalide');
    if ($info[0] > BN_MAX_DIMENSION || $info[1] > BN_MAX_DIMENSION) {
        fail(413, 'Dimensions max ' . BN_MAX_DIMENSION . '×' . BN_MAX_DIMENSION . ' px');
    }

    $pdo = db();
    $cnt = $pdo->prepare('SELECT COUNT(*) FROM uploads WHERE user_id = ?');
    $cnt->execute([$u['id']]);
    if ((int) $cnt->fetchColumn() >= BN_MAX_UPLOADS_PER_USER) {
        fail(429, 'Trop d\'images stockées, supprime des badges inutilisés');
    }

    $dir = uploads_dir();
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $name = bin2hex(random_bytes(16)) . '.' . $ext;
    $moved = $isRaw ? rename($tmp, "$dir/$name") : move_uploaded_file($tmp, "$dir/$name");
    if (!$moved) fail(500, 'Écriture impossible');
    chmod("$dir/$name", 0644);

    $pdo->prepare('INSERT INTO uploads (file, user_id, created_at) VALUES (?, ?, ?)')
        ->execute([$name, $u['id'], time()]);

    json_out(201, ['iconUrl' => icon_url($name)]);
}

/**
 * Reçoit le corps de la requête en flux, plafonné en taille ET en débit
 * (UPLOAD_RATE_BYTES_PER_SEC, 10 Mo/s par défaut, 0 = illimité).
 * Rejette dès que la taille max est dépassée, sans attendre la fin du transfert.
 * Pour que le bridage freine réellement le client, le serveur web ne doit pas
 * mettre le corps en tampon (nginx : fastcgi_request_buffering off).
 */
function receive_raw_upload(int $max): string
{
    $len = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0) fail(411, 'Content-Length requis');
    if ($len > $max) fail(413, 'Fichier trop gros (max ' . round($max / 1048576) . ' Mo)');

    $rate = (int) cfg('UPLOAD_RATE_BYTES_PER_SEC', (string) (10 * 1024 * 1024));
    @set_time_limit(120);

    $tmp = tempnam(sys_get_temp_dir(), 'bn_');
    if ($tmp === false) fail(500, 'Fichier temporaire impossible');
    register_shutdown_function(static fn () => @unlink($tmp));

    $in = fopen('php://input', 'rb');
    $out = fopen($tmp, 'wb');
    $total = 0;
    $t0 = microtime(true);

    while (!feof($in)) {
        $chunk = fread($in, 65536);
        if ($chunk === false || $chunk === '') break;
        $total += strlen($chunk);
        if ($total > $max) {
            fclose($out);
            fail(413, 'Fichier trop gros (max ' . round($max / 1048576) . ' Mo)');
        }
        fwrite($out, $chunk);
        if ($rate > 0) {
            $ahead = $total / $rate - (microtime(true) - $t0);
            if ($ahead > 0) usleep((int) ($ahead * 1_000_000));
        }
    }
    fclose($in);
    fclose($out);

    if ($total === 0) fail(400, 'Corps vide');
    return $tmp;
}
