-- =====================================================================
-- 02 — Tables source de la DGI (jeu de test)
-- À exécuter sur la base enfichable XEPDB1, après 01_utilisateurs.sql :
--   sqlplus sys/MOT_DE_PASSE@//localhost:1521/XEPDB1 as sysdba
--   SQL> @02_tables_source.sql
--
-- Les noms sont volontairement NON quotés : Oracle les stocke en MAJUSCULES,
-- ce qui correspond à ce qu'envoie Laravel (le pilote Oracle met les
-- identifiants en majuscules avant de les entourer de guillemets).
-- Seules les colonnes du centre contiennent des espaces et sont donc quotées.
--
-- ATTENTION : ce script SUPPRIME puis recrée les tables source (données de test).
-- Il ne doit jamais être lancé sur une base contenant de vraies déclarations.
-- =====================================================================

CONNECT EKAJY/"Ekajy2026#"@//192.168.1.189:1522/XEPDB1

SET DEFINE OFF
SET ECHO OFF

-- ---------------------------------------------------------------------
-- Suppression des tables existantes (relançable sans erreur)
-- ---------------------------------------------------------------------
BEGIN
  FOR t IN (
    SELECT table_name FROM user_tables
     WHERE table_name IN ('DECLARATION_IR', 'DECLARATION_TVA', 'ASSUJETTISSEMENT',
                          'INFO_CONTRIBUABLE', 'CENTRE_GESTIONNAIRE', 'AGENTS',
                          'A_TAB_RECOUPEMENT', 'A_TAB_DAU')
  ) LOOP
    EXECUTE IMMEDIATE 'DROP TABLE "' || t.table_name || '" CASCADE CONSTRAINTS';
  END LOOP;
END;
/

-- ---------------------------------------------------------------------
-- Contribuables (E-Kajy)
--   ETAT : 1 = actif, 2 = bloqué, 3 = cessation, 4 = radié
-- ---------------------------------------------------------------------
CREATE TABLE info_contribuable (
  NIF            VARCHAR2(10)  NOT NULL,
  ETAT           NUMBER(1),
  RAISON_SOCIALE VARCHAR2(200),
  CENTRE_GEST    VARCHAR2(200),
  DATE_CESSATION DATE,
  DATE_RADIE     DATE,
  DATE_BLOQUE    DATE
);

CREATE INDEX idx_info_contribuable_nif ON info_contribuable (NIF);

-- ---------------------------------------------------------------------
-- Assujettissements (un impôt par ligne et par exercice)
--   PERIODICITE : 1 = mensuelle, 5 = annuelle ; ACTIF / EXONERE : 1 ou 0
-- ---------------------------------------------------------------------
CREATE TABLE assujettissement (
  NIF         VARCHAR2(10) NOT NULL,
  REGIST_NAME VARCHAR2(200),
  ANNEE       NUMBER(4),
  DEBUT       DATE,
  FIN         DATE,
  PERIODICITE NUMBER(2),
  DATE_ASSUJ  DATE,
  ABREV       VARCHAR2(10),
  ACTIF       NUMBER(1),
  EXONERE     NUMBER(1)
);

CREATE INDEX idx_assujettissement_nif ON assujettissement (NIF, ANNEE);

-- ---------------------------------------------------------------------
-- Déclarations : une ligne par ligne du formulaire (IRD_LINE_NO)
--   IR  : CA = ligne 15, résultat = ligne 20, etc. (config/risque.php)
--   TVA : CA = ligne 170 (config/dgi.php)
-- ---------------------------------------------------------------------
CREATE TABLE declaration_ir (
  CF             VARCHAR2(200),
  FISCAL_NO      VARCHAR2(10) NOT NULL,
  RS             VARCHAR2(200),
  RECEPTION_DATE NUMBER(10),
  TAX_PERIOD_NO  NUMBER(10),
  MOIS           NUMBER(2),
  ANNEE          NUMBER(4),
  MONTANT_SOUMIS  NUMBER(18, 2),
  MONTANT_LIQUIDE NUMBER(18, 2),
  VERSION_NO     NUMBER(2),
  IRD_LINE_NO    NUMBER(4),
  LABEL_2        VARCHAR2(200),
  DATE_RECEPTION DATE,
  DATE_ECHEANCE  DATE
);

CREATE INDEX idx_declaration_ir_nif ON declaration_ir (FISCAL_NO, ANNEE, IRD_LINE_NO);

CREATE TABLE declaration_tva (
  CENTRE          VARCHAR2(200),
  FISCAL_NO       VARCHAR2(10) NOT NULL,
  RS              VARCHAR2(200),
  RECEPTION_DATE  NUMBER(10),
  TAX_PERIOD_NO   NUMBER(10),
  MOIS            NUMBER(2),
  ANNEE           NUMBER(4),
  MONTANT_SOUMIS  NUMBER(18, 2),
  MONTANT_LIQUIDE NUMBER(18, 2),
  IRD_LINE_NO     NUMBER(4),
  LABEL_2         VARCHAR2(200),
  DATE_RECEPTION  DATE,
  DATE_ECHEANCE   DATE
);

CREATE INDEX idx_declaration_tva_nif ON declaration_tva (FISCAL_NO, ANNEE, MOIS, IRD_LINE_NO);

-- ---------------------------------------------------------------------
-- Centres gestionnaires officiels (noms de colonnes avec espaces)
-- ---------------------------------------------------------------------
CREATE TABLE centre_gestionnaire (
  "CENTRE ID"      VARCHAR2(20),
  "LIBELLE CENTRE" VARCHAR2(200),
  "CENTRE ABREV"   VARCHAR2(100)
);

-- ---------------------------------------------------------------------
-- Personnel E-Kajy (contrôle du centre des agents à la connexion)
-- Alimentée par : php artisan dgi:agents-source
-- ---------------------------------------------------------------------
CREATE TABLE agents (
  EMAIL          VARCHAR2(255) NOT NULL,
  MATRICULE      VARCHAR2(50),
  NOM            VARCHAR2(200),
  CENTRE_CODE    VARCHAR2(20),
  CENTRE_LIBELLE VARCHAR2(200),
  GRADE          VARCHAR2(100),
  ACTIF          NUMBER(1) DEFAULT 1
);

CREATE UNIQUE INDEX idx_agents_email ON agents (EMAIL);

-- ---------------------------------------------------------------------
-- Analyse risque : recoupement (par NIF et année)
-- ---------------------------------------------------------------------
CREATE TABLE a_tab_recoupement (
  NIF                     VARCHAR2(10) NOT NULL,
  ANNEE                   NUMBER(4),
  PRESTATIONS_DE_SERVICE  NUMBER(18, 2),
  ACHATS_EFFECTUES        NUMBER(18, 2),
  ACHATS_CONSOMMES        NUMBER(18, 2),
  PRODUITS_LOCAUX_VENDUS  NUMBER(18, 2),
  EXPORTATIONS            NUMBER(18, 2),
  IMPORTATIONS            NUMBER(18, 2),
  MONTANT_TOTAL           NUMBER(18, 2),
  TVA_DED_ACHAT           NUMBER(18, 2),
  TVA_DED_IMP             NUMBER(18, 2),
  MARCHER_PUBLIC          NUMBER(18, 2)
);

CREATE INDEX idx_a_tab_recoupement_nif ON a_tab_recoupement (NIF, ANNEE);

-- ---------------------------------------------------------------------
-- Analyse risque : DAU (déclarations en douane)
--   MODELE : IM4 (importation) ou EX1 (exportation)
-- ---------------------------------------------------------------------
CREATE TABLE a_tab_dau (
  NIF        VARCHAR2(10) NOT NULL,
  ANNEE      NUMBER(4),
  MODELE     VARCHAR2(10),
  TVA        NUMBER(18, 2),
  VALEUR_CAF NUMBER(18, 2)
);

CREATE INDEX idx_a_tab_dau_nif ON a_tab_dau (NIF, ANNEE);

-- ---------------------------------------------------------------------
-- Lecture seule pour le compte de l'application Laravel
-- (SOURCE_DB_USERNAME=DGI_RO, SOURCE_DB_SCHEMA=EKAJY)
-- ---------------------------------------------------------------------
GRANT SELECT ON info_contribuable TO DGI_RO;
GRANT SELECT ON assujettissement   TO DGI_RO;
GRANT SELECT ON declaration_ir     TO DGI_RO;
GRANT SELECT ON declaration_tva    TO DGI_RO;
GRANT SELECT ON centre_gestionnaire TO DGI_RO;
GRANT SELECT ON agents             TO DGI_RO;
GRANT SELECT ON a_tab_recoupement  TO DGI_RO;
GRANT SELECT ON a_tab_dau          TO DGI_RO;

PROMPT Tables source creees dans le schema EKAJY et droits SELECT accordes a DGI_RO.
