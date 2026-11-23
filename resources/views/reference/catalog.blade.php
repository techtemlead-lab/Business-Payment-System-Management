@extends('reference.layout')
@section('title', __('site.products').' · '.\App\Support\SiteCustomization::brandName())
@section('bodyClass','ref-catalog-page')
@section('content')
@php
    $chrome = fn (string $key, ?string $custom = null): string => \App\Support\SiteChrome::text($key, $custom);
@endphp
<main id="main" class="ref-container ref-inner-main">
<nav class="ref-breadcrumb" aria-label="{{ __('site.breadcrumb') }}"><a href="/" aria-label="{{ __('site.home') }}"><i class="fa-solid fa-house" aria-hidden="true"></i></a><span>/</span><a href="/products">{{ __('site.products') }}</a>@if(request('category'))<span>/</span><span>{{ request('category') }}</span>@endif</nav>
@component('reference.partials.page-plate', ['title' => request('category') ?: $chrome('catalog_note_title'), 'lead' => $chrome('catalog_note_body'), 'icon' => 'fa-seedling'])
    @if($publicPageEnabled['dealers'] ?? true)<a href="/dealers" class="ref-plate-button"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> {{ $chrome('find_a_dealer') }}</a>@endif
@endcomponent
@php
    $sortChoices = ['name' => 'site.sort_name', 'price_asc' => 'site.sort_price_asc', 'price_desc' => 'site.sort_price_desc'];
    $filtersAreOn = filled(request('category')) || filled(request('concern')) || filled(request('min_price')) || filled(request('max_price')) || filled(request('in_stock'));
    $allProductsLabel = $chrome('all_products', $siteCustomization['catalog_all_products_label'] ?? null);
@endphp
<div class="pf-layout{{ request('q') ? ' is-searching' : '' }}">
{{-- One row of drop-downs on a computer, two to a row on a phone; choosing a value applies it at once, only the typed price keeps its own button. --}}
<form method="get" action="/products#catalog-results" class="pf-bar" data-filter-autosubmit aria-label="{{ __('site.catalog_filter_title') }}">
<input type="hidden" name="q" value="{{ request('q') }}">
<div class="pf-grid">
<label class="pf-field"><span class="pf-label">{{ $chrome('categories', $siteCustomization['catalog_categories_title'] ?? null) }}</span><select name="category" class="pf-select{{ request('category') ? ' is-on' : '' }}"><option value="">{{ $allProductsLabel }}</option>@foreach($catalogCategories as $category)<option value="{{ $category }}" @selected(request('category') === $category)>{{ $category }}</option>@endforeach</select></label>
@if($catalogConcerns !== [])<label class="pf-field"><span class="pf-label">{{ $chrome('catalog_concern_title', $siteCustomization['catalog_concern_title'] ?? null) }}</span><select name="concern" class="pf-select{{ request('concern') ? ' is-on' : '' }}"><option value="">{{ $allProductsLabel }}</option>@foreach($catalogConcerns as $concern)<option value="{{ $concern['id'] }}" @selected((int) request('concern') === $concern['id'])>{{ \App\Models\ProductConcern::nameForVisitor($concern['name_bn'], $concern['name_en']) }}</option>@endforeach</select></label>@endif
<label class="pf-field"><span class="pf-label">{{ __('site.availability') }}</span><select name="in_stock" class="pf-select{{ request('in_stock') ? ' is-on' : '' }}"><option value="">{{ __('site.any') }}</option><option value="1" @selected(request('in_stock'))>{{ $chrome('in_stock_only', $siteCustomization['catalog_instock_label'] ?? null) }}</option></select></label>
<label class="pf-field"><span class="pf-label">{{ $chrome('sort_by', $siteCustomization['catalog_sort_by_label'] ?? null) }}</span><select name="sort" class="pf-select">@foreach($sortChoices as $sortValue => $sortWords)<option value="{{ $sortValue }}" @selected(request('sort', 'name') === $sortValue)>{{ __($sortWords) }}</option>@endforeach</select></label>
<div class="pf-field pf-price"><span class="pf-label">{{ $chrome('mrp_range', $siteCustomization['catalog_price_title'] ?? null) }}</span><div class="pf-price-row"><input class="pf-input" type="number" inputmode="decimal" min="0" step="0.01" name="min_price" value="{{ request('min_price') }}" placeholder="{{ __('site.min') }}" aria-label="{{ __('site.min') }}"><span class="pf-dash" aria-hidden="true">–</span><input class="pf-input" type="number" inputmode="decimal" min="0" step="0.01" name="max_price" value="{{ request('max_price') }}" placeholder="{{ __('site.max') }}" aria-label="{{ __('site.max') }}"><button class="pf-apply" type="submit">{{ $chrome('apply_filters', $siteCustomization['catalog_apply_filters_btn'] ?? null) }}</button></div></div>
</div>
@if($filtersAreOn)<a href="/products{{ request('q') ? '?q='.urlencode((string) request('q')) : '' }}" class="pf-clear"><i class="fa-solid fa-xmark" aria-hidden="true"></i> {{ $chrome('clear_filters', $siteCustomization['catalog_clear_filters_btn'] ?? null) }}</a>@endif
</form>
<section class="ref-catalog-results" id="catalog-results" tabindex="-1"><p class="ref-catalog-count pf-count">@if(request('q')){{ __('site.products_found_for', ['count' => \App\Support\LocalDigits::text($catalog->total()), 'query' => request('q')]) }}@else{{ __('site.products_found', ['count' => \App\Support\LocalDigits::text($catalog->total())]) }}@endif</p>
<div class="ref-product-grid ref-catalog-grid">@forelse($catalog as $item)@include('reference.product-card',compact('item'))@empty<div class="ref-empty"><i class="fa-solid fa-box-open" aria-hidden="true"></i><h2>{{ $chrome('catalog_empty_title', $siteCustomization['catalog_empty_title'] ?? null) }}</h2><p>{{ $chrome('catalog_empty_desc', $siteCustomization['catalog_empty_desc'] ?? null) }}</p><a href="/products" class="ref-primary-button">{{ $chrome('browse_all_products', $siteCustomization['catalog_empty_btn'] ?? null) }}</a></div>@endforelse</div>
@if($catalog->hasPages())<nav class="ref-pagination" aria-label="{{ __('site.products') }}" >@if($catalog->previousPageUrl())<a href="{{ $catalog->previousPageUrl() }}">{{ __('site.pagination_previous') }}</a>@endif<span>{{ __('site.pagination_page_of', ['page' => \App\Support\LocalDigits::text($catalog->currentPage()), 'pages' => \App\Support\LocalDigits::text($catalog->lastPage())]) }}</span>@if($catalog->nextPageUrl())<a href="{{ $catalog->nextPageUrl() }}">{{ __('site.pagination_next') }}</a>@endif</nav>@endif</section></div></main>
@endsection
