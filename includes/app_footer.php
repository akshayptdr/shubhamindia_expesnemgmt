</main>

<script>
    // Mobile Sidebar Toggle
    const mobileMenuToggle = document.getElementById('mobileMenuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarOverlay = document.getElementById('sidebarOverlay');

    function toggleSidebar() {
        sidebar.classList.toggle('active');
        sidebarOverlay.classList.toggle('active');
        document.body.style.overflow = sidebar.classList.contains('active') ? 'hidden' : '';
    }

    if (mobileMenuToggle) {
        mobileMenuToggle.addEventListener('click', toggleSidebar);
    }

    if (sidebarOverlay) {
        sidebarOverlay.addEventListener('click', toggleSidebar);
    }

    // Search focus effects
    const focusInput = document.querySelector('.search-bar input');
    const focusBar = document.querySelector('.search-bar');

    if (focusInput && focusBar) {
        focusInput.addEventListener('focus', () => focusBar.classList.add('focused'));
        focusInput.addEventListener('blur', () => focusBar.classList.remove('focused'));

        // Search Auto-submit (Debounced)
        const searchForm = focusInput.closest('form');
        let searchTimeout;

        focusInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => {
                searchForm.submit();
            }, 500);
        });
    }

    // Sidebar Dropdown Toggle
    function toggleDropdown(btn) {
        const dropdown = btn.closest('.nav-dropdown');
        dropdown.classList.toggle('open');
    }
</script>
</body>

</html>