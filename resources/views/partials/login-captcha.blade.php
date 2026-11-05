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
    .login-captcha-row img { width: 100%; max-width: 260px; height: auto; aspect-ratio: 220 / 72; border-radius: 8px; border: 1px solid rgba(100, 116, 139, .45); background: #f6f8fa; image-rendering: auto; }
    .login-captcha-refresh { min-height: 44px; padding: 0 14px; border-radius: 8px; border: 1px solid rgba(100, 116, 139, .55); background: transparent; color: inherit; font: inherit; cursor: pointer; }
    .login-captcha input { font-size: 1.1rem; }
    .login-captcha input:not(:placeholder-shown) { text-transform: uppercase; letter-spacing: .2em; }
    .login-captcha-hint { margin: 0; font-size: .85rem; opacity: .8; }
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
