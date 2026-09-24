/**
 * Notifications push du navigateur.
 *
 * Fonctionnement : le dashboard interroge /api/alerts.php a intervalle
 * regulier et affiche une notification systeme pour chaque NOUVELLE alerte.
 * Pas de Service Worker ni de serveur push externe : les notifications ne
 * s'affichent que lorsqu'un onglet du dashboard est ouvert, ce qui suffit
 * pour un poste de supervision et evite toute infrastructure supplementaire.
 *
 * L'identifiant de la derniere alerte vue est conserve dans localStorage,
 * pour ne pas re-notifier les memes alertes a chaque rechargement de page.
 */

const IMNotify = (() => {
    const STORAGE_KEY = 'im_last_alert_id';
    const STORAGE_ENABLED = 'im_notify_enabled';
    const POLL_MS = 20000;

    let timer = null;

    // --- Preference utilisateur -------------------------------------
    function isEnabled() {
        try {
            return localStorage.getItem(STORAGE_ENABLED) === '1';
        } catch (e) {
            return false;
        }
    }

    function setEnabled(on) {
        try {
            localStorage.setItem(STORAGE_ENABLED, on ? '1' : '0');
        } catch (e) { /* mode prive : la preference ne survivra pas */ }
    }

    function lastSeenId() {
        try {
            return parseInt(localStorage.getItem(STORAGE_KEY) || '0', 10) || 0;
        } catch (e) {
            return 0;
        }
    }

    function setLastSeenId(id) {
        try {
            localStorage.setItem(STORAGE_KEY, String(id));
        } catch (e) { /* ignore */ }
    }

    // --- Autorisation navigateur ------------------------------------
    function supported() {
        return typeof window !== 'undefined' && 'Notification' in window;
    }

    async function requestPermission() {
        if (!supported()) {
            return 'unsupported';
        }
        if (Notification.permission === 'granted') {
            return 'granted';
        }
        if (Notification.permission === 'denied') {
            // Le navigateur ne redemandera pas : l'utilisateur doit lever le
            // blocage lui-meme dans les parametres du site.
            return 'denied';
        }
        try {
            return await Notification.requestPermission();
        } catch (e) {
            return 'denied';
        }
    }

    // --- Affichage ---------------------------------------------------
    const ICONS = {
        critical: '⛔',
        warning: '⚠️',
        info: 'ℹ️',
    };

    function show(alert) {
        if (!supported() || Notification.permission !== 'granted') {
            return;
        }
        const icon = ICONS[alert.severity] || ICONS.info;
        const title = `${icon} ${alert.severity === 'critical' ? 'Alerte critique' : 'Intranet Monitor'}`;

        const n = new Notification(title, {
            body: alert.message,
            // tag : une alerte deja affichee n'est pas empilee en double
            tag: 'im-alert-' + alert.id,
            // Une alerte critique reste affichee jusqu'a action de l'utilisateur.
            requireInteraction: alert.severity === 'critical',
        });

        n.onclick = () => {
            window.focus();
            window.location.href = 'alerts.php';
            n.close();
        };
    }

    // --- Boucle de verification --------------------------------------
    async function poll() {
        if (!isEnabled() || !supported() || Notification.permission !== 'granted') {
            return;
        }
        try {
            const data = await IM.apiGet('/alerts.php', { unread: 1, limit: 20 });
            const alerts = (data && data.alerts) || [];
            if (!alerts.length) {
                return;
            }

            const seen = lastSeenId();
            // L'API renvoie les plus recentes d'abord : on notifie dans l'ordre
            // chronologique pour que la plus recente arrive en dernier.
            const fresh = alerts.filter(a => Number(a.id) > seen).reverse();

            fresh.forEach(show);

            const maxId = Math.max(...alerts.map(a => Number(a.id)));
            if (maxId > seen) {
                setLastSeenId(maxId);
            }
        } catch (e) {
            // Session expiree ou serveur injoignable : on reessaiera au
            // prochain cycle, sans bruit dans la console.
        }
    }

    function start() {
        if (timer) {
            clearInterval(timer);
        }
        // Premiere synchronisation silencieuse : on enregistre l'etat courant
        // sans notifier, pour ne pas inonder l'utilisateur a l'activation.
        timer = setInterval(poll, POLL_MS);
    }

    async function primeBaseline() {
        try {
            const data = await IM.apiGet('/alerts.php', { unread: 1, limit: 1 });
            const alerts = (data && data.alerts) || [];
            if (alerts.length) {
                setLastSeenId(Number(alerts[0].id));
            }
        } catch (e) { /* ignore */ }
    }

    // --- Bouton de la barre superieure -------------------------------
    function updateButton(btn) {
        if (!btn) {
            return;
        }
        const icon = btn.querySelector('i');
        if (!supported()) {
            btn.title = 'Notifications non supportees par ce navigateur';
            btn.disabled = true;
            if (icon) icon.className = 'bi bi-bell-slash';
            return;
        }
        if (Notification.permission === 'denied') {
            btn.title = 'Notifications bloquees dans les parametres du navigateur';
            if (icon) icon.className = 'bi bi-bell-slash';
            return;
        }
        const on = isEnabled() && Notification.permission === 'granted';
        btn.title = on ? 'Notifications activees — cliquer pour desactiver'
                       : 'Activer les notifications du navigateur';
        btn.classList.toggle('active', on);
        if (icon) icon.className = on ? 'bi bi-bell-fill' : 'bi bi-bell';
    }

    function initButton() {
        const btn = document.getElementById('notifyToggle');
        if (!btn) {
            return;
        }
        updateButton(btn);

        btn.addEventListener('click', async () => {
            if (!supported()) {
                return;
            }
            if (isEnabled() && Notification.permission === 'granted') {
                setEnabled(false);
                updateButton(btn);
                return;
            }
            const result = await requestPermission();
            if (result === 'granted') {
                setEnabled(true);
                await primeBaseline();   // ne pas notifier l'historique existant
                new Notification('✅ Notifications activees', {
                    body: 'Vous serez averti des nouvelles alertes.',
                    tag: 'im-notify-on',
                });
            } else if (result === 'denied') {
                alert("Les notifications sont bloquees pour ce site.\n\n"
                    + "Autorisez-les dans les parametres du navigateur "
                    + "(icone a gauche de la barre d'adresse), puis reessayez.");
            }
            updateButton(btn);
        });
    }

    function init() {
        initButton();
        start();
    }

    return { init, isEnabled, setEnabled, requestPermission, show };
})();

document.addEventListener('DOMContentLoaded', () => {
    if (typeof IM !== 'undefined') {
        IMNotify.init();
    }
});
