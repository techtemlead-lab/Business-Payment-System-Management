@php
    $trust = fn (string $key): string => \App\Domain\Content\PublicTrustContent::text($key);
    $listTitle = $trust('articles_title');
@endphp
@extends('reference.layout')
@section('title', $article->title.' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><a href="/articles">{{ $listTitle }}</a><span>/</span><span>{{ $article->title }}</span></nav>
    <article class="ref-content-card">
        <h1>{{ $article->title }}</h1>
        @if($article->image_url)
        <img src="{{ $article->image_url }}" alt="" decoding="async" style="width:100%;height:auto;border-radius:8px;margin-bottom:18px;">
        @endif
        @include('reference.partials.trust-prose', ['text' => $article->body])
        <p class="ref-form-hint">{{ $trust('articles_disclaimer') }}</p>
    </article>
    <div class="ref-information-links" style="margin-top:22px;"><a href="/articles" class="ref-secondary-button">{{ __('public_trust.articles_back') }}</a></div>
</main>
@endsection
