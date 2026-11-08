@php
    $selectedRating = max(1, min(5, (int) old('rating', 5)));
@endphp
<section class="ref-content-card ref-product-reviews" id="product-reviews" aria-labelledby="product-reviews-title">
    <h2 id="product-reviews-title">{{ __('product_facts.reviews_title') }}</h2>

    @if(session('product_review_received'))
        <div class="ref-alert ref-notice" role="status"><b>{{ __('product_facts.review_received') }}</b></div>
    @endif

    @forelse($reviews as $review)
        <article class="ref-product-review">
            <div class="ref-testimonial-stars" role="img" aria-label="{{ __('site.testimonial_stars_label', ['count' => \App\Support\LocalDigits::text($review['rating'])]) }}">
                @for($i = 1; $i <= 5; $i++)
                    <span class="ref-star {{ $i <= $review['rating'] ? 'is-filled' : 'is-empty' }}" aria-hidden="true">★</span>
                @endfor
            </div>
            <p class="ref-product-review-text">{{ $review['message'] }}</p>
            <small class="ref-product-review-author"><b>{{ $review['name'] }}</b> · {{ $review['location'] }}</small>
        </article>
    @empty
        <p>{{ __('product_facts.reviews_empty') }}</p>
    @endforelse

    <h3 class="ref-product-review-form-title">{{ __('product_facts.review_form_title') }}</h3>
    <p>{{ __('product_facts.review_form_hint') }}</p>
    <form method="post" action="{{ route('products.reviews.store', $item['id'], false) }}" class="ref-feedback-form">
        @csrf
        <input type="text" name="website" value="" tabindex="-1" autocomplete="off" class="ref-honeypot" aria-hidden="true">

        <div class="ref-feedback-meta-row">
            <label class="ref-feedback-field">
                <span class="ref-feedback-label">{{ __('site.feedback_name') }}</span>
                <input type="text" name="name" value="{{ old('name', auth()->user()->name ?? '') }}" required maxlength="150" class="ref-feedback-input">
                @error('name')<small class="ref-feedback-error">{{ $message }}</small>@enderror
            </label>
            <label class="ref-feedback-field">
                <span class="ref-feedback-label">{{ __('site.feedback_location') }}</span>
                <input type="text" name="location" value="{{ old('location') }}" maxlength="120" placeholder="{{ __('site.feedback_location_ph') }}" class="ref-feedback-input">
                @error('location')<small class="ref-feedback-error">{{ $message }}</small>@enderror
            </label>
        </div>

        <fieldset class="ref-star-rating" data-selected="{{ $selectedRating }}">
            <legend class="ref-feedback-label">{{ __('site.feedback_rating') }}</legend>
            <div class="ref-star-rating-choices">
                @for($i = 5; $i >= 1; $i--)
                    <input type="radio" class="ref-star-rating-input" name="rating" id="product-review-rating-{{ $i }}" value="{{ $i }}" @checked($selectedRating === $i) required>
                    <label class="ref-star-rating-option" for="product-review-rating-{{ $i }}" title="{{ __('site.feedback_rating_stars', ['count' => \App\Support\LocalDigits::text($i)]) }}">
                        <span class="ref-sr-only">{{ __('site.feedback_rating_stars', ['count' => \App\Support\LocalDigits::text($i)]) }}</span>
                        <span class="ref-star-rating-glyph" aria-hidden="true">★</span>
                    </label>
                @endfor
            </div>
            @error('rating')<small class="ref-feedback-error">{{ $message }}</small>@enderror
        </fieldset>

        <label class="ref-feedback-field">
            <span class="ref-feedback-label">{{ __('site.feedback_message') }}</span>
            <textarea name="message" rows="4" required minlength="20" maxlength="2000" class="ref-feedback-input">{{ old('message') }}</textarea>
            @error('message')<small class="ref-feedback-error">{{ $message }}</small>@enderror
        </label>

        <button type="submit" class="ref-primary-button">{{ __('product_facts.review_submit') }}</button>
    </form>
</section>
