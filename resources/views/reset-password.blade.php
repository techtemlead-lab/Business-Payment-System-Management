<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="referrer" content="no-referrer"><meta name="robots" content="noindex,nofollow">
    <title>Set your password · Madina</title>
    @vite('resources/css/app.css')
</head>
<body>
<div class="auth-page">
    <section class="auth-story"><a href="/" class="brand">madina<small>NATURAL HEALTH CARE</small></a><div><div class="eyebrow">YOUR PARTNER WORKSPACE</div><h1>A fresh start.<br>Made for you.</h1><p>Your account is created and maintained by company staff.</p></div><span>ROOTED IN NATURE. CONNECTED BY CARE.</span></section>
    <main class="auth-main"><a href="/" class="text-link">← Back to website</a><div class="auth-form">
        <h1>Set your password.</h1><p>Use at least 12 characters with uppercase, lowercase and a number.</p>
        @if($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
        <form class="action-form" action="/reset-password" method="post">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label>Email address<input required type="email" name="email" autocomplete="username" value="{{ old('email', $email) }}"></label>
            <label>New password<input required type="password" name="password" minlength="12" autocomplete="new-password"></label>
            <label>Confirm password<input required type="password" name="password_confirmation" minlength="12" autocomplete="new-password"></label>
            <button class="button dark">Set password →</button>
        </form>
    </div></main>
</div>
</body>
</html>
