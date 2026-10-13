@php
    if (!isset($referenceProducts)) {
        $referenceProducts = app()->environment('local') ? json_decode(file_get_contents(public_path('reference/home-products.json')), true)['products'] : [];
    }
@endphp
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>@yield('title','Madina Natural Health Care')</title><meta name="description" content="Madina products, company information and dealer network.">
<link rel="icon" href="/reference/favicon.png"><link rel="stylesheet" href="/reference/fontawesome-local.css"><link rel="stylesheet" href="/reference/instrument-sans-local.css">
@vite(['resources/css/reference-home.css','resources/js/reference-home.ts'])</head>
<body class="ref-home @yield('bodyClass')">@include('reference.header')@yield('content')@include('reference.footer')</body></html>
