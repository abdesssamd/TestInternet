<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../middleware/Session.php';

Session::start();
Session::requireAuth();

$pageTitle = 'Postes';
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
        <main class="im-content">

            <div class="im-card">
                <div class="im-filters">
                    <input type="text" id="fSearch" placeholder="Rechercher (nom, IP, utilisateur)..." style="min-width:220px;">
                    <select id="fInternet">
                        <option value="">Internet : tous</option>
                        <option value="1">Internet : oui</option>
                        <option value="0">Internet : non</option>
                    </select>
                    <select id="fWifi">
                        <option value="">Wi-Fi : tous</option>
                        <option value="1">Wi-Fi : actif</option>
                        <option value="0">Wi-Fi : inactif</option>
                    </select>
                    <select id="fEthernet">
                        <option value="">Ethernet : tous</option>
                        <option value="1">Ethernet : actif</option>
                        <option value="0">Ethernet : inactif</option>
                    </select>
                    <select id="fStatus">
                        <option value="">Statut : tous</option>
                        <option value="online">En ligne</option>
                        <option value="offline">Hors ligne</option>
                    </select>
                </div>

                <div class="table-responsive">
                    <table class="im-table">
                        <thead>
                        <tr>
                            <th data-sort="hostname">Nom PC</th>
                            <th data-sort="primary_ip">IP</th>
                            <th>MAC</th>
                            <th>Utilisateur</th>
                            <th>OS</th>
                            <th>Ethernet</th>
                            <th>Wi-Fi</th>
                            <th data-sort="internet_status">Internet</th>
                            <th>Passerelle</th>
                            <th data-sort="last_seen">Dernier contact</th>
                            <th>Statut</th>
                        </tr>
                        </thead>
                        <tbody id="deviceRows">
                        <tr><td colspan="11" class="im-loader">Chargement...</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-between align-items-center mt-3">
                    <span class="text-muted small" id="resultCount"></span>
                    <div class="btn-group">
                        <button class="btn btn-sm btn-outline-secondary" id="prevPage"><i class="bi bi-chevron-left"></i></button>
                        <span class="btn btn-sm btn-outline-secondary disabled" id="pageIndicator">1</span>
                        <button class="btn btn-sm btn-outline-secondary" id="nextPage"><i class="bi bi-chevron-right"></i></button>
                    </div>
                </div>
            </div>

        </main>
    </div>
</div>

<script src="<?= APP_BASE_PATH ?>/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
<script src="<?= APP_BASE_PATH ?>/assets/js/app.js"></script>
<script>
let state = { search: '', internet: '', wifi: '', ethernet: '', status: '', sort: 'hostname', dir: 'ASC', page: 1, per_page: 25 };
let debounceTimer = null;

function bindFilters() {
    document.getElementById('fSearch').addEventListener('input', (e) => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(() => { state.search = e.target.value; state.page = 1; load(); }, 300);
    });
    ['fInternet', 'fWifi', 'fEthernet', 'fStatus'].forEach(id => {
        document.getElementById(id).addEventListener('change', (e) => {
            const key = id.replace('f', '').toLowerCase();
            state[key] = e.target.value;
            state.page = 1;
            load();
        });
    });
    document.querySelectorAll('th[data-sort]').forEach(th => {
        th.addEventListener('click', () => {
            const col = th.dataset.sort;
            if (state.sort === col) {
                state.dir = state.dir === 'ASC' ? 'DESC' : 'ASC';
            } else {
                state.sort = col;
                state.dir = 'ASC';
            }
            load();
        });
    });
    document.getElementById('prevPage').addEventListener('click', () => { if (state.page > 1) { state.page--; load(); } });
    document.getElementById('nextPage').addEventListener('click', () => { state.page++; load(); });
}

async function load() {
    const tbody = document.getElementById('deviceRows');
    try {
        const data = await IM.apiGet('/devices.php', state);
        if (!data.devices.length) {
            tbody.innerHTML = '<tr><td colspan="11" class="im-empty">Aucun poste ne correspond aux filtres.</td></tr>';
        } else {
            tbody.innerHTML = data.devices.map(d => `
                <tr onclick="window.location='device_detail.php?id=${d.id}'" style="cursor:pointer;">
                    <td><strong>${IM.escapeHtml(d.hostname)}</strong></td>
                    <td>${IM.escapeHtml(d.primary_ip || '—')}</td>
                    <td class="text-muted small">${IM.escapeHtml(d.primary_mac || '—')}</td>
                    <td>${IM.escapeHtml(d.current_user || '—')}</td>
                    <td class="text-muted small">${IM.escapeHtml(d.os_name || '—')}</td>
                    <td>${IM.badge(d.ethernet_active)}</td>
                    <td>${IM.badge(d.wifi_active)}</td>
                    <td>${IM.badge(d.internet_status)}</td>
                    <td class="text-muted small">${IM.escapeHtml(d.gateway || '—')}</td>
                    <td class="text-muted small">${IM.timeAgo(d.last_seen)}</td>
                    <td>${IM.badge(d.online, 'EN LIGNE', 'HORS LIGNE')}</td>
                </tr>
            `).join('');
        }
        const totalPages = Math.max(1, Math.ceil(data.total / state.per_page));
        document.getElementById('pageIndicator').textContent = `${data.page} / ${totalPages}`;
        document.getElementById('resultCount').textContent = `${data.total} poste(s)`;
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="11" class="im-empty text-danger">Erreur de chargement</td></tr>';
    }
}

bindFilters();
load();
setInterval(load, 15000);
</script>
</body>
</html>
