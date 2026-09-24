<?php
/**
 * GET  /api/settings.php  -> reglages non sensibles + liste des SSID connus
 * POST /api/settings.php  actions :
 *   set_setting {key, value}      -> ecrit un reglage generique (jamais smtp_password_enc)
 *   set_smtp    {host,port,encryption,username,password,from_email,from_name,to_emails,min_severity,throttle_seconds,enabled}
 *   test_smtp   {}                -> envoie un email de test avec la config actuelle
 *   add_ssid    {ssid}
 *   remove_ssid {id}
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';
require_once __DIR__ . '/../models/Setting.php';
require_once __DIR__ . '/../models/KnownSsid.php';
require_once __DIR__ . '/../services/Mailer.php';

Session::start();
Session::requireAuthApi();

header('Content-Type: application/json; charset=utf-8');

// Cles jamais exposees en lecture (secrets)
const HIDDEN_KEYS = ['smtp_password_enc'];

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $settings = SettingModel::all();
    foreach (HIDDEN_KEYS as $k) {
        unset($settings[$k]);
    }
    $settings['smtp_password_set'] = !empty(SettingModel::get('smtp_password_enc', ''));

    echo json_encode([
        'settings'    => $settings,
        'known_ssids' => KnownSsidModel::listWithIds(),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode((string) $raw, true) ?: [];

    $csrfToken = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    if (!Csrf::verify($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Jeton CSRF invalide']);
        exit;
    }

    $action = $payload['action'] ?? '';

    $allowedSettingKeys = [
        'anomaly_detection_enabled', 'anomaly_default_cidr',
        'ghost_scan_enabled', 'ghost_scan_min_interval_seconds',
    ];

    if ($action === 'set_setting') {
        $key = (string) ($payload['key'] ?? '');
        $value = (string) ($payload['value'] ?? '');
        if (!in_array($key, $allowedSettingKeys, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Reglage non autorise']);
            exit;
        }
        SettingModel::set($key, substr($value, 0, 255));
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($action === 'set_smtp') {
        SettingModel::set('smtp_enabled', !empty($payload['enabled']) ? '1' : '0');
        SettingModel::set('smtp_host', substr((string) ($payload['host'] ?? ''), 0, 255));
        SettingModel::set('smtp_port', (string) (int) ($payload['port'] ?? 587));
        $encryption = in_array($payload['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $payload['encryption'] : 'tls';
        SettingModel::set('smtp_encryption', $encryption);
        SettingModel::set('smtp_username', substr((string) ($payload['username'] ?? ''), 0, 255));
        SettingModel::set('smtp_from_email', substr((string) ($payload['from_email'] ?? ''), 0, 255));
        SettingModel::set('smtp_from_name', substr((string) ($payload['from_name'] ?? 'Intranet Monitor Pro'), 0, 255));
        SettingModel::set('smtp_to_emails', substr((string) ($payload['to_emails'] ?? ''), 0, 255));
        $minSeverity = in_array($payload['min_severity'] ?? '', ['info', 'warning', 'critical'], true) ? $payload['min_severity'] : 'critical';
        SettingModel::set('notify_min_severity', $minSeverity);
        SettingModel::set('notify_throttle_seconds', (string) max(60, (int) ($payload['throttle_seconds'] ?? 1800)));

        if (!empty($payload['password'])) {
            SettingModel::set('smtp_password_enc', Mailer::encryptSecret((string) $payload['password']));
        }

        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($action === 'test_smtp') {
        $to = SettingModel::get('smtp_to_emails', '');
        if (!$to) {
            echo json_encode(['status' => 'error', 'message' => 'Aucun destinataire configure']);
            exit;
        }
        $ok = Mailer::send($to, '[Intranet Monitor Pro] Email de test', 'Ceci est un email de test envoye depuis la page Reglages. Si vous le recevez, la configuration SMTP fonctionne.');
        echo json_encode(['status' => $ok ? 'ok' : 'error', 'message' => $ok ? 'Email envoye avec succes.' : 'Echec de l\'envoi, voir les logs serveur.']);
        exit;
    }

    if ($action === 'add_ssid') {
        $ssid = trim((string) ($payload['ssid'] ?? ''));
        if ($ssid === '' || strlen($ssid) > 128) {
            http_response_code(400);
            echo json_encode(['error' => 'SSID invalide']);
            exit;
        }
        KnownSsidModel::add($ssid);
        echo json_encode(['status' => 'ok']);
        exit;
    }

    if ($action === 'remove_ssid') {
        $id = (int) ($payload['id'] ?? 0);
        if ($id > 0) {
            KnownSsidModel::remove($id);
        }
        echo json_encode(['status' => 'ok']);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Action inconnue']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Methode non autorisee']);
