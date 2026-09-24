<?php
/**
 * Decide si une alerte doit declencher une notification email, en respectant
 * l'activation globale, le seuil de severite minimal et un throttle par (agent, type).
 */

declare(strict_types=1);

require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/Alert.php';
require_once __DIR__ . '/Mailer.php';

final class Notifier
{
    private const SEVERITY_RANK = ['info' => 1, 'warning' => 2, 'critical' => 3];

    public static function notifyAlert(int $alertId, int $agentId, string $type, string $severity, string $message): void
    {
        if (!SettingModel::getBool('smtp_enabled', false)) {
            return;
        }

        $minSeverity = SettingModel::get('notify_min_severity', 'critical') ?? 'critical';
        if (!self::meetsSeverity($severity, $minSeverity)) {
            return;
        }

        $throttleSeconds = SettingModel::getInt('notify_throttle_seconds', 1800);
        if (AlertModel::recentlyNotified($agentId, $type, $throttleSeconds)) {
            return;
        }

        $to = SettingModel::get('smtp_to_emails', '') ?? '';
        if (trim($to) === '') {
            return;
        }

        $subject = '[' . APP_NAME . '] ' . strtoupper($severity) . ' : ' . $type;
        $sent = Mailer::send($to, $subject, $message);

        if ($sent) {
            AlertModel::markNotified($alertId);
        }
    }

    private static function meetsSeverity(string $severity, string $minSeverity): bool
    {
        $level = self::SEVERITY_RANK[$severity] ?? 0;
        $minLevel = self::SEVERITY_RANK[$minSeverity] ?? self::SEVERITY_RANK['critical'];
        return $level >= $minLevel;
    }
}
