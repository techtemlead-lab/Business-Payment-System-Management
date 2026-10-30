<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
@include('partials.theme-boot')
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="#011627">
<title>@yield('title', (is_array($social ?? null) ? ($social['title'] ?? null) : null) ?: __('site.meta_title_default'))</title>
@if(($social ?? null) !== false)
@include('reference.partials.social-meta', ['social' => is_array($social ?? null) ? $social : \App\Support\SocialShareMeta::homepage()])
@endif
<link rel="icon" href="/favicon.ico">
<link rel="icon" type="image/png" href="/reference/favicon.png?v=8">
<link rel="apple-touch-icon" href="/reference/favicon.png?v=8">
<link rel="manifest" href="/manifest.webmanifest">
<link rel="preload" as="image" href="/madina-header-logo.png?v=9" fetchpriority="high">
<link rel="stylesheet" href="/reference/instrument-sans-local.css">
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=instrument-serif:400,400i,700&display=swap">
@if(app()->getLocale() === 'bn')
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=noto-sans-bengali:400,500,600,700&display=swap">
@endif
{{-- Load Font Awesome on the critical path so header/category icons never render as empty boxes. --}}
<link rel="preload" href="/reference/fonts/fa-solid-900.woff2" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="/reference/fonts/fa-brands-400.woff2" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="/reference/fontawesome-local.css">
@vite(['resources/css/reference-home.css','resources/js/reference-home.ts'])</head>
<body class="ref-home locale-{{ app()->getLocale() }} @yield('bodyClass')">
<div class="ref-ambient-canvas" aria-hidden="true">
    <div class="ref-ambient-orb ref-orb-emerald"></div>
    <div class="ref-ambient-orb ref-orb-flame"></div>
    <div class="ref-ambient-orb ref-orb-indigo"></div>
    <div class="ref-ambient-grid"></div>
</div>
@include('reference.header')
@if(config('madina.preview_mode'))
<aside class="ref-preview-banner" role="status"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>{{ __('site.preview_banner') }}</span></aside>
@endif
@yield('content')
@include('reference.footer')
<script @if($cspNonce = request()?->attributes?->get('csp_nonce')) nonce="{{ $cspNonce }}" @endif>
if('serviceWorker' in navigator && (location.protocol==='https:' || location.hostname==='localhost' || location.hostname==='127.0.0.1')){
  window.addEventListener('load',function(){navigator.serviceWorker.register('/sw.js').catch(function(){});});
}
</script>
</body></html>
