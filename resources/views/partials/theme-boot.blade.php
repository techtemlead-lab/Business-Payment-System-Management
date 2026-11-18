{{-- Apply saved day/night preference before CSS paints to avoid a flash of the wrong theme. --}}
<meta name="madina-theme-domain" content="{{ \App\Support\PortalHost::domain() }}">
<script @if($cspNonce = request()?->attributes?->get('csp_nonce')) nonce="{{ $cspNonce }}" @endif>
(function () {
    try {
        var key = 'madina-color-scheme';
        var cookieName = 'madina_color_scheme';
        var domainMeta = document.querySelector('meta[name="madina-theme-domain"]');
        var cookieDomain = domainMeta ? String(domainMeta.getAttribute('content') || '') : '';
        function readCookie(name) {
            var parts = ('; ' + document.cookie).split('; ' + name + '=');
            if (parts.length < 2) return null;
            return decodeURIComponent(parts.pop().split(';').shift() || '');
        }
        var stored = readCookie(cookieName) || localStorage.getItem(key);
        // Day/light is the product default; OS preference is ignored until the user toggles.
        var scheme = (stored === 'light' || stored === 'dark') ? stored : 'light';
        document.documentElement.setAttribute('data-theme', scheme);
        document.documentElement.style.colorScheme = scheme;
        window.__MADINA_THEME_DOMAIN__ = cookieDomain;
        // Text size chosen with the A buttons (dealers and sub-dealers); applied before paint so the page does not jump.
        var size = localStorage.getItem('madina-text-size');
        if (size === 'small' || size === 'normal' || size === 'large' || size === 'xlarge') {
            document.documentElement.setAttribute('data-text-size', size);
        }
    } catch (e) {}
})();
</script>
