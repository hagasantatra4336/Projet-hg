-- =====================================================================
-- 01 — Utilisateurs du serveur Oracle de test
-- À exécuter en SYS sur la base enfichable XEPDB1 (Oracle XE 21c) :
--   sqlplus sys/MOT_DE_PASSE@//localhost:1521/XEPDB1 as sysdba
--   SQL> @01_utilisateurs.sql
--
-- Deux comptes, comme en production :
--   EKAJY   : propriétaire des tables source (la DGI / E-Kajy)
--   DGI_RO  : compte LECTURE SEULE utilisé par l'application Laravel
--             (=> SOURCE_DB_SCHEMA=EKAJY dans le .env)
-- =====================================================================

ALTER SESSION SET "_ORACLE_SCRIPT" = true;

-- Propriétaire des données
CREATE USER EKAJY IDENTIFIED BY "Ekajy2026#"
  DEFAULT TABLESPACE users
  QUOTA UNLIMITED ON users;

GRANT CREATE SESSION, CREATE TABLE, CREATE VIEW TO EKAJY;

-- Compte de lecture de l'application
CREATE USER DGI_RO IDENTIFIED BY "DgiRo2026#"
  DEFAULT TABLESPACE users;

GRANT CREATE SESSION TO DGI_RO;

PROMPT Utilisateurs EKAJY (proprietaire) et DGI_RO (lecture seule) crees.
