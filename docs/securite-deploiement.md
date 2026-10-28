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

## Variables d'environnement utiles

- `TRUSTED_PROXIES` : adresses des proxys dont les en-têtes `X-Forwarded-*` sont acceptés.
  Par défaut `127.0.0.1,::1` (nginx sur le même hôte). Indispensable pour que les limitations
  par IP et le HTTPS détecté (`X-Forwarded-Proto`) soient corrects.
- `SANCTUM_EXPIRATION` : durée de vie des jetons en minutes (480 par défaut).
- `SESSION_SECURE_COOKIE=true` et `APP_ENV=production`, `APP_DEBUG=false` en production.
