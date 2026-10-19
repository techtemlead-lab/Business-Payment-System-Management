<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $product['name'] }} · Madina</title>@vite('resources/css/app.css')</head>
<body class="public-site">
<a class="skip-link" href="#main">Skip to content</a>
@if(app()->environment('local'))<div class="demo-banner">LOCAL PREVIEW · Sample products and prices are not an approved client catalog.</div>@endif
<header class="public-header"><a href="/" class="brand">madina<small>NATURAL HEALTH CARE</small></a><nav aria-label="Main navigation"><a href="/products">Our products</a><a href="/dealers">Find a dealer</a><a href="/contact">Contact</a></nav><a class="button dark" href="{{ \App\Support\PortalHost::url('dealer', '/login') }}">Partner sign in ↗</a></header>
<main id="main" class="public-section">
    <a class="text-link" href="/products">← Back to products</a>
    <section class="product-detail">
        <div class="product-art {{ $product['tone'] }}" aria-hidden="true"><div class="mini-bottle"><div class="mini-cap"></div><b>madina</b><span>{{ $product['category'] }}</span><small>NATURAL CARE</small></div></div>
        <div><div class="eyebrow">{{ $product['category'] }} · {{ $product['sku'] }}</div><h1>{{ $product['name'] }}</h1><p class="prose">{{ $product['description'] }}</p><h2>৳{{ $product['mrp'] }} <small>MRP / {{ $product['unit'] }}</small></h2><p class="muted">{{ $product['stock'] > 0 ? 'In stock' : 'Awaiting stock' }}</p><a href="/contact" class="button dark">Enquire about this product ↗</a><p class="muted">Existing partners can request products from their workspace.</p></div>
    </section>
</main>
<footer class="public-footer"><span>Madina Natural Health Care</span><a href="/contact">Contact the company →</a></footer>
</body></html>
