<?php
/**
 * Configuration centrale de l'application.
 * Modifier les valeurs ci-dessous selon l'environnement.
 */

declare(strict_types=1);

// --- Base de donnees ---
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3307');
define('DB_NAME', 'intranet_monitor');
define('DB_USER', 'user_paie');
define('DB_PASS', 'abdessamadeKH');
define('DB_CHARSET', 'utf8mb4');

// --- Application ---
define('APP_NAME', 'Intranet Monitor Pro');
define('APP_TIMEZONE', 'Europe/Paris');
define('APP_BASE_PATH', '/MONITOR/server'); // chemin relatif depuis la racine web (htdocs)

// --- Securite session ---
define('SESSION_LIFETIME_SECONDS', 1800); // 30 min d'inactivite -> deconnexion
define('SESSION_NAME', 'intranet_monitor_sid');

// --- API agents ---
define('API_RATE_LIMIT_SECONDS', 20);     // min interval entre deux reports d'un meme agent
define('API_MAX_PAYLOAD_BYTES', 65536);   // 64 Ko, largement suffisant pour un rapport JSON
define('OFFLINE_THRESHOLD_SECONDS', 180); // au dela, un agent est considere hors-ligne

// --- Chiffrement des secrets stockes en base (ex: mot de passe SMTP) ---
// Cle hexadecimale de 32 octets (64 caracteres hex). A generer une seule fois avec :
//   php -r "echo bin2hex(random_bytes(32));"
// Meme niveau de confiance que DB_PASS ci-dessus : ne jamais exposer ce fichier au web.
// Si cette cle est perdue/changee, les secrets deja chiffres en base ne seront plus dechiffrables
// (il suffit de les ressaisir depuis la page Reglages).
define('SETTINGS_ENC_KEY', '87921258ea2cec679922bf7b24f6252b9b8466a48599b23894e9d1ba15ba1330');

date_default_timezone_set(APP_TIMEZONE);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // ne jamais afficher les erreurs au client en prod
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../logs_php_errors.log');
