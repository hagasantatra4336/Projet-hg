-- =====================================================================
-- 03 — Données de démonstration (serveur Oracle de test)
-- À exécuter sur XEPDB1, après 02_tables_source.sql :
--   sqlplus sys/MOT_DE_PASSE@//localhost:1521/XEPDB1 as sysdba
--   SQL> @03_donnees_demo.sql
--
-- Les déclarations sont générées de 2024 jusqu'à l'année en cours
-- (mois écoulés seulement), recoupement et DAU en sont déduits : le jeu
-- reste cohérent quelle que soit la date d'exécution.
--
-- Scénarios prévus (comme la base de test PostgreSQL) :
--   ALPHA   : contribuable sain, CA en hausse, déclarations complètes
--   BETA    : CA en forte baisse et TVA non déposée d'avril à juin
--             de l'année précédente (défaillance)
--   GAMMA   : importations (DAU IM4) et TVA déductible sur biens importés
--   DELTA   : cessation d'activité au 30/09/2024 (plus d'obligation ensuite)
--   EPSILON : radié fin 2023, aucune déclaration
-- Les montants sont fictifs et servent uniquement aux tests.
--
-- ATTENTION : ce script VIDE les tables source avant de les remplir.
-- =====================================================================

CONNECT EKAJY/"Ekajy2026#"@//192.168.1.189:1522/XEPDB1

SET DEFINE OFF
SET SERVEROUTPUT ON

TRUNCATE TABLE declaration_ir;
TRUNCATE TABLE declaration_tva;
TRUNCATE TABLE assujettissement;
TRUNCATE TABLE info_contribuable;
TRUNCATE TABLE centre_gestionnaire;
TRUNCATE TABLE agents;
TRUNCATE TABLE a_tab_recoupement;
TRUNCATE TABLE a_tab_dau;

-- ---------------------------------------------------------------------
-- Centres gestionnaires (créés dans l'application par dgi:importer-centres)
-- ---------------------------------------------------------------------
INSERT INTO centre_gestionnaire ("CENTRE ID", "LIBELLE CENTRE", "CENTRE ABREV") VALUES ('21-1-702', 'CF TANA I', 'TANA I');
INSERT INTO centre_gestionnaire ("CENTRE ID", "LIBELLE CENTRE", "CENTRE ABREV") VALUES ('21-2-401', 'CF MAHAJANGA A', 'MAHAJ A');
INSERT INTO centre_gestionnaire ("CENTRE ID", "LIBELLE CENTRE", "CENTRE ABREV") VALUES ('21-3-501', 'CF FIANARANTSOA A', 'FIANA A');

-- ---------------------------------------------------------------------
-- Contribuables
-- ---------------------------------------------------------------------
INSERT INTO info_contribuable (NIF, ETAT, RAISON_SOCIALE, CENTRE_GEST, DATE_CESSATION, DATE_RADIE, DATE_BLOQUE)
VALUES ('1000000001', 1, 'SOCIETE ALPHA SARL', 'CF TANA I', NULL, NULL, NULL);

INSERT INTO info_contribuable (NIF, ETAT, RAISON_SOCIALE, CENTRE_GEST, DATE_CESSATION, DATE_RADIE, DATE_BLOQUE)
VALUES ('1000000002', 1, 'SOCIETE BETA SA', 'CF TANA I', NULL, NULL, NULL);

INSERT INTO info_contribuable (NIF, ETAT, RAISON_SOCIALE, CENTRE_GEST, DATE_CESSATION, DATE_RADIE, DATE_BLOQUE)
VALUES ('1000000003', 1, 'ETABLISSEMENT GAMMA', 'CF MAHAJANGA A', NULL, NULL, NULL);

INSERT INTO info_contribuable (NIF, ETAT, RAISON_SOCIALE, CENTRE_GEST, DATE_CESSATION, DATE_RADIE, DATE_BLOQUE)
VALUES ('1000000004', 3, 'SOCIETE DELTA', 'CF FIANARANTSOA A', DATE '2024-09-30', NULL, NULL);

INSERT INTO info_contribuable (NIF, ETAT, RAISON_SOCIALE, CENTRE_GEST, DATE_CESSATION, DATE_RADIE, DATE_BLOQUE)
VALUES ('1000000005', 4, 'SOCIETE EPSILON', 'CF TANA I', NULL, DATE '2023-12-31', NULL);

-- ---------------------------------------------------------------------
-- Déclarations TVA (mensuelles) et IR (annuelles)
--   TVA : 6 lignes par mois (150, 160, 170, 300, 315, 400) + 305 pour GAMMA
--   IR  : 4 lignes par exercice (15, 20, 115, 125), version 2 = définitive
--   L'IR d'un exercice est déposé l'année suivante : pas d'IR pour l'année en cours.
-- ---------------------------------------------------------------------
DECLARE
  v_annee      NUMBER := EXTRACT(YEAR FROM SYSDATE);
  v_mois_max   NUMBER := EXTRACT(MONTH FROM SYSDATE) - 1;
  v_fin_mois   DATE;
  v_rec        DATE;
  v_ech        DATE;
  v_ca         NUMBER;
  v_ca_annuel  NUMBER;
  v_absent     BOOLEAN;

  CURSOR c_soc IS
    SELECT '1000000001' AS nif, 'CF TANA I' AS centre, 'SOCIETE ALPHA SARL' AS rs,
           'ALPHA' AS profil FROM dual
    UNION ALL
    SELECT '1000000002', 'CF TANA I', 'SOCIETE BETA SA', 'BETA' FROM dual
    UNION ALL
    SELECT '1000000003', 'CF MAHAJANGA A', 'ETABLISSEMENT GAMMA', 'GAMMA' FROM dual
    UNION ALL
    SELECT '1000000004', 'CF FIANARANTSOA A', 'SOCIETE DELTA', 'DELTA' FROM dual;
BEGIN
  FOR s IN c_soc LOOP
    FOR a IN 2024 .. v_annee LOOP
      v_ca_annuel := 0;

      FOR m IN 1 .. 12 LOOP
        v_ca := CASE s.profil
                  WHEN 'ALPHA' THEN CASE WHEN a >= 2025 THEN 55000000 ELSE 50000000 END
                  WHEN 'BETA'  THEN CASE WHEN a >= 2025 THEN 25000000 ELSE 60000000 END
                  WHEN 'GAMMA' THEN 30000000
                  ELSE 15000000
                END;

        v_absent := (s.profil = 'BETA' AND a = v_annee - 1 AND m BETWEEN 4 AND 6)  -- défaut de dépôt
                 OR (s.profil = 'DELTA' AND (a <> 2024 OR m > 9))                  -- cessation 30/09/2024
                 OR (a = v_annee AND m > v_mois_max);                              -- mois pas encore échus

        IF NOT v_absent THEN
          v_ca_annuel := v_ca_annuel + v_ca;

          v_fin_mois := LAST_DAY(TO_DATE(TO_CHAR(a) || '-' || LPAD(TO_CHAR(m), 2, '0') || '-01', 'YYYY-MM-DD'));
          v_rec := v_fin_mois + 10;   -- dépôt le 10 du mois suivant
          v_ech := v_fin_mois + 20;   -- échéance le 20 du mois suivant

          INSERT INTO declaration_tva (CENTRE, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                       MONTANT_SOUMIS, MONTANT_LIQUIDE, IRD_LINE_NO, LABEL_2, DATE_RECEPTION, DATE_ECHEANCE)
          VALUES (s.centre, s.nif, s.rs, a, a * 100 + m, m, a,
                  v_ca, v_ca, 170, 'Chiffre d affaires total', v_rec, v_ech);

          INSERT INTO declaration_tva (CENTRE, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                       MONTANT_SOUMIS, MONTANT_LIQUIDE, IRD_LINE_NO, LABEL_2, DATE_RECEPTION, DATE_ECHEANCE)
          VALUES (s.centre, s.nif, s.rs, a, a * 100 + m, m, a,
                  v_ca, v_ca, 150, 'Chiffre d affaires taxable', v_rec, v_ech);

          INSERT INTO declaration_tva (CENTRE, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                       MONTANT_SOUMIS, MONTANT_LIQUIDE, IRD_LINE_NO, LABEL_2, DATE_RECEPTION, DATE_ECHEANCE)
          VALUES (s.centre, s.nif, s.rs, a, a * 100 + m, m, a,
                  0, 0, 160, 'Chiffre d affaires exonere', v_rec, v_ech);

          INSERT INTO declaration_tva (CENTRE, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                       MONTANT_SOUMIS, MONTANT_LIQUIDE, IRD_LINE_NO, LABEL_2, DATE_RECEPTION, DATE_ECHEANCE)
          VALUES (s.centre, s.nif, s.rs, a, a * 100 + m, m, a,
                  ROUND(v_ca * 0.10, 2), ROUND(v_ca * 0.10, 2), 300, 'TVA deductible biens locaux', v_rec, v_ech);

          INSERT INTO declaration_tva (CENTRE, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                       MONTANT_SOUMIS, MONTANT_LIQUIDE, IRD_LINE_NO, LABEL_2, DATE_RECEPTION, DATE_ECHEANCE)
          VALUES (s.centre, s.nif, s.rs, a, a * 100 + m, m, a,
                  ROUND(v_ca * 0.02, 2), ROUND(v_ca * 0.02, 2), 315, 'TVA deductible investissements locaux', v_rec, v_ech);

          INSERT INTO declaration_tva (CENTRE, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                       MONTANT_SOUMIS, MONTANT_LIQUIDE, IRD_LINE_NO, LABEL_2, DATE_RECEPTION, DATE_ECHEANCE)
          VALUES (s.centre, s.nif, s.rs, a, a * 100 + m, m, a,
                  0, 0, 400, 'Credit de TVA', v_rec, v_ech);

          -- GAMMA importe : TVA déductible sur biens importés (ligne 305)
          IF s.profil = 'GAMMA' THEN
            INSERT INTO declaration_tva (CENTRE, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                         MONTANT_SOUMIS, MONTANT_LIQUIDE, IRD_LINE_NO, LABEL_2, DATE_RECEPTION, DATE_ECHEANCE)
            VALUES (s.centre, s.nif, s.rs, a, a * 100 + m, m, a,
                    ROUND(v_ca * 0.05, 2), ROUND(v_ca * 0.05, 2), 305, 'TVA deductible biens importes', v_rec, v_ech);
          END IF;
        END IF;
      END LOOP;

      -- Déclaration IR de l'exercice (déposée l'année suivante ; DELTA cesse fin 2024)
      IF a < v_annee AND NOT (s.profil = 'DELTA' AND a > 2024) THEN
        -- Déclaration rectificative (version 1) pour ALPHA 2024 : la version 2 ci-dessous fait foi
        IF s.profil = 'ALPHA' AND a = 2024 THEN
          INSERT INTO declaration_ir (CF, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                      MONTANT_SOUMIS, MONTANT_LIQUIDE, VERSION_NO, IRD_LINE_NO, LABEL_2,
                                      DATE_RECEPTION, DATE_ECHEANCE)
          VALUES (s.centre, s.nif, s.rs, a, a * 1000, NULL, a,
                  ROUND(v_ca_annuel * 0.90, 2), ROUND(v_ca_annuel * 0.90, 2), 1, 15,
                  'Chiffre d affaires total (provisoire)', TO_DATE(TO_CHAR(a) || '-01-31', 'YYYY-MM-DD'),
                  TO_DATE(TO_CHAR(a + 1) || '-04-30', 'YYYY-MM-DD'));
        END IF;

        INSERT INTO declaration_ir (CF, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                    MONTANT_SOUMIS, MONTANT_LIQUIDE, VERSION_NO, IRD_LINE_NO, LABEL_2,
                                    DATE_RECEPTION, DATE_ECHEANCE)
        VALUES (s.centre, s.nif, s.rs, a, a * 1000, NULL, a,
                v_ca_annuel, v_ca_annuel, 2, 15, 'Chiffre d affaires total',
                TO_DATE(TO_CHAR(a + 1) || '-03-31', 'YYYY-MM-DD'), TO_DATE(TO_CHAR(a + 1) || '-04-30', 'YYYY-MM-DD'));

        INSERT INTO declaration_ir (CF, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                    MONTANT_SOUMIS, MONTANT_LIQUIDE, VERSION_NO, IRD_LINE_NO, LABEL_2,
                                    DATE_RECEPTION, DATE_ECHEANCE)
        VALUES (s.centre, s.nif, s.rs, a, a * 1000, NULL, a,
                ROUND(v_ca_annuel * 0.10, 2), ROUND(v_ca_annuel * 0.10, 2), 2, 20, 'Resultat comptable',
                TO_DATE(TO_CHAR(a + 1) || '-03-31', 'YYYY-MM-DD'), TO_DATE(TO_CHAR(a + 1) || '-04-30', 'YYYY-MM-DD'));

        INSERT INTO declaration_ir (CF, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                    MONTANT_SOUMIS, MONTANT_LIQUIDE, VERSION_NO, IRD_LINE_NO, LABEL_2,
                                    DATE_RECEPTION, DATE_ECHEANCE)
        VALUES (s.centre, s.nif, s.rs, a, a * 1000, NULL, a,
                ROUND(v_ca_annuel * 0.02, 2), ROUND(v_ca_annuel * 0.02, 2), 2, 115, 'IR brut',
                TO_DATE(TO_CHAR(a + 1) || '-03-31', 'YYYY-MM-DD'), TO_DATE(TO_CHAR(a + 1) || '-04-30', 'YYYY-MM-DD'));

        INSERT INTO declaration_ir (CF, FISCAL_NO, RS, RECEPTION_DATE, TAX_PERIOD_NO, MOIS, ANNEE,
                                    MONTANT_SOUMIS, MONTANT_LIQUIDE, VERSION_NO, IRD_LINE_NO, LABEL_2,
                                    DATE_RECEPTION, DATE_ECHEANCE)
        VALUES (s.centre, s.nif, s.rs, a, a * 1000, NULL, a,
                0, 0, 2, 125, 'Reductions',
                TO_DATE(TO_CHAR(a + 1) || '-03-31', 'YYYY-MM-DD'), TO_DATE(TO_CHAR(a + 1) || '-04-30', 'YYYY-MM-DD'));
      END IF;
    END LOOP;
  END LOOP;
END;
/

-- ---------------------------------------------------------------------
-- Assujettissements (TVA mensuelle et IR annuel, de 2024 à l'année en cours)
-- DELTA n'a plus d'obligation après 2024 (cessation).
-- ---------------------------------------------------------------------
INSERT INTO assujettissement (NIF, REGIST_NAME, ANNEE, DEBUT, FIN, PERIODICITE, DATE_ASSUJ, ABREV, ACTIF, EXONERE)
SELECT s.nif, s.rs, a.annee,
       TO_DATE(TO_CHAR(a.annee) || '-01-01', 'YYYY-MM-DD'),
       TO_DATE(TO_CHAR(a.annee) || '-12-31', 'YYYY-MM-DD'),
       i.periodicite, DATE '2024-01-01', i.abrev, 1, 0
FROM (
  SELECT '1000000001' AS nif, 'SOCIETE ALPHA SARL' AS rs, 0 AS cesse FROM dual
  UNION ALL SELECT '1000000002', 'SOCIETE BETA SA', 0 FROM dual
  UNION ALL SELECT '1000000003', 'ETABLISSEMENT GAMMA', 0 FROM dual
  UNION ALL SELECT '1000000004', 'SOCIETE DELTA', 1 FROM dual
) s
CROSS JOIN (
  SELECT 'TVA' AS abrev, 1 AS periodicite FROM dual
  UNION ALL SELECT 'IR', 5 FROM dual
) i
CROSS JOIN (
  SELECT 2023 + LEVEL AS annee
    FROM dual
  CONNECT BY LEVEL <= EXTRACT(YEAR FROM SYSDATE) - 2023
) a
WHERE NOT (s.cesse = 1 AND a.annee > 2024);

-- ---------------------------------------------------------------------
-- Recoupement : déduit des déclarations TVA (cohérent pour tous les exercices)
-- ---------------------------------------------------------------------
INSERT INTO a_tab_recoupement (NIF, ANNEE, PRESTATIONS_DE_SERVICE, ACHATS_EFFECTUES, ACHATS_CONSOMMES,
                              PRODUITS_LOCAUX_VENDUS, EXPORTATIONS, IMPORTATIONS, MONTANT_TOTAL,
                              TVA_DED_ACHAT, TVA_DED_IMP, MARCHER_PUBLIC)
SELECT nif, annee,
       ROUND(ca * 0.50, 2), ROUND(ca * 0.40, 2), ROUND(ca * 0.35, 2), ROUND(ca * 0.30, 2),
       0, CASE WHEN nif = '1000000003' THEN ROUND(ca * 0.20, 2) ELSE 0 END,
       ca, ROUND(ca * 0.10, 2), CASE WHEN nif = '1000000003' THEN ROUND(ca * 0.05, 2) ELSE 0 END, 0
FROM (
  SELECT FISCAL_NO AS nif, ANNEE AS annee, SUM(MONTANT_SOUMIS) AS ca
    FROM declaration_tva
   WHERE IRD_LINE_NO = 170
   GROUP BY FISCAL_NO, ANNEE
);

-- ---------------------------------------------------------------------
-- DAU : importations IM4 (GAMMA) et exportations EX1 (ALPHA)
-- ---------------------------------------------------------------------
INSERT INTO a_tab_dau (NIF, ANNEE, MODELE, TVA, VALEUR_CAF)
SELECT FISCAL_NO, ANNEE, 'IM4', ROUND(SUM(MONTANT_SOUMIS) * 0.04, 2), ROUND(SUM(MONTANT_SOUMIS) * 0.20, 2)
FROM declaration_tva
WHERE IRD_LINE_NO = 170 AND FISCAL_NO = '1000000003'
GROUP BY FISCAL_NO, ANNEE;

INSERT INTO a_tab_dau (NIF, ANNEE, MODELE, TVA, VALEUR_CAF)
SELECT FISCAL_NO, ANNEE, 'EX1', NULL, ROUND(SUM(MONTANT_SOUMIS) * 0.10, 2)
FROM declaration_tva
WHERE IRD_LINE_NO = 170 AND FISCAL_NO = '1000000001'
GROUP BY FISCAL_NO, ANNEE;

COMMIT;

-- Table des agents : laissée vide, elle est alimentée depuis les comptes de
-- l'application par :  php artisan dgi:agents-source
PROMPT Donnees de demonstration inserees (2024 a annee en cours).
