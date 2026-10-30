/* Car Rental - shared page behaviour (menus, theme, confirmations). No libraries. */
(function () {
    'use strict';
    var root = document.documentElement;
    var body = document.body;

    /* Theme: remembers light/dark per browser; otherwise follows the system setting. */
    function currentTheme() {
        var set = root.getAttribute('data-theme');
        if (set) return set;
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    }
    var themeBtn = document.getElementById('themeToggle');
    function paintThemeButton() {
        if (themeBtn) themeBtn.textContent = currentTheme() === 'dark' ? 'Light mode' : 'Dark mode';
    }
    if (themeBtn) {
        themeBtn.addEventListener('click', function () {
            var next = currentTheme() === 'dark' ? 'light' : 'dark';
            root.setAttribute('data-theme', next);
            try { localStorage.setItem('cr-theme', next); } catch (e) { /* private mode */ }
            paintThemeButton();
        });
        paintThemeButton();
    }

    /* Drop-down menus in the top bar: <button data-menu="id"> opens <ul id="id">. One open at a time. */
    var menuButtons = document.querySelectorAll('[data-menu]');
    function closeMenus(except) {
        menuButtons.forEach(function (b) {
            if (b === except) return;
            b.setAttribute('aria-expanded', 'false');
            var m = document.getElementById(b.getAttribute('data-menu'));
            if (m) m.classList.remove('open');
        });
    }
    menuButtons.forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var menu = document.getElementById(btn.getAttribute('data-menu'));
            if (!menu) return;
            var open = !menu.classList.contains('open');
            closeMenus(btn);
            menu.classList.toggle('open', open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.nav-menu')) closeMenus(null);
    });

    /* Whole menu on small screens. */
    var navToggle = document.getElementById('navToggle');
    function setNav(open) {
        body.classList.toggle('nav-open', open);
        if (navToggle) {
            navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            navToggle.textContent = open ? 'Close' : 'Menu';
        }
    }
    if (navToggle) navToggle.addEventListener('click', function () { setNav(!body.classList.contains('nav-open')); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeMenus(null); setNav(false); }
    });

    /* Ask before dangerous actions: <a data-confirm="Delete this car?"> or <button data-confirm="...">. */
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (el && !window.confirm(el.getAttribute('data-confirm'))) {
            e.preventDefault();
            e.stopImmediatePropagation();
        }
    }, true);

    /* Show / hide password: <button type="button" data-password-toggle="inputId">. */
    document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var input = document.getElementById(btn.getAttribute('data-password-toggle'));
            if (!input) return;
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            btn.textContent = show ? 'Hide' : 'Show';
        });
    });

    /* Wide tables scroll sideways on phones instead of breaking the page. */
    document.querySelectorAll('.app-content table').forEach(function (table) {
        var p = table.parentElement;
        if (p && p.classList.contains('table-wrap')) return;
        var wrap = document.createElement('div');
        wrap.className = 'table-wrap';
        p.insertBefore(wrap, table);
        wrap.appendChild(table);
    });

    /* Prevent double submits: disable the clicked submit button once the form is sent. */
    /* Only normal POST forms that stay in this tab (filters and downloads are left alone). */
    var submitSelector = 'button[type="submit"], button:not([type]), input[type="submit"]';
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
        if (form.hasAttribute('data-allow-resubmit') || form.getAttribute('target')) return;
        setTimeout(function () {
            if (e.defaultPrevented) return;
            form.querySelectorAll(submitSelector).forEach(function (b) { b.disabled = true; });
        }, 0);
    });
    window.addEventListener('pageshow', function (e) {
        if (!e.persisted) return; // only when the page comes back from the browser's back/forward cache
        document.querySelectorAll('form ' + submitSelector.split(', ').join(':disabled, form ') + ':disabled').forEach(function (b) {
            if (!b.hasAttribute('data-keep-disabled')) b.disabled = false;
        });
    });
})();
