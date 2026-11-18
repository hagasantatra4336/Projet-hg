# Sécurité au déploiement (nginx)

Les réponses de l'API portent déjà leurs en-têtes de sécurité (middleware `SecurityHeaders`).
Le front est servi par nginx en statique : les en-têtes des pages HTML doivent donc être ajoutés
dans le bloc `server` qui sert `dgi-frontend/dist`.

```nginx
server {
    # ... root / index / try_files pour le SPA, proxy /api vers php-fpm ...

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    add_header Referrer-Policy "no-referrer" always;
    add_header Permissions-Policy "camera=(), microphone=(), geolocation=()" always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;

    # script-src 'self' : le script de thème est dans public/theme-init.js, aucun script en ligne.
    # style-src 'unsafe-inline' est nécessaire aux styles calculés par React (barres, aperçu A4).
    add_header Content-Security-Policy "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'" always;
}
```

## En-têtes `X-Forwarded-*` : les écraser, jamais les relayer

`TRUSTED_PROXIES` fait confiance à `127.0.0.1`, l'adresse de nginx : c'est nécessaire pour que
les limitations par IP voient la vraie adresse du client. Mais en FastCGI, nginx transmet
**tous** les en-têtes reçus du navigateur, y compris ceux que le client a forgés. Un attaquant
qui envoie `X-Forwarded-Host: site-pirate.mg` arrive donc depuis une adresse de confiance :
Symfony accepte l'en-tête et fabrique des URL (liens d'e-mail, redirections) vers ce domaine.
C'est le vecteur de **CVE-2026-123339** (`symfony/http-kernel`).

La parade consiste à écraser ces en-têtes dans le bloc `location` qui passe à PHP-FPM :

```nginx
location ^~ /api/ {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/dgi/dgi-backend/public/index.php;
    fastcgi_param SCRIPT_NAME /index.php;

    # Un client ne doit jamais pouvoir imposer ces valeurs.
    fastcgi_param HTTP_X_FORWARDED_HOST  $host;
    fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
    fastcgi_param HTTP_X_FORWARDED_FOR   $remote_addr;
    fastcgi_param HTTP_X_FORWARDED_PORT  $server_port;
}
```

Contrôle :

```bash
curl -sS -H 'X-Forwarded-Host: pirate.example' https://dgi.exemple.mg/api/me -o /dev/null -w '%{http_code}\n'
# puis, dans storage/logs/laravel.log ou dans un lien reçu par e-mail :
# l'hôte doit rester celui du site, jamais pirate.example.
```

Ne mettez **jamais** `private_ranges` dans `TRUSTED_PROXIES` : ce raccourci est affecté par
**CVE-2026-121619** (adresses IPv4-mappées, 6to4, Teredo et NAT64 acceptées comme proxys).
Une liste d'adresses explicites est la seule forme recommandée.

## Version de PHP

Le 21/10/2026, deux exécutions de code à distance critiques ont été corrigées :
**CVE-2026-109618** et **CVE-2026-109619** (« php2shell », CVSS 9.2). Elles sont au catalogue
KEV de la CISA depuis le 26/10/2026, donc **activement exploitées**.

| Version | État |
|---|---|
| 8.5.12, 8.4.27, 8.3.36, 8.2.35 | corrigées |
| 8.3.6 (dépôt Ubuntu 24.04) | **vulnérable** |

Exiger `php -v` >= 8.3.36 (8.4.27 ou plus conseillé), typiquement via le dépôt `ondrej/php`.
En mesure d'attente uniquement : `session.upload_progress.enabled=0` dans `php.ini`.

## Dépendances PHP

`composer audit` doit répondre *No security vulnerability advisories found* avant chaque mise
en ligne. Ce contrôle fait partie de la procédure de mise à jour.
- `SANCTUM_EXPIRATION` : durée de vie des jetons en minutes (480 par défaut).
- `SESSION_SECURE_COOKIE=true` et `APP_ENV=production`, `APP_DEBUG=false` en production.
