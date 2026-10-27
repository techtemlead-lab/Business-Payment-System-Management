<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title', ($social['title'] ?? null) ?: __('site.meta_title_default'))</title>
@include('reference.partials.social-meta', ['social' => $social ?? \App\Support\SocialShareMeta::homepage()])
<link rel="icon" href="/reference/favicon.png"><link rel="stylesheet" href="/reference/fontawesome-local.css"><link rel="stylesheet" href="/reference/instrument-sans-local.css">
<link rel="preconnect" href="https://fonts.bunny.net"><link href="https://fonts.bunny.net/css?family=noto-sans-bengali:400,500,600,700&display=swap" rel="stylesheet">
@vite(['resources/css/reference-home.css','resources/js/reference-home.ts'])</head>
<body class="ref-home locale-{{ app()->getLocale() }} @yield('bodyClass')">
@include('reference.header')
@if(config('madina.preview_mode'))
<aside class="ref-preview-banner" role="status"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>{{ __('site.preview_banner') }}</span></aside>
@endif
@yield('content')
@include('reference.footer')
</body></html>
