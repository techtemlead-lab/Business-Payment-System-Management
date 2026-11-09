{{--
    Owner text is always escaped and shown as plain paragraphs. A paragraph whose first line starts "1." or "১." is a section.
    With asPage the text gets its own card and, when it has three or more sections, a list of them to jump to.
--}}
@php
    $asPage = $asPage ?? false;
    $blocks = [];
    foreach (preg_split('/\r?\n\r?\n+/u', trim($text)) as $paragraph) {
        $paragraphLines = preg_split('/\r?\n/u', trim($paragraph));
        if (trim($paragraph) === '') {
            continue;
        }
        $heading = preg_match('/^[0-9০-৯]+\.\s+/u', $paragraphLines[0]) === 1 ? array_shift($paragraphLines) : null;
        $blocks[] = ['heading' => $heading, 'body' => trim(implode("\n", $paragraphLines))];
    }
    $headings = array_values(array_filter(array_column($blocks, 'heading')));
    $hasContentsList = $asPage && count($headings) >= 3;
    $sectionNumber = 0;
@endphp
@if($asPage)
<div class="ref-reading {{ $hasContentsList ? 'has-contents' : '' }}">
    @if($hasContentsList)
    <nav class="ref-reading-contents" aria-labelledby="reading-contents-title">
        <h2 id="reading-contents-title">{{ __('site.on_this_page') }}</h2>
        <ol>
            @foreach($headings as $index => $headingText)
            <li><a href="#part-{{ $index + 1 }}">{{ $headingText }}</a></li>
            @endforeach
        </ol>
    </nav>
    @endif
    <section class="ref-content-card ref-legal-body-card">
@endif
        <div class="ref-legal-document">
            @foreach($blocks as $block)
                @if($block['heading'] !== null)
                    <div class="ref-legal-section" id="part-{{ ++$sectionNumber }}">
                        <h2 class="ref-legal-heading">{{ $block['heading'] }}</h2>
                        @if($block['body'] !== '')
                            <div class="ref-content-prose ref-legal-prose">{{ $block['body'] }}</div>
                        @endif
                    </div>
                @else
                    <div class="ref-content-prose ref-legal-prose">{{ $block['body'] }}</div>
                @endif
            @endforeach
        </div>
@if($asPage)
    </section>
</div>
@endif
