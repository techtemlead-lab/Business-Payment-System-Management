<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · {{ __('site.company_bar') }}</title>
    <meta name="robots" content="noindex">
    <link rel="icon" href="/reference/favicon.png">
    <link rel="stylesheet" href="/reference/fontawesome-local.css">
    <link rel="stylesheet" href="/reference/instrument-sans-local.css">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=noto-sans-bengali:400,500,600,700&display=swap" rel="stylesheet">
    @vite(['resources/css/reference-home.css'])
</head>
<body class="ref-home locale-{{ app()->getLocale() }} ref-error-page">
    <header class="ref-company-bar" role="banner">
        <div class="ref-company-bar-inner">
            <span class="ref-bar-flourish left" aria-hidden="true"><span class="ref-bar-line"></span><i class="fa-solid fa-leaf"></i></span>
            <span class="ref-bar-text">{{ __('site.company_bar') }}</span>
            <span class="ref-bar-flourish right" aria-hidden="true"><i class="fa-solid fa-leaf"></i><span class="ref-bar-line"></span></span>
        </div>
    </header>
    <header class="ref-header">
        <div class="ref-container ref-header-inner" style="justify-content:center">
            <a class="ref-logo ref-madina-logo" href="/" aria-label="{{ __('site.home') }}">
                <img src="/madina-header-logo.png?v=7" width="180" height="48" style="height:auto;max-height:48px;width:auto;" alt="{{ __('site.company_bar') }}" decoding="async">
            </a>
        </div>
    </header>
    <main id="main" class="ref-container ref-inner-main ref-error-main">
        <article class="ref-content-card ref-error-card">
            <p class="ref-error-code">@yield('code')</p>
            <h1>@yield('heading')</h1>
            <p class="ref-error-body">@yield('message')</p>
            <div class="ref-error-actions">
                <a href="/" class="ref-primary-button">{{ __('site.error_back_home') }}</a>
                <a href="/contact" class="ref-secondary-button">{{ __('site.contact_us') }}</a>
            </div>
        </article>
    </main>
    <footer class="ref-footer" style="margin-top:auto">
        <div class="ref-container ref-footer-bottom" style="border-top:0">
            <span>{{ __('site.timezone_currency') }}</span>
            <span>&copy; {{ date('Y') }} {{ __('site.company_bar') }}</span>
        </div>
    </footer>
</body>
</html>
