<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';

Session::start();
Session::requireAuth();

$pageTitle = 'Tableau de bord';
$activePage = 'dashboard';
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

            <div class="im-stat-grid" id="statGrid">
                <div class="im-card im-stat-card">
                    <div class="im-stat-icon blue"><i class="bi bi-pc-display"></i></div>
                    <div><div class="im-stat-value" id="statTotal">–</div><div class="im-stat-label">Postes</div></div>
                </div>
                <div class="im-card im-stat-card">
                    <div class="im-stat-icon green"><i class="bi bi-broadcast"></i></div>
                    <div><div class="im-stat-value" id="statOnline">–</div><div class="im-stat-label">En ligne</div></div>
                </div>
                <div class="im-card im-stat-card">
                    <div class="im-stat-icon purple"><i class="bi bi-globe2"></i></div>
                    <div><div class="im-stat-value" id="statInternet">–</div><div class="im-stat-label">Internet</div></div>
                </div>
                <div class="im-card im-stat-card">
                    <div class="im-stat-icon blue"><i class="bi bi-wifi"></i></div>
                    <div><div class="im-stat-value" id="statWifi">–</div><div class="im-stat-label">Wi-Fi actif</div></div>
                </div>
                <div class="im-card im-stat-card">
                    <div class="im-stat-icon green"><i class="bi bi-ethernet"></i></div>
                    <div><div class="im-stat-value" id="statEthernet">–</div><div class="im-stat-label">Ethernet</div></div>
                </div>
                <div class="im-card im-stat-card">
                    <div class="im-stat-icon red"><i class="bi bi-exclamation-triangle"></i></div>
                    <div><div class="im-stat-value" id="statAlerts">–</div><div class="im-stat-label">Alertes</div></div>
                </div>
            </div>

            <div class="im-card mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0">Etat des postes</h6>
                    <a href="devices.php" class="small">Voir tous les postes <i class="bi bi-arrow-right"></i></a>
                </div>
                <div class="table-responsive">
                    <table class="im-table">
                        <thead>
                        <tr>
                            <th>Poste</th><th>IP</th><th>Interface</th><th>LAN</th><th>Internet</th><th>Dernier contact</th>
                        </tr>
                        </thead>
                        <tbody id="dashboardDeviceRows">
                        <tr><td colspan="6" class="im-loader">Chargement...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="im-card">
                <h6 class="mb-3">Historique Internet (6 dernieres heures)</h6>
                <canvas id="internetChart" height="90"></canvas>
            </div>

        </main>
    </div>
</div>

<script src="<?= APP_BASE_PATH ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/vendor/chartjs/chart.umd.min.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/app.js"></script>
<script>
let internetChart = null;

async function refreshStats() {
    try {
        const data = await IM.apiGet('/stats.php');
        document.getElementById('statTotal').textContent = data.stats.total;
        document.getElementById('statOnline').textContent = data.stats.online;
        document.getElementById('statInternet').textContent = data.stats.internet;
        document.getElementById('statWifi').textContent = data.stats.wifi;
        document.getElementById('statEthernet').textContent = data.stats.ethernet;
        document.getElementById('statAlerts').textContent = data.stats.alerts;

        const labels = data.series.map(p => p.bucket.substring(11, 16));
        const values = data.series.map(p => Number(p.internet_count));

        if (!internetChart) {
            const ctx = document.getElementById('internetChart').getContext('2d');
            internetChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Postes avec Internet',
                        data: values,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,0.12)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 0,
                    }],
                },
                options: {
                    responsive: true,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
                },
            });
        } else {
            internetChart.data.labels = labels;
            internetChart.data.datasets[0].data = values;
            internetChart.update();
        }
    } catch (e) {
        console.error(e);
    }
}

async function refreshDevices() {
    const tbody = document.getElementById('dashboardDeviceRows');
    try {
        const data = await IM.apiGet('/devices.php', { per_page: 8, sort: 'last_seen', dir: 'DESC' });
        if (!data.devices.length) {
            tbody.innerHTML = '<tr><td colspan="6" class="im-empty">Aucun poste enregistre pour le moment.</td></tr>';
            return;
        }
        tbody.innerHTML = data.devices.map(d => `
            <tr onclick="window.location='device_detail.php?id=${d.id}'" style="cursor:pointer;">
                <td><strong>${IM.escapeHtml(d.hostname)}</strong></td>
                <td>${IM.escapeHtml(d.primary_ip || '—')}</td>
                <td>${d.ethernet_active ? '<i class="bi bi-ethernet"></i> Ethernet' : (d.wifi_active ? '<i class="bi bi-wifi"></i> Wi-Fi' : '—')}</td>
                <td>${IM.badge(d.online, 'EN LIGNE', 'HORS LIGNE')}</td>
                <td>${IM.badge(d.internet_status)}</td>
                <td class="text-muted small">${IM.timeAgo(d.last_seen)}</td>
            </tr>
        `).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="6" class="im-empty text-danger">Erreur de chargement</td></tr>';
    }
}

function tick() {
    refreshStats();
    refreshDevices();
}
tick();
setInterval(tick, 12000);
</script>
</body>
</html>
