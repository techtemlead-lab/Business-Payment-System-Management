<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">@include('partials.theme-boot')<style>
/* Until the screen is drawn, show the shape of the page (menu, top bar, cards) and a word, never a blank spinner. */
#boot-skeleton{position:fixed;inset:0;z-index:5;display:flex;background:#f3f5f2;color:#334155;font:600 16px/1.5 "Hind Siliguri",system-ui,sans-serif}
#boot-skeleton .bs-side{width:224px;flex:none;background:#2e3192}
#boot-skeleton .bs-main{flex:1;min-width:0;padding:16px 20px}
#boot-skeleton .bs-bar{height:44px;border-radius:12px;background:#e2e8e4;margin-bottom:20px}
#boot-skeleton .bs-hero{height:180px;border-radius:20px;background:#d5e6dc;margin-bottom:16px}
#boot-skeleton .bs-row{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
#boot-skeleton .bs-card{height:96px;border-radius:14px;background:#e6ebe8}
#boot-skeleton .bs-hero,#boot-skeleton .bs-card,#boot-skeleton .bs-bar{animation:bs-pulse 1.4s ease-in-out infinite}
#boot-skeleton .bs-say{margin:0 0 14px;color:#006a4e}
html[data-theme="dark"] #boot-skeleton{background:#07131f;color:#cbd5e1}
html[data-theme="dark"] #boot-skeleton .bs-bar,html[data-theme="dark"] #boot-skeleton .bs-card{background:#10253a}
html[data-theme="dark"] #boot-skeleton .bs-hero{background:#12392f}
html[data-theme="dark"] #boot-skeleton .bs-say{color:#2fbf8c}
@keyframes bs-pulse{0%,100%{opacity:1}50%{opacity:.55}}
@media (max-width:900px){#boot-skeleton .bs-side{display:none}}
@media (prefers-reduced-motion:reduce){#boot-skeleton .bs-hero,#boot-skeleton .bs-card,#boot-skeleton .bs-bar{animation:none}}
</style><meta name="csrf-token" content="{{ csrf_token() }}"><meta name="color-scheme" content="light dark"><meta name="theme-color" content="#011627"><meta name="robots" content="noindex,nofollow"><meta name="application-name" content="{{ \App\Support\SiteCustomization::panelTabName() }}"><link rel="icon" href="{{ \App\Support\SiteCustomization::faviconUrl() }}"><link rel="apple-touch-icon" href="{{ \App\Support\SiteCustomization::faviconUrl() }}">@unless(config('madina.inline_translations'))<link rel="preload" as="fetch" type="application/json" href="{{ \App\Support\TranslationBundle::url(app()->getLocale()) }}" crossorigin="anonymous">@endunless<link rel="preconnect" href="https://fonts.bunny.net"><link href="https://fonts.bunny.net/css?family=hind-siliguri:400,500,600,700&display=swap" rel="stylesheet"><link href="https://fonts.bunny.net/css?family=instrument-serif:400,400i,700&display=swap" rel="stylesheet">@viteReactRefresh @vite(['resources/css/app.css','resources/js/app.tsx','resources/css/dashboard.css'])<x-inertia::head /></head><body class="locale-{{ app()->getLocale() }}"><div id="boot-skeleton" aria-hidden="true"><div class="bs-side"></div><div class="bs-main"><p class="bs-say">{{ __('partner_ux.loading') }}</p><div class="bs-bar"></div><div class="bs-hero"></div><div class="bs-row"><div class="bs-card"></div><div class="bs-card"></div><div class="bs-card"></div><div class="bs-card"></div></div></div></div><x-inertia-app /></body></html>
