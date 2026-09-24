-- ---------------------------------------------------------------------
-- Coupure d'Internet a distance (le poste reste joignable sur le LAN)
--
-- Ajoute :
--   1. les colonnes d'etat de blocage sur `agents`
--   2. les nouveaux types d'evenements dans l'ENUM `events.type`
--
-- A executer une seule fois sur une base deja installee :
--   mysql -u <user> -p -h 127.0.0.1 --port=3307 intranet_monitor < migration_internet_block.sql
-- ---------------------------------------------------------------------

-- 1. Etat de blocage par poste ----------------------------------------
--    internet_blocked        : etat demande par le serveur (consigne)
--    internet_block_applied  : etat reellement confirme par l'agent
--    Les deux sont distincts : tant que l'agent n'a pas rappele, la
--    consigne est "en attente" et l'interface doit le montrer.
ALTER TABLE `agents`
    ADD COLUMN `internet_blocked` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Consigne serveur : 1 = Internet doit etre coupe' AFTER `is_active`,
    ADD COLUMN `internet_block_applied` TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Etat confirme par l agent lors de son dernier rapport' AFTER `internet_blocked`,
    ADD COLUMN `internet_block_at` DATETIME NULL DEFAULT NULL
        COMMENT 'Date de la derniere demande de changement' AFTER `internet_block_applied`,
    ADD COLUMN `internet_block_by` VARCHAR(64) NULL DEFAULT NULL
        COMMENT 'Utilisateur dashboard a l origine de la demande' AFTER `internet_block_at`;

-- 2. Nouveaux types d'evenements --------------------------------------
--    L'ENUM est strict : sans cette extension, EventModel::log() echoue.
ALTER TABLE `events`
    MODIFY COLUMN `type` ENUM(
        'AGENT_ONLINE','AGENT_OFFLINE',
        'WIFI_CONNECTED','WIFI_DISCONNECTED',
        'INTERNET_ON','INTERNET_OFF',
        'INTERFACE_ADDED','INTERFACE_REMOVED',
        'IP_CHANGED','GATEWAY_CHANGED',
        'NETWORK_ANOMALY_DETECTED','GHOST_DEVICE_DETECTED',
        'INTERNET_BLOCK_REQUESTED','INTERNET_UNBLOCK_REQUESTED',
        'INTERNET_BLOCK_APPLIED','INTERNET_BLOCK_RELEASED'
    ) NOT NULL;
