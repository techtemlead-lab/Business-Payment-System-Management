@use('App\Support\Money')
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order['number'] ?? '' }} — {{ $company['name'] ?? 'Madina' }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: freeserif, DejaVu Sans, Arial, sans-serif;
            color: #0f172a;
            font-size: 11px;
            line-height: 1.35;
            margin: 0;
            padding: 0;
            background: #ffffff;
        }

        /* Screen Preview */
        @media screen {
            body {
                background: #f1f5f9;
                padding-bottom: 40px;
            }
            .sheet {
                background: #ffffff;
                width: 210mm;
                max-width: 95%;
                margin: 20px auto;
                padding: 14mm 16mm 18mm;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08), 0 8px 10px -6px rgba(0, 0, 0, 0.05);
                border: 1px solid #e2e8f0;
                border-radius: 6px;
            }
        }

        /* Print Page Layout */
        @page {
            size: A4 portrait;
            margin: 10mm 12mm 12mm 12mm;
        }

        @media print {
            body {
                background: #ffffff !important;
                padding: 0 !important;
                margin: 0 !important;
            }
            .print-toolbar {
                display: none !important;
            }
            .sheet {
                width: 100% !important;
                max-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border: none !important;
            }
            .watermark {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                color: rgba(21, 128, 61, 0.08) !important;
            }
            table.items tr {
                page-break-inside: avoid;
            }
        }

        /* Toolbar */
        .print-toolbar {
            position: sticky;
            top: 0;
            z-index: 50;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            padding: 12px 20px;
            background: #0f172a;
            color: #ffffff;
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .print-toolbar .toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .print-toolbar .badge {
            background: #006837;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 8px;
            border-radius: 4px;
            letter-spacing: .05em;
            text-transform: uppercase;
        }
        .print-toolbar .toolbar-title {
            font-size: 14px;
            font-weight: 600;
            letter-spacing: .01em;
        }
        .print-toolbar .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }
        .print-toolbar button, .print-toolbar a {
            appearance: none;
            border: 0;
            border-radius: 6px;
            padding: 7px 14px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all .15s ease-in-out;
        }
        .print-toolbar .btn-primary {
            background: #006837;
            color: #ffffff;
        }
        .print-toolbar .btn-primary:hover {
            background: #008744;
        }
        .print-toolbar .btn-secondary {
            background: #ffffff;
            color: #0f172a;
        }
        .print-toolbar .btn-secondary:hover {
            background: #f1f5f9;
        }
        .print-toolbar .btn-outline {
            background: rgba(255, 255, 255, 0.12);
            color: #ffffff;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        .print-toolbar .btn-outline:hover {
            background: rgba(255, 255, 255, 0.22);
        }

        /* Invoice Sheet Layout */
        .header { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .header td { vertical-align: middle; }
        .logo-cell { width: 70px; text-align: center; vertical-align: middle; }
        .logo-cell img {
            width: 64px;
            height: 64px;
            max-width: 64px;
            max-height: 64px;
            object-fit: contain;
            display: inline-block;
        }
        .brand-block { text-align: center; }
        .brand-name {
            font-size: 21px;
            font-weight: 700;
            color: #006837;
            letter-spacing: .02em;
            margin: 0;
        }
        .brand-address { font-size: 11px; color: #334155; margin: 2px 0 0; }
        .brand-phone { font-size: 11px; color: #334155; margin: 1px 0 0; font-weight: 500; }
        .marketed { text-align: center; margin: 6px 0 4px; }
        .marketed .by { color: #1e40af; font-weight: 700; font-size: 12px; }
        .marketed .phones { color: #334155; font-size: 11px; }

        .invoice-title { text-align: center; margin: 8px 0 10px; }
        .invoice-title span {
            display: inline-block;
            font-size: 18px;
            font-weight: 700;
            color: #006837;
            border-bottom: 2px solid #006837;
            padding: 0 20px 2px;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .meta-row { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .meta-row td { vertical-align: top; }
        .invoice-no { font-size: 12px; font-weight: 700; padding-top: 4px; color: #0f172a; }
        .referred {
            border: 1px solid #94a3b8;
            padding: 4px 10px;
            font-size: 10.5px;
            font-weight: 600;
            text-align: center;
            display: inline-block;
            background: #f8fafc;
            border-radius: 3px;
        }

        .dealer-box {
            border: 1.5px solid #1e3a8a;
            padding: 8px 10px;
            width: 65%;
            background: #fbfcfe;
            border-radius: 4px;
        }
        .dealer-box table { width: 100%; border-collapse: collapse; }
        .dealer-box td { padding: 1.5px 0; font-size: 10.5px; vertical-align: top; }
        .dealer-box .k { width: 32%; font-weight: 600; color: #1e293b; }
        .dealer-box .v { color: #0f172a; }
        .date-cell { text-align: right; padding-top: 6px; font-size: 11.5px; font-weight: 700; color: #0f172a; }

        /* Items Table */
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        table.items th {
            font-size: 9.5px;
            font-weight: 700;
            text-align: center;
            padding: 6px 4px;
            border-top: 1.5px solid #0f172a;
            border-bottom: 1.5px solid #0f172a;
            background: #f1f5f9;
            color: #0f172a;
            text-transform: uppercase;
            letter-spacing: .02em;
        }
        table.items th.name, table.items td.name { text-align: left; }
        table.items td {
            font-size: 10px;
            padding: 5px 4px;
            border-bottom: 0.5px solid #cbd5e1;
            text-align: center;
            vertical-align: middle;
        }
        table.items td.name {
            text-align: left;
            line-height: 1.25;
        }
        table.items td.name .product-title {
            font-weight: 600;
            color: #0f172a;
        }
        table.items td.name .product-meta {
            font-size: 9px;
            color: #64748b;
            margin-top: 1px;
        }
        table.items td.num { text-align: right; white-space: nowrap; }

        /* Payment summary */
        table.pay-summary { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.pay-summary td { vertical-align: top; }
        table.pay-summary .totals { width: 42%; }
        table.pay-summary .totals table { width: 100%; border-collapse: collapse; }
        table.pay-summary .totals td { font-size: 10.5px; padding: 3px 6px; border-bottom: 0.5px solid #cbd5e1; }
        table.pay-summary .totals td.amt { text-align: right; font-weight: 600; white-space: nowrap; }
        table.pay-summary .totals tr.due td { font-weight: 700; border-top: 1.5px solid #0f172a; border-bottom: 1.5px solid #0f172a; }
        table.pay-summary .history { padding-right: 14px; }
        table.pay-summary .history table { width: 100%; border-collapse: collapse; }
        table.pay-summary .history th { font-size: 9px; text-align: left; padding: 3px 4px; background: #f1f5f9; border-bottom: 1px solid #94a3b8; }
        table.pay-summary .history td { font-size: 9.5px; padding: 3px 4px; border-bottom: 0.5px solid #e2e8f0; }
        .pay-state { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 9.5px; font-weight: 700; }
        .pay-state.paid { background: #dcfce7; color: #166534; }
        .pay-state.partial { background: #fef3c7; color: #92400e; }
        .pay-state.unpaid { background: #fee2e2; color: #991b1b; }

        table.items tr.total-row td {
            font-weight: 700;
            border-top: 1.5px solid #0f172a;
            border-bottom: 1.5px solid #0f172a;
            padding-top: 6px;
            padding-bottom: 6px;
            background: #f8fafc;
        }

        .in-words {
            margin-top: 12px;
            font-size: 11px;
            padding: 6px 8px;
            background: #f8fafc;
            border-left: 3px solid #006837;
        }
        .in-words strong { font-weight: 700; color: #006837; }

        .signs {
            width: 100%;
            margin-top: 44px;
            border-collapse: collapse;
        }
        .signs td {
            width: 33.33%;
            text-align: center;
            font-size: 10.5px;
            font-weight: 600;
            padding-top: 26px;
            color: #1e293b;
        }
        .signs .line {
            border-top: 1px solid #0f172a;
            margin: 0 auto 5px;
            width: 75%;
        }

        .watermark {
            position: fixed;
            top: 40%;
            left: 5%;
            width: 90%;
            text-align: center;
            font-size: 50px;
            font-weight: 700;
            color: rgba(0, 104, 55, 0.08);
            transform: rotate(-28deg);
            letter-spacing: .08em;
            z-index: 0;
            pointer-events: none;
        }
        .content { position: relative; z-index: 1; }
    </style>
</head>
<body>
@if (! empty($printable))
    <div class="print-toolbar">
        <div class="toolbar-left">
            <span class="badge">{{ $watermark_label ?: 'INVOICE' }}</span>
            <span class="toolbar-title">{{ __('portal.print_invoice') }} — {{ $order['number'] ?? '' }}</span>
        </div>
        <div class="actions">
            @php
                $invoiceShareUrl = $share_url ?? request()->url();
                $invoiceShareText = __('portal.share_invoice_text', ['number' => $order['number'] ?? '']);
                $invoiceShareBody = $invoiceShareText.' '.$invoiceShareUrl;
            @endphp
            <button type="button" class="btn-primary" onclick="window.print()">
                🖨️ {{ __('portal.print_invoice') }}
            </button>
            <a class="btn-secondary" href="{{ url('/orders/'.($order_id ?? '').'/invoice') }}">
                ⬇️ {{ __('portal.download_invoice') }}
            </a>
            <a class="btn-outline" target="_blank" rel="noopener" href="https://wa.me/?text={{ rawurlencode($invoiceShareBody) }}">
                {{ __('portal.share_invoice_whatsapp') }}
            </a>
            <a class="btn-outline" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u={{ rawurlencode($invoiceShareUrl) }}">
                {{ __('portal.share_facebook') }}
            </a>
            <a class="btn-outline" href="mailto:?subject={{ rawurlencode($invoiceShareText) }}&body={{ rawurlencode($invoiceShareBody) }}">
                {{ __('portal.share_invoice_email') }}
            </a>
            <a class="btn-outline" href="sms:?&body={{ rawurlencode($invoiceShareBody) }}">
                {{ __('portal.share_invoice_sms') }}
            </a>
        </div>
    </div>
@endif

@if (! empty($watermark_label))
    <div class="watermark">{{ $watermark_label }}</div>
@endif

<div class="content sheet">
    <table class="header">
        <tr>
            <td class="logo-cell">
                @if (! empty($company['logo_path']))
                    <img src="{{ $company['logo_path'] }}" alt="Logo" width="64" height="64">
                @endif
            </td>
            <td class="brand-block">
                @if (! empty($company['manufacturer']))
                    <div class="brand-name">{{ $company['manufacturer'] }}</div>
                @else
                    <div class="brand-name">{{ $company['name'] }}</div>
                @endif
                <div class="brand-address">{{ $company['address'] }}</div>
                <div class="brand-phone">{{ __('portal.inv_mobile_short') }}: {{ $company['phones'] }}</div>
            </td>
            <td class="logo-cell"></td>
        </tr>
    </table>

    @if (! empty($company['marketed_by']))
        <div class="marketed">
            <div class="by">{{ __('portal.inv_marketed_by') }}: {{ $company['marketed_by'] }}</div>
            @if (! empty($company['marketed_phones']))
                <div class="phones">{{ __('portal.inv_mobile_short') }}: {{ $company['marketed_phones'] }}</div>
            @endif
        </div>
    @endif

    <div class="invoice-title"><span>{{ __('portal.inv_title') }}</span></div>

    <table class="meta-row">
        <tr>
            <td class="invoice-no">{{ __('portal.inv_number') }} : {{ $order['number'] }}</td>
            <td style="text-align:right">
                @if (! empty($buyer['referred_code']))
                    <div class="referred">{{ __('portal.inv_referred_code') }}: {{ $buyer['referred_code'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="meta-row">
        <tr>
            <td>
                <div class="dealer-box">
                    <table>
                        <tr><td class="k">{{ __('portal.inv_name') }} :</td><td class="v">{{ $buyer['name'] }}</td></tr>
                        <tr><td class="k">{{ __('portal.inv_dealer_code') }} :</td><td class="v">{{ $buyer['code'] ?: '—' }}</td></tr>
                        <tr><td class="k">{{ __('portal.inv_designation') }} :</td><td class="v">{{ $buyer['designation'] }}</td></tr>
                        <tr><td class="k">{{ __('portal.inv_area') }} :</td><td class="v">{{ $buyer['area'] }}</td></tr>
                        <tr><td class="k">{{ __('portal.inv_mobile') }} :</td><td class="v">{{ $buyer['phone'] ?: '—' }}</td></tr>
                        <tr><td class="k">{{ __('portal.inv_booking_address') }} :</td><td class="v">{{ $buyer['booking_address'] }}</td></tr>
                    </table>
                </div>
            </td>
            <td class="date-cell">{{ __('portal.inv_date') }} : {{ $order['date'] }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:6%">{{ __('portal.inv_col_sl') }}</th>
                <th class="name" style="width:23%">{{ __('portal.inv_col_product') }}</th>
                <th style="width:9%">{{ __('portal.inv_col_pack') }}</th>
                <th style="width:8%">{{ __('portal.inv_col_qty') }}</th>
                <th style="width:10%">{{ __('portal.inv_col_rate') }}</th>
                <th style="width:12%">{{ __('portal.inv_col_mrp_bill') }}</th>
                <th style="width:11%">{{ __('portal.inv_col_commission') }}</th>
                <th style="width:12%">{{ __('portal.inv_col_total') }}</th>
                <th style="width:9%">{{ __('portal.inv_col_remarks') }}</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{{ $line['sl'] }}</td>
                <td class="name">
                    <div class="product-title">{{ $line['name'] }}</div>
                    @if (! empty($line['strength']) || ! empty($line['sub_category']))
                        <div class="product-meta">
                            {{ collect([$line['sub_category'], $line['strength']])->filter()->implode(' • ') }}
                        </div>
                    @endif
                    @if (! empty($line['batch_number']) || ! empty($line['expires_on']))
                        <div class="product-meta">
                            {{ collect([
                                ! empty($line['batch_number']) ? __('portal.inv_batch', ['batch' => $line['batch_number']]) : null,
                                ! empty($line['expires_on']) ? __('portal.inv_expiry', ['date' => $line['expires_on']]) : null,
                            ])->filter()->implode(' • ') }}
                        </div>
                    @endif
                </td>
                <td>{{ $line['pack_size'] }}</td>
                <td><strong>{{ $line['quantity'] }}</strong></td>
                <td class="num">{{ Money::grouped($line['rate']) }}</td>
                <td class="num">{{ Money::grouped($line['mrp_bill']) }}</td>
                <td class="num">{{ Money::grouped($line['commission']) }}</td>
                <td class="num"><strong>{{ Money::grouped($line['total']) }}</strong></td>
                <td>{{ $line['remark'] }}</td>
            </tr>
        @endforeach
            <tr class="total-row">
                <td></td>
                <td class="name"><strong>{{ __('portal.inv_total_row') }}</strong></td>
                <td></td>
                <td><strong>{{ $totals['quantity'] }}</strong></td>
                <td></td>
                <td class="num"><strong>{{ Money::grouped($totals['mrp_bill']) }}</strong></td>
                <td class="num"><strong>{{ Money::grouped($totals['commission']) }}</strong></td>
                <td class="num"><strong>{{ Money::grouped($totals['total']) }}</strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="in-words"><strong>{{ __('portal.inv_in_words') }} :</strong> {{ $totals['in_words'] }}</div>

    <table class="pay-summary">
        <tr>
            <td class="history">
                @if (! empty($payments))
                    <table>
                        <thead>
                            <tr>
                                <th>{{ __('portal.inv_pay_date') }}</th>
                                <th>{{ __('portal.inv_pay_method') }}</th>
                                <th>{{ __('portal.inv_pay_reference') }}</th>
                                <th style="text-align:right">{{ __('portal.inv_pay_amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($payments as $payment)
                            <tr>
                                <td>{{ $payment['date'] }}</td>
                                <td>{{ $payment['channel'] }}</td>
                                <td>{{ $payment['reference'] }}</td>
                                <td style="text-align:right">{{ $payment['amount'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </td>
            <td class="totals">
                <table>
                    <tr><td>{{ __('portal.inv_bill_total') }}</td><td class="amt">৳ {{ $order['total_display'] }}</td></tr>
                    <tr><td>{{ __('portal.inv_paid') }}</td><td class="amt">৳ {{ $order['paid_display'] }}</td></tr>
                    @if ($order['credit_display'] !== '0.00')
                        <tr><td>{{ __('portal.inv_credit') }}</td><td class="amt">৳ {{ $order['credit_display'] }}</td></tr>
                    @endif
                    <tr class="due"><td>{{ __('portal.inv_due') }}</td><td class="amt">৳ {{ $order['due_display'] }}</td></tr>
                    <tr><td>{{ __('portal.inv_payment_status') }}</td><td class="amt"><span class="pay-state {{ $order['payment_state'] }}">{{ $order['payment_state_label'] }}</span></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="signs">
        <tr>
            <td><div class="line"></div>{{ __('portal.inv_sign_customer') }}</td>
            <td><div class="line"></div>{{ __('portal.inv_sign_distributor') }}</td>
            <td><div class="line"></div>{{ __('portal.inv_sign_authority') }}</td>
        </tr>
    </table>
</div>

@if (! empty($auto_print))
<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 250);
    });
</script>
@endif
</body>
</html>
