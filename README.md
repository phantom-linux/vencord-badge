# BadgeNetwork

Plugin Vencord pour créer des badges personnalisés hébergés sur votre propre serveur.
Les badges sont visibles uniquement par les utilisateurs qui utilisent Vencord avec ce plugin.

Ce projet contient :

- `BadgeNetwork/` : plugin Vencord ;
- `badge-server/` : API PHP auto-hébergée.

Auteur et mainteneur : **Linux Phantom**.

## Licence

BadgeNetwork est distribué sous licence **GPL-3.0-or-later**.
Voir le fichier [`LICENSE`](./LICENSE).

## Plugin Vencord

### Prérequis

- [Node.js LTS](https://nodejs.org/)
- [pnpm](https://pnpm.io/) : `npm install -g pnpm`
- Vencord installé depuis les sources : https://docs.vencord.dev/installing/
- Git facultatif

### Installation

1. Placez le dossier `BadgeNetwork/` dans `src/userplugins/` de votre source Vencord.
2. Modifiez `DEFAULT_API_BASE` dans `BadgeNetwork/settings.ts` avec l’URL HTTPS de votre serveur.
3. Depuis la racine de Vencord :

```powershell
pnpm build
pnpm inject
```

4. Fermez complètement Discord, rouvrez-le, puis activez **BadgeNetwork**.

### Première utilisation

1. Ouvrez les réglages du plugin.
2. Autorisez le domaine du serveur pour la CSP.
3. Fermez complètement Discord, rouvrez-le.
4. Cliquez sur **Se connecter** puis collez le token API.
5. Créez un badge et choisissez **Afficher**.

## Serveur PHP

Le backend est compatible avec PHP 8.1+, SQLite et cURL. Il ne nécessite aucune dépendance Composer.

### Prérequis

- PHP ≥ 8.1 avec `pdo_sqlite`, `curl`, `fileinfo` et `mbstring` (`gd` conseillé) ;
- un domaine en HTTPS ;
- une application Discord configurée dans le Developer Portal.

### Installation

1. Placez les fichiers du projet sur le serveur. **Seul `badge-server/public/` doit être la racine web.**
2. Copiez `badge-server/config.example.php` vers `badge-server/config.php`, puis remplissez :
   - `BASE_URL` : URL publique, par exemple `https://badges.example.com` ;
   - `DISCORD_CLIENT_ID` et `DISCORD_CLIENT_SECRET` : valeurs Discord ;
   - `JWT_SECRET` : secret aléatoire d’au moins 32 caractères.
3. Générez un secret avec :

```bash
php -r "echo bin2hex(random_bytes(32));"
```

4. Dans OAuth2 du Developer Portal Discord, ajoutez exactement :
   `https://badges.example.com/auth/discord/callback`
5. Accordez les droits d’écriture à l’utilisateur PHP sur `badge-server/data/` et `badge-server/public/uploads/`.
6. Pour Apache, utilisez `.htaccess`. Pour Nginx, adaptez `badge-server/nginx.example.conf`.
7. Configurez l’URL du serveur dans les réglages du plugin.

### Taille et débit des uploads

La limite maximale est de 10 Mo par fichier. Trois configurations doivent être alignées :

| Emplacement | Réglage |
|---|---|
| PHP (`.user.ini`, `.htaccess` ou `php.ini`) | `upload_max_filesize = 10M`, `post_max_size = 12M` |
| Nginx | `client_max_body_size 11m;` |
| `config.php` | `MAX_UPLOAD_BYTES`, 10 Mo par défaut |

Le débit de téléchargement est limité côté serveur web. Le débit d’upload de fichiers envoyés en « corps brut » est limité par `UPLOAD_RATE_BYTES_PER_SEC` (10 Mo/s par défaut).

- Avec nginx, `fastcgi_request_buffering off;` est obligatoire pour que PHP puisse bridger l’upload pendant sa réception.
- L’upload `multipart/form-data` reste accepté, mais PHP ne reçoit les données qu’après leur réception complète.
- Pour limiter le multipart au niveau réseau, utilisez `tc`, un pare-feu ou les outils de votre hébergeur.

Le plugin envoie déjà les images en corps brut :

```ts
const res = await fetch(`${apiBase}/badges/upload`, {
    method: "POST",
    headers: {
        Authorization: `Bearer ${token}`,
        "Content-Type": file.type || "application/octet-stream",
    },
    body: file,
});
```

## API

| Route | Auth | Rôle |
|---|---|---|
| `GET /auth/discord` | Non | Lance l’OAuth Discord avec le scope `identify` |
| `GET /auth/discord/callback` | Non | Affiche le token à coller dans le plugin |
| `GET /auth/me` | Bearer | Retourne l’utilisateur courant |
| `GET /badges/:userId` | Non | Retourne les badges affichés d’un utilisateur |
| `GET /badges/me` | Bearer | Retourne la bibliothèque complète |
| `PUT /badges` | Bearer | Remplace la bibliothèque (25 badges maximum, 10 affichés) |
| `POST /badges/upload` | Bearer | Upload multipart ou corps brut, PNG/JPEG/GIF/WebP, 10 Mo maximum, 2048 px maximum |

## Sécurité

- JWT HS256 avec expiration et algorithme imposé ;
- cookie `state` anti-CSRF pour l’OAuth ;
- vérification du type réel des fichiers et des dimensions ;
- noms aléatoires et dossier d’uploads sans exécution de scripts ;
- seules les images du serveur elles-mêmes peuvent être enregistrées ;
- limitation du débit par IP sans conservation de l’adresse IP en clair ;
- requêtes SQL préparées ;
- base SQLite hors de la racine web ;
- suppression automatique des images orphelines.

## Test rapide sans Discord

```bash
cp badge-server/config.example.php badge-server/config.php
php badge-server/tools/make-token.php 123456789012345678 alice
curl -H "Authorization: Bearer <JWT>" https://badges.example.com/auth/me
```

Le dossier `badge-server/tools/` doit rester hors de la racine web.

## Sauvegarde

Sauvegardez régulièrement :

- `badge-server/data/badges.sqlite`
- `badge-server/public/uploads/`
