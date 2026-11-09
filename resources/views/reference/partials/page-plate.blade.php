{{-- The title plate every inner public page opens with, drawn like the label on one of the company's jars. --}}
<header class="ref-page-plate">
    <div class="ref-page-plate-text">
        <h1>{{ $title }}</h1>
        @if(filled($lead ?? null))
        <p class="ref-page-plate-lead">{{ $lead }}</p>
        @endif
        @if(filled(trim((string) ($slot ?? ''))))
        <div class="ref-page-plate-extra">{{ $slot }}</div>
        @endif
    </div>
    <span class="ref-page-plate-seal" aria-hidden="true"><i class="fa-solid {{ $icon }}"></i></span>
    <svg class="ref-page-plate-sprig" viewBox="0 0 240 240" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">
        <path d="M28 232C66 170 118 112 214 26"/>
        <path d="M66 176c-30-4-48-20-54-46 28 0 48 16 54 46Z"/>
        <path d="M66 176c20-24 44-30 72-20-16 22-42 30-72 20Z"/>
        <path d="M106 130c-28-10-42-28-42-54 26 6 42 24 42 54Z"/>
        <path d="M106 130c24-20 48-22 74-8-20 20-46 24-74 8Z"/>
        <path d="M148 88c-24-14-34-34-30-58 24 10 36 30 30 58Z"/>
        <path d="M148 88c26-14 48-12 70 4-22 16-46 16-70-4Z"/>
    </svg>
</header>
