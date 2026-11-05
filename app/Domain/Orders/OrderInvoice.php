<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\AmountInWords;
use App\Support\Money;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class OrderInvoice
{
    /**
     * @return 'none'|'dealer'|'sub_dealer'
     */
    public function watermarkFor(User $actor, Order $order): string
    {
        if ($actor->owner() || $actor->staff()) {
            return 'none';
        }
        if ($actor->role === 'dealer' && (int) $order->user_id === (int) $actor->id) {
            return 'dealer';
        }
        if ($actor->role === 'sub_dealer' && (int) $order->user_id === (int) $actor->id) {
            return 'sub_dealer';
        }
        if (in_array($actor->role, ['dealer', 'sub_dealer'], true)) {
            return $actor->role === 'dealer' ? 'dealer' : 'sub_dealer';
        }

        return 'none';
    }

    public function assertVisible(User $actor, Order $order): void
    {
        if ($actor->owner() || $actor->staff()) {
            return;
        }
        abort_unless((int) $order->user_id === (int) $actor->id, 404);
    }

    public function downloadPdf(User $actor, Order $order): BinaryFileResponse
    {
        $this->assertVisible($actor, $order);
        $watermark = $this->watermarkFor($actor, $order);
        $payload = $this->payload($order, $watermark);
        $html = view('invoices.order-letter', $payload)->render();

        $dir = Storage::disk('local')->path('order-invoices');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $filename = 'invoice-'.$order->number.'-'.Str::lower($watermark === 'none' ? 'company' : str_replace('_', '-', $watermark)).'.pdf';
        $path = $dir.'/'.$filename;

        $pdf = new Mpdf([
            'tempDir' => $dir,
            'format' => 'A4',
            'default_font' => 'freeserif',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'margin_left' => 12,
            'margin_right' => 12,
            'margin_top' => 10,
            'margin_bottom' => 12,
        ]);
        $pdf->WriteHTML($html);
        $pdf->Output($path, Destination::FILE);

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    public function printHtml(User $actor, Order $order): Response
    {
        $this->assertVisible($actor, $order);
        $watermark = $this->watermarkFor($actor, $order);
        $payload = $this->payload($order, $watermark);
        $payload['printable'] = true;
        $payload['auto_print'] = true;
        $payload['order_id'] = $order->id;
        $payload['share_url'] = PortalHost::url(PortalHost::forUser($actor), '/orders/'.$order->id.'/invoice/print');
        if (! empty($payload['company']['logo_path']) && is_file((string) $payload['company']['logo_path'])) {
            $publicLogo = public_path('branding/madina-logo-latest.png');
            $payload['company']['logo_path'] = is_file($publicLogo)
                ? asset('branding/madina-logo-latest.png')
                : asset('madina-header-logo.png');
        }

        return response()->view('invoices.order-letter', $payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Order $order, string $watermark): array
    {
        $order->loadMissing(['user', 'lines']);
        $buyer = $order->user;
        $profile = is_array($buyer?->profile) ? $buyer->profile : [];
        $custom = SiteCustomization::all();

        $logoPath = public_path('branding/madina-logo-latest.png');
        if (! is_file($logoPath)) {
            $logoPath = public_path('madina-header-logo.png');
        }

        $company = [
            'name' => (string) ($custom['contact_company_title'] ?? config('madina.company_name', 'Madina Natural Health Care')),
            'address' => (string) ($custom['office_address'] ?? $custom['footer_address'] ?? 'Thana Road, Pirganj 5470, Rangpur, Bangladesh'),
            'phones' => (string) ($custom['contact_phone'] ?? $custom['footer_phone'] ?? '+8801730532966'),
            'manufacturer' => (string) config('madina.invoice_manufacturer', 'Holistic Nutracare'),
            'marketed_by' => (string) config('madina.invoice_marketed_by', 'Madina Natural Health Care'),
            'marketed_phones' => (string) config('madina.invoice_marketed_phones', '01723-731877, 01730532966'),
            'logo_path' => is_file($logoPath) ? $logoPath : null,
        ];

        $products = Product::query()
            ->whereIn('id', $order->lines->pluck('product_id')->filter()->all())
            ->get(['id', 'unit', 'strength', 'sub_category', 'category'])
            ->keyBy('id');

        $lines = [];
        $sumQty = 0;
        $sumMrpBill = 0;
        $sumCommission = 0;
        $sumTotal = 0;

        foreach ($order->lines as $index => $line) {
            $product = $products->get($line->product_id);
            $qty = (int) $line->quantity;
            $mrpCents = Money::cents((string) $line->mrp);
            $rateCents = Money::cents((string) $line->unit_price);
            $totalCents = Money::cents((string) $line->total);
            $mrpBillCents = $mrpCents * $qty;
            $commissionCents = max(0, $mrpBillCents - $totalCents);
            $remarkPct = $mrpCents > 0
                ? (int) round((($mrpCents - $rateCents) / $mrpCents) * 100)
                : 0;

            $sumQty += $qty;
            $sumMrpBill += $mrpBillCents;
            $sumCommission += $commissionCents;
            $sumTotal += $totalCents;

            $lines[] = [
                'sl' => $index + 1,
                'name' => $line->name,
                'strength' => $product?->strength,
                'sub_category' => $product?->sub_category,
                'category' => $product?->category,
                'pack_size' => (string) ($product?->unit ?: 'pack'),
                'quantity' => $qty,
                'mrp' => Money::decimal($mrpCents),
                'rate' => Money::decimal($rateCents),
                'mrp_bill' => Money::decimal($mrpBillCents),
                'commission' => Money::decimal($commissionCents),
                'total' => Money::decimal($totalCents),
                'remark' => $remarkPct > 0 ? $remarkPct.'%' : '—',
            ];
        }

        $sponsor = $buyer?->sponsor_id
            ? User::query()->find($buyer->sponsor_id)
            : null;

        $bookingAddress = collect([
            $profile['shop_name'] ?? $profile['pharmacy'] ?? null,
            $profile['village'] ?? null,
            $profile['upazila'] ?? null,
            $buyer?->district,
            $buyer?->division,
        ])->filter()->implode(', ');

        $designation = match ($buyer?->role) {
            'dealer' => __('portal.inv_role_dealer'),
            'sub_dealer' => __('portal.inv_role_sub_dealer'),
            default => Str::headline((string) $buyer?->role),
        };

        $tz = (string) config('madina.business_timezone', config('app.timezone'));
        $created = optional($order->created_at)?->timezone($tz);

        $paidCents = Money::cents((string) $order->paid);
        $dueCents = Money::cents($order->outstanding());
        $paymentState = match (true) {
            $dueCents === 0 => 'paid',
            $paidCents > 0 => 'partial',
            default => 'unpaid',
        };
        $payments = $order->receipts()
            ->orderBy('created_at')
            ->get(['channel', 'reference', 'amount', 'created_at'])
            ->map(fn ($receipt) => [
                'channel' => Str::headline((string) $receipt->channel),
                'reference' => (string) $receipt->reference,
                'amount' => Money::grouped((string) $receipt->amount),
                'date' => optional($receipt->created_at)?->timezone($tz)->format('d/m/Y'),
            ])
            ->all();

        return [
            'company' => $company,
            'order' => [
                'number' => $order->number,
                'date' => $created?->format('d/m/Y'),
                'created_at' => $created?->format('d/m/Y'),
                'status' => $order->status,
                'total' => Money::decimal(Money::cents((string) $order->total)),
                'paid' => Money::decimal($paidCents),
                'due' => $order->outstanding(),
                'total_display' => Money::grouped((string) $order->total),
                'paid_display' => Money::grouped(Money::decimal($paidCents)),
                'credit_display' => Money::grouped((string) ($order->due_credit ?? '0.00')),
                'due_display' => Money::grouped($order->outstanding()),
                'payment_state' => $paymentState,
                'payment_state_label' => __('portal.inv_payment_'.$paymentState),
            ],
            'payments' => $payments,
            'buyer' => [
                'name' => $buyer?->name,
                'code' => $buyer?->dealer_code,
                'designation' => $designation,
                'area' => $buyer?->district
                    ?: ($profile['upazila'] ?? $profile['place_code'] ?? $profile['area_code'] ?? '—'),
                'phone' => $buyer?->phone,
                'booking_address' => $bookingAddress !== '' ? $bookingAddress : '—',
                'referred_code' => $sponsor?->dealer_code ?: $sponsor?->refer_code,
            ],
            'lines' => $lines,
            'totals' => [
                'quantity' => $sumQty,
                'mrp_bill' => Money::decimal($sumMrpBill),
                'commission' => Money::decimal($sumCommission),
                'total' => Money::decimal($sumTotal),
                'in_words' => AmountInWords::taka(Money::decimal($sumTotal)),
            ],
            'watermark' => $watermark,
            'watermark_label' => match ($watermark) {
                'dealer' => __('portal.inv_copy_dealer'),
                'sub_dealer' => __('portal.inv_copy_sub_dealer'),
                default => null,
            },
        ];
    }
}
