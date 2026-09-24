-- ---------------------------------------------------------------------
-- Auto-enregistrement des agents + etat de surveillance
--
-- Remplace le systeme de token pre-genere : un poste qui contacte le
-- serveur pour la premiere fois est cree automatiquement, et se trouve
-- SOUS SURVEILLANCE par defaut. Seul un administrateur peut ensuite le
-- passer hors surveillance depuis le dashboard.
--
-- A executer une seule fois :
--   mysql -u <user> -p -h 127.0.0.1 --port=3307 intranet_monitor < migration_autoenroll.sql
-- ---------------------------------------------------------------------

-- 1. Etat de surveillance -----------------------------------------------
--    1 (defaut) = le poste est supervise.
--    0          = mis hors surveillance explicitement par un administrateur.
ALTER TABLE `agents`
    ADD COLUMN `is_monitored` TINYINT(1) NOT NULL DEFAULT 1
        COMMENT '1 = sous surveillance (defaut), 0 = exclu par un administrateur' AFTER `is_active`,
    ADD COLUMN `monitoring_changed_at` DATETIME NULL DEFAULT NULL
        COMMENT 'Date du dernier changement d etat de surveillance' AFTER `is_monitored`,
    ADD COLUMN `monitoring_changed_by` VARCHAR(64) NULL DEFAULT NULL
        COMMENT 'Administrateur ayant change l etat de surveillance' AFTER `monitoring_changed_at`,
    ADD COLUMN `auto_enrolled` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = cree automatiquement au premier contact' AFTER `monitoring_changed_by`,
    ADD COLUMN `first_seen_ip` VARCHAR(45) NULL DEFAULT NULL
        COMMENT 'IP source lors de l auto-enregistrement' AFTER `auto_enrolled`;

-- 2. Le token n'est plus obligatoire ------------------------------------
--    Les postes auto-enregistres n'en ont pas. La colonne est conservee
--    pour les agents deja installes avec un token (compatibilite).
ALTER TABLE `agents`
    MODIFY COLUMN `token_hash` CHAR(64) NULL DEFAULT NULL,
    MODIFY COLUMN `token_hint` VARCHAR(12) NULL DEFAULT NULL;

-- 3. Nouveaux evenements -------------------------------------------------
ALTER TABLE `events`
    MODIFY COLUMN `type` ENUM(
        'AGENT_ONLINE','AGENT_OFFLINE',
        'WIFI_CONNECTED','WIFI_DISCONNECTED',
        'INTERNET_ON','INTERNET_OFF',
        'INTERFACE_ADDED','INTERFACE_REMOVED',
        'IP_CHANGED','GATEWAY_CHANGED',
        'NETWORK_ANOMALY_DETECTED','GHOST_DEVICE_DETECTED',
        'INTERNET_BLOCK_REQUESTED','INTERNET_UNBLOCK_REQUESTED',
        'INTERNET_BLOCK_APPLIED','INTERNET_BLOCK_RELEASED',
        'INTERNET_BLOCK_BYPASSED',
        'AGENT_AUTO_ENROLLED','MONITORING_ENABLED','MONITORING_DISABLED'
    ) NOT NULL;
