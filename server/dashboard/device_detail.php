<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';

Session::start();
Session::requireAuth();

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: devices.php');
    exit;
}

$pageTitle = 'Detail du poste';
$activePage = 'devices';
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
        <main class="im-content" id="content">
            <div class="im-loader">Chargement du poste...</div>
        </main>
    </div>
</div>

<script src="<?= APP_BASE_PATH ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/vendor/chartjs/chart.umd.min.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/app.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/notifications.js"></script>
<script>
const deviceId = <?= json_encode($id) ?>;
const CSRF_TOKEN = <?= json_encode(Csrf::token()) ?>;

function internetBlockPanel(d) {
    const wanted  = Number(d.internet_blocked) === 1;
    const applied = Number(d.internet_block_applied) === 1;
    // Consigne posee mais pas encore confirmee par l'agent : etat transitoire.
    const pending = wanted !== applied;

    let etat;
    if (pending) {
        etat = `<span class="badge bg-warning text-dark"><i class="bi bi-hourglass-split"></i> ${wanted ? 'Coupure en attente' : 'Retablissement en attente'}</span>`;
    } else if (applied) {
        etat = '<span class="badge bg-danger"><i class="bi bi-slash-circle"></i> Internet coupe</span>';
    } else {
        etat = '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Internet autorise</span>';
    }

    let details = '';
    if (wanted) {
        const parts = [];
        if (d.internet_block_reason) {
            parts.push(`<strong>Motif :</strong> ${IM.escapeHtml(d.internet_block_reason)}`);
        }
        if (d.internet_block_by) {
            parts.push(`Coupe par ${IM.escapeHtml(d.internet_block_by)}`);
        }
        if (d.internet_block_at) {
            parts.push(IM.timeAgo(d.internet_block_at));
        }
        if (parts.length) {
            details = `<div class="text-muted small mt-1">${parts.join(' &middot; ')}</div>`;
        }
    } else if (d.internet_block_by) {
        details = `<div class="text-muted small mt-1">Derniere action par ${IM.escapeHtml(d.internet_block_by)}</div>`;
    }

    // La coupure n'expire pas : elle reste active tant qu'un administrateur
    // ne la leve pas. Le formulaire de motif n'apparait donc qu'a la coupure.
    const zoneAction = wanted
        ? `<button class="btn btn-sm btn-success" onclick="setInternetBlocked(false)">
               <i class="bi bi-play-circle"></i> Retablir Internet
           </button>`
        : `<div class="d-flex gap-2 align-items-start flex-wrap justify-content-end">
               <input type="text" id="blockReason" class="form-control form-control-sm"
                      style="max-width:240px" maxlength="255"
                      placeholder="Motif (optionnel)">
               <button class="btn btn-sm btn-danger" onclick="setInternetBlocked(true)">
                   <i class="bi bi-slash-circle"></i> Couper Internet
               </button>
           </div>`;

    return `
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                ${etat}
                ${details}
                <div class="form-text mb-0">
                    Le poste reste joignable sur le reseau local : seul l'acces Internet est bloque.
                    La coupure reste active jusqu'a ce qu'un administrateur la leve.
                </div>
            </div>
            ${zoneAction}
        </div>
        <div id="blockResult" class="mt-2"></div>
    `;
}

async function setInternetBlocked(blocked) {
    let reason = null;
    if (blocked) {
        const field = document.getElementById('blockReason');
        reason = field ? field.value.trim() : '';
        let texte = "Couper l'acces Internet de ce poste ?\n\n";
        texte += "Le poste restera joignable sur le reseau local.\n";
        texte += "L'ordre sera applique au prochain rapport de l'agent (jusqu'a 1 minute).\n";
        texte += "La coupure restera active jusqu'a ce que vous la leviez.";
        if (reason) { texte += "\n\nMotif : " + reason; }
        if (!confirm(texte)) { return; }
    } else if (!confirm("Retablir l'acces Internet de ce poste ?")) {
        return;
    }

    const zone = document.getElementById('blockResult');
    zone.innerHTML = '<div class="text-muted small">Envoi de la consigne...</div>';
    try {
        const r = await IM.apiPost('/agents_admin.php', {
            action: 'set_internet_blocked',
            id: deviceId,
            blocked: blocked,
            reason: reason || '',
            csrf_token: CSRF_TOKEN,
        });
        zone.innerHTML = `<div class="alert alert-info py-2 small mb-0">${IM.escapeHtml(r.message)}</div>`;
        load();
    } catch (e) {
        zone.innerHTML = '<div class="alert alert-danger py-2 small mb-0">Erreur : consigne non enregistree.</div>';
    }
}

function monitoringPanel(d) {
    const on = Number(d.is_monitored) !== 0;
    const etat = on
        ? '<span class="badge bg-success"><i class="bi bi-eye"></i> Sous surveillance</span>'
        : '<span class="badge bg-secondary"><i class="bi bi-eye-slash"></i> Hors surveillance</span>';
    const bouton = on
        ? `<button class="btn btn-sm btn-outline-secondary" onclick="setMonitored(false)">
               <i class="bi bi-eye-slash"></i> Retirer de la surveillance
           </button>`
        : `<button class="btn btn-sm btn-outline-primary" onclick="setMonitored(true)">
               <i class="bi bi-eye"></i> Remettre sous surveillance
           </button>`;
    return `
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                ${etat}
                <div class="form-text mb-0">Un poste detecte est place sous surveillance par defaut.</div>
            </div>
            ${bouton}
        </div>
        <div id="monitorResult" class="mt-2"></div>
    `;
}

async function setMonitored(on) {
    const zone = document.getElementById('monitorResult');
    zone.innerHTML = '<div class="text-muted small">Enregistrement...</div>';
    try {
        const r = await IM.apiPost('/agents_admin.php', {
            action: 'set_monitored',
            id: deviceId,
            monitored: on,
            csrf_token: CSRF_TOKEN,
        });
        zone.innerHTML = `<div class="alert alert-info py-2 small mb-0">${IM.escapeHtml(r.message)}</div>`;
        load();
    } catch (e) {
        zone.innerHTML = '<div class="alert alert-danger py-2 small mb-0">Erreur : modification non enregistree.</div>';
    }
}

function adapterRow(a) {
    const icon = a.type === 'ETHERNET' ? 'bi-ethernet' : (a.type === 'WIFI' ? 'bi-wifi' : 'bi-hdd-network');
    return `
        <div class="im-card mb-2">
            <div class="d-flex justify-content-between align-items-center">
                <span><i class="bi ${icon}"></i> <strong>${IM.escapeHtml(a.name)}</strong> <span class="text-muted small">(${a.type})</span></span>
                ${IM.badge(a.status === 'UP', 'UP', 'DOWN')}
            </div>
            <div class="row small text-muted mt-2">
                <div class="col-6 col-md-3">IP : ${IM.escapeHtml(a.ipv4 || '—')}</div>
                <div class="col-6 col-md-3">MAC : ${IM.escapeHtml(a.mac || '—')}</div>
                <div class="col-6 col-md-3">Passerelle : ${IM.escapeHtml(a.gateway || '—')}</div>
                <div class="col-6 col-md-3">SSID : ${IM.escapeHtml(a.ssid || '—')}</div>
            </div>
        </div>`;
}

function eventRow(e) {
    return `<div class="d-flex justify-content-between border-bottom py-2 small">
        <span>${IM.escapeHtml(e.message)}</span>
        <span class="text-muted">${IM.escapeHtml(e.created_at)}</span>
    </div>`;
}

let historyChart = null;

async function load() {
    const content = document.getElementById('content');
    try {
        const data = await IM.apiGet('/device.php', { id: deviceId });
        const d = data.device;

        content.innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div>
                    <h4 class="mb-0">${IM.escapeHtml(d.hostname)}</h4>
                    <span class="text-muted small">${d.is_active ? '' : '<span class="text-danger">Agent desactive</span>'}</span>
                </div>
                <div>${IM.badge(d.online, 'EN LIGNE', 'HORS LIGNE')}</div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <div class="im-card h-100">
                        <h6><i class="bi bi-info-circle"></i> Informations</h6>
                        <table class="im-table">
                            <tr><td>Utilisateur</td><td>${IM.escapeHtml(d.current_user || '—')}${d.domain ? ' (' + IM.escapeHtml(d.domain) + ')' : ''}</td></tr>
                            <tr><td>Systeme</td><td>${IM.escapeHtml(d.os_name || '—')} ${IM.escapeHtml(d.os_version || '')}</td></tr>
                            <tr><td>IP principale</td><td>${IM.escapeHtml(d.primary_ip || '—')}</td></tr>
                            <tr><td>MAC</td><td>${IM.escapeHtml(d.primary_mac || '—')}</td></tr>
                            <tr><td>Version agent</td><td>${IM.escapeHtml(d.agent_version || '—')}</td></tr>
                            <tr><td>Token</td><td class="text-muted">••••••${IM.escapeHtml(d.token_hint || '')}</td></tr>
                        </table>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="im-card h-100">
                        <h6><i class="bi bi-globe2"></i> Internet</h6>
                        <table class="im-table">
                            <tr><td>Statut</td><td>${IM.badge(d.internet_status)}</td></tr>
                            <tr><td>Latence</td><td>${d.internet_latency !== null ? d.internet_latency + ' ms' : '—'}</td></tr>
                            <tr><td>Dernier test</td><td>${IM.escapeHtml(d.internet_checked_at || '—')}</td></tr>
                            <tr><td>Detail tests</td><td class="text-muted small">${IM.escapeHtml(d.internet_test || '—')}</td></tr>
                        </table>
                    </div>
                </div>
            </div>

            <div class="im-card mb-3">
                <h6 class="mb-2"><i class="bi bi-graph-up"></i> Disponibilite</h6>
                <div id="availTiles" class="row g-2 text-center">
                    <div class="col-4"><div class="text-muted small">24h</div><div id="avail24h" class="fw-bold">–</div></div>
                    <div class="col-4"><div class="text-muted small">7j</div><div id="avail7d" class="fw-bold">–</div></div>
                    <div class="col-4"><div class="text-muted small">30j</div><div id="avail30d" class="fw-bold">–</div></div>
                </div>
            </div>

            <h6 class="mb-2"><i class="bi bi-hdd-network"></i> Interfaces reseau</h6>
            <div class="mb-3">${data.adapters.map(adapterRow).join('') || '<div class="im-empty">Aucune interface remontee</div>'}</div>

            <div class="im-card mb-3">
                <h6 class="mb-2">Historique Internet</h6>
                <canvas id="deviceChart" height="80"></canvas>
            </div>

            <div class="im-card mb-3">
                <h6 class="mb-2"><i class="bi bi-shield-slash"></i> Acces Internet</h6>
                ${internetBlockPanel(data.device)}
            </div>

            <div class="im-card mb-3">
                <h6 class="mb-2"><i class="bi bi-eye"></i> Surveillance</h6>
                ${monitoringPanel(data.device)}
            </div>

            <div class="im-card">
                <h6 class="mb-2"><i class="bi bi-clock-history"></i> Historique des evenements</h6>
                ${data.events.map(eventRow).join('') || '<div class="im-empty">Aucun evenement</div>'}
            </div>
        `;

        const labels = data.history.map(p => p.recorded_at.substring(11, 16));
        const values = data.history.map(p => Number(p.internet_status));
        const ctx = document.getElementById('deviceChart').getContext('2d');
        if (historyChart) historyChart.destroy();
        historyChart = new Chart(ctx, {
            type: 'line',
            data: { labels, datasets: [{ label: 'Internet (1=OK)', data: values, stepped: true, borderColor: '#16a34a', backgroundColor: 'rgba(22,163,74,0.15)', fill: true, pointRadius: 0 }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { min: 0, max: 1, ticks: { stepSize: 1 } } } },
        });
        loadAvailability();
    } catch (e) {
        content.innerHTML = '<div class="im-empty text-danger">Poste introuvable ou erreur de chargement.</div>';
    }
}

async function loadAvailability() {
    try {
        const [d24, d7, d30] = await Promise.all([
            IM.apiGet('/availability.php', { agent_id: deviceId, window: '24h' }),
            IM.apiGet('/availability.php', { agent_id: deviceId, window: '7d' }),
            IM.apiGet('/availability.php', { agent_id: deviceId, window: '30d' }),
        ]);
        document.getElementById('avail24h').textContent = d24.internet_uptime_pct + '%';
        document.getElementById('avail7d').textContent = d7.internet_uptime_pct + '%';
        document.getElementById('avail30d').textContent = d30.internet_uptime_pct + '%';
    } catch (e) { /* silencieux : la carte reste a '–' */ }
}

load();
setInterval(load, 15000);
</script>
</body>
</html>
