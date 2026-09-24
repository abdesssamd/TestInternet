-- ---------------------------------------------------------------------
-- Detection de contournement de la coupure Internet
--
-- Ajoute le type d'alerte `INTERNET_BLOCK_BYPASSED`, leve quand un poste
-- declare appliquer la coupure alors que ses propres tests montrent qu'il
-- accede toujours a Internet (agent altere, regles pare-feu supprimees...).
--
-- A executer une seule fois sur une base deja installee :
--   mysql -u <utilisateur> -p -h 127.0.0.1 --port=3307 intranet_monitor < migration_block_tamper.sql
-- ---------------------------------------------------------------------

ALTER TABLE `alerts`
    MODIFY COLUMN `type` ENUM(
        'DOUBLE_CONNEXION','WIFI_ACTIVATED','INTERNET_DETECTED',
        'AGENT_UNREACHABLE','INTERFACE_ANOMALY',
        'NETWORK_ANOMALY','GHOST_DEVICE',
        'INTERNET_BLOCK_BYPASSED'
    ) NOT NULL;

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
        'INTERNET_BLOCK_BYPASSED'
    ) NOT NULL;
