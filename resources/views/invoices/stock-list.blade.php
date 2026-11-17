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
        table.header td { vertical-align: middle; }
        .logo-cell { width: 110px; text-align: left; }
        .legal-cell { width: 200px; text-align: right; }
        .brand-name { font-size: 23pt; font-weight: bold; color: #006837; }
        .brand-line { font-size: 13pt; color: #334155; line-height: 1.45; }
        .title { text-align: center; margin: 8px 0 6px; }
        .title span { font-size: 18pt; font-weight: bold; color: #006837; border-bottom: 2px solid #006837; padding: 0 18px 2px; }
        table.meta { width: 100%; border-collapse: collapse; margin-bottom: 6px; font-size: 12pt; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { background: #e3f1eb; color: #00482f; border: 1px solid #94a3b8; padding: 5px 3px; text-align: center; font-size: 10.5pt; }
        table.items td { border: 1px solid #cbd5e1; padding: 4px 5px; vertical-align: middle; font-size: 11.5pt; }
        table.items tr { page-break-inside: avoid; }
        .num { text-align: right; }
        .mid { text-align: center; }
        .note { font-size: 10.5pt; color: #64748b; margin-top: 8px; line-height: 1.5; }
        h2.section { margin: 14px 0 4px; font-size: 15pt; color: #006837; }
        .empty { text-align: center; color: #64748b; padding: 14px 0; }
    </style>
</head>
<body>
<table class="header">
    <tr>
        <td class="logo-cell">
            @if (! empty($company['logo_path']))
                <img src="{{ $company['logo_path'] }}" alt="" width="76" height="76">
            @endif
        </td>
        <td style="text-align:center">
            <div class="brand-name">{{ $company['name'] }}</div>
            <div class="brand-line">{{ $company['address'] }}</div>
        </td>
        <td class="legal-cell">
            <div class="brand-line">{{ __('site.top_bar_trade_licence') }}: <b>{{ $digits($company['licence']) }}</b></div>
            <div class="brand-line">{{ __('site.top_bar_trademark') }}: <b>{{ $company['trademark'] }}</b></div>
        </td>
    </tr>
</table>
<div style="text-align:center;margin-top:4px">
    @include('partials.letterhead-contacts', ['company' => $company, 'size' => '12.5pt', 'localDigits' => true])
</div>

<div class="title"><span>{{ __('stock_print.title') }}</span></div>

<table class="meta">
    <tr>
        <td>{{ __('stock_print.branch') }} : <b>{{ $branch !== '' ? $branch : '—' }}</b></td>
        <td style="text-align:right">{{ __('stock_print.printed_on') }} : <b>{{ $digits($printed_at->format('d/m/Y')) }}</b></td>
    </tr>
</table>

<table class="items" repeat_header="1">
    <thead>
        <tr>
            <th style="width:5%">{{ __('stock_print.col_serial') }}</th>
            <th style="width:9%">{{ __('stock_print.col_image') }}</th>
            <th style="width:27%">{{ __('stock_print.col_product') }}</th>
            <th style="width:11%">{{ __('stock_print.col_pack') }}</th>
            <th style="width:13%">{{ __('stock_print.col_category') }}</th>
            <th style="width:13%">{{ __('stock_print.col_group') }}</th>
            <th style="width:8%">{{ __('stock_print.col_mrp') }}</th>
            <th style="width:7%">{{ __('stock_print.col_dealer_discount') }}</th>
            <th style="width:7%">{{ __('stock_print.col_sub_dealer_discount') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td class="mid">{{ $digits($row['serial']) }}</td>
                <td class="mid">
                    @if (! empty($row['image']))
                        <img src="{{ $row['image'] }}" alt="" width="46" height="46">
                    @else
                        —
                    @endif
                </td>
                <td>{{ $row['name'] }}</td>
                <td class="mid">{{ $row['pack_size'] ?? '—' }}</td>
                <td class="mid">{{ $row['category'] ?? '—' }}</td>
                <td class="mid">{{ $row['group'] ?? '—' }}</td>
                <td class="num">{{ $amount($row['mrp']) }}</td>
                <td class="mid">{{ $percent($row['dealer_discount']) }}</td>
                <td class="mid">{{ $percent($row['sub_dealer_discount']) }}</td>
            </tr>
        @empty
            <tr><td colspan="9" class="empty">{{ __('stock_print.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

<h2 class="section">{{ __('stock_print.table_usage') }}</h2>
<table class="items" repeat_header="1">
    <thead>
        <tr>
            <th style="width:8%">{{ __('stock_print.col_serial') }}</th>
            <th style="width:28%">{{ __('stock_print.col_product') }}</th>
            <th style="width:64%">{{ __('stock_print.col_usage') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td class="mid">{{ $digits($row['serial']) }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{!! nl2br(e($row['usage'] ?? '—')) !!}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">{{ __('stock_print.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

<h2 class="section">{{ __('stock_print.table_efficacy') }}</h2>
<table class="items" repeat_header="1">
    <thead>
        <tr>
            <th style="width:8%">{{ __('stock_print.col_serial') }}</th>
            <th style="width:28%">{{ __('stock_print.col_product') }}</th>
            <th style="width:64%">{{ __('stock_print.col_efficacy') }}</th>
        </tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>
                <td class="mid">{{ $digits($row['serial']) }}</td>
                <td>{{ $row['name'] }}</td>
                <td>{!! nl2br(e($row['efficacy'] ?? '—')) !!}</td>
            </tr>
        @empty
            <tr><td colspan="3" class="empty">{{ __('stock_print.empty') }}</td></tr>
        @endforelse
    </tbody>
</table>

<div class="note">{{ __('stock_print.note') }}</div>
</body>
</html>
