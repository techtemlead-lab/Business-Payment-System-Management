{{--
    The company's Trade Licence and TM (trademark) numbers, as two short lines: the top right of the invoice and of the
    printed product and stock lists. Needs $company (see PdfLetterhead::company(), or the invoice payload) with 'licence'
    and 'trademark'; $style adds a CSS style to each line. A line is left out when its number is empty.
--}}
@use('App\Support\LocalDigits')
@if (($company['licence'] ?? '') !== '')
    <div class="brand-line legal-id legal-licence" style="{{ $style ?? '' }}">{{ __('site.top_bar_trade_licence') }}: <b>{{ LocalDigits::text($company['licence']) }}</b></div>
@endif
@if (($company['trademark'] ?? '') !== '')
    <div class="brand-line legal-id legal-trademark" style="{{ $style ?? '' }}">{{ __('site.top_bar_trademark') }}: <b>{{ $company['trademark'] }}</b></div>
@endif
