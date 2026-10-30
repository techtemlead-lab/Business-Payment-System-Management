{{-- Progressive first paint: top progress + splash only if load is slow (YouTube-style). --}}
<style @if($cspNonce = request()?->attributes?->get('csp_nonce')) nonce="{{ $cspNonce }}" @endif>
html.ref-booting{--ref-boot-progress:0}
.ref-page-progress{position:fixed;inset:0 auto auto 0;z-index:10000;height:3px;width:100%;pointer-events:none;background:transparent;opacity:0;transition:opacity .2s ease}
.ref-page-progress.is-active{opacity:1}
.ref-page-progress > span{display:block;height:100%;width:var(--ref-boot-progress,12%);max-width:100%;background:linear-gradient(90deg,#006837,#f34c20,#006837);background-size:200% 100%;border-radius:0 2px 2px 0;box-shadow:0 0 10px rgba(243,76,32,.35);transition:width .35s cubic-bezier(.16,1,.3,1);animation:refProgressSheen 1.1s linear infinite}
.ref-page-boot{position:fixed;inset:0;z-index:9999;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:14px;background:linear-gradient(180deg,#011627 0%,#022c1b 55%,#011627 100%);color:#fff;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .28s ease,visibility .28s ease}
html.ref-boot-visible:not(.ref-boot-ready) .ref-page-boot{opacity:1;visibility:visible;pointer-events:auto}
.ref-page-boot img{width:88px;height:88px;object-fit:contain;filter:drop-shadow(0 8px 24px rgba(0,0,0,.35))}
.ref-page-boot-bar{width:96px;height:3px;border-radius:99px;background:rgba(255,255,255,.15);overflow:hidden}
.ref-page-boot-bar > i{display:block;height:100%;width:40%;border-radius:inherit;background:#4ade80;animation:refBootPulse 1s ease-in-out infinite}
@keyframes refProgressSheen{to{background-position:-200% 0}}
@keyframes refBootPulse{0%,100%{transform:translateX(0)}50%{transform:translateX(150%)}}
@media(prefers-reduced-motion:reduce){.ref-page-progress > span,.ref-page-boot-bar > i{animation:none!important}.ref-page-boot,.ref-page-progress{transition:none!important}}
</style>
<div class="ref-page-progress" id="refPageProgress" role="progressbar" aria-hidden="true" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span></span></div>
<div class="ref-page-boot" id="refPageBoot" aria-hidden="true">
    <img src="/madina-header-logo.png?v=9" width="88" height="88" alt="" decoding="async" fetchpriority="high">
    <div class="ref-page-boot-bar" aria-hidden="true"><i></i></div>
    <span class="ref-sr-only">{{ __('site.loading_experience') }}</span>
</div>
<script @if($cspNonce = request()?->attributes?->get('csp_nonce')) nonce="{{ $cspNonce }}" @endif>
(function () {
    var root = document.documentElement;
    root.classList.add('ref-booting');
    root.style.setProperty('--ref-boot-progress', '14%');
    var progress = document.getElementById('refPageProgress');
    if (progress) {
        progress.classList.add('is-active');
        progress.setAttribute('aria-hidden', 'false');
        progress.setAttribute('aria-valuenow', '14');
    }
    var splashTimer = window.setTimeout(function () {
        root.classList.add('ref-boot-visible');
        var boot = document.getElementById('refPageBoot');
        if (boot) boot.setAttribute('aria-hidden', 'false');
    }, 140);
    function markReady() {
        window.clearTimeout(splashTimer);
        root.style.setProperty('--ref-boot-progress', '82%');
        if (progress) progress.setAttribute('aria-valuenow', '82');
        root.classList.add('ref-boot-ready');
        var boot = document.getElementById('refPageBoot');
        if (boot) boot.setAttribute('aria-hidden', 'true');
    }
    function markDone() {
        root.style.setProperty('--ref-boot-progress', '100%');
        if (progress) progress.setAttribute('aria-valuenow', '100');
        window.setTimeout(function () {
            root.classList.remove('ref-booting', 'ref-boot-visible');
            root.classList.add('ref-boot-done');
            if (progress) {
                progress.classList.remove('is-active');
                progress.setAttribute('aria-hidden', 'true');
            }
            root.style.removeProperty('--ref-boot-progress');
        }, 180);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', markReady, { once: true });
    } else {
        markReady();
    }
    if (document.readyState === 'complete') {
        markDone();
    } else {
        window.addEventListener('load', markDone, { once: true });
    }
    window.__madinaPageBoot = { markReady: markReady, markDone: markDone };
})();
</script>
