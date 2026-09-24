<?php
/**
 * migrate.php — applique toutes les migrations manquantes sur la base.
 *
 * A lancer apres une mise a jour du code, sur CHAQUE serveur :
 *     php database/migrate.php
 *
 * Le script est idempotent : une migration deja appliquee est ignoree
 * (colonne/index deja present). Il peut donc etre relance sans risque.
 */

declare(strict_types=1);

require_once __DIR__ . '/../server/config/config.php';
require_once __DIR__ . '/../server/config/database.php';

$pdo = Database::getConnection();

/**
 * Decoupe un script SQL en instructions.
 *
 * Un simple explode(';') casse des qu'un point-virgule apparait dans une
 * chaine (typiquement un COMMENT '...'), produisant des instructions
 * tronquees et une erreur de syntaxe. On ignore donc les ';' situes a
 * l'interieur des chaines quotees.
 *
 * @return string[]
 */
function splitSqlStatements(string $sql): array
{
    $statements = [];
    $current = '';
    $quote = null;
    $len = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($quote !== null) {
            $current .= $ch;
            if ($ch === '\\' && $i + 1 < $len) {   // echappement : on avale le suivant
                $current .= $sql[++$i];
            } elseif ($ch === $quote) {
                $quote = null;
            }
            continue;
        }

        if ($ch === "'" || $ch === '"' || $ch === '`') {
            $quote = $ch;
            $current .= $ch;
            continue;
        }

        if ($ch === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            continue;
        }

        $current .= $ch;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

$files = glob(__DIR__ . '/migration_*.sql') ?: [];
sort($files);

if (!$files) {
    echo "Aucun fichier migration_*.sql trouve.\n";
    exit(0);
}

echo "Base : " . DB_NAME . " sur " . DB_HOST . ":" . DB_PORT . "\n";
echo str_repeat('-', 60) . "\n";

$appliedTotal = 0;
$skippedTotal = 0;

foreach ($files as $file) {
    $name = basename($file);
    $sql = str_replace("\r\n", "\n", (string) file_get_contents($file));
    $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;

    $applied = 0;
    $skipped = 0;
    $failed = null;

    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') {
            continue;
        }
        try {
            $pdo->exec($stmt);
            $applied++;
        } catch (PDOException $e) {
            $code = (int) ($e->errorInfo[1] ?? 0);
            // 1060 : colonne deja presente / 1061 : index deja present
            // 1091 : element a supprimer inexistant
            if (in_array($code, [1060, 1061, 1091], true)) {
                $skipped++;
            } else {
                $failed = $e->getMessage();
                break;
            }
        }
    }

    $appliedTotal += $applied;
    $skippedTotal += $skipped;

    if ($failed !== null) {
        printf("  %-40s ECHEC\n", $name);
        echo "      " . substr($failed, 0, 120) . "\n";
    } elseif ($applied > 0) {
        printf("  %-40s applique (%d instruction%s)\n", $name, $applied, $applied > 1 ? 's' : '');
    } else {
        printf("  %-40s deja a jour\n", $name);
    }
}

echo str_repeat('-', 60) . "\n";
printf("%d instruction(s) appliquee(s), %d deja en place.\n", $appliedTotal, $skippedTotal);

// Controle final : les colonnes dont le code a besoin sont-elles la ?
$required = [
    'agents' => ['internet_blocked', 'internet_block_applied', 'is_monitored', 'auto_enrolled', 'first_seen_ip'],
];
$missing = [];
foreach ($required as $table => $columns) {
    foreach ($columns as $col) {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
        $stmt->execute([$col]);
        if (!$stmt->fetch()) {
            $missing[] = "$table.$col";
        }
    }
}

if ($missing) {
    echo "\nATTENTION - colonnes toujours manquantes :\n";
    foreach ($missing as $m) {
        echo "  - $m\n";
    }
    exit(1);
}

echo "Schema complet : l'API agent peut fonctionner.\n";
exit(0);
