<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';

Session::start();
Session::requireAuth();

$pageTitle = 'Appareils inconnus';
$activePage = 'ghosts';
$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <?php include __DIR__ . '/../views/partials/head.php'; ?>
</head>
<body>
<div class="im-layout">
    <?php include __DIR__ . '/../views/partials/sidebar.php'; ?>
    <div class="im-main">
        <?php include __DIR__ . '/../views/partials/topbar.php'; ?>
        <main class="im-content">
            <div class="im-card">
                <p class="text-muted small">
                    Appareils vus sur le reseau local (scan ARP effectue par les agents deja installes)
                    dont l'adresse MAC ne correspond a aucun poste gere. Peuvent etre des imprimantes,
                    box, telephones, ou des appareils non autorises.
                </p>
                <div class="table-responsive">
                    <table class="im-table">
                        <thead><tr><th>MAC</th><th>Derniere IP</th><th>Vu par</th><th>1re detection</th><th>Derniere detection</th><th>Detections</th><th>Action</th></tr></thead>
                        <tbody id="ghostRows"><tr><td colspan="7" class="im-loader">Chargement...</td></tr></tbody>
                    </table>
                </div>
            </div>
        </main>
    </div>
</div>

<script src="<?= APP_BASE_PATH ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/app.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/notifications.js"></script>
<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

async function load() {
    const tbody = document.getElementById('ghostRows');
    try {
        const data = await IM.apiGet('/ghost_devices.php');
        if (!data.devices.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="im-empty"><i class="bi bi-check-circle" style="font-size:2rem;"></i><p>Aucun appareil non gere detecte.</p></td></tr>';
            return;
        }
        tbody.innerHTML = data.devices.map(d => `
            <tr>
                <td><code>${IM.escapeHtml(d.mac)}</code></td>
                <td>${IM.escapeHtml(d.last_ip || '—')}</td>
                <td class="text-muted small">${IM.escapeHtml(d.seen_by_hostname)}</td>
                <td class="text-muted small">${IM.escapeHtml(d.first_seen)}</td>
                <td class="text-muted small">${IM.timeAgo(d.last_seen)}</td>
                <td>${d.sightings_count}</td>
                <td><button class="btn btn-sm btn-outline-secondary" onclick="ignoreDevice(${d.id})">Ecarter</button></td>
            </tr>
        `).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="7" class="im-empty text-danger">Erreur de chargement</td></tr>';
    }
}

async function ignoreDevice(id) {
    if (!confirm('Ecarter cet appareil de la liste (ex: imprimante connue) ?')) return;
    try {
        await IM.apiPost('/ghost_devices.php', { action: 'ignore', id, ignored: true, csrf_token: CSRF_TOKEN });
        load();
    } catch (e) { alert('Erreur.'); }
}

load();
setInterval(load, 20000);
</script>
</body>
</html>
