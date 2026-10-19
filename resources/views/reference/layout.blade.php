<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title','Madina Natural Health Care')</title><meta name="description" content="Madina products, company information and dealer network.">
<link rel="icon" href="/reference/favicon.png"><link rel="stylesheet" href="/reference/fontawesome-local.css"><link rel="stylesheet" href="/reference/instrument-sans-local.css">
<link rel="preconnect" href="https://fonts.bunny.net"><link href="https://fonts.bunny.net/css?family=noto-sans-bengali:400,500,600,700&display=swap" rel="stylesheet">
@vite(['resources/css/reference-home.css','resources/js/reference-home.ts'])</head>
<body class="ref-home locale-{{ app()->getLocale() }} @yield('bodyClass')">
@include('reference.header')
@if(config('madina.preview_mode'))
<aside class="ref-container" style="padding:12px 20px;background:#fff8df;color:#664b00" role="status">Client preview · Demonstration data is retained. Financial posting is disabled.</aside>
@endif
@yield('content')
@include('reference.footer')
</body></html>
