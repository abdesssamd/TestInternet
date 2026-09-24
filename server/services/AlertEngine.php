<?php
/**
 * Centralise la creation d'alertes a partir des evenements detectes
 * lors du traitement d'un rapport agent, et declenche les notifications
 * email associees (via Notifier, qui gere lui-meme l'activation/seuil/throttle).
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/Alert.php';
require_once __DIR__ . '/Notifier.php';

final class AlertEngine
{
    public static function dualConnection(int $agentId, string $hostname, array $activeAdapters): void
    {
        if (AlertModel::existsRecent($agentId, 'DOUBLE_CONNEXION', 300)) {
            return;
        }
        $summary = implode(', ', array_map(
            static fn(array $a) => sprintf('%s (%s)', $a['name'], $a['ipv4']),
            $activeAdapters
        ));
        $message = "$hostname possede plusieurs interfaces reseau actives avec Internet : $summary";
        $id = AlertModel::create($agentId, 'DOUBLE_CONNEXION', 'critical', $message, ['adapters' => $activeAdapters]);
        Notifier::notifyAlert($id, $agentId, 'DOUBLE_CONNEXION', 'critical', $message);
    }

    public static function wifiActivated(int $agentId, string $hostname): void
    {
        if (AlertModel::existsRecent($agentId, 'WIFI_ACTIVATED', 300)) {
            return;
        }
        $message = "$hostname vient d'activer le Wi-Fi";
        $id = AlertModel::create($agentId, 'WIFI_ACTIVATED', 'warning', $message);
        Notifier::notifyAlert($id, $agentId, 'WIFI_ACTIVATED', 'warning', $message);
    }

    public static function internetDetected(int $agentId, string $hostname): void
    {
        if (AlertModel::existsRecent($agentId, 'INTERNET_DETECTED', 300)) {
            return;
        }
        $message = "$hostname possede desormais un acces Internet";
        $id = AlertModel::create($agentId, 'INTERNET_DETECTED', 'info', $message);
        Notifier::notifyAlert($id, $agentId, 'INTERNET_DETECTED', 'info', $message);
    }

    public static function agentUnreachable(int $agentId, string $hostname): void
    {
        if (AlertModel::existsRecent($agentId, 'AGENT_UNREACHABLE', 300)) {
            return;
        }
        $message = "$hostname ne repond plus";
        $id = AlertModel::create($agentId, 'AGENT_UNREACHABLE', 'critical', $message);
        Notifier::notifyAlert($id, $agentId, 'AGENT_UNREACHABLE', 'critical', $message);
    }

    /**
     * Anomalie reseau : MAC inattendue, IP hors sous-reseau attendu, ou SSID hors liste blanche.
     */
    public static function networkAnomaly(int $agentId, string $hostname, string $severity, string $message, array $details = []): void
    {
        if (AlertModel::existsRecent($agentId, 'NETWORK_ANOMALY', 300)) {
            return;
        }
        $fullMessage = "$hostname : $message";
        $id = AlertModel::create($agentId, 'NETWORK_ANOMALY', $severity, $fullMessage, $details ?: null);
        Notifier::notifyAlert($id, $agentId, 'NETWORK_ANOMALY', $severity, $fullMessage);
    }

    /**
     * Appareil non gere detecte sur le LAN (MAC inconnue vue par un agent).
     */
    public static function ghostDevice(int $agentId, string $hostname, string $mac, ?string $ip): void
    {
        if (AlertModel::existsRecent($agentId, 'GHOST_DEVICE', 300)) {
            return;
        }
        $message = "$hostname a detecte un appareil non gere sur le LAN : $mac" . ($ip ? " ($ip)" : '');
        $id = AlertModel::create($agentId, 'GHOST_DEVICE', 'warning', $message, ['mac' => $mac, 'ip' => $ip]);
        Notifier::notifyAlert($id, $agentId, 'GHOST_DEVICE', 'warning', $message);
    }

    /**
     * Contournement de la coupure Internet : le poste declare appliquer le
     * blocage, mais ses propres tests de connectivite reussissent. Signe d'un
     * agent altere ou de regles de pare-feu supprimees localement.
     * Severite critical : c'est la supervision elle-meme qui n'est plus fiable.
     */
    public static function internetBlockBypassed(int $agentId, string $hostname, array $details = []): void
    {
        if (AlertModel::existsRecent($agentId, 'INTERNET_BLOCK_BYPASSED', 300)) {
            return;
        }
        $message = "$hostname : coupure Internet contournee (le poste accede toujours a Internet)";
        $id = AlertModel::create($agentId, 'INTERNET_BLOCK_BYPASSED', 'critical', $message, $details ?: null);
        Notifier::notifyAlert($id, $agentId, 'INTERNET_BLOCK_BYPASSED', 'critical', $message);
    }
}
