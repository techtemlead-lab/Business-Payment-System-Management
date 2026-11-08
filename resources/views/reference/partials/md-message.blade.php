{{-- Managing Director's message: all text, the photo and the on/off switch come from the website editor. --}}
@php
    $mdMessage = \App\Support\SiteCustomization::managingDirectorMessage($siteCustomization ?? null);
    $mdPlacement = $placement ?? 'about';
    $mdIsCompact = $mdPlacement === 'home';
    $mdVisibleCount = $mdIsCompact ? 2 : 3;
    $mdParagraphs = $mdMessage['paragraphs'] ?? [];
    // The short home version trims long text with CSS, so it needs the button even when nothing else is hidden.
    $mdHasMore = $mdIsCompact
        ? count($mdParagraphs) > $mdVisibleCount || mb_strlen(implode(' ', $mdParagraphs)) > 320
        : count($mdParagraphs) > $mdVisibleCount + 1;
    $mdShown = $mdHasMore ? array_slice($mdParagraphs, 0, $mdVisibleCount) : $mdParagraphs;
    $mdHidden = $mdHasMore ? array_slice($mdParagraphs, $mdVisibleCount) : [];
@endphp
@if($mdMessage)
<section id="md-message" class="ref-md-message ref-md-message--{{ $mdPlacement }}" data-reveal aria-labelledby="md-message-title-{{ $mdPlacement }}">
    <figure class="ref-md-photo-card">
        <div class="ref-md-photo-frame">
            <img src="{{ $mdMessage['image'] }}" alt="{{ $mdMessage['image_alt'] }}" width="800" height="782" loading="{{ $mdIsCompact ? 'eager' : 'lazy' }}" decoding="async">
        </div>
        <figcaption>
            <strong>{{ $mdMessage['name'] }}</strong>
            <span>{{ $mdMessage['title'] }}</span>
        </figcaption>
    </figure>
    <div class="ref-md-content">
        <span class="ref-md-quote-mark" aria-hidden="true">&ldquo;</span>
        <h2 id="md-message-title-{{ $mdPlacement }}">{{ $mdMessage['heading'] }}</h2>
        <div class="ref-md-text">
            @foreach($mdShown as $paragraph)
            <p>{{ $paragraph }}</p>
            @endforeach
            @if($mdHasMore)
            <div class="ref-md-more" id="md-message-more-{{ $mdPlacement }}" hidden>
                @foreach($mdHidden as $paragraph)
                <p>{{ $paragraph }}</p>
                @endforeach
            </div>
            @endif
        </div>
        @if($mdHasMore)
        <button type="button" class="ref-md-toggle" data-md-toggle aria-expanded="false" aria-controls="md-message-more-{{ $mdPlacement }}" data-label-more="{{ __('site.md_message_read_more') }}" data-label-less="{{ __('site.md_message_read_less') }}">
            <span>{{ __('site.md_message_read_more') }}</span>
            <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
        </button>
        @endif
        <footer class="ref-md-signature">
            <span class="ref-md-signature-rule" aria-hidden="true"></span>
            <strong>{{ $mdMessage['name'] }}</strong>
            <span>{{ $mdMessage['title'] }}</span>
            <span class="ref-md-signature-company">{{ $mdMessage['company'] }}</span>
        </footer>
    </div>
</section>
@endif
