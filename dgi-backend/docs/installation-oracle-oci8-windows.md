# Installation Oracle (oci8 + Instant Client) — Windows / Herd

Fiche de l'installation effectuée le **16/11/2026** sur le poste de développement, pour que
`yajra/laravel-oci8` (= le pilote `oracle` de `config/database.php`) puisse se connecter aux bases
sources Oracle de la DGI.

## 1. Résultat obtenu

| Élément | Version / valeur |
|---|---|
| PHP (Herd) | 8.4.27 — NTS, x64, Visual C++ 2022 (VC17) |
| Extension PHP | `oci8` **3.4.1** (build `8.4-nts-vs17-x64`) |
| Oracle Instant Client | **23.26.1.0.0** (Basic Light, x64) |
| `composer check-platform-reqs` | `ext-oci8 3.4.1 ... success` |
| Test du pilote Laravel | le driver `oracle` se charge et répond `ORA-12541` (aucun serveur Oracle local) → la chaîne PHP → oci8 → Oracle est opérationnelle |

## 2. Où se trouvent les fichiers

| Fichier / dossier | Chemin |
|---|---|
| Client Oracle (librairies, `oci.dll`) | `C:\oracle\instantclient_23_0\` |
| Extension PHP | `C:\Users\haga\.config\herd\bin\php84\ext\php_oci8_19.dll` |
| Configuration PHP (ligne `extension=oci8_19`) | `C:\Users\haga\.config\herd\bin\php84\php.ini` |
| Sauvegarde du php.ini avant modification | `C:\Users\haga\.config\herd\bin\php84\php.ini.backup-oci8` |
| Téléchargements (archives, peuvent être supprimées) | `%TEMP%\instantclient-basiclite.zip` et `%TEMP%\php_oci8.zip` |
| Variable d'environnement modifiée | `PATH` (utilisateur) — `C:\oracle\instantclient_23_0` en tête |
| Connexions déclarées côté Laravel | `dgi-backend\config\database.php` (`source`, `source_declarations`, …) |
| Groupes / tables source | `dgi-backend\config\dgi.php` (`source.connections`, `source.declarations`, …) |
| Tables recoupement / DAU | `dgi-backend\config\risque.php` |
| Variables d'environnement | `dgi-backend\.env` (modèle documenté : `dgi-backend\.env.example`) |
| Résolution des connexions dans le code | `dgi-backend\app\Support\SourceConnexion.php` |

## 3. Étapes exécutées (reproductibles sur un autre poste)

### 3.1 Oracle Instant Client

```powershell
New-Item -ItemType Directory -Force C:\oracle | Out-Null
Invoke-WebRequest -Uri "https://download.oracle.com/otn_software/nt/instantclient/instantclient-basiclite-windows.zip" `
  -OutFile "$env:TEMP\instantclient-basiclite.zip"
Expand-Archive "$env:TEMP\instantclient-basiclite.zip" -DestinationPath C:\oracle -Force
```

Le dossier extrait est `C:\oracle\instantclient_23_0` (le nom suit la version du client).

Prérequis : **Visual C++ Redistributable 2015-2022 x64** (`https://aka.ms/vs/17/release/vc_redist.x64.exe`).
Le SDK, SQL*Plus, ODBC et PDO_OCI ne sont **pas** nécessaires : `yajra/laravel-oci8` utilise
directement l'extension `oci8`.

### 3.2 PATH

```powershell
$oci = "C:\oracle\instantclient_23_0"
$userPath = [Environment]::GetEnvironmentVariable("PATH", "User")
[Environment]::SetEnvironmentVariable("PATH", "$oci;$userPath", "User")
```

**Fermer puis rouvrir les terminaux** (Herd, VS Code, PowerShell) : le PATH n'est lu qu'au démarrage.

### 3.3 Extension oci8

Le build doit correspondre exactement à `php -v` + `php -i | Select-String "Thread Safety"` :
PHP 8.4 **NTS** x64 VC17 → `php_oci8-3.4.1-8.4-nts-vs17-x64.zip`.

```powershell
Invoke-WebRequest -Uri "https://windows.php.net/downloads/pecl/releases/oci8/3.4.1/php_oci8-3.4.1-8.4-nts-vs17-x64.zip" `
  -OutFile "$env:TEMP\php_oci8.zip"
Expand-Archive "$env:TEMP\php_oci8.zip" -DestinationPath "$env:TEMP\oci8" -Force
Copy-Item "$env:TEMP\oci8\php_oci8_19.dll" "C:\Users\haga\.config\herd\bin\php84\ext\" -Force
```

Index des builds : <https://windows.php.net/downloads/pecl/releases/oci8/>

### 3.4 php.ini

Sauvegarde puis activation (bloc des extensions, après `extension=pdo_sqlite`) :

```ini
extension=oci8_19
```

**Important — `extension_dir` doit être un chemin absolu.** Herd met par défaut
`extension_dir = "ext"` (ligne 764) : PHP le résout par rapport au dossier courant, donc
`php` lancé depuis `dgi-backend` ne trouve plus la DLL. Le 25/12/2025 ce réglage a été remplacé par :

```ini
extension_dir = "C:\Users\haga\.config\herd\bin\php84\ext"
```

(sans `.dll` dans `extension=` ; sauvegarde : `php.ini.backup-oci8`).

## 4. Vérifications

```powershell
php -m | Select-String oci8      # -> oci8
php --ri oci8                    # -> OCI8 Support => enabled, Version => 3.4.1,
                                 #    Oracle Run-time Client Library Version => 23.26.1.0.0
composer check-platform-reqs     # -> ext-oci8 3.4.1 success
```

Côté Laravel, après avoir renseigné le `.env` (`SOURCE_DB_DRIVER=oracle`, hôte, port,
`SOURCE_DB_SERVICE_NAME`, utilisateur, mot de passe) :

```powershell
php artisan config:clear
php artisan dgi:importer-source --tester
```

La commande affiche, pour chaque table, la **connexion** et la **base** réellement utilisées.

## 5. Dépannage

| Message | Cause | Correction |
|---|---|---|
| `Unable to load dynamic library 'oci8_19'` | mauvais build (TS/NTS, x86/x64), DLL absent de `ext\`, ou `extension_dir` relatif | reprendre 3.3 ; vérifier que `extension_dir` est un chemin **absolu** (3.4) |
| `The specified module could not be found` | `oci.dll` introuvable : PATH non pris en compte | rouvrir le terminal, vérifier `$env:PATH` |
| `ORA-12541: No listener` | aucun serveur Oracle sur hôte/port | vérifier `SOURCE_DB_HOST` / `PORT`, pare-feu, VPN |
| `ORA-12514: service name not found` | `SERVICE_NAME` incorrect | `SELECT name FROM v$services;` sur le serveur |
| `ORA-01017: invalid username/password` | identifiants | vérifier `SOURCE_*_DB_USERNAME` / `PASSWORD` |
| `ORA-00942: table or view does not exist` | table dans un autre schéma | `SOURCE_<GROUPE>_DB_SCHEMA=PROPRIETAIRE`, voir `docs/import-source-postgresql.md` §6 |
| `Undefined constant "Yajra\Pdo\OCI_DEFAULT"` | extension `oci8` non chargée | revenir à l'étape 3.3 |

## 6. Reste à faire

1. Renseigner les paramètres Oracle dans `dgi-backend\.env` (bloc détaillé dans `.env.example`,
   section « Bases sources ») : `SOURCE_DB_DRIVER=oracle`, `SOURCE_DB_*`, puis
   `DGI_SOURCE_CONNECTION_<GROUPE>` si les groupes sont dans des bases différentes.
2. `php artisan config:clear`
3. `php artisan dgi:importer-source --tester`, puis `php artisan dgi:importer-centres`,
   `php artisan dgi:importer-source`, `php artisan dgi:importer-risque`.

**Pas encore de serveur Oracle ?** Voir `docs/serveur-oracle-local-windows.md` : installation
d'Oracle XE 21c, création des comptes/tables (`database/oracle/01…03`), `.env` et vérifications.

> Après un redémarrage ou une mise à jour de Herd/PHP : vérifier que `php.ini` contient toujours
> `extension=oci8_19` et que le dossier `C:\oracle\instantclient_23_0` est toujours dans le PATH.
