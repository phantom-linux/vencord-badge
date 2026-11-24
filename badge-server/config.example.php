<?php
// Copie ce fichier en config.php et remplis les valeurs.
// (Tu peux aussi utiliser des variables d'environnement du même nom.)
return [
    // URL publique de l'API, en HTTPS, sans slash final.
    'BASE_URL' => 'https://badges.example.com',

    // Discord Developer Portal → ton application → OAuth2.
    // Ajoute comme Redirect : BASE_URL + /auth/discord/callback
    'DISCORD_CLIENT_ID' => '',
    'DISCORD_CLIENT_SECRET' => '',

    // Chaîne aléatoire d'au moins 32 caractères. Génère-la avec :
    //   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    'JWT_SECRET' => '',

    // Durée de validité du token, en jours.
    'JWT_TTL_DAYS' => '30',

    // Taille max d'un upload (octets). 10 Mo par défaut.
    'MAX_UPLOAD_BYTES' => 10 * 1024 * 1024,

    // Débit max de réception d'un upload "corps brut", en octets/seconde, par requête.
    // 10 Mo/s par défaut, 0 = illimité. (Ne s'applique pas à l'upload multipart, voir README.)
    'UPLOAD_RATE_BYTES_PER_SEC' => 10 * 1024 * 1024,

    // Mets '1' uniquement si l'API est derrière un reverse proxy de confiance (X-Forwarded-For).
    'TRUST_PROXY' => '0',
];
