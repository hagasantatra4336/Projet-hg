# Récupérer les déclarations depuis une base PostgreSQL (source)

L'application peut lire les déclarations dans une **base PostgreSQL externe** (la « source », en lecture seule),
les copier dans ses propres tables (`contribuables`, `declarations`) puis lancer l'analyse des défaillances.

```
Base PostgreSQL source ──(SELECT)──▶ SourceDeclarationImporter ──▶ contribuables + declarations ──▶ DefaillanceDetector ──▶ alertes
```

## 1. Créer la base de test

1. Dans le `.env` du backend (voir `.env.example`) :
   ```
   SOURCE_DB_HOST=127.0.0.1
   SOURCE_DB_PORT=5432
   SOURCE_DB_DATABASE=dgi_source_test
   SOURCE_DB_USERNAME=postgres      # doit avoir le droit CREATEDB
   SOURCE_DB_PASSWORD=...
   ```
   puis `php artisan config:clear`.
2. Avoir au moins **un centre** dans l'application (Administration > Centres) : les contribuables de test y sont répartis.
3. Créer la base, la table `declarations_source` et les données simulées :
   ```
   php artisan dgi:source-test-db            # relançable
   php artisan dgi:source-test-db --reset    # supprime puis recrée (refusé si le nom ne contient pas « test », sauf --force)
   ```
   Données : 30 contribuables anonymes (`NIF-SRC-001`…), 6 scénarios de TVA (mêmes que `DeclarationDemoSeeder`),
   IRSA régulier pour un sur trois, plus **4 lignes volontairement invalides** (centre inconnu, impôt inconnu,
   période `2026-13`, montant négatif) qui doivent être rejetées.

## 2. Importer et analyser

```
php artisan dgi:importer-source --tester                       # vérifie la connexion (base, version, nb de lignes)
php artisan dgi:importer-source                                # importe tout l'historique
php artisan dgi:importer-source --depuis=2025-10 --jusqua=2026-09 --centre=1
php artisan dgi:importer-source --analyser                     # importe puis détecte les défaillances
```
Une ligne déjà importée (même NIF + impôt + période) est **mise à jour**, jamais dupliquée. Les lignes invalides sont
rejetées et listées (15 messages max), le reste est importé.

Résultat attendu avec la base de test : 4 rejets ; des alertes de défaillance pour les scénarios 1/8, 2/7, 4/10 et 1/12
(le scénario « régulier » et le « 3 mois / 3 » n'en génèrent pas).

## 3. API (jeton Sanctum ; superadmin, central, admin — l'admin est limité à son centre)

| Méthode | Route | Rôle |
|---|---|---|
| GET | `/api/declarations/source/test` | teste la connexion à la source |
| POST | `/api/declarations/import-source` | corps JSON facultatif : `depuis`, `jusqua` (`AAAA-MM`), `analyser` (booléen) |

Une erreur de connexion renvoie **502** avec un message générique ; le détail technique n'est écrit que dans les logs.

## 4. Brancher la vraie base

Changez `SOURCE_DB_*` pour pointer vers la vraie base (idéalement avec un compte **en lecture seule**). Si les noms de
table/colonnes diffèrent, renseignez `DGI_SOURCE_TABLE` et `DGI_SOURCE_COL_*` (voir `config/dgi.php`). Attendu côté
source : `periode` en texte `AAAA-MM`, `type_impot` ∈ TVA/IR/IS/IRSA, `centre` = nom d'un centre de l'application.
Si la source stocke une vraie date, créez une **vue** PostgreSQL qui expose `to_char(date, 'YYYY-MM') as periode`.

## 5. Tests automatiques

`php artisan test --filter=SourceImportTest` — n'exige pas PostgreSQL (la source y est une SQLite en mémoire).

## 6. Oracle, et une base différente par groupe de tables

Les tables peuvent venir de **plusieurs bases**. On raisonne par « groupe » : `declarations` (IR + TVA),
`assujettissements`, `contribuables`, `centres`, `agents`, `recoupement`, `dau`.

| Où | Ce qu'on règle |
|---|---|
| `.env` → `DGI_SOURCE_CONNECTION_<GROUPE>` | nom de la connexion à utiliser (`source_declarations`, …) |
| `.env` → `SOURCE_<GROUPE>_DB_*` | hôte, port, service/SID, utilisateur, mot de passe, charset : seules les variables à changer |
| `config/database.php` | connexions `source_<groupe>` (déjà déclarées) |
| `config/dgi.php` | noms de **tables et colonnes** (`source.declarations.IR.table`, …) |
| `config/risque.php` | tables `recoupement` et `dau` |

Exemple minimal pour deux bases Oracle :

```dotenv
SOURCE_DB_DRIVER=oracle
SOURCE_DB_HOST=10.0.0.10
SOURCE_DB_PORT=1521
SOURCE_DB_SERVICE_NAME=ORCLPDB1
SOURCE_DB_DATABASE=ORCLPDB1
SOURCE_DB_USERNAME=DGI_APP
SOURCE_DB_PASSWORD=...
SOURCE_DB_CHARSET=AL32UTF8

DGI_SOURCE_CONNECTION_DECLARATIONS=source_declarations
SOURCE_DECLARATIONS_DB_HOST=10.0.0.11
SOURCE_DECLARATIONS_DB_SERVICE_NAME=DECLPDB1
SOURCE_DECLARATIONS_DB_DATABASE=DECLPDB1
SOURCE_DECLARATIONS_DB_USERNAME=DGI_DECL
SOURCE_DECLARATIONS_DB_PASSWORD=...
```

Points à connaître :

- **Extension `oci8` obligatoire** (`yajra/laravel-oci8` l'exige) + Oracle Instant Client. Sans elle :
  `Undefined constant "Yajra\Pdo\OCI_DEFAULT"` et `composer install` en échec.
  Procédure Windows/Herd détaillée et chemins des fichiers : `docs/installation-oracle-oci8-windows.md`.
- **Propriétaire des tables** : les métadonnées sont lues sur `owner = utilisateur de connexion`.
  Si les tables sont dans un autre schéma (ou derrière un synonyme), ajoutez
  `SOURCE_<GROUPE>_DB_SCHEMA=EKAJY` : les requêtes utilisent alors `EKAJY.TABLE` et les colonnes sont
  cherchées chez ce propriétaire. C'est le cas typique d'un compte en lecture seule.
- `service_name` vide ⇒ la valeur de `DATABASE` est prise comme **SID**. Pour un service, renseignez
  `SOURCE_*_DB_SERVICE_NAME`.
- **Granularité** : IR et TVA sont dans le même groupe, donc dans la même base.
- `dgi:source-test-db`, `dgi:source-test-data`, `dgi:source-anomalies-demo`
  écrivent dans la base de chaque groupe ; la première refuse de tourner si les groupes sont séparés
  (elle crée toutes les tables de test dans une seule base).
- Vérification : `php artisan dgi:importer-source --tester` affiche, pour chaque table, la connexion et
  la base réellement utilisées.
- **Serveur Oracle local** (comptes, tables source, données de démo, `.env`, dépannage) :
  `docs/serveur-oracle-local-windows.md`.

