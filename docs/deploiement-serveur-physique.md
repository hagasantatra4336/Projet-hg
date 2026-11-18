# Déployer l'application sur un serveur physique

Ce guide décrit un déploiement sur Ubuntu Server 24.04 LTS. Il suppose que l'application et PostgreSQL sont sur le serveur, et que la base source Oracle 21c est déjà disponible sur un réseau privé.

Ne copiez pas le `.env` de développement en production. N'inscrivez aucun mot de passe dans Git. Les commandes ci-dessous doivent être adaptées au domaine, aux adresses IP et aux comptes réels.

La personne qui déploie doit disposer d'un compte Linux avec accès SSH et droits `sudo` sur le serveur. Elle y installera les dépendances et créera aussi son compte superadministrateur dans l'application. Composer, PHP et les autres outils d'installation ne sont pas requis sur son ordinateur personnel ; Node.js/npm peuvent également être installés sur un autre poste si le frontend y est construit.

## 1. Préparer le serveur et le réseau

Prévoir :

- un serveur 64 bits, au minimum 4 vCPU, 8 Go de RAM et 50 Go de stockage SSD ;
- Ubuntu Server 24.04 LTS à jour et un compte administrateur avec accès SSH ;
- une adresse IP fixe, un nom de domaine et un certificat HTTPS ;
- un accès réseau privé du serveur applicatif à Oracle 21c ;
- les identifiants PostgreSQL, Oracle en lecture seule et SMTP.

Le port Oracle (souvent 1521, parfois 1522) doit être accessible uniquement depuis le serveur applicatif ou le VPN. Ne publiez pas Oracle ni PostgreSQL sur Internet. Sur le pare-feu, n'ouvrez publiquement que SSH, HTTP et HTTPS.

Dans les exemples, remplacez `dgi.example.mg`, `ORACLE_HOST_OR_IP` et les valeurs `CHANGE_ME` par les valeurs de votre organisation.

## 2. Installer les outils et dépendances système

Mettre le système à jour et installer Nginx, PostgreSQL, PHP 8.3, Composer et les extensions Laravel sur le serveur :

```bash
sudo apt update
sudo apt full-upgrade -y
sudo apt install -y git curl unzip zip ca-certificates gnupg nginx postgresql postgresql-contrib \
  php8.3-cli php8.3-fpm php8.3-common php8.3-pgsql php8.3-xml php8.3-curl \
  php8.3-mbstring php8.3-zip php8.3-bcmath php8.3-intl php8.3-gd php8.3-opcache \
  php8.3-dev php-pear build-essential libaio1t64 composer certbot python3-certbot-nginx
```

Les commandes ci-dessous utilisent PHP 8.3 et le pilote OCI8 pour Oracle. Installer les correctifs de sécurité Ubuntu avant le déploiement, puis vérifier :

```bash
php -v
composer --version
sudo systemctl enable --now nginx postgresql php8.3-fpm
```

Installer Node.js 22 sur le serveur uniquement si le frontend y sera construit. Sinon, installer Node.js/npm sur le poste de préparation et transférer le dossier `dgi-frontend/dist` généré :

```bash
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
node --version
npm --version
```

## 3. Installer le client Oracle et OCI8

Cette étape est obligatoire sur le serveur PHP pour que Laravel puisse lire Oracle.

1. Depuis la [page de téléchargement Oracle Instant Client pour Linux x86-64](https://www.oracle.com/database/technologies/instant-client/linux-x86-64-downloads.html), télécharger **Basic** et **SDK**. Utiliser le même numéro de version pour les deux archives.
2. Copier les archives dans `~/Downloads`, puis les extraire :

```bash
cd ~/Downloads
sudo mkdir -p /opt/oracle
sudo unzip instantclient-basic-linux.x64-*.zip -d /opt/oracle
sudo unzip instantclient-sdk-linux.x64-*.zip -d /opt/oracle
```

3. Créer un lien stable vers le dossier extrait. Remplacer le nom du dossier par celui réellement téléchargé :

```bash
sudo ln -s /opt/oracle/instantclient_23_0 /opt/oracle/instantclient
echo /opt/oracle/instantclient | sudo tee /etc/ld.so.conf.d/oracle-instantclient.conf
sudo ldconfig
```

Installer OCI8 avec PECL. Quand il demande le chemin Instant Client, saisir `instantclient,/opt/oracle/instantclient` :

```bash
sudo pecl install oci8
echo "extension=oci8.so" | sudo tee /etc/php/8.3/mods-available/oci8.ini
sudo phpenmod -v 8.3 oci8
sudo systemctl restart php8.3-fpm
php -m | grep -i oci8
```

La dernière commande doit afficher `oci8`. Si PECL échoue, vérifier que les archives Basic et SDK ont été extraites, que le chemin du client est exact et que `ldconfig` ne signale pas d'erreur.

## 4. Créer la base PostgreSQL applicative

Se connecter à PostgreSQL :

```bash
sudo -u postgres psql
```

Dans l'invite `psql`, créer une base et un compte dédiés. Choisir un mot de passe fort, puis quitter :

```sql
CREATE USER dgi_app WITH PASSWORD 'REMPLACER_PAR_UN_MOT_DE_PASSE_FORT';
CREATE DATABASE dgi_app OWNER dgi_app;
\q
```

La base applicative est PostgreSQL. Oracle reste une source distincte ; ne mettez pas `DB_CONNECTION=oracle`.

## 5. Préparer Oracle 21c

Demander à l'administrateur Oracle :

1. le nom DNS ou l'adresse privée du serveur Oracle, le port et le `SERVICE_NAME` de la PDB ;
2. un compte dédié à l'application, par exemple `DGI_RO`, limité à `CREATE SESSION` et aux droits `SELECT` nécessaires ;
3. le schéma propriétaire des tables, par exemple `EKAJY`, et les noms exacts des tables.

Le compte applicatif doit avoir des droits `SELECT` directs sur les tables source utilisées : déclarations IR et TVA, assujettissements, contribuables, centres et, si disponibles, agents, recoupement et DAU. Ne lui accordez pas de droits DBA ni de droits d'écriture.

Si une nouvelle base Oracle est créée spécialement pour des essais, les scripts SQL se trouvent dans `dgi-backend/database/oracle/`. Faites-les examiner par l'administrateur Oracle avant usage. `02_tables_source.sql` recrée les tables et `03_donnees_demo.sql` vide/recharge les données : ce sont des scripts de démonstration, à ne pas exécuter sur des données de production.

Depuis le serveur applicatif, vérifier que le port est joignable :

```bash
nc -vz ORACLE_HOST_OR_IP 1522
```

Installer `netcat-openbsd` avec `sudo apt install -y netcat-openbsd` si `nc` n'est pas disponible. Un échec indique généralement un problème de routage, VPN, écouteur Oracle ou pare-feu.

## 6. Récupérer le projet et installer les dépendances

Créer le dossier et cloner le dépôt avec le compte de déploiement :

```bash
sudo mkdir -p /var/www/dgi
sudo chown "$USER":www-data /var/www/dgi
git clone URL_DU_DEPOT /var/www/dgi/projet
cd /var/www/dgi/projet
```

Remplacer `URL_DU_DEPOT` par l'URL Git du dépôt. Si le dépôt est privé, configurer une clé SSH de déploiement ; ne placez pas de jeton Git dans une commande enregistrée ou dans le dépôt.

Créer le fichier d'environnement du backend et éditer les paramètres :

```bash
cd /var/www/dgi/projet/dgi-backend
cp .env.example .env
nano .env
```

Configurer au minimum les paramètres ci-dessous. Le fichier `dgi-backend/.env.example` contient aussi les noms de tables source utilisés actuellement.

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://dgi.example.mg
FRONTEND_URL=https://dgi.example.mg
CORS_ALLOWED_ORIGINS=https://dgi.example.mg

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=dgi_app
DB_USERNAME=dgi_app
DB_PASSWORD="MOT_DE_PASSE_POSTGRESQL"

SOURCE_DB_DRIVER=oracle
SOURCE_DB_HOST=ORACLE_HOST_OR_IP
SOURCE_DB_PORT=1522
SOURCE_DB_SERVICE_NAME=XEPDB1
SOURCE_DB_DATABASE=XE
SOURCE_DB_USERNAME=DGI_RO
SOURCE_DB_PASSWORD="MOT_DE_PASSE_ORACLE"
SOURCE_DB_SCHEMA=EKAJY
SOURCE_DB_CHARSET=AL32UTF8
DGI_SOURCE_CONNECTION=source
```

Utiliser le vrai port et le vrai `SERVICE_NAME` Oracle. Les exemples `XE`, `XEPDB1` et `EKAJY` ne sont valables que si votre installation les utilise. Mettre entre guillemets les mots de passe contenant `#` ou des espaces.

Installer les dépendances PHP et vérifier les extensions :

```bash
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
composer check-platform-reqs
composer audit
```

Si `composer check-platform-reqs` signale `ext-oci8`, reprendre l'étape 3 avant de continuer.

Générer la clé de chiffrement une seule fois sur ce serveur :

```bash
php artisan key:generate
```

Ne régénérez pas cette clé lors d'une mise à jour : elle sert notamment à vérifier les OTP existants.

## 7. Configurer l'envoi des OTP par SMTP

Les codes OTP sont envoyés par e-mail après approbation d'une inscription et lors d'une connexion. L'envoi est immédiat ; aucun processus `queue:work` n'est nécessaire pour ces e-mails.

Dans le compte du fournisseur de messagerie, créer un accès SMTP réservé à l'application. Pour Gmail :

1. activer la validation en deux étapes sur le compte Google ;
2. créer un **mot de passe d'application** réservé au serveur DGI depuis [les paramètres Google](https://myaccount.google.com/apppasswords) ;
3. ne pas utiliser le mot de passe normal du compte Gmail ;
4. si « Mots de passe des applications » n'est pas proposé, demander à l'administrateur Google Workspace ou utiliser le SMTP officiel de l'organisation.

Renseigner les paramètres dans le fichier `.env` du backend :

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=adresse-expediteur@gmail.com
MAIL_PASSWORD="MOT_DE_PASSE_APPLICATION"
MAIL_FROM_ADDRESS=adresse-expediteur@gmail.com
MAIL_FROM_NAME="DGI"
```

Pour un autre fournisseur, remplacer l'hôte, le port, le mode TLS et les identifiants par les valeurs fournies par ce fournisseur. Garder `MAIL_FROM_ADDRESS` autorisé par le compte SMTP. Ne jamais laisser `MAIL_MAILER=log` en production : les messages seraient écrits dans les journaux au lieu d'être envoyés.

Effacer la configuration mise en cache après chaque modification du `.env` :

```bash
php artisan config:clear
```

Tester l'envoi avant d'ouvrir l'application :

```bash
php artisan tinker
```

Dans Tinker :

```php
use Illuminate\Support\Facades\Mail;
Mail::raw('Test SMTP DGI', function ($message) {
    $message->to('adresse-de-test@example.mg')->subject('Test SMTP DGI');
});
```

Vérifier la réception, les courriers indésirables et les journaux Laravel :

```bash
tail -n 100 storage/logs/laravel.log
```

Si le test échoue, vérifier les identifiants SMTP, le port sortant 587, le TLS, le DNS du serveur et les restrictions du fournisseur de messagerie.

## 8. Migrer la base et préparer le premier compte

Depuis le backend, exécuter les migrations et créer seulement les fonctions de base. Ne lancez pas `php artisan db:seed` sur une installation réelle : le seeder général contient un utilisateur de démonstration.

```bash
php artisan migrate --force
php artisan db:seed --class=FonctionSeeder --force
```

Lancer cette commande sur le serveur en remplaçant l'adresse et le nom par ceux du responsable du déploiement :

```bash
php artisan dgi:make-superadmin adresse-du-responsable@example.mg --nom="Nom du responsable"
```

La commande demande le mot de passe de façon interactive ; ne le mettez pas dans la ligne de commande. Elle crée le compte **superadministrateur de l'application**, pas un compte Linux. Après le déploiement, cette personne se connecte à l'application avec ce compte pour configurer les centres et les autres utilisateurs.

## 9. Tester Oracle et importer les données

Après avoir rempli les paramètres Oracle, vider le cache et tester les connexions ainsi que les tables :

```bash
php artisan config:clear
php artisan dgi:importer-source --tester
```

Ne continuez pas si une table requise est introuvable ou si la connexion échoue. Vérifier le `SERVICE_NAME`, les droits `SELECT`, le schéma propriétaire et la connectivité réseau.

Importer d'abord les centres, puis les données fiscales :

```bash
php artisan dgi:importer-centres
php artisan dgi:importer-source --depuis=2024
php artisan dgi:importer-risque --depuis=2024 --analyser
```

Adapter `2024` à la première année disponible dans la base Oracle. L'analyse des alertes peut aussi être lancée depuis la page Anomalies. L'import Oracle n'est pas automatiquement exécuté par l'application.

## 10. Construire le frontend

Le site et l'API utilisent ici le même domaine, donc le frontend appelle `/api`. Construire les fichiers statiques sur le serveur (si Node.js/npm y sont installés) :

```bash
cd /var/www/dgi/projet/dgi-frontend
cp .env.example .env
npm ci
npm run build
```

Le résultat est produit dans `dgi-frontend/dist`. Pour construire le frontend sur un autre poste, exécuter les mêmes commandes depuis le dossier `dgi-frontend` du dépôt, puis transférer le dossier `dist` au chemin `/var/www/dgi/projet/dgi-frontend/dist` sur le serveur. Dans ce cas, Node.js/npm ne sont pas nécessaires sur le serveur.

Si l'API utilise un domaine différent, modifier `VITE_API_URL` dans `dgi-frontend/.env` avant `npm run build`, par exemple `https://api.example.mg/api`, et inscrire l'origine du site frontend dans `CORS_ALLOWED_ORIGINS` du backend.

## 11. Configurer Nginx et HTTPS

Dans les commandes ci-dessous, remplacer `/var/www/dgi/projet` si le projet est installé ailleurs. Créer `/etc/nginx/sites-available/dgi` :

```nginx
server {
    listen 80;
    server_name dgi.example.mg;
    root /var/www/dgi/projet/dgi-backend/public;
    index index.php;
    client_max_body_size 6M;

    location = /api {
        try_files $uri $uri/ @laravel;
    }

    location ^~ /api/ {
        try_files $uri $uri/ @laravel;
    }

    location = /up {
        try_files $uri $uri/ @laravel;
    }

    location / {
        root /var/www/dgi/projet/dgi-frontend/dist;
        try_files $uri $uri/ /index.html;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }

    location @laravel {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /var/www/dgi/projet/dgi-backend/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param HTTP_HOST $server_name;
        fastcgi_param HTTP_X_FORWARDED_HOST $server_name;
        fastcgi_param HTTP_X_FORWARDED_PROTO $scheme;
        fastcgi_param HTTP_X_FORWARDED_FOR $remote_addr;
        fastcgi_param HTTP_X_FORWARDED_PORT $server_port;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

Activer et vérifier la configuration :

```bash
sudo ln -s /etc/nginx/sites-available/dgi /etc/nginx/sites-enabled/dgi
sudo nginx -t
sudo systemctl reload nginx
```

Autoriser SSH avant d'activer le pare-feu :

```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
sudo ufw status
```

Faire pointer le DNS `dgi.example.mg` vers l'adresse publique du serveur, puis activer le certificat HTTPS. Dans Certbot, choisir la redirection automatique de HTTP vers HTTPS :

```bash
sudo certbot --nginx -d dgi.example.mg
sudo certbot renew --dry-run
```

L'application doit être servie en HTTPS. Les en-têtes de sécurité de l'API sont fournis par Laravel ; pour les pages statiques, appliquer aussi les directives Nginx de `docs/securite-deploiement.md`.

## 12. Permissions et tâches périodiques

Nginx/PHP doit pouvoir lire le code et écrire uniquement dans `storage` et `bootstrap/cache` :

```bash
cd /var/www/dgi/projet/dgi-backend
sudo chown -R "$USER":www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

Laravel doit exécuter régulièrement le planificateur : il envoie les rappels OTP d'approbation, clôt les codes expirés et purge les anciens jetons. Ajouter une tâche cron :

```bash
sudo crontab -e
```

Ajouter cette ligne :

```cron
* * * * * cd /var/www/dgi/projet/dgi-backend && php artisan schedule:run >> /dev/null 2>&1
```

## 13. Vérifications avant ouverture

Depuis le serveur :

```bash
cd /var/www/dgi/projet/dgi-backend
php artisan about
php artisan migrate:status
php artisan dgi:importer-source --tester
curl -I https://dgi.example.mg
curl -i https://dgi.example.mg/up
```

Puis, depuis un navigateur :

1. vérifier que la page de connexion et le formulaire d'inscription s'ouvrent en HTTPS ;
2. tester la réception SMTP ;
3. créer une demande de compte, l'approuver, puis vérifier la réception du code OTP ;
4. se connecter et vérifier le code OTP de connexion ;
5. vérifier les données importées et les alertes.

Quand les tests sont réussis, mettre en cache la configuration Laravel :

```bash
cd /var/www/dgi/projet/dgi-backend
php artisan config:cache
php artisan view:cache
```

Après toute modification du `.env`, lancer `php artisan config:clear`, refaire les tests nécessaires, puis recacher avec les deux commandes ci-dessus.

En production, garder `APP_ENV=production` et `APP_DEBUG=false`. En cas d'erreur, consulter `storage/logs/laravel.log`, les journaux Nginx et `journalctl -u php8.3-fpm`.

## 14. Sauvegardes et mises à jour

Prévoir une sauvegarde régulière de PostgreSQL, des fichiers nécessaires et du `.env` dans un emplacement chiffré et protégé. Le `.env` contient des secrets : ne pas le placer dans le dépôt ni dans un répertoire web public. Faire régulièrement un test de restauration. La sauvegarde de la base Oracle doit être coordonnée avec son administrateur.

Pour mettre à jour l'application après sauvegarde :

```bash
cd /var/www/dgi/projet
git pull
cd dgi-backend
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
cd ../dgi-frontend
npm ci
npm run build
cd ../dgi-backend
php artisan optimize
sudo systemctl reload php8.3-fpm
sudo systemctl reload nginx
```

Ne remplacez pas le `.env` existant et ne régénérez pas `APP_KEY` lors d'une mise à jour.
