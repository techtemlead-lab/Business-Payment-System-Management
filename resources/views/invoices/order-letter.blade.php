<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: freeserif, DejaVu Sans, sans-serif; color: #011627; font-size: 12px; }
        .letterhead { border-bottom: 2px solid #2e3192; padding-bottom: 10px; margin-bottom: 16px; }
        .brand { font-size: 22px; font-weight: 700; color: #2e3192; letter-spacing: .02em; }
        .tagline { color: #64748b; font-size: 11px; margin-top: 2px; }
        .meta { width: 100%; margin-top: 10px; }
        .meta td { vertical-align: top; }
        .box { border: 1px solid #e2e8f0; padding: 10px 12px; border-radius: 4px; }
        .label { color: #64748b; font-size: 10px; text-transform: uppercase; letter-spacing: .06em; }
        h1 { font-size: 18px; margin: 0 0 8px; color: #011627; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 14px; }
        table.items th { background: #f1f5f9; color: #334155; font-size: 10px; text-align: left; padding: 8px; border-bottom: 1px solid #cbd5e1; }
        table.items td { padding: 8px; border-bottom: 1px solid #e2e8f0; font-size: 11px; }
        .totals { width: 280px; margin-left: auto; margin-top: 14px; }
        .totals td { padding: 5px 0; }
        .totals .grand { font-size: 14px; font-weight: 700; border-top: 2px solid #2e3192; padding-top: 8px; }
        .footer { margin-top: 28px; border-top: 1px solid #e2e8f0; padding-top: 10px; color: #64748b; font-size: 10px; }
        .watermark {
            position: fixed;
            top: 38%;
            left: 8%;
            width: 84%;
            text-align: center;
            font-size: 54px;
            font-weight: 700;
            color: rgba(46, 49, 146, 0.12);
            transform: rotate(-28deg);
            letter-spacing: .08em;
            z-index: 0;
        }
        .content { position: relative; z-index: 1; }
        @media print {
            .watermark {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
                color: rgba(46, 49, 146, 0.14) !important;
            }
        }
    </style>
</head>
<body>
@if (! empty($watermark_label))
    <div class="watermark">{{ $watermark_label }}</div>
@endif
<div class="content">
    <div class="letterhead">
        <div class="brand">{{ $company['name'] }}</div>
        <div class="tagline">{{ $company['tagline'] }}</div>
        <div class="tagline">{{ $company['address'] }}@if($company['phone']) · {{ $company['phone'] }}@endif @if($company['email']) · {{ $company['email'] }}@endif</div>
    </div>

    <table class="meta">
        <tr>
            <td style="width:55%">
                <div class="box">
                    <div class="label">Bill to</div>
                    <strong>{{ $buyer['name'] }}</strong><br>
                    {{ $buyer['code'] }} · {{ str_replace('_', ' ', (string) $buyer['role']) }}<br>
                    @if($buyer['shop']){{ $buyer['shop'] }}<br>@endif
                    @if($buyer['area'])Area: {{ $buyer['area'] }}<br>@endif
                    {{ collect([$buyer['district'], $buyer['division']])->filter()->implode(', ') }}<br>
                    {{ $buyer['phone'] }} @if($buyer['email']) · {{ $buyer['email'] }}@endif
                </div>
            </td>
            <td style="width:45%; padding-left:12px;">
                <h1>Invoice Letter</h1>
                <div><span class="label">Invoice No</span><br><strong>{{ $order['number'] }}</strong></div>
                <div style="margin-top:8px"><span class="label">Date</span><br>{{ $order['created_at'] }}</div>
                <div style="margin-top:8px"><span class="label">Status</span><br>{{ str_replace('_', ' ', $order['status']) }}</div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:8%">#</th>
                <th>Product</th>
                <th style="width:12%">Qty</th>
                <th style="width:16%">Unit</th>
                <th style="width:18%">Amount</th>
            </tr>
        </thead>
        <tbody>
        @foreach ($lines as $index => $line)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $line['name'] }}@if($line['sku']) <span style="color:#64748b">({{ $line['sku'] }})</span>@endif</td>
                <td>{{ $line['quantity'] }}</td>
                <td>৳{{ $line['unit_price'] }}</td>
                <td>৳{{ $line['total'] }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td style="text-align:right">৳{{ $order['total'] }}</td></tr>
        <tr><td>Verified paid</td><td style="text-align:right">৳{{ $order['paid'] }}</td></tr>
        <tr><td>Outstanding</td><td style="text-align:right">৳{{ $order['due'] }}</td></tr>
        <tr class="grand"><td>Invoice total</td><td style="text-align:right">৳{{ $order['total'] }}</td></tr>
    </table>

    <div class="footer">
        This invoice letter is issued by {{ $company['name'] }}.
        @if ($watermark_label)
            This print is a {{ $watermark_label }} for partner records and does not replace the company delivery original.
        @else
            This is the company delivery original (no partner watermark).
        @endif
    </div>
</div>
</body>
</html>
