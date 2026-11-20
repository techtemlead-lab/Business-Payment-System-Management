@use('App\Support\LocalDigits')
@use('App\Support\Money')
@php
    $digits = static fn ($value): string => LocalDigits::text((string) $value);
    $amount = static fn (?string $value): string => $value === null ? '—' : '৳ '.$digits(Money::grouped($value));
    $date = static fn (?string $value): string => $value === null || $value === '' ? '—' : $digits(\Illuminate\Support\Carbon::parse($value)->format('d/m/Y'));
    $showsCost = $stock['shows_cost'];
    $columns = 12 + ($stock['all_branches'] ? 1 : 0) + ($showsCost ? 1 : 0);
    $levelClass = ['low' => 'warn', 'out_of_stock' => 'bad'];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('stock_print.title') }}</title>
    <style>
        body { font-family: freeserif, sans-serif; color: #0f172a; font-size: 9.5pt; margin: 0; }
        table.header { width: 100%; border-collapse: collapse; }
        table.header td { vertical-align: top; }
        .logo-cell { width: 130px; text-align: left; }
        .legal-cell { width: 230px; text-align: right; }
        .brand-name { font-size: 21pt; font-weight: bold; line-height: 1.05; color: #006837; }
        .brand-line { font-size: 11pt; color: #334155; line-height: 1.3; }
        .title { text-align: center; margin: 6px 0 4px; }
        .title span { font-size: 16pt; font-weight: bold; color: #006837; border-bottom: 2px solid #006837; padding: 0 18px 2px; }
        .scope { text-align: center; font-size: 10pt; color: #334155; margin-bottom: 6px; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { background: #e3f1eb; color: #00482f; border: 1px solid #94a3b8; padding: 4px 2px; text-align: center; font-size: 9pt; }
        table.items td { border: 1px solid #cbd5e1; padding: 3px 4px; vertical-align: middle; font-size: 9.5pt; }
        table.items tr { page-break-inside: avoid; }
        table.items tr.total td { background: #f1f5f9; font-weight: bold; }
        .num { text-align: right; }
        .mid { text-align: center; }
        .small { font-size: 8.5pt; color: #475569; }
        .warn { color: #b45309; font-weight: bold; }
        .bad { color: #b91c1c; font-weight: bold; }
        .note { font-size: 9pt; color: #64748b; margin-top: 6px; line-height: 1.5; }
        .empty { text-align: center; color: #64748b; padding: 14px 0; }
    </style>
</head>
<body>
@include('partials.print-letterhead', ['company' => $company])

<div class="title"><span>{{ __('stock_print.title') }}</span></div>
<div class="scope">
    {{ __('stock_print.printed_on', ['date' => $printedAt]) }}
    · {{ $stock['all_branches'] ? __('stock_print.all_branches') : __('stock_print.branch', ['branch' => $stock['branch_name'] ?? '—']) }}
    @if ($stock['filtered']) · {{ __('stock_print.filtered') }} @endif
</div>

<table class="items" repeat_header="1">
    <thead>
        <tr>
            <th style="width:3%">{{ __('stock_print.col_serial') }}</th>
            <th>{{ __('stock_print.col_product') }}</th>
            <th>{{ __('stock_print.col_category') }}</th>
            @if ($stock['all_branches'])<th>{{ __('stock_print.col_branch') }}</th>@endif
            <th>{{ __('stock_print.col_mrp') }}</th>
            <th>{{ __('stock_print.col_in_stock') }}</th>
            <th>{{ __('stock_print.col_reserved') }}</th>
            <th>{{ __('stock_print.col_free') }}</th>
            <th>{{ __('stock_print.col_on_hold') }}</th>
            <th>{{ __('stock_print.col_with_courier') }}</th>
            <th>{{ __('stock_print.col_expired') }}</th>
            <th>{{ __('stock_print.col_status') }}</th>
            <th style="width:15%">{{ __('stock_print.col_batches') }}</th>
            <th>{{ __('stock_print.col_last_movement') }}</th>
            <th>{{ __('stock_print.col_value_price') }}</th>
            @if ($showsCost)<th>{{ __('stock_print.col_value_cost') }}</th>@endif
        </tr>
    </thead>
    <tbody>
        @forelse ($stock['rows'] as $row)
            <tr>
                <td class="mid">{{ $digits($row['serial']) }}</td>
                <td>
                    <b>{{ $row['name'] }}</b>@if ($row['pack_size']) <span class="small">· {{ $row['pack_size'] }}</span>@endif
                    <div class="small">{{ __('stock_print.code') }}: {{ $row['sku'] ?: '—' }}@if ($row['unit']) · {{ $row['unit'] }}@endif @unless ($row['live']) · {{ __('stock_print.not_live') }}@endunless</div>
                </td>
                <td class="mid">{{ $row['category'] ?? '—' }}</td>
                @if ($stock['all_branches'])<td class="mid">{{ $row['branch_name'] ?? '—' }}</td>@endif
                <td class="num">{{ $amount($row['mrp']) }}</td>
                <td class="num">{{ $digits($row['in_stock']) }}</td>
                <td class="num">{{ $digits($row['booked']) }}</td>
                <td class="num">{{ $digits($row['free_to_sell']) }}</td>
                <td class="num">{{ $digits($row['on_hold']) }}</td>
                <td class="num">{{ $digits($row['with_courier']) }}</td>
                <td class="num">{{ $digits($row['expired']) }}</td>
                <td class="mid {{ $levelClass[$row['level']] ?? '' }}">
                    {{ __('stock_lots.level_'.$row['level']) }}
                    @if ($row['level'] === 'low')<div class="small">{{ __('stock_print.low_at', ['quantity' => $digits($row['low_stock_at'])]) }}</div>@endif
                    @if ($row['is_stock_paused'])<div class="small">{{ __('stock_print.paused') }}</div>@endif
                </td>
                <td class="small">
                    @forelse ($row['lots'] as $lot)
                        <div>{{ $lot['batch'] ?: __('stock_print.no_batch') }} · {{ $digits($lot['quantity']) }} · {{ $date($lot['expires_on']) }}</div>
                    @empty
                        @if ($row['batch_number'] || $row['expires_on'])
                            <div>{{ $row['batch_number'] ?: __('stock_print.no_batch') }} · {{ $date($row['expires_on']) }}</div>
                        @else
                            —
                        @endif
                    @endforelse
                </td>
                <td class="mid">{{ $date($row['last_movement']) }}</td>
                <td class="num">{{ $amount($row['price_value']) }}</td>
                @if ($showsCost)<td class="num">{{ $amount($row['cost_value']) }}</td>@endif
            </tr>
        @empty
            <tr><td colspan="{{ $columns }}" class="empty">{{ __('stock_print.empty') }}</td></tr>
        @endforelse
        @if ($stock['rows'] !== [])
            <tr class="total">
                <td colspan="{{ $stock['all_branches'] ? 5 : 4 }}" class="num">{{ __('stock_print.total') }}</td>
                <td class="num">{{ $digits($stock['totals']['in_stock']) }}</td>
                <td class="num">{{ $digits($stock['totals']['booked']) }}</td>
                <td class="num">—</td>
                <td class="num">{{ $digits($stock['totals']['on_hold']) }}</td>
                <td class="num">{{ $digits($stock['totals']['with_courier']) }}</td>
                <td class="num">{{ $digits($stock['totals']['expired']) }}</td>
                <td class="mid small">{{ __('stock_print.low_out_counts', ['low' => $digits($stock['totals']['low']), 'out' => $digits($stock['totals']['out'])]) }}</td>
                <td></td>
                <td></td>
                <td class="num">{{ $amount($stock['totals']['price_value']) }}</td>
                @if ($showsCost)<td class="num">{{ $amount($stock['totals']['cost_value']) }}</td>@endif
            </tr>
        @endif
    </tbody>
</table>

<div class="note">
    {{ __('stock_print.note') }}
    @if ($stock['truncated']) {{ __('stock_print.truncated', ['shown' => $digits(count($stock['rows'])), 'total' => $digits($stock['total'])]) }} @endif
</div>
</body>
</html>
