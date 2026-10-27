{{-- Apply saved day/night preference before CSS paints to avoid a flash of the wrong theme. --}}
<script>
(function () {
    try {
        var key = 'madina-color-scheme';
        var stored = localStorage.getItem(key);
        var scheme = (stored === 'light' || stored === 'dark')
            ? stored
            : (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', scheme);
        document.documentElement.style.colorScheme = scheme;
    } catch (e) {}
})();
</script>
