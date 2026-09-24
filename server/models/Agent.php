<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

final class AgentModel
{
    public static function findByHostname(string $hostname): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM agents WHERE hostname = :h LIMIT 1');
        $stmt->execute(['h' => $hostname]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function findById(int $id): ?array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT * FROM agents WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Cree un nouvel agent et retourne [id, token_en_clair].
     */
    public static function create(string $hostname): array
    {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $tokenHint = substr($token, -6);

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO agents (hostname, token_hash, token_hint, is_active, created_at)
             VALUES (:hostname, :hash, :hint, 1, NOW())'
        );
        $stmt->execute([
            'hostname' => $hostname,
            'hash'     => $tokenHash,
            'hint'     => $tokenHint,
        ]);

        return [
            'id'    => (int) $pdo->lastInsertId(),
            'token' => $token,
        ];
    }

    /**
     * Cree un poste detecte automatiquement lors de son premier rapport.
     * Il est SOUS SURVEILLANCE par defaut : c'est l'etat sur lequel on veut
     * se tromper du bon cote. Un administrateur peut l'exclure ensuite.
     */
    public static function autoEnroll(string $hostname, ?string $ip): array
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'INSERT INTO agents (hostname, is_active, is_monitored, auto_enrolled, first_seen_ip, created_at)
             VALUES (:hostname, 1, 1, 1, :ip, NOW())'
        );
        $stmt->execute(['hostname' => $hostname, 'ip' => $ip]);

        return self::findById((int) $pdo->lastInsertId());
    }

    /**
     * Active ou retire la surveillance d'un poste (action administrateur).
     */
    public static function setMonitored(int $id, bool $monitored, string $byUsername): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE agents
                SET is_monitored          = :m,
                    monitoring_changed_at = NOW(),
                    monitoring_changed_by = :by
              WHERE id = :id'
        );
        $stmt->execute(['m' => $monitored ? 1 : 0, 'by' => $byUsername, 'id' => $id]);
    }

    public static function updateFromReport(int $agentId, array $data): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE agents SET
                os_name = :os_name,
                os_version = :os_version,
                `current_user` = :current_user,
                domain = :domain,
                primary_ip = :primary_ip,
                primary_mac = :primary_mac,
                gateway = :gateway,
                ethernet_active = :ethernet_active,
                wifi_active = :wifi_active,
                wifi_ssid = :wifi_ssid,
                internet_status = :internet_status,
                internet_test = :internet_test,
                internet_latency = :internet_latency,
                internet_checked_at = :internet_checked_at,
                agent_version = :agent_version,
                last_seen = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'os_name'             => $data['os_name'] ?? null,
            'os_version'          => $data['os_version'] ?? null,
            'current_user'        => $data['current_user'] ?? null,
            'domain'              => $data['domain'] ?? null,
            'primary_ip'          => $data['primary_ip'] ?? null,
            'primary_mac'         => $data['primary_mac'] ?? null,
            'gateway'             => $data['gateway'] ?? null,
            'ethernet_active'     => !empty($data['ethernet_active']) ? 1 : 0,
            'wifi_active'         => !empty($data['wifi_active']) ? 1 : 0,
            'wifi_ssid'           => $data['wifi_ssid'] ?? null,
            'internet_status'     => !empty($data['internet_status']) ? 1 : 0,
            'internet_test'       => $data['internet_test'] ?? null,
            'internet_latency'    => $data['internet_latency'] ?? null,
            'internet_checked_at' => $data['internet_checked_at'] ?? null,
            'agent_version'       => $data['agent_version'] ?? null,
            'id'                  => $agentId,
        ]);
    }

    public static function setActive(int $id, bool $active): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('UPDATE agents SET is_active = :a WHERE id = :id');
        $stmt->execute(['a' => $active ? 1 : 0, 'id' => $id]);
    }

    /**
     * Pose ou leve la consigne de coupure Internet pour un poste.
     * Ne touche pas a `internet_block_applied` : c'est l'agent qui confirme
     * l'application reelle lors de son prochain rapport.
     */
    public static function setInternetBlocked(int $id, bool $blocked, string $byUsername): void
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare(
            'UPDATE agents
                SET internet_blocked  = :b,
                    internet_block_at = NOW(),
                    internet_block_by = :by
              WHERE id = :id'
        );
        $stmt->execute(['b' => $blocked ? 1 : 0, 'by' => $byUsername, 'id' => $id]);
    }

    /**
     * Enregistre l'etat reellement applique, tel que rapporte par l'agent.
     * Retourne true si cet etat vient de changer (utile pour ne journaliser
     * qu'une seule fois, et pas a chaque rapport).
     */
    public static function markInternetBlockApplied(int $id, bool $applied): bool
    {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare('SELECT internet_block_applied FROM agents WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $previous = (int) $stmt->fetchColumn();

        if ($previous === ($applied ? 1 : 0)) {
            return false;
        }

        $stmt = $pdo->prepare('UPDATE agents SET internet_block_applied = :a WHERE id = :id');
        $stmt->execute(['a' => $applied ? 1 : 0, 'id' => $id]);
        return true;
    }

    /**
     * @param array<string,mixed> $filters keys: search, internet, wifi, ethernet, status
     */
    public static function search(array $filters, string $sort = 'hostname', string $dir = 'ASC', int $limit = 50, int $offset = 0): array
    {
        $allowedSort = ['hostname', 'primary_ip', 'last_seen', 'internet_status'];
        if (!in_array($sort, $allowedSort, true)) {
            $sort = 'hostname';
        }
        $dir = strtoupper($dir) === 'DESC' ? 'DESC' : 'ASC';

        $where = [];
        $params = [];

        if (!empty($filters['search'])) {
            $where[] = '(hostname LIKE :search OR primary_ip LIKE :search OR `current_user` LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (isset($filters['internet']) && $filters['internet'] !== '') {
            $where[] = 'internet_status = :internet';
            $params['internet'] = (int) $filters['internet'];
        }
        if (isset($filters['wifi']) && $filters['wifi'] !== '') {
            $where[] = 'wifi_active = :wifi';
            $params['wifi'] = (int) $filters['wifi'];
        }
        if (isset($filters['ethernet']) && $filters['ethernet'] !== '') {
            $where[] = 'ethernet_active = :ethernet';
            $params['ethernet'] = (int) $filters['ethernet'];
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'online') {
                $where[] = 'last_seen >= (NOW() - INTERVAL ' . OFFLINE_THRESHOLD_SECONDS . ' SECOND)';
            } elseif ($filters['status'] === 'offline') {
                $where[] = '(last_seen IS NULL OR last_seen < (NOW() - INTERVAL ' . OFFLINE_THRESHOLD_SECONDS . ' SECOND))';
            }
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM agents $whereSql ORDER BY $sort $dir LIMIT :limit OFFSET :offset");
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $countStmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM agents $whereSql");
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetch()['cnt'];

        return ['rows' => $rows, 'total' => $total];
    }

    public static function stats(): array
    {
        $pdo = Database::getConnection();
        $row = $pdo->query(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN last_seen >= (NOW() - INTERVAL ' . OFFLINE_THRESHOLD_SECONDS . ' SECOND) THEN 1 ELSE 0 END) AS online,
                SUM(CASE WHEN internet_status = 1 THEN 1 ELSE 0 END) AS internet,
                SUM(CASE WHEN wifi_active = 1 THEN 1 ELSE 0 END) AS wifi,
                SUM(CASE WHEN ethernet_active = 1 THEN 1 ELSE 0 END) AS ethernet
             FROM agents'
        )->fetch();

        $alerts = $pdo->query('SELECT COUNT(*) AS cnt FROM alerts WHERE is_read = 0')->fetch();

        return [
            'total'    => (int) ($row['total'] ?? 0),
            'online'   => (int) ($row['online'] ?? 0),
            'internet' => (int) ($row['internet'] ?? 0),
            'wifi'     => (int) ($row['wifi'] ?? 0),
            'ethernet' => (int) ($row['ethernet'] ?? 0),
            'alerts'   => (int) ($alerts['cnt'] ?? 0),
        ];
    }
}
