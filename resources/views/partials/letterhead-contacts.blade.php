{{--
    The contact lines under the company name on the stock list and the invoice:
    WhatsApp, mobile, email and website, each with its own small icon.
    Needs $company (see LetterheadContacts) and $size (a CSS font size); $localDigits turns 0-9 into Bangla digits.
--}}
@use('App\Support\LocalDigits')
@php
    $number = static fn (string $value): string => ! empty($localDigits) ? LocalDigits::text($value) : $value;
    $icon = static fn (string $line): string => '<img src="'.e($company['icons'][$line] ?? '').'" alt="" style="width:'.$size.';height:'.$size.';vertical-align:middle">';
    $first = array_values(array_filter([
        ($company['whatsapp'] ?? '') !== '' ? ['whatsapp', __('stock_print.whatsapp').': '.$number($company['whatsapp'])] : null,
        ($company['mobile'] ?? '') !== '' ? ['mobile', __('stock_print.mobile').': '.$number($company['mobile'])] : null,
    ]));
    $second = array_values(array_filter([
        ($company['email'] ?? '') !== '' ? ['email', $company['email']] : null,
        ($company['website'] ?? '') !== '' ? ['website', $company['website']] : null,
    ]));
@endphp
@foreach ([$first, $second] as $row)
    @if ($row !== [])
        <div class="contact-line" style="font-size:{{ $size }};margin-top:2px;line-height:1.5">
            @foreach ($row as $index => [$line, $text])
                @if ($index > 0)&nbsp;&nbsp;&nbsp;&nbsp;@endif
                {!! $icon($line) !!}&nbsp;@if ($line === 'website' && ! empty($company['website_url']))<a href="{{ $company['website_url'] }}" style="color:#0f4c81;text-decoration:none">{{ $text }}</a>@else{{ $text }}@endif
            @endforeach
        </div>
    @endif
@endforeach
