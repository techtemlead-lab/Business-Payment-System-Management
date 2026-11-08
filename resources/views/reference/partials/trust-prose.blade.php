{{-- Owner text is always escaped and shown as plain paragraphs, the same way as the privacy and terms pages. --}}
<div class="ref-legal-document">
    @foreach(preg_split('/\r?\n\r?\n+/u', trim($text)) as $paragraph)
        @php
            $trimmedParagraph = trim($paragraph);
            $paragraphLines = preg_split('/\r?\n/u', $trimmedParagraph);
            $isSectionHeading = preg_match('/^[0-9১-৯]+\.\s+/u', $paragraphLines[0] ?? '') === 1;
        @endphp
        @if($trimmedParagraph !== '' && $isSectionHeading)
            <div class="ref-legal-section">
                <h2 class="ref-legal-heading">{{ array_shift($paragraphLines) }}</h2>
                @if(filled(trim(implode("\n", $paragraphLines))))
                    <div class="ref-content-prose ref-legal-prose" style="white-space:pre-line;">{{ implode("\n", $paragraphLines) }}</div>
                @endif
            </div>
        @elseif($trimmedParagraph !== '')
            <div class="ref-content-prose ref-legal-prose" style="white-space:pre-line;">{{ $trimmedParagraph }}</div>
        @endif
    @endforeach
</div>
