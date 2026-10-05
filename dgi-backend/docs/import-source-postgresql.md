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
