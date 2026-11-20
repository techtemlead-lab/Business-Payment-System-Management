@use('App\Support\LocalDigits')
@use('App\Support\Money')
@php
    $digits = static fn ($value): string => LocalDigits::text((string) $value);
    $amount = static fn (?string $value): string => $value === null ? '—' : '৳ '.$digits(Money::grouped($value));
    $percent = static fn (?string $value): string => $value === null ? '—' : $digits($value).'%';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('stock_print.title') }}</title>
    <style>
        body { font-family: freeserif, sans-serif; color: #0f172a; font-size: 11pt; margin: 0; }
        table.header { width: 100%; border-collapse: collapse; }
        table.header td { vertical-align: top; }
        .logo-cell { width: 165px; text-align: left; }
        .legal-cell { width: 245px; text-align: right; }
        .brand-name { font-size: 25pt; font-weight: bold; line-height: 1.05; color: #006837; }
        .brand-line { font-size: 13pt; color: #334155; line-height: 1.3; }
        .title { text-align: center; margin: 8px 0 8px; }
        .title span { font-size: 18pt; font-weight: bold; color: #006837; border-bottom: 2px solid #006837; padding: 0 18px 2px; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { background: #e3f1eb; color: #00482f; border: 1px solid #94a3b8; padding: 5px 2px; text-align: center; font-size: 10.5pt; }
        table.items td { border: 1px solid #cbd5e1; padding: 4px 5px; vertical-align: middle; font-size: 11pt; }
        table.items tr { page-break-inside: avoid; }
        .num { text-align: right; }
        .mid { text-align: center; }
        .note { font-size: 10.5pt; color: #64748b; margin-top: 8px; line-height: 1.5; }
        .empty { text-align: center; color: #64748b; padding: 14px 0; }
    </style>
</head>
<body>
<table class="header">
    <tr>
        <td class="logo-cell">
            @if (! empty($company['logo_path']))
                <img src="{{ $company['logo_path'] }}" alt="" width="140" height="140" style="margin-top:4px">
            @endif
        </td>
        <td style="text-align:center;vertical-align:top">
            <div class="brand-name">{{ $company['name'] }}</div>
            <div class="brand-line">{{ $company['address'] }}</div>
            <div style="margin-top:3px">
                @include('partials.letterhead-contacts', ['company' => $company, 'size' => '13pt', 'localDigits' => true])
            </div>
        </td>
        <td class="legal-cell">
            <div class="brand-line">{{ __('site.top_bar_trade_licence') }}: <b>{{ $digits($company['licence']) }}</b></div>
            <div class="brand-line">{{ __('site.top_bar_trademark') }}: <b>{{ $company['trademark'] }}</b></div>
        </td>
    </tr>
</table>

<div class="title"><span>{{ __('stock_print.title') }}</span></div>

<table class="items" repeat_header="1">
    <thead>
        <tr>
            <th style="width:4%">{{ __('stock_print.col_serial') }}</th>
            <th style="width:13%">{{ __('stock_print.col_image') }}</th>
            <th style="width:12%">{{ __('stock_print.col_product') }}</th>
            <th style="width:7%">{{ __('stock_print.col_pack') }}</th>
            <th style="width:8%">{{ __('stock_print.col_group') }}</th>
            <th style="width:8%">{{ __('stock_print.col_category') }}</th>
            <th style="width:12%">{{ __('stock_print.col_efficacy') }}</th>
            <th style="width:12%">{{ __('stock_print.col_usage') }}</th>
            <th style="width:8%">{{ __('stock_print.col_mrp') }}</th>
            <th style="width:8%">{{ __('stock_print.col_dealer_discount') }}</th>
            <th style="width:9%">{{ __('stock_print.col_sub_dealer_discount') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td class="mid">{{ $digits($row['serial']) }}</td>
                <td class="mid">
                    @if (! empty($row['image']))
                        <img src="{{ $row['image'] }}" alt="" width="{{ $row['image_width'] ?? \App\Domain\Inventory\StockPrintPdf::DisplayPixels }}" height="{{ $row['image_height'] ?? \App\Domain\Inventory\StockPrintPdf::DisplayPixels }}">
                    @else
                        —
                    @endif
                </td>
                <td>{{ $row['name'] }}</td>
                <td class="mid">{{ $row['pack_size'] ?? '—' }}</td>
                <td class="mid">{{ $row['group'] ?? '—' }}</td>
                <td class="mid">{{ $row['category'] ?? '—' }}</td>
                <td>{!! nl2br(e($row['efficacy'] ?? '—')) !!}</td>
                <td>{!! nl2br(e($row['usage'] ?? '—')) !!}</td>
                <td class="num">{{ $amount($row['mrp']) }}</td>
                <td class="mid">{{ $percent($row['dealer_discount']) }}</td>
                <td class="mid">{{ $percent($row['sub_dealer_discount']) }}</td>
            </tr>
        @empty
            <tr><td colspan="11" class="empty">{{ __('stock_print.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

<div class="note">{{ __('stock_print.note') }}</div>
</body>
</html>
