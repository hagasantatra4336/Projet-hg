# Créer un vrai serveur Oracle local (Windows) — Oracle XE 21c

Ce guide installe un **véritable serveur Oracle Database** sur la machine de développement,
crée les comptes et les tables source de la DGI, puis branche l'application Laravel dessus.

Il complète [installation-oracle-oci8-windows.md](installation-oracle-oci8-windows.md)
(client `oci8` + Instant Client, déjà installés). Les scripts SQL sont dans
[`database/oracle/`](../database/oracle/) :

| Fichier | Rôle |
|---|---|
| `01_utilisateurs.sql` | comptes `EKAJY` (propriétaire des tables) et `DGI_RO` (lecture seule) |
| `02_tables_source.sql` | 8 tables source + droits `SELECT` pour `DGI_RO` |
| `03_donnees_demo.sql` | jeu de démonstration (5 contribuables, IR/TVA, recoupement, DAU), généré jusqu'à l'année en cours |

---

## 1. Quelle option choisir ?

| Option | Avantages | Inconvénients | Recommandation |
|---|---|---|---|
| **Oracle XE 21c natif Windows** | gratuit, vrai Oracle, aucun Docker/WSL, `sqlplus` inclus, 10–20 min | ~2 Go de RAM, 1,8 Go de téléchargement, installeur en administrateur | **recommandé sur ce poste** (pas de Docker/WSL, 8 Go de RAM) |
| Docker (`gvenzl/oracle-xe`) | isolé, destructible, reproductible | Docker Desktop + WSL2 à installer, ~4 Go de RAM | si Docker est déjà présent |
| Oracle Cloud « Always Free » | aucune installation, accessible de partout | autonomie limitée, wallet TLS, latence réseau | pour une démo distante |

La suite décrit **Oracle XE 21c natif**. Les deux autres options sont résumées au §9.

---

## 2. Prérequis

- Windows 10/11 64 bits, **8 Go de RAM minimum** (fermer les gros logiciels pendant l'installation).
- **10 Go d'espace disque libre** (1,8 Go de zip + ~6 Go installés + données).
- Droits **administrateur** (UAC) pour l'installeur et pour démarrer les services.
- Aucun `ORACLE_HOME` ni `TNS_ADMIN` défini dans les variables d'environnement (l'installeur XE le refuse).
- Le dossier d'installation ne doit contenir **ni espace ni accent** : `C:\app\oracle`.

Vérification rapide (PowerShell) :

```powershell
(Get-CimInstance Win32_ComputerSystem).TotalPhysicalMemory / 1GB   # >= 8
Get-PSDrive C | Select-Object @{n='LibreGo';e={[math]::Round($_.Free/1GB,1)}}
$env:ORACLE_HOME; $env:TNS_ADMIN                                  # doivent être vides
```

> Si `ORACLE_HOME` est défini : le supprimer des variables utilisateur
> (`Paramètres > Système > Variables d'environnement`), fermer et rouvrir le terminal.

---

## 3. Télécharger et installer Oracle XE 21c

### 3.1 Téléchargement

Page officielle : <https://www.oracle.com/database/technologies/xe-downloads.html>

Lien direct (Windows 64 bits) :

<https://download.oracle.com/otn-pub/otn_software/db-express/OracleXE213_Win64.zip>

- Taille : **1 967 615 483 octets** (~1,83 Go)
- SHA-256 : `939742c3305c466566a55f607638621b6aa7033a183175f6bcd6cffb48e6bc3f`

Contrôle du fichier téléchargé :

```powershell
Get-FileHash "$env:USERPROFILE\Downloads\OracleXE213_Win64.zip" -Algorithm SHA256
```

### 3.2 Extraction

Extraire le zip dans un dossier sans espace, par exemple `C:\install\OracleXE213`,
puis **clic droit sur `setup.exe` → Exécuter en tant qu'administrateur**.

### 3.3 Assistant d'installation

1. **License** : accepter.
2. **Oracle Home** : `C:\app\oracle\product\21c\dbhomeXE`
   (emplacement des binaires ; à noter, il servira pour `sqlplus`).
3. **Oracle Base** : `C:\app\oracle` — **Database file location** : `C:\app\oracle\oradata`.
4. **Mot de passe** : celui de `SYS` / `SYSTEM` / `PDBADMIN`. Il doit contenir majuscule,
   minuscule, chiffre et caractère spécial, et **ne pas commencer par un chiffre**
   (exemple utilisé dans ce guide : `Sys2026#`).
5. **Port** : `1521` (laisser par défaut) — **nom de la base enfichable** : `XEPDB1` (défaut).
6. Laisser cochée la création du service Windows « Oracle Database XE ».
7. **Install** : compter 10 à 20 minutes (le « Database Configuration Assistant » est long).
8. À la fin, noter le message *« The installation of Oracle Database 21c Express Edition was successful »*.

> L'installeur peut ajouter `C:\app\oracle\product\21c\dbhomeXE\bin` au `PATH` et définir
> `ORACLE_HOME`. Ce n'est pas gênant, mais **le dossier de l'Instant Client doit rester en tête
> du `PATH`** pour que PHP utilise le client 23 (voir §7.3).

### 3.4 Vérifier l'installation

```powershell
Get-Service Oracle*          # OracleServiceXE et OracleXETNSListener doivent être "Running"
netstat -ano | Select-String ":1521"   # une ligne LISTENING
```

Puis, avec `sqlplus` (`C:\app\oracle\product\21c\dbhomeXE\bin\sqlplus.exe`, ajouter ce dossier au `PATH`) :

```powershell
sqlplus "sys/Sys2026#@//localhost:1521/XEPDB1" as sysdba
```

```sql
SELECT banner_full FROM v$version;
SELECT name, open_mode FROM v$pdbs;      -- XEPDB1 en READ WRITE
EXIT
```

---

## 4. Créer les comptes, les tables et les données

Les scripts `02` et `03` se connectent eux-mêmes en `EKAJY` (ils contiennent un `CONNECT`). Depuis `dgi-backend` :

```powershell
$env:NLS_LANG = ".AL32UTF8"      # accents corrects dans les données
cd database\oracle
sqlplus sys/Sys2026#@//localhost:1521/XEPDB1 as sysdba
```

Dans `sqlplus` :

```sql
@01_utilisateurs.sql
@02_tables_source.sql
@03_donnees_demo.sql
EXIT
```

Puis revenir au backend pour la suite :

```powershell
cd ..\..
```

| Script | Contenu |
|---|---|
| `01_utilisateurs.sql` | `EKAJY` (propriétaire, mot de passe `Ekajy2026#`) et `DGI_RO` (lecture seule, `DgiRo2026#`) |
| `02_tables_source.sql` | `INFO_CONTRIBUABLE`, `ASSUJETTISSEMENT`, `DECLARATION_IR`, `DECLARATION_TVA`, `CENTRE_GESTIONNAIRE`, `AGENTS`, `A_TAB_RECOUPEMENT`, `A_TAB_DAU` |
| `03_donnees_demo.sql` | centres, 5 contribuables, déclarations TVA/IR de 2024 à l'année en cours, recoupement, DAU |

> `02` **supprime puis recrée** les tables, `03` les **vide** : scripts de test uniquement,
> jamais sur une base contenant de vraies déclarations.

Vérification côté serveur :

```sql
CONNECT DGI_RO/"DgiRo2026#"@//localhost:1521/XEPDB1
SELECT table_name FROM user_tables ORDER BY table_name;
SELECT COUNT(*) FROM EKAJY.DECLARATION_TVA;
SELECT COUNT(*) FROM EKAJY.INFO_CONTRIBUABLE;
```

`DGI_RO` ne voit rien dans `USER_TABLES` (les tables appartiennent à `EKAJY`) : c'est normal,
d'où `SOURCE_DB_SCHEMA=EKAJY`. Les `SELECT` ci-dessus, eux, doivent fonctionner.

---

## 5. Brancher l'application Laravel

Dans `dgi-backend\.env` (bloc « Bases sources » ; la base applicative reste PostgreSQL) :

```dotenv
# --- Base source Oracle (serveur XE local) ---
SOURCE_DB_DRIVER=oracle
SOURCE_DB_HOST=127.0.0.1
SOURCE_DB_PORT=1521
# Base enfichable (PDB). SOURCE_DB_SERVICE_NAME est prioritaire sur SOURCE_DB_DATABASE.
SOURCE_DB_SERVICE_NAME=XEPDB1
SOURCE_DB_DATABASE=XE
SOURCE_DB_USERNAME=DGI_RO
SOURCE_DB_PASSWORD="DgiRo2026#"
# Propriétaire des tables : les requêtes sont qualifiées EKAJY.TABLE
# et les métadonnées (tables/colonnes) sont lues chez ce propriétaire.
SOURCE_DB_SCHEMA=EKAJY
SOURCE_DB_CHARSET=AL32UTF8
```

> Le `#` du mot de passe est protégé par les guillemets : sans eux, `.env` peut le prendre
> pour le début d'un commentaire.
> Un seul serveur suffit : laisser vides les `DGI_SOURCE_CONNECTION_<GROUPE>` et les
> `SOURCE_<GROUPE>_DB_*`. Pour une base par groupe, voir `docs/import-source-postgresql.md` §6.

Puis :

```powershell
php artisan config:clear
php artisan dgi:importer-source --tester
```

Résultat attendu (les 5 groupes testés — déclarations IR/TVA, assujettissements, contribuables,
centres, agents — doivent être trouvés, avec leur nombre de lignes) :

```
Connexion OK : base « XEPDB1 ».
  ✔ declaration_ir (… ligne(s))
  ✔ declaration_tva (… ligne(s))
  ✔ assujettissement (…)
  ✔ info_contribuable (5)
  ✔ centre_gestionnaire (3)
  ✔ agents (0)
```

Puis importer :

```powershell
php artisan dgi:importer-centres                    # crée CF TANA I, CF MAHAJANGA A, CF FIANARANTSOA A
php artisan dgi:importer-source --depuis=2024 --jusqua=2025
php artisan dgi:importer-risque --depuis=2024 --jusqua=2025 --analyser
php artisan dgi:agents-source                       # remplit AGENTS avec les comptes de l'application
```

- `dgi:importer-centres` **avant** l'import : les contribuables sont rattachés à un centre par son code ou son nom.
- `dgi:importer-source` crée les contribuables, les assujettissements et les déclarations.
- `dgi:importer-risque` reconstitue la base d'analyse (IR, TVA, recoupement, DAU) puis lance les règles.
- `dgi:agents-source` est à relancer après chaque création de compte (la base source fait foi).

---

## 6. Sauvegarde et remise à zéro

Le dossier de vidage `DATA_PUMP_DIR` existe par défaut dans XE ; on utilise `SYSTEM` (rôle DBA),
`EKAJY` n'ayant pas forcément les droits sur ce dossier :

```powershell
# Sauvegarde logique du schéma EKAJY
expdp system/Sys2026#@//localhost:1521/XEPDB1 directory=DATA_PUMP_DIR dumpfile=ekajy_%U.dmp logfile=ekajy.log schemas=EKAJY
# Restauration
impdp system/Sys2026#@//localhost:1521/XEPDB1 directory=DATA_PUMP_DIR dumpfile=ekajy_%U.dmp logfile=imp.log
```

Remise à zéro la plus simple : relancer `03_donnees_demo.sql` (vide et recharge les données),
ou `02_tables_source.sql` puis `03` (recrée tout).

---

## 7. Dépannage

### 7.1 Connexion / services

| Message | Cause | Correction |
|---|---|---|
| `ORA-12541: TNS:no listener` | écouteur arrêté | `net start OracleXETNSListener`, puis `lsnrctl status` |
| `ORA-12514: service name not found` | service inconnu | `lsnrctl services` ; utiliser `SOURCE_DB_SERVICE_NAME=XEPDB1` (pas `XE`) |
| `ORA-01034` / `ORA-27101` | instance arrêtée | `net start OracleServiceXE` ; sinon `sqlplus / as sysdba` → `startup` |
| `ORA-01017: invalid username/password` | identifiants | vérifier `SOURCE_DB_USERNAME` / `PASSWORD` (guillemets dans `.env`) |
| `ORA-28000: the account is locked` | trop d'échecs | `ALTER USER DGI_RO ACCOUNT UNLOCK;` en SYS |
| `ORA-28001: the password has expired` | mot de passe expiré | `ALTER USER DGI_RO IDENTIFIED BY "DgiRo2026#";` |
| `ORA-00942: table or view does not exist` | schéma non qualifié | `SOURCE_DB_SCHEMA=EKAJY` puis `php artisan config:clear` |
| `ORA-12547: TNS lost contact` | client/serveur incompatibles | Instant Client en tête du `PATH`, redémarrer le terminal |

### 7.2 `sqlplus` et installeur

| Problème | Correction |
|---|---|
| `sqlplus` non reconnu | ajouter `C:\app\oracle\product\21c\dbhomeXE\bin` au `PATH`, rouvrir le terminal |
| `SP2-0306: invalid option` sur `as sysdba` | utiliser `sqlplus sys/MOT_DE_PASSE@//localhost:1521/XEPDB1 as sysdba` |
| `INS-30011` / mot de passe refusé | majuscule + minuscule + chiffre + spécial, ne pas commencer par un chiffre |
| L'installeur refuse de démarrer | `ORACLE_HOME`/`TNS_ADMIN` encore définis, ou droits admin absents |
| Port 1521 déjà utilisé | `netstat -ano \| Select-String ":1521"`, arrêter le processus ou choisir un autre port |
| Installation très lente | antivirus : exclure `C:\app\oracle` et le dossier d'extraction |
| `ORA-04031` / manque de mémoire | fermer les applications ; XE est limité à ~2 Go de SGA |

### 7.3 PHP / oci8 après l'installation de XE

L'installeur XE modifie le `PATH` et peut définir `ORACLE_HOME`. Pour que PHP continue d'utiliser
l'Instant Client 23 :

```powershell
# Le dossier Instant Client doit précéder les binaires XE
$env:PATH -split ';' | Select-String oracle
php -m | Select-String oci8
php --ri oci8 | Select-String "Client Library"
```

Si `oci8` ne se charge plus : remettre `C:\oracle\instantclient_23_0` en tête du `PATH`
(variables d'environnement utilisateur), ou supprimer `ORACLE_HOME`/`TNS_ADMIN`,
puis rouvrir le terminal et redémarrer Herd.

---

## 8. Ce qui a été créé (rappel)

```
C:\app\oracle\product\21c\dbhomeXE\      binaires Oracle XE (sqlplus, lsnrctl)
C:\app\oracle\oradata\                   fichiers de la base (XE, XEPDB1)
C:\app\oracle\admin\                     traces et journaux

Service Windows : OracleServiceXE        (instance)
Service Windows : OracleXETNSListener    (écouteur, port 1521)

Schéma EKAJY  : tables source (propriétaire)
Compte DGI_RO : SELECT uniquement (utilisé par Laravel)
```

---

## 9. Alternatives

### 9.1 Docker (si Docker Desktop est installé)

```powershell
docker run -d --name oracle-xe -p 1521:1521 -e ORACLE_PASSWORD="Sys2026#" gvenzl/oracle-xe:21-slim
docker logs -f oracle-xe          # attendre « DATABASE IS READY TO USE! »
```

Puis exécuter les scripts avec le `sqlplus` du conteneur :

```powershell
docker exec -it oracle-xe sqlplus sys/Sys2026#@//localhost:1521/XEPDB1 as sysdba
```

Les identifiants et le `.env` restent identiques (§5), sauf le mot de passe `SYS` choisi ci-dessus.

### 9.2 Oracle Cloud « Always Free »

Créer une base **Autonomous Transaction Processing** (Always Free) dans la région la plus proche,
télécharger le **wallet**, puis :

```dotenv
SOURCE_DB_DRIVER=oracle
SOURCE_DB_TNS=dgi_high                    # alias du tnsnames.ora du wallet (prioritaire)
SOURCE_DB_USERNAME=DGI_RO
SOURCE_DB_PASSWORD="..."
SOURCE_DB_SCHEMA=EKAJY
```

`TNS_ADMIN` doit pointer sur le dossier du wallet décompressé (variables d'environnement).
Plus lourd à maintenir qu'un serveur local, à réserver aux démonstrations distantes.
