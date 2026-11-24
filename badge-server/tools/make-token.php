<?php
// Usage (CLI, pour tester sans passer par Discord) :
//   php tools/make-token.php <discord_user_id> [username]
// Crée l'utilisateur en base si besoin et affiche un JWT valide.
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/../src/lib.php';

$id = $argv[1] ?? '';
if (!preg_match('/^\d{15,25}$/', $id)) {
    fwrite(STDERR, "Usage: php tools/make-token.php <discord_user_id> [username]\n");
    exit(1);
}
$username = $argv[2] ?? 'test';

db()->prepare(
    'INSERT INTO users (id, username, created_at) VALUES (?, ?, ?)
     ON CONFLICT (id) DO UPDATE SET username = excluded.username'
)->execute([$id, $username, time()]);

echo jwt_sign(['sub' => $id, 'iat' => time(), 'exp' => time() + 86400]), PHP_EOL;
