<?php
/**
 * Detecte les situations de double connexion reseau simultanee
 * (ex: Ethernet vers le LAN entreprise + Wi-Fi ou 4G vers Internet).
 */

declare(strict_types=1);

final class DualConnectionDetector
{
    /**
     * @param array<int,array<string,mixed>> $adapters interfaces actives (status = UP, ipv4 non nul)
     * @return array{isDual: bool, activeAdapters: array}
     */
    public static function analyze(array $adapters, bool $internetStatus): array
    {
        $activeWithIp = array_values(array_filter($adapters, static function (array $a): bool {
            return ($a['status'] ?? '') === 'UP' && !empty($a['ipv4']);
        }));

        $isDual = count($activeWithIp) >= 2 && $internetStatus === true;

        return [
            'isDual'         => $isDual,
            'activeAdapters' => $activeWithIp,
        ];
    }
}
