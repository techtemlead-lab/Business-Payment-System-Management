{{-- Picture captcha for the sign-in form. Each picture is single-use and expires after a few minutes. --}}
<div class="login-captcha" data-login-captcha>
    <label for="login-captcha-input">{{ __('auth_ui.captcha_label') }}</label>
    <div class="login-captcha-row">
        <img src="/login/captcha?t={{ now()->getTimestampMs() }}" width="220" height="72" alt="{{ __('auth_ui.captcha_alt') }}" data-login-captcha-image>
        <button type="button" class="login-captcha-refresh" data-login-captcha-refresh aria-label="{{ __('auth_ui.captcha_refresh') }}">
            <span aria-hidden="true">↻</span> {{ __('auth_ui.captcha_refresh') }}
        </button>
    </div>
    <input id="login-captcha-input" type="text" name="captcha" required maxlength="12" autocomplete="off" autocapitalize="characters" autocorrect="off" spellcheck="false" inputmode="text" placeholder="{{ __('auth_ui.captcha_placeholder') }}" @if($errors->has('captcha')) aria-invalid="true" @endif>
    <p class="login-captcha-hint">{{ __('auth_ui.captcha_hint') }}</p>
</div>
<style @if($cspNonce = request()?->attributes?->get('csp_nonce')) nonce="{{ $cspNonce }}" @endif>
    .login-captcha { display: grid; gap: 8px; margin: 4px 0 8px; }
    .login-captcha-row { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .login-captcha-row img { flex: 1 1 160px; min-width: 0; width: 100%; max-width: 260px; height: auto; aspect-ratio: 220 / 72; border-radius: 8px; border: 1px solid rgba(100, 116, 139, .45); background: #f6f8fa; image-rendering: auto; }
    .login-captcha-refresh { flex: 0 0 auto; display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 44px; padding: 0 14px; border-radius: 8px; border: 1px solid rgba(100, 116, 139, .55); background: transparent; color: inherit; font: inherit; font-size: .9rem; font-weight: 600; white-space: nowrap; cursor: pointer; transition: background-color .2s ease, border-color .2s ease, transform .12s ease; }
    .login-captcha-refresh:hover { background: rgba(100, 116, 139, .1); border-color: rgba(100, 116, 139, .8); }
    .login-captcha-refresh:active { transform: scale(.97); }
    .login-captcha-refresh span { display: inline-block; font-size: 1.1em; line-height: 1; transition: transform .45s cubic-bezier(.34, 1.56, .64, 1); }
    .login-captcha-refresh:hover span { transform: rotate(-180deg); }
    .login-captcha input:not(:placeholder-shown) { text-transform: uppercase; letter-spacing: .2em; font-weight: 600; }
    .login-captcha-hint { margin: 0; font-size: .85rem; opacity: .8; }
    /* Narrow cards (phones): the refresh button becomes a square icon so the picture stays full size. */
    .login-captcha { container-type: inline-size; }
    @container (max-width: 330px) {
        .login-captcha-row { flex-wrap: nowrap; gap: 8px; }
        .login-captcha-row img { flex: 1 1 auto; max-width: none; }
        .login-captcha-refresh { width: 48px; min-height: 48px; padding: 0; font-size: 0; gap: 0; }
        .login-captcha-refresh span { font-size: 22px; }
    }
    @media (prefers-reduced-motion: reduce) { .login-captcha-refresh, .login-captcha-refresh span { transition: none; } .login-captcha-refresh:hover span, .login-captcha-refresh:active { transform: none; } }
</style>
<script @if($cspNonce = request()?->attributes?->get('csp_nonce')) nonce="{{ $cspNonce }}" @endif>
    (function () {
        var box = document.querySelector('[data-login-captcha]');
        if (!box) { return; }
        var image = box.querySelector('[data-login-captcha-image]');
        var input = box.querySelector('input[name="captcha"]');
        box.querySelector('[data-login-captcha-refresh]').addEventListener('click', function () {
            image.src = '/login/captcha?t=' + Date.now();
            input.value = '';
            input.focus();
        });
        // A page restored from the back/forward cache would show a picture that has already been used.
        window.addEventListener('pageshow', function (event) {
            if (event.persisted) { image.src = '/login/captcha?t=' + Date.now(); input.value = ''; }
        });
    })();
</script>
