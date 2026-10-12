@php
    $site = $page['props']['website'] ?? [];
    $publicPage = collect($site['pages'] ?? [])->firstWhere('slug', $page['props']['slug'] ?? '');
    $isPublic = request()->routeIs('home', 'website.page');
    $title = $publicPage['title'] ?? data_get($site, 'seo.title', 'Nexora');
    $description = $publicPage['description'] ?? data_get($site, 'seo.description', '');
    if (data_get($site, 'languages.default') === 'bn' && ! $publicPage) {
        $translations = $site['translations'] ?? [];
        $title = $translations['seo.title'] ?? $title;
        $description = $translations['seo.description'] ?? $description;
    }
    $indexable = $isPublic && ($publicPage['index'] ?? data_get($site, 'seo.index', true));
@endphp
<!DOCTYPE html>
<html lang="{{ data_get($site, 'languages.default', 'en') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="{{ data_get($site, 'theme.primary', '#183c30') }}">
    <link inertia="favicon" rel="icon" href="{{ data_get($site, 'brand.favicon') ?: '/favicon.svg' }}">
    <meta inertia="robots" name="robots" content="{{ $indexable ? 'index,follow' : 'noindex,nofollow' }}">
    @if ($isPublic)
        <title inertia>{{ $title }}</title>
        <meta inertia="description" name="description" content="{{ $description }}">
        <meta inertia="og:title" property="og:title" content="{{ $title }}">
        <meta inertia="og:description" property="og:description" content="{{ $description }}">
        @if (data_get($site, 'seo.image'))
            <meta inertia="og:image" property="og:image" content="{{ url(data_get($site, 'seo.image')) }}">
        @endif
    @endif
    @viteReactRefresh
    @vite('resources/js/app.tsx')
    @inertiaHead
</head>
<body>@inertia</body>
</html>
