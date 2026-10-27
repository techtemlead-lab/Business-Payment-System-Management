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
<link rel="preload" as="image" href="/madina-header-logo.png?v=8" fetchpriority="high">
<link rel="stylesheet" href="/reference/instrument-sans-local.css">
@if(app()->getLocale() === 'bn')
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link rel="stylesheet" href="https://fonts.bunny.net/css?family=noto-sans-bengali:400,500,600,700&display=swap">
@endif
{{-- Defer Font Awesome so first paint is not blocked by the 100KB icon stylesheet. --}}
<link rel="stylesheet" href="/reference/fontawesome-local.css" media="print" onload="this.media='all'">
<noscript><link rel="stylesheet" href="/reference/fontawesome-local.css"></noscript>
@vite(['resources/css/reference-home.css','resources/js/reference-home.ts'])</head>
<body class="ref-home locale-{{ app()->getLocale() }} @yield('bodyClass')">
@include('reference.header')
@if(config('madina.preview_mode'))
<aside class="ref-preview-banner" role="status"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>{{ __('site.preview_banner') }}</span></aside>
@endif
@yield('content')
@include('reference.footer')
</body></html>
