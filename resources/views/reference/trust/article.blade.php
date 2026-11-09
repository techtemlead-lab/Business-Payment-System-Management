@php
    $trust = fn (string $key): string => \App\Domain\Content\PublicTrustContent::text($key);
    $listTitle = $trust('articles_title');
@endphp
@extends('reference.layout')
@section('title', $article->title.' · '.\App\Support\SiteCustomization::brandName())
@section('content')
<main id="main" class="ref-container ref-inner-main">
    <nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><a href="/articles">{{ $listTitle }}</a><span>/</span><span>{{ $article->title }}</span></nav>
    @include('reference.partials.page-plate', ['title' => $article->title, 'icon' => 'fa-book-open'])
    <article class="ref-content-card ref-form-page">
        @if($article->image_url)
        <img class="ref-article-picture is-full" src="{{ $article->image_url }}" alt="" decoding="async">
        @endif
        @include('reference.partials.trust-prose', ['text' => $article->body])
        <p class="ref-form-hint ref-page-note">{{ $trust('articles_disclaimer') }}</p>
    </article>
    <div class="ref-information-links ref-form-page"><a href="/articles" class="ref-secondary-button">{{ __('public_trust.articles_back') }}</a></div>
</main>
@endsection
