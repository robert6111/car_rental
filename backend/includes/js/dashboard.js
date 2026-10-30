(function () {
    function ready(fn) {
        if (document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    ready(function () {
        const sidebar = document.getElementById('sidebarMenu') || document.querySelector('.left.side-menu');
        const toggle = document.getElementById('mobileMenuToggle');
        const closeBtn = document.getElementById('sidebarCloseBtn');
        const overlay = document.getElementById('sidebarOverlay');
        const profileButton = document.getElementById('profileButton');
        const profileMenu = document.getElementById('profileMenu');

        function openSidebar() {
            if (!sidebar) return;
            sidebar.classList.add('active');
            document.body.classList.add('sidebar-open');
            if (overlay) overlay.classList.add('active');
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
        }

        function closeSidebar() {
            if (!sidebar) return;
            sidebar.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            if (overlay) overlay.classList.remove('active');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }

        if (toggle) {
            toggle.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (sidebar && sidebar.classList.contains('active')) closeSidebar();
                else openSidebar();
            });
        }

        if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
        if (overlay) overlay.addEventListener('click', closeSidebar);

        document.querySelectorAll('#sidebar-menu li.has_sub > a[href="javascript:void(0);"]').forEach(function (link) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                const parent = link.closest('li.has_sub');
                if (!parent) return;
                parent.classList.toggle('open');
            });
        });

        document.querySelectorAll('#sidebar-menu a').forEach(function (link) {
            link.addEventListener('click', function () {
                const href = link.getAttribute('href') || '';
                if (href !== '' && href !== 'javascript:void(0);' && window.innerWidth <= 1199) {
                    closeSidebar();
                }
            });
        });

        if (profileButton && profileMenu) {
            profileButton.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                profileMenu.classList.toggle('show');
            });
        }

        document.addEventListener('click', function (event) {
            if (profileMenu && !event.target.closest('.profile-dropdown')) {
                profileMenu.classList.remove('show');
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeSidebar();
                if (profileMenu) profileMenu.classList.remove('show');
            }
        });
    });
})();

function toggleDropdown() {
    const menu = document.getElementById('profileMenu');
    if (menu) menu.classList.toggle('show');
}
