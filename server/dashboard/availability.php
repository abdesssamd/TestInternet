<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';

Session::start();
Session::requireAuth();

$pageTitle = 'Disponibilite';
$activePage = 'availability';
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
                <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
                    <div class="im-filters mb-0">
                        <select id="fWindow">
                            <option value="24h">24 heures</option>
                            <option value="7d">7 jours</option>
                            <option value="30d">30 jours</option>
                        </select>
                    </div>
                    <a id="exportBtn" href="#" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-download"></i> Exporter en CSV
                    </a>
                </div>
                <p class="text-muted small">
                    Disponibilite calculee sur la proportion de rapports recus ou Internet/LAN etait actif
                    (base sur l'intervalle de rapport de chaque agent, pas une mesure continue).
                </p>
                <div class="table-responsive">
                    <table class="im-table">
                        <thead><tr><th>Poste</th><th>Echantillons</th><th>Disponibilite Internet</th><th>Disponibilite LAN</th></tr></thead>
                        <tbody id="availRows"><tr><td colspan="4" class="im-loader">Chargement...</td></tr></tbody>
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
function pctBadge(pct) {
    let cls = 'on';
    if (pct < 90) cls = 'off';
    else if (pct < 99) cls = 'neutral';
    return `<span class="badge-dot ${cls}">${pct}%</span>`;
}

function updateExportLink() {
    const win = document.getElementById('fWindow').value;
    document.getElementById('exportBtn').href = `${IM.API_BASE}/availability.php?window=${win}&export=csv`;
}

async function load() {
    const win = document.getElementById('fWindow').value;
    const tbody = document.getElementById('availRows');
    try {
        const data = await IM.apiGet('/availability.php', { window: win });
        if (!data.devices.length) {
            tbody.innerHTML = '<tr><td colspan="4" class="im-empty">Aucune donnee disponible.</td></tr>';
            return;
        }
        tbody.innerHTML = data.devices.map(d => `
            <tr>
                <td><strong>${IM.escapeHtml(d.hostname)}</strong></td>
                <td class="text-muted small">${d.total_samples}</td>
                <td>${pctBadge(d.internet_uptime_pct)}</td>
                <td>${pctBadge(d.lan_uptime_pct)}</td>
            </tr>
        `).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="4" class="im-empty text-danger">Erreur de chargement</td></tr>';
    }
}

document.getElementById('fWindow').addEventListener('change', () => { updateExportLink(); load(); });
updateExportLink();
load();
setInterval(load, 30000);
</script>
</body>
</html>
