<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: freeserif, DejaVu Sans, sans-serif; color: #111827; font-size: 11px; }
        .sheet { width: 100%; }
        .header { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        .header td { vertical-align: middle; }
        .logo { width: 72px; }
        .logo img { width: 64px; height: 64px; object-fit: contain; }
        .brand-block { text-align: center; }
        .brand-name { font-size: 22px; font-weight: 700; color: #15803d; letter-spacing: .02em; margin: 0; }
        .brand-address { font-size: 11px; color: #111827; margin: 2px 0 0; }
        .brand-phone { font-size: 11px; color: #111827; margin: 1px 0 0; }
        .marketed { text-align: center; margin: 6px 0 2px; }
        .marketed .by { color: #1d4ed8; font-weight: 700; font-size: 12px; }
        .marketed .phones { color: #111827; font-size: 11px; }
        .invoice-title { text-align: center; margin: 8px 0 12px; }
        .invoice-title span {
            display: inline-block; font-size: 20px; font-weight: 700; color: #15803d;
            border-bottom: 2px solid #15803d; padding: 0 18px 2px; letter-spacing: .04em;
        }
        .row { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .row td { vertical-align: top; }
        .invoice-no { font-size: 12px; padding-top: 4px; }
        .referred {
            border: 1px solid #94a3b8; padding: 6px 10px; font-size: 11px; font-weight: 600;
            text-align: center; min-width: 160px;
        }
        .dealer-box {
            border: 1.5px solid #1e3a8a; padding: 8px 10px; width: 62%;
        }
        .dealer-box table { width: 100%; border-collapse: collapse; }
        .dealer-box td { padding: 1px 0; font-size: 11px; }
        .dealer-box .k { width: 34%; font-weight: 600; }
        .date-cell { text-align: right; padding-top: 8px; font-size: 12px; font-weight: 600; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table.items th {
            font-size: 10px; font-weight: 700; text-align: center; padding: 5px 3px;
            border-bottom: 1.5px solid #111827; color: #111827;
        }
        table.items th.name, table.items td.name { text-align: left; }
        table.items td {
            font-size: 10px; padding: 5px 3px; border-bottom: 0.5px solid #cbd5e1;
            text-align: center; vertical-align: middle;
        }
        table.items td.name { text-align: left; }
        table.items tr.total-row td {
            font-weight: 700; border-top: 1.5px solid #111827; border-bottom: 1.5px solid #111827;
            padding-top: 7px; padding-bottom: 7px;
        }
        .in-words { margin-top: 12px; font-size: 12px; }
        .in-words strong { font-weight: 700; }
        .signs { width: 100%; margin-top: 42px; border-collapse: collapse; }
        .signs td { width: 33%; text-align: center; font-size: 11px; padding-top: 28px; }
        .signs .line { border-top: 1px solid #111827; margin: 0 auto 4px; width: 78%; }
        .watermark {
            position: fixed; top: 38%; left: 8%; width: 84%; text-align: center;
            font-size: 54px; font-weight: 700; color: rgba(21, 128, 61, 0.10);
            transform: rotate(-28deg); letter-spacing: .08em; z-index: 0;
        }
        .content { position: relative; z-index: 1; }
        @media print {
            .watermark { -webkit-print-color-adjust: exact; print-color-adjust: exact; color: rgba(21, 128, 61, 0.12) !important; }
        }
    </style>
</head>
<body>
@if (! empty($watermark_label))
    <div class="watermark">{{ $watermark_label }}</div>
@endif
<div class="content sheet">
    <table class="header">
        <tr>
            <td class="logo">
                @if (! empty($company['logo_path']))
                    <img src="{{ $company['logo_path'] }}" alt="Logo">
                @endif
            </td>
            <td class="brand-block">
                @if (! empty($company['manufacturer']))
                    <div class="brand-name">{{ $company['manufacturer'] }}</div>
                @else
                    <div class="brand-name">{{ $company['name'] }}</div>
                @endif
                <div class="brand-address">{{ $company['address'] }}</div>
                <div class="brand-phone">Mob: {{ $company['phones'] }}</div>
            </td>
            <td class="logo"></td>
        </tr>
    </table>

    @if (! empty($company['marketed_by']))
        <div class="marketed">
            <div class="by">Marketed By: {{ $company['marketed_by'] }}.</div>
            @if (! empty($company['marketed_phones']))
                <div class="phones">Mob: {{ $company['marketed_phones'] }}</div>
            @endif
        </div>
    @endif

    <div class="invoice-title"><span>Invoice</span></div>

    <table class="row">
        <tr>
            <td class="invoice-no">Invoice No : {{ $order['number'] }}</td>
            <td style="text-align:right">
                @if (! empty($buyer['referred_code']))
                    <div class="referred">Referred Dealer Code: {{ $buyer['referred_code'] }}</div>
                @endif
            </td>
        </tr>
    </table>

    <table class="row">
        <tr>
            <td>
                <div class="dealer-box">
                    <table>
                        <tr><td class="k">Name :</td><td>{{ $buyer['name'] }}</td></tr>
                        <tr><td class="k">Dealer Code :</td><td>{{ $buyer['code'] ?: '—' }}</td></tr>
                        <tr><td class="k">Designation :</td><td>{{ $buyer['designation'] }}</td></tr>
                        <tr><td class="k">Area :</td><td>{{ $buyer['area'] }}</td></tr>
                        <tr><td class="k">Mobile No :</td><td>{{ $buyer['phone'] ?: '—' }}</td></tr>
                        <tr><td class="k">Booking Address :</td><td>{{ $buyer['booking_address'] }}</td></tr>
                    </table>
                </div>
            </td>
            <td class="date-cell">Date : {{ $order['date'] }}</td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:6%">Sl.No</th>
                <th class="name" style="width:18%">Product's Name</th>
                <th style="width:9%">Pack Size</th>
                <th style="width:8%">Quantity</th>
                <th style="width:9%">Rate</th>
                <th style="width:12%">MRP Rate Bill</th>
                <th style="width:11%">Commission</th>
                <th style="width:11%">Total Bill</th>
                <th style="width:8%">Remarks</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($lines as $line)
            <tr>
                <td>{{ $line['sl'] }}</td>
                <td class="name">{{ $line['name'] }}</td>
                <td>{{ $line['pack_size'] }}</td>
                <td>{{ $line['quantity'] }}</td>
                <td>{{ $line['rate'] }}</td>
                <td>{{ $line['mrp_bill'] }}</td>
                <td>{{ $line['commission'] }}</td>
                <td>{{ $line['total'] }}</td>
                <td>{{ $line['remark'] }}</td>
            </tr>
        @endforeach
            <tr class="total-row">
                <td></td>
                <td class="name">Total =</td>
                <td></td>
                <td>{{ $totals['quantity'] }}</td>
                <td></td>
                <td>{{ $totals['mrp_bill'] }}</td>
                <td>{{ $totals['commission'] }}</td>
                <td>{{ $totals['total'] }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="in-words"><strong>In Word :</strong> {{ $totals['in_words'] }}</div>

    <table class="signs">
        <tr>
            <td><div class="line"></div>Customer Signature</td>
            <td><div class="line"></div>Distributor Signature</td>
            <td><div class="line"></div>Authority Signature</td>
        </tr>
    </table>
</div>
</body>
</html>
