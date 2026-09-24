<?php
/**
 * install.php — Assistant d'installation du serveur Intranet Monitor Pro.
 *
 * Une seule page, trois etapes :
 *   1. Verification des prerequis (PHP, extensions, droits d'ecriture)
 *   2. Saisie base de donnees + compte administrateur
 *   3. Creation du schema, du compte admin et de config/config.php
 *
 * Securite : la page se verrouille d'elle-meme une fois l'installation
 * terminee (presence de config/config.php avec une base joignable). Le
 * fichier doit ensuite etre supprime — un rappel est affiche.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

const CONFIG_PATH = __DIR__ . '/server/config/config.php';
const SCHEMA_PATH = __DIR__ . '/database/schema.sql';

// ---------------------------------------------------------------------
// Verrou : si l'appli est deja installee, on refuse de rejouer l'assistant
// ---------------------------------------------------------------------
function alreadyInstalled(): bool
{
    if (!file_exists(CONFIG_PATH)) {
        return false;
    }
    // Un config.php peut exister sans base creee : on verifie la table users.
    try {
        require_once CONFIG_PATH;
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        return (bool) $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

// ---------------------------------------------------------------------
// Etape 1 : prerequis
// ---------------------------------------------------------------------
function checkRequirements(): array
{
    $configDir = dirname(CONFIG_PATH);
    return [
        ['PHP 8.0 ou superieur', PHP_VERSION_ID >= 80000, PHP_VERSION],
        ['Extension PDO MySQL', extension_loaded('pdo_mysql'), extension_loaded('pdo_mysql') ? 'chargee' : 'manquante'],
        ['Extension OpenSSL (chiffrement des secrets)', extension_loaded('openssl'), extension_loaded('openssl') ? 'chargee' : 'manquante'],
        ['Extension mbstring', extension_loaded('mbstring'), extension_loaded('mbstring') ? 'chargee' : 'manquante'],
        ['Dossier config accessible en ecriture', is_writable($configDir), $configDir],
        ['Fichier database/schema.sql present', file_exists(SCHEMA_PATH), SCHEMA_PATH],
    ];
}

$requirements = checkRequirements();
$allOk = true;
foreach ($requirements as $r) {
    if (!$r[1]) {
        $allOk = false;
    }
}

$installed = alreadyInstalled();
$errors = [];
$done = false;

// ---------------------------------------------------------------------
// Traitement du formulaire
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installed && $allOk) {
    $dbHost   = trim((string) ($_POST['db_host'] ?? '127.0.0.1'));
    $dbPort   = trim((string) ($_POST['db_port'] ?? '3306'));
    $dbName   = trim((string) ($_POST['db_name'] ?? 'intranet_monitor'));
    $dbUser   = trim((string) ($_POST['db_user'] ?? 'root'));
    $dbPass   = (string) ($_POST['db_pass'] ?? '');
    $adminUser = trim((string) ($_POST['admin_user'] ?? 'admin'));
    $adminPass = (string) ($_POST['admin_pass'] ?? '');
    $adminPass2 = (string) ($_POST['admin_pass2'] ?? '');
    $basePath = rtrim(trim((string) ($_POST['base_path'] ?? '/MONITOR/server')), '/');
    $timezone = trim((string) ($_POST['timezone'] ?? 'Europe/Paris'));

    if ($adminUser === '' || strlen($adminUser) > 64) {
        $errors[] = "L'identifiant administrateur est invalide.";
    }
    if (strlen($adminPass) < 10) {
        $errors[] = 'Le mot de passe administrateur doit contenir au moins 10 caracteres.';
    }
    if ($adminPass !== $adminPass2) {
        $errors[] = 'Les deux mots de passe administrateur ne correspondent pas.';
    }
    if (!in_array($timezone, timezone_identifiers_list(), true)) {
        $errors[] = 'Fuseau horaire inconnu.';
    }

    $pdo = null;
    if (!$errors) {
        // Connexion sans nom de base : on doit pouvoir creer la base.
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $dbHost, $dbPort),
                $dbUser,
                $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $errors[] = 'Connexion MySQL impossible : ' . $e->getMessage();
        }
    }

    if (!$errors && $pdo) {
        try {
            // Le nom de base ne peut pas etre un parametre prepare : on le
            // filtre strictement avant de l'injecter dans le CREATE DATABASE.
            if (!preg_match('/^[A-Za-z0-9_]+$/', $dbName)) {
                throw new RuntimeException('Nom de base invalide (lettres, chiffres et _ uniquement).');
            }
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `$dbName`");

            // --- Schema ---
            $sql = (string) file_get_contents(SCHEMA_PATH);
            $sql = str_replace("\r\n", "\n", $sql);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt !== '') {
                    $pdo->exec($stmt);
                }
            }

            // --- Migrations postérieures au schema initial ---
            foreach (glob(__DIR__ . '/database/migration_*.sql') ?: [] as $file) {
                $m = str_replace("\r\n", "\n", (string) file_get_contents($file));
                $m = preg_replace('/^\s*--.*$/m', '', $m) ?? $m;
                foreach (array_filter(array_map('trim', explode(';', $m))) as $stmt) {
                    if ($stmt === '') {
                        continue;
                    }
                    try {
                        $pdo->exec($stmt);
                    } catch (PDOException $e) {
                        // 1060/1061 : colonne ou index deja present (schema deja a jour).
                        if (!in_array((int) ($e->errorInfo[1] ?? 0), [1060, 1061], true)) {
                            throw $e;
                        }
                    }
                }
            }

            // --- Compte administrateur ---
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare(
                'INSERT INTO users (username, password_hash, role, is_active, created_at)
                 VALUES (:u, :h, \'admin\', 1, NOW())
                 ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), is_active = 1'
            );
            $stmt->execute(['u' => $adminUser, 'h' => $hash]);

            // --- config.php ---
            $encKey = bin2hex(random_bytes(32));
            $tpl = <<<'PHPTPL'
<?php
/**
 * Configuration centrale de l'application.
 * Genere par install.php le {GENERATED_AT}.
 */

declare(strict_types=1);

// --- Base de donnees ---
define('DB_HOST', '{DB_HOST}');
define('DB_PORT', '{DB_PORT}');
define('DB_NAME', '{DB_NAME}');
define('DB_USER', '{DB_USER}');
define('DB_PASS', '{DB_PASS}');
define('DB_CHARSET', 'utf8mb4');

// --- Application ---
define('APP_NAME', 'Intranet Monitor Pro');
define('APP_TIMEZONE', '{TIMEZONE}');
define('APP_BASE_PATH', '{BASE_PATH}'); // chemin relatif depuis la racine web

// --- Securite session ---
define('SESSION_LIFETIME_SECONDS', 1800);
define('SESSION_NAME', 'intranet_monitor_sid');

// --- API agents ---
define('API_RATE_LIMIT_SECONDS', 20);
define('API_MAX_PAYLOAD_BYTES', 65536);
define('OFFLINE_THRESHOLD_SECONDS', 180);

// --- Chiffrement des secrets stockes en base (ex: mot de passe SMTP) ---
// Si cette cle change, les secrets deja chiffres devront etre ressaisis.
define('SETTINGS_ENC_KEY', '{ENC_KEY}');

date_default_timezone_set(APP_TIMEZONE);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/../../logs_php_errors.log');
PHPTPL;

            $config = strtr($tpl, [
                '{GENERATED_AT}' => date('Y-m-d H:i:s'),
                '{DB_HOST}'      => addslashes($dbHost),
                '{DB_PORT}'      => addslashes($dbPort),
                '{DB_NAME}'      => addslashes($dbName),
                '{DB_USER}'      => addslashes($dbUser),
                '{DB_PASS}'      => addslashes($dbPass),
                '{TIMEZONE}'     => addslashes($timezone),
                '{BASE_PATH}'    => addslashes($basePath),
                '{ENC_KEY}'      => $encKey,
            ]);

            if (file_put_contents(CONFIG_PATH, $config) === false) {
                throw new RuntimeException('Impossible d\'ecrire ' . CONFIG_PATH);
            }

            $done = true;
        } catch (Throwable $e) {
            $errors[] = 'Installation interrompue : ' . $e->getMessage();
        }
    }
}

$v = static fn(string $k, string $d = ''): string
    => htmlspecialchars((string) ($_POST[$k] ?? $d), ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installation — Intranet Monitor Pro</title>
<style>
  :root{
    --ink:#1b2130; --muted:#6b7280; --line:#e3e5ea;
    --accent:#2563eb; --ok:#0f8f4a; --err:#c62828; --bg:#f4f6f9;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);
       font-family:"Segoe UI",system-ui,sans-serif;font-size:15px;line-height:1.55}
  .wrap{max-width:720px;margin:0 auto;padding:32px 20px 64px}
  header{display:flex;align-items:center;gap:12px;margin-bottom:6px}
  header .dot{width:34px;height:34px;border-radius:8px;background:var(--accent);
              display:grid;place-items:center;color:#fff;font-weight:700}
  h1{font-size:22px;margin:0}
  .sub{color:var(--muted);font-size:14px;margin:0 0 24px}
  .card{background:#fff;border:1px solid var(--line);border-radius:10px;
        padding:22px;margin-bottom:18px}
  h2{font-size:15px;margin:0 0 14px;text-transform:uppercase;
     letter-spacing:.08em;color:var(--muted)}
  table.req{width:100%;border-collapse:collapse}
  table.req td{padding:7px 0;border-bottom:1px solid var(--line);vertical-align:top}
  table.req td:last-child{text-align:right;color:var(--muted);font-size:13px}
  .yes{color:var(--ok);font-weight:700}
  .no{color:var(--err);font-weight:700}
  label{display:block;font-size:13px;color:var(--muted);margin:12px 0 4px}
  input{width:100%;padding:9px 11px;border:1px solid var(--line);
        border-radius:7px;font-size:14px;font-family:inherit}
  input:focus{outline:2px solid var(--accent);outline-offset:-1px;border-color:var(--accent)}
  .row{display:flex;gap:14px}.row>div{flex:1}
  button{background:var(--accent);color:#fff;border:0;border-radius:7px;
         padding:11px 20px;font-size:15px;font-weight:600;cursor:pointer;margin-top:20px}
  button:disabled{background:#9aa3b2;cursor:not-allowed}
  .msg{padding:12px 14px;border-radius:8px;margin-bottom:14px;font-size:14px}
  .msg.err{background:#fdecea;border:1px solid #f5c6c2;color:var(--err)}
  .msg.ok{background:#e7f6ed;border:1px solid #b7e0c6;color:var(--ok)}
  .msg.warn{background:#fff6e5;border:1px solid #f0d9a8;color:#8a5a00}
  code{background:#eef1f5;padding:2px 6px;border-radius:4px;font-size:13px}
  .hint{font-size:12.5px;color:var(--muted);margin-top:4px}
  a.btn{display:inline-block;margin-top:16px;background:var(--accent);color:#fff;
        text-decoration:none;padding:11px 20px;border-radius:7px;font-weight:600}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <span class="dot">IM</span>
    <h1>Intranet Monitor Pro</h1>
  </header>
  <p class="sub">Assistant d'installation du serveur</p>

<?php if ($installed): ?>

  <div class="card">
    <div class="msg warn">
      <strong>L'application est deja installee.</strong><br>
      L'assistant est desactive pour ne pas ecraser la configuration existante.
    </div>
    <p style="font-size:14px">
      Pour reinstaller : supprimez <code>server/config/config.php</code>, puis rechargez cette page.
    </p>
    <a class="btn" href="server/dashboard/login.php">Aller au dashboard</a>
  </div>

<?php elseif ($done): ?>

  <div class="card">
    <div class="msg ok"><strong>Installation terminee.</strong></div>
    <p style="font-size:14px">
      La base a ete creee, le compte administrateur enregistre, et
      <code>server/config/config.php</code> genere.
    </p>
    <div class="msg warn">
      <strong>A faire maintenant :</strong> supprimez le fichier
      <code>install.php</code> a la racine du projet. Tant qu'il est present,
      quiconque y accede peut voir cette page.
    </div>
    <a class="btn" href="server/dashboard/login.php">Se connecter au dashboard</a>
  </div>

<?php else: ?>

  <div class="card">
    <h2>1. Prerequis</h2>
    <table class="req">
      <?php foreach ($requirements as [$label, $ok, $detail]): ?>
        <tr>
          <td><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
          <td><span class="<?= $ok ? 'yes' : 'no' ?>"><?= $ok ? '✓' : '✕' ?></span></td>
          <td><?= htmlspecialchars((string) $detail, ENT_QUOTES) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    <?php if (!$allOk): ?>
      <div class="msg err" style="margin-top:14px">
        Corrigez les points marques ✕ avant de continuer.
      </div>
    <?php endif; ?>
  </div>

  <?php foreach ($errors as $e): ?>
    <div class="msg err"><?= htmlspecialchars($e, ENT_QUOTES) ?></div>
  <?php endforeach; ?>

  <form method="post">
    <div class="card">
      <h2>2. Base de donnees</h2>
      <div class="row">
        <div>
          <label for="db_host">Hote MySQL</label>
          <input id="db_host" name="db_host" value="<?= $v('db_host', '127.0.0.1') ?>" required>
        </div>
        <div>
          <label for="db_port">Port</label>
          <input id="db_port" name="db_port" value="<?= $v('db_port', '3306') ?>" required>
          <div class="hint">XAMPP utilise souvent 3306, parfois 3307.</div>
        </div>
      </div>
      <label for="db_name">Nom de la base</label>
      <input id="db_name" name="db_name" value="<?= $v('db_name', 'intranet_monitor') ?>" required>
      <div class="hint">Creee automatiquement si elle n'existe pas.</div>
      <div class="row">
        <div>
          <label for="db_user">Utilisateur MySQL</label>
          <input id="db_user" name="db_user" value="<?= $v('db_user', 'root') ?>" required>
        </div>
        <div>
          <label for="db_pass">Mot de passe MySQL</label>
          <input id="db_pass" name="db_pass" type="password" value="<?= $v('db_pass') ?>">
        </div>
      </div>
    </div>

    <div class="card">
      <h2>3. Compte administrateur</h2>
      <label for="admin_user">Identifiant</label>
      <input id="admin_user" name="admin_user" value="<?= $v('admin_user', 'admin') ?>" required>
      <div class="row">
        <div>
          <label for="admin_pass">Mot de passe</label>
          <input id="admin_pass" name="admin_pass" type="password" minlength="10" required>
          <div class="hint">10 caracteres minimum.</div>
        </div>
        <div>
          <label for="admin_pass2">Confirmation</label>
          <input id="admin_pass2" name="admin_pass2" type="password" minlength="10" required>
        </div>
      </div>
    </div>

    <div class="card">
      <h2>4. Application</h2>
      <div class="row">
        <div>
          <label for="base_path">Chemin web du serveur</label>
          <input id="base_path" name="base_path" value="<?= $v('base_path', '/MONITOR/server') ?>" required>
          <div class="hint">Chemin depuis la racine web (htdocs).</div>
        </div>
        <div>
          <label for="timezone">Fuseau horaire</label>
          <input id="timezone" name="timezone" value="<?= $v('timezone', 'Europe/Paris') ?>" required>
        </div>
      </div>
      <button type="submit" <?= $allOk ? '' : 'disabled' ?>>Installer</button>
    </div>
  </form>

<?php endif; ?>

</div>
</body>
</html>
