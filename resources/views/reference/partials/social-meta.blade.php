@php
    /** @var array{title?:string,description?:string,url?:string,image?:?string,image_width?:?int,image_height?:?int,image_type?:?string,type?:string,site_name?:string} $social */
    $social = $social ?? [];
    $title = $social['title'] ?? __('site.meta_title_default');
    $description = $social['description'] ?? __('site.meta_description');
    $url = $social['url'] ?? url()->current();
    $fallbackImage = \App\Support\SocialShareMeta::absoluteUrl(\App\Support\SocialShareMeta::DefaultShareImage, true);
    $image = $social['image'] ?? $fallbackImage;
    $imageWidth = $social['image_width'] ?? null;
    $imageHeight = $social['image_height'] ?? null;
    $imageType = $social['image_type'] ?? null;
    $type = $social['type'] ?? 'website';
    $siteName = $social['site_name'] ?? config('app.name', 'Madina Natural Health Care');
@endphp
<meta name="description" content="{{ $description }}">
<link rel="canonical" href="{{ $url }}">
<meta property="og:type" content="{{ $type }}">
<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $url }}">
@if($image)
<meta property="og:image" content="{{ $image }}">
<meta property="og:image:secure_url" content="{{ $image }}">
@if($imageType)<meta property="og:image:type" content="{{ $imageType }}">@endif
@if($imageWidth)<meta property="og:image:width" content="{{ $imageWidth }}">@endif
@if($imageHeight)<meta property="og:image:height" content="{{ $imageHeight }}">@endif
<meta property="og:image:alt" content="{{ $title }}">
@endif
<meta name="twitter:card" content="{{ $image ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
@if($image)
<meta name="twitter:image" content="{{ $image }}">
@endif
