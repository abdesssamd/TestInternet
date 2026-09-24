<?php
/**
 * Compare l'etat reseau rapporte par un agent a sa baseline attendue
 * (MAC, sous-reseau IP, SSID Wi-Fi) et signale les ecarts suspects.
 * Logique pure, sans effet de bord (meme style que DualConnectionDetector).
 */

declare(strict_types=1);

final class AnomalyDetector
{
    /**
     * @param array|null   $baseline   ligne agent_baselines (ou null si aucune)
     * @param array        $reportData donnees sanitizees du rapport (primary_mac, primary_ip, wifi_active, wifi_ssid, ...)
     * @param string[]     $knownSsids liste blanche globale des SSID (vide = verification desactivee)
     * @param string|null  $globalCidr CIDR global par defaut si la baseline n'en definit pas
     * @return array{anomalies: array<int,array{type:string,severity:string,message:string,details:array}>}
     */
    public static function analyze(?array $baseline, array $reportData, array $knownSsids, ?string $globalCidr): array
    {
        $anomalies = [];

        $expectedMac = $baseline['expected_mac'] ?? null;
        $currentMac = $reportData['primary_mac'] ?? null;
        if ($expectedMac && $currentMac && strcasecmp($expectedMac, $currentMac) !== 0) {
            $anomalies[] = [
                'type'     => 'MAC_MISMATCH',
                'severity' => 'critical',
                'message'  => "adresse MAC inattendue ($currentMac au lieu de $expectedMac attendue) - usurpation possible",
                'details'  => ['expected_mac' => $expectedMac, 'current_mac' => $currentMac],
            ];
        }

        $cidr = $baseline['expected_cidr'] ?? $globalCidr;
        $currentIp = $reportData['primary_ip'] ?? null;
        if (!empty($cidr) && $currentIp && !self::cidrContains($currentIp, $cidr)) {
            $anomalies[] = [
                'type'     => 'IP_OUT_OF_SUBNET',
                'severity' => 'critical',
                'message'  => "IP hors du sous-reseau attendu ($currentIp n'est pas dans $cidr)",
                'details'  => ['expected_cidr' => $cidr, 'current_ip' => $currentIp],
            ];
        }

        $wifiActive = !empty($reportData['wifi_active']);
        $ssid = $reportData['wifi_ssid'] ?? null;
        if ($wifiActive && $ssid && !empty($knownSsids) && !in_array($ssid, $knownSsids, true)) {
            $anomalies[] = [
                'type'     => 'UNKNOWN_SSID',
                'severity' => 'warning',
                'message'  => "connexion a un reseau Wi-Fi non autorise ($ssid)",
                'details'  => ['ssid' => $ssid, 'known_ssids' => $knownSsids],
            ];
        }

        return ['anomalies' => $anomalies];
    }

    /**
     * Verifie si une IPv4 appartient a un bloc CIDR (ex: "192.168.1.0/24").
     * Implementation bitwise pure, sans dependance.
     */
    public static function cidrContains(string $ip, string $cidr): bool
    {
        $parts = explode('/', $cidr);
        if (count($parts) !== 2) {
            return true; // CIDR mal forme : ne pas bloquer sur une config invalide
        }
        [$subnet, $maskBits] = $parts;
        $maskBits = (int) $maskBits;
        if ($maskBits < 0 || $maskBits > 32) {
            return true;
        }

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return true; // IPv6 ou format invalide : pas de verification
        }

        $mask = $maskBits === 0 ? 0 : (~0 << (32 - $maskBits));
        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
