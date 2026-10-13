"""Extract the approved homepage chrome into shared Blade templates once."""
from pathlib import Path
root=Path(__file__).resolve().parents[2]
views=root/'resources/views/reference'
home=views/'home.blade.php'
source=home.read_text(encoding='utf-8')
if "@extends('reference.layout')" in source:
    raise SystemExit('Shared layout already extracted.')
header=source[source.index('<a class="ref-skip"'):source.index('<main id="main"')]
footer=source[source.index('<footer class="ref-footer"'):source.index('</body>')]
prefix=source[:source.index('<!DOCTYPE html>')]
main=source[source.index('<main id="main"'):source.index('<footer class="ref-footer"')]
header=header.replace('href="#featured-products"','href="/#featured-products"')
(views/'header.blade.php').write_text(header,encoding='utf-8')
(views/'footer.blade.php').write_text(footer,encoding='utf-8')
(views/'layout.blade.php').write_text('''@php
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
''',encoding='utf-8')
home.write_text(prefix+"\n@extends('reference.layout')\n@section('title','Madina Natural Health Care · Products & Dealer Network')\n@section('content')\n"+main+"\n@endsection\n",encoding='utf-8')
print('Shared header, footer and layout created; homepage markup preserved.')
