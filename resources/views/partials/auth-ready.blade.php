{{-- Sign-in forms wait for the page to finish loading, so words typed too early are not lost when it redraws. --}}
<p class="auth-wait" data-auth-wait role="status" style="display:none;text-align:center;margin:8px 0 0;font-size:14px">{{ __('office_shell.page_loading') }}</p>
<script>
(function () {
    var forms = document.querySelectorAll('.company-auth-card form, .ref-auth-card form');
    var notes = document.querySelectorAll('[data-auth-wait]');
    var done = false;
    function hold() {
        forms.forEach(function (form) {
            form.querySelectorAll('input[type="text"], input[type="email"], input[type="password"]').forEach(function (field) {
                if (!field.readOnly) { field.readOnly = true; field.setAttribute('data-auth-held', '1'); }
            });
            form.querySelectorAll('button[type="submit"], button:not([type])').forEach(function (button) {
                if (!button.disabled) { button.disabled = true; button.setAttribute('data-auth-held', '1'); }
            });
        });
        notes.forEach(function (note) { note.style.display = 'block'; });
    }
    function release() {
        if (done) { return; }
        done = true;
        document.querySelectorAll('[data-auth-held]').forEach(function (element) {
            if (element.tagName === 'INPUT') { element.readOnly = false; } else { element.disabled = false; }
            element.removeAttribute('data-auth-held');
        });
        notes.forEach(function (note) { note.style.display = 'none'; });
    }
    if (document.readyState === 'complete') { return; }
    hold();
    window.addEventListener('load', release);
    // If a slow font or script never finishes, the form still opens after a few seconds.
    window.setTimeout(release, 6000);
})();
</script>
