/**
 * Intranet Monitor Pro - logique frontend commune (theme, sidebar, helpers AJAX).
 */

const IM = (() => {
    const API_BASE = window.IM_API_BASE || '/MONITOR/server/api';

    function initTheme() {
        const stored = (() => {
            try { return localStorage.getItem('im_theme'); } catch (e) { return null; }
        })();
        if (stored) {
            document.documentElement.setAttribute('data-theme', stored);
        }
        const toggle = document.getElementById('themeToggle');
        if (toggle) {
            toggle.addEventListener('click', () => {
                const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
                const next = current === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', next);
                try { localStorage.setItem('im_theme', next); } catch (e) { /* ignore */ }
            });
        }
    }

    function initSidebar() {
        const btn = document.getElementById('sidebarToggle');
        const sidebar = document.querySelector('.im-sidebar');
        if (btn && sidebar) {
            btn.addEventListener('click', () => sidebar.classList.toggle('open'));
        }
    }

    async function apiGet(path, params = {}) {
        const url = new URL(API_BASE + path, window.location.origin);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== '' && v !== null && v !== undefined) url.searchParams.set(k, v);
        });
        const res = await fetch(url.toString(), { credentials: 'same-origin' });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    async function apiPost(path, body = {}) {
        const res = await fetch(API_BASE + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    function badge(status, onText = 'OUI', offText = 'NON') {
        const cls = status ? 'on' : 'off';
        const text = status ? onText : offText;
        return `<span class="badge-dot ${cls}">${text}</span>`;
    }

    function timeAgo(dateStr) {
        if (!dateStr) return 'jamais';
        const d = new Date(dateStr.replace(' ', 'T'));
        const diffSec = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diffSec < 5) return 'a l\'instant';
        if (diffSec < 60) return `il y a ${diffSec}s`;
        if (diffSec < 3600) return `il y a ${Math.floor(diffSec / 60)} min`;
        if (diffSec < 86400) return `il y a ${Math.floor(diffSec / 3600)} h`;
        return `il y a ${Math.floor(diffSec / 86400)} j`;
    }

    function escapeHtml(str) {
        if (str === null || str === undefined) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    document.addEventListener('DOMContentLoaded', () => {
        initTheme();
        initSidebar();
    });

    return { apiGet, apiPost, badge, timeAgo, escapeHtml, API_BASE };
})();
