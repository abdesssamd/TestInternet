<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';

Session::start();
Session::requireAuth();

$pageTitle = 'Alertes';
$activePage = 'alerts';
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
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="im-filters mb-0">
                        <select id="fUnread">
                            <option value="0">Toutes les alertes</option>
                            <option value="1">Non lues uniquement</option>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-outline-primary" id="markAllBtn">
                        <i class="bi bi-check2-all"></i> Tout marquer comme lu
                    </button>
                </div>
                <div id="alertList"><div class="im-loader">Chargement...</div></div>
            </div>
        </main>
    </div>
</div>

<script src="<?= APP_BASE_PATH ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/app.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/notifications.js"></script>
<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

function severityIcon(sev) {
    if (sev === 'critical') return '<i class="bi bi-exclamation-octagon-fill im-severity-critical"></i>';
    if (sev === 'warning') return '<i class="bi bi-exclamation-triangle-fill im-severity-warning"></i>';
    return '<i class="bi bi-info-circle-fill im-severity-info"></i>';
}

async function load() {
    const list = document.getElementById('alertList');
    const unread = document.getElementById('fUnread').value === '1';
    try {
        const data = await IM.apiGet('/alerts.php', { unread: unread ? 1 : 0 });
        if (!data.alerts.length) {
            list.innerHTML = '<div class="im-empty"><i class="bi bi-check-circle" style="font-size:2rem;"></i><p>Aucune alerte.</p></div>';
            return;
        }
        list.innerHTML = data.alerts.map(a => `
            <div class="im-alert-item ${a.is_read ? '' : 'unread'}">
                <div>
                    ${severityIcon(a.severity)}
                    <strong class="ms-1">${IM.escapeHtml(a.hostname)}</strong>
                    <span class="ms-2">${IM.escapeHtml(a.message)}</span>
                    <div class="text-muted small">${IM.escapeHtml(a.created_at)}</div>
                </div>
                ${a.is_read ? '<span class="text-muted small">Lu</span>' : `<button class="btn btn-sm btn-outline-secondary" onclick="markRead(${a.id})">Marquer comme lu</button>`}
            </div>
        `).join('');
    } catch (e) {
        list.innerHTML = '<div class="im-empty text-danger">Erreur de chargement</div>';
    }
}

async function markRead(id) {
    try {
        await IM.apiPost('/alerts.php', { action: 'mark_read', id, csrf_token: CSRF_TOKEN });
        load();
    } catch (e) { alert('Erreur lors du marquage.'); }
}

document.getElementById('markAllBtn').addEventListener('click', async () => {
    if (!confirm('Marquer toutes les alertes comme lues ?')) return;
    try {
        await IM.apiPost('/alerts.php', { action: 'mark_all_read', csrf_token: CSRF_TOKEN });
        load();
    } catch (e) { alert('Erreur.'); }
});

document.getElementById('fUnread').addEventListener('change', load);
load();
setInterval(load, 15000);
</script>
</body>
</html>
