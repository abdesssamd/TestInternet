<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';
require_once __DIR__ . '/../middleware/Csrf.php';

Session::start();
Session::requireAuth();

$pageTitle = 'Reglages';
$activePage = 'settings';
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

            <div class="im-card mb-4">
                <h6 class="mb-3"><i class="bi bi-plus-circle"></i> Enregistrer un nouveau poste</h6>
                <form id="createForm" class="d-flex gap-2 flex-wrap">
                    <input type="text" id="newHostname" placeholder="Nom du poste (ex: PC-045)" class="form-control" style="max-width:280px;" required>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-plus-lg"></i> Creer</button>
                </form>
                <div id="tokenResult" class="mt-3"></div>
            </div>

            <div class="im-card mb-4">
                <h6 class="mb-3"><i class="bi bi-hdd-network"></i> Agents enregistres</h6>
                <div class="table-responsive">
                    <table class="im-table">
                        <thead><tr><th>Poste</th><th>Token</th><th>Statut agent</th><th>Dernier contact</th><th>Action</th></tr></thead>
                        <tbody id="agentRows"><tr><td colspan="5" class="im-loader">Chargement...</td></tr></tbody>
                    </table>
                </div>
            </div>

            <div class="im-card mb-4">
                <h6 class="mb-3"><i class="bi bi-shield-exclamation"></i> Detection d'anomalies reseau</h6>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="anomalyEnabled">
                    <label class="form-check-label small" for="anomalyEnabled">Activer la detection (MAC inattendue, IP hors sous-reseau, SSID inconnu)</label>
                </div>
                <div class="mb-3">
                    <label class="form-label small">Sous-reseau attendu par defaut (CIDR, ex: 192.168.1.0/24)</label>
                    <div class="d-flex gap-2">
                        <input type="text" id="anomalyCidr" class="form-control" style="max-width:280px;" placeholder="192.168.1.0/24">
                        <button class="btn btn-outline-primary btn-sm" onclick="saveCidr()">Enregistrer</button>
                    </div>
                    <div class="form-text">Laisser vide pour desactiver la verification IP-hors-sous-reseau. La MAC attendue de chaque poste est apprise automatiquement au premier contact de son agent.</div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Reseaux Wi-Fi autorises (liste blanche)</label>
                    <div class="d-flex gap-2 mb-2">
                        <input type="text" id="newSsid" class="form-control" style="max-width:280px;" placeholder="Nom du reseau (SSID)">
                        <button class="btn btn-outline-primary btn-sm" onclick="addSsid()">Ajouter</button>
                    </div>
                    <div id="ssidList" class="d-flex flex-wrap gap-2"></div>
                    <div class="form-text">Liste vide = verification SSID desactivee (aucun faux positif au demarrage).</div>
                </div>
            </div>

            <div class="im-card mb-4">
                <h6 class="mb-3"><i class="bi bi-envelope"></i> Notifications email (SMTP)</h6>
                <div class="form-check form-switch mb-3">
                    <input class="form-check-input" type="checkbox" id="smtpEnabled">
                    <label class="form-check-label small" for="smtpEnabled">Activer l'envoi d'emails pour les alertes critiques</label>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Serveur SMTP</label><input type="text" id="smtpHost" class="form-control" placeholder="smtp.gmail.com"></div>
                    <div class="col-md-3"><label class="form-label small">Port</label><input type="number" id="smtpPort" class="form-control" value="587"></div>
                    <div class="col-md-3"><label class="form-label small">Chiffrement</label>
                        <select id="smtpEncryption" class="form-select">
                            <option value="tls">TLS (STARTTLS)</option>
                            <option value="ssl">SSL</option>
                            <option value="none">Aucun</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Utilisateur SMTP</label><input type="text" id="smtpUsername" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label small">Mot de passe SMTP</label><input type="password" id="smtpPassword" class="form-control" placeholder="Laisser vide pour ne pas changer"></div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6"><label class="form-label small">Adresse expediteur</label><input type="email" id="smtpFromEmail" class="form-control"></div>
                    <div class="col-md-6"><label class="form-label small">Nom expediteur</label><input type="text" id="smtpFromName" class="form-control"></div>
                </div>
                <div class="mb-2">
                    <label class="form-label small">Destinataires (separes par une virgule)</label>
                    <input type="text" id="smtpToEmails" class="form-control" placeholder="admin@exemple.com, astreinte@exemple.com">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-md-6"><label class="form-label small">Severite minimale notifiee</label>
                        <select id="notifyMinSeverity" class="form-select">
                            <option value="info">Info</option>
                            <option value="warning">Avertissement</option>
                            <option value="critical">Critique</option>
                        </select>
                    </div>
                    <div class="col-md-6"><label class="form-label small">Delai minimum entre 2 notifications (secondes)</label><input type="number" id="notifyThrottle" class="form-control" value="1800"></div>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-primary btn-sm" onclick="saveSmtp()"><i class="bi bi-save"></i> Enregistrer</button>
                    <button class="btn btn-outline-secondary btn-sm" onclick="testSmtp()"><i class="bi bi-send"></i> Envoyer un test</button>
                </div>
                <div id="smtpResult" class="mt-2"></div>
            </div>

        </main>
    </div>
</div>

<script src="<?= APP_BASE_PATH ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/app.js"></script>
<script>
const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;

async function loadAgents() {
    const tbody = document.getElementById('agentRows');
    try {
        const data = await IM.apiGet('/devices.php', { per_page: 200, sort: 'hostname' });
        if (!data.devices.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="im-empty">Aucun agent enregistre.</td></tr>';
            return;
        }
        tbody.innerHTML = data.devices.map(d => `
            <tr>
                <td><strong>${IM.escapeHtml(d.hostname)}</strong></td>
                <td class="text-muted small">••••••••</td>
                <td>${d.is_active ? '<span class="badge-dot on">ACTIF</span>' : '<span class="badge-dot off">DESACTIVE</span>'}</td>
                <td class="text-muted small">${IM.timeAgo(d.last_seen)}</td>
                <td>
                    <button class="btn btn-sm btn-outline-${d.is_active ? 'danger' : 'success'}" onclick="toggleActive(${d.id}, ${!d.is_active})">
                        ${d.is_active ? 'Desactiver' : 'Activer'}
                    </button>
                </td>
            </tr>
        `).join('');
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="5" class="im-empty text-danger">Erreur de chargement</td></tr>';
    }
}

async function toggleActive(id, active) {
    const verb = active ? 'activer' : 'desactiver';
    if (!confirm(`Confirmer : ${verb} cet agent ?`)) return;
    try {
        await IM.apiPost('/agents_admin.php', { action: 'set_active', id, active, csrf_token: CSRF_TOKEN });
        loadAgents();
    } catch (e) { alert('Erreur lors de la mise a jour.'); }
}

document.getElementById('createForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const hostname = document.getElementById('newHostname').value.trim();
    if (!hostname) return;
    const resultDiv = document.getElementById('tokenResult');
    try {
        const data = await IM.apiPost('/agents_admin.php', { action: 'create', hostname, csrf_token: CSRF_TOKEN });
        if (data.error) {
            resultDiv.innerHTML = `<div class="alert alert-danger py-2">${IM.escapeHtml(data.error)}</div>`;
            return;
        }
        resultDiv.innerHTML = `
            <div class="alert alert-success py-2">
                <strong>Poste cree.</strong> Copiez ce token maintenant : il ne sera plus jamais affiche en clair.
                <div class="mt-2 p-2 bg-dark text-white rounded" style="font-family:monospace; word-break:break-all;">${IM.escapeHtml(data.token)}</div>
                <div class="small text-muted mt-1">A renseigner dans agent/config.ps1 sur le poste ${IM.escapeHtml(hostname)}.</div>
            </div>`;
        document.getElementById('newHostname').value = '';
        loadAgents();
    } catch (err) {
        resultDiv.innerHTML = '<div class="alert alert-danger py-2">Erreur lors de la creation.</div>';
    }
});

loadAgents();

// --- Reglages anomalies / SMTP / SSID ---
async function loadSettings() {
    try {
        const data = await IM.apiGet('/settings.php');
        const s = data.settings;
        document.getElementById('anomalyEnabled').checked = s.anomaly_detection_enabled === '1';
        document.getElementById('anomalyCidr').value = s.anomaly_default_cidr || '';
        document.getElementById('smtpEnabled').checked = s.smtp_enabled === '1';
        document.getElementById('smtpHost').value = s.smtp_host || '';
        document.getElementById('smtpPort').value = s.smtp_port || '587';
        document.getElementById('smtpEncryption').value = s.smtp_encryption || 'tls';
        document.getElementById('smtpUsername').value = s.smtp_username || '';
        document.getElementById('smtpFromEmail').value = s.smtp_from_email || '';
        document.getElementById('smtpFromName').value = s.smtp_from_name || '';
        document.getElementById('smtpToEmails').value = s.smtp_to_emails || '';
        document.getElementById('notifyMinSeverity').value = s.notify_min_severity || 'critical';
        document.getElementById('notifyThrottle').value = s.notify_throttle_seconds || '1800';
        document.getElementById('smtpPassword').placeholder = s.smtp_password_set === true || s.smtp_password_set === '1'
            ? 'Mot de passe deja configure — laisser vide pour le conserver'
            : 'Aucun mot de passe configure';

        renderSsidList(data.known_ssids);
    } catch (e) { /* ignore */ }
}

function renderSsidList(ssids) {
    const container = document.getElementById('ssidList');
    if (!ssids.length) {
        container.innerHTML = '<span class="text-muted small">Aucun SSID dans la liste blanche.</span>';
        return;
    }
    container.innerHTML = ssids.map(s => `
        <span class="badge text-bg-secondary d-inline-flex align-items-center gap-1">
            ${IM.escapeHtml(s.ssid)}
            <i class="bi bi-x-circle" style="cursor:pointer;" onclick="removeSsid(${s.id})"></i>
        </span>
    `).join('');
}

document.getElementById('anomalyEnabled').addEventListener('change', async (e) => {
    await IM.apiPost('/settings.php', { action: 'set_setting', key: 'anomaly_detection_enabled', value: e.target.checked ? '1' : '0', csrf_token: CSRF_TOKEN });
});

async function saveCidr() {
    const value = document.getElementById('anomalyCidr').value.trim();
    await IM.apiPost('/settings.php', { action: 'set_setting', key: 'anomaly_default_cidr', value, csrf_token: CSRF_TOKEN });
}

async function addSsid() {
    const input = document.getElementById('newSsid');
    const ssid = input.value.trim();
    if (!ssid) return;
    await IM.apiPost('/settings.php', { action: 'add_ssid', ssid, csrf_token: CSRF_TOKEN });
    input.value = '';
    loadSettings();
}

async function removeSsid(id) {
    await IM.apiPost('/settings.php', { action: 'remove_ssid', id, csrf_token: CSRF_TOKEN });
    loadSettings();
}

async function saveSmtp() {
    const resultDiv = document.getElementById('smtpResult');
    try {
        await IM.apiPost('/settings.php', {
            action: 'set_smtp',
            enabled: document.getElementById('smtpEnabled').checked,
            host: document.getElementById('smtpHost').value.trim(),
            port: parseInt(document.getElementById('smtpPort').value, 10) || 587,
            encryption: document.getElementById('smtpEncryption').value,
            username: document.getElementById('smtpUsername').value.trim(),
            password: document.getElementById('smtpPassword').value,
            from_email: document.getElementById('smtpFromEmail').value.trim(),
            from_name: document.getElementById('smtpFromName').value.trim(),
            to_emails: document.getElementById('smtpToEmails').value.trim(),
            min_severity: document.getElementById('notifyMinSeverity').value,
            throttle_seconds: parseInt(document.getElementById('notifyThrottle').value, 10) || 1800,
            csrf_token: CSRF_TOKEN,
        });
        document.getElementById('smtpPassword').value = '';
        resultDiv.innerHTML = '<div class="alert alert-success py-2">Configuration SMTP enregistree.</div>';
        loadSettings();
    } catch (e) {
        resultDiv.innerHTML = '<div class="alert alert-danger py-2">Erreur lors de l\'enregistrement.</div>';
    }
}

async function testSmtp() {
    const resultDiv = document.getElementById('smtpResult');
    resultDiv.innerHTML = '<div class="text-muted small">Envoi en cours...</div>';
    try {
        const data = await IM.apiPost('/settings.php', { action: 'test_smtp', csrf_token: CSRF_TOKEN });
        resultDiv.innerHTML = `<div class="alert alert-${data.status === 'ok' ? 'success' : 'danger'} py-2">${IM.escapeHtml(data.message)}</div>`;
    } catch (e) {
        resultDiv.innerHTML = '<div class="alert alert-danger py-2">Erreur lors du test.</div>';
    }
}

loadSettings();
</script>
</body>
</html>
