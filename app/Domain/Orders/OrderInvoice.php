<?php

namespace App\Domain\Orders;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockLotReports;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\AmountInWords;
use App\Support\Money;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Carbon\CarbonImmutable;
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
        if ($actor->owner() || ($actor->staff() && ($actor->allows('orders') || $actor->allows('finance')) && app(BranchOperations::class)->mayOpenOrder($actor, $order))) {
            return;
        }
        abort_if($actor->staff(), 403);
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
        $payload['csp_nonce'] = (string) request()->attributes->get('csp_nonce', '');
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

        // Letterhead text is edited in the website editor; its factory defaults fill any gap.
        $defaults = SiteCustomization::defaults();
        $letterhead = static fn (string $key): string => filled($custom[$key] ?? null) ? (string) $custom[$key] : (string) ($defaults[$key] ?? '');
        $company = [
            'name' => $letterhead('contact_company_title'),
            'address' => $letterhead('office_address'),
            'phones' => $letterhead('contact_phone'),
            'manufacturer' => $letterhead('invoice_manufacturer'),
            // Empty boxes mean "same as the company": the name and phones are stored once.
            'marketed_by' => $letterhead('invoice_marketed_by') ?: $letterhead('contact_company_title'),
            'marketed_phones' => $letterhead('invoice_marketed_phones') ?: implode(', ', array_filter([SiteCustomization::secondPhone(), $letterhead('contact_phone')])),
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

        $dispatchedBatches = app(StockLotReports::class)->dispatchedBatches($order->lines->pluck('id')->map(fn ($id): int => (int) $id)->all());
        $formatExpiry = static fn (?string $date): ?string => $date ? CarbonImmutable::parse($date)->format('d/m/Y') : null;

        foreach ($order->lines as $index => $line) {
            $product = $products->get($line->product_id);
            $sentBatches = $dispatchedBatches[(int) $line->getKey()] ?? [];
            // Sent before batches were recorded: the copy taken when the order was placed is all there is.
            $usesOrderCopy = $sentBatches === [] && (int) $line->getAttribute('dispatched') > 0;
            $orderBatch = $line->batch_number;
            $orderExpiry = $line->expires_on;
            $qty = (int) $line->quantity;
            $mrpCents = Money::cents((string) $line->mrp);
            $rateCents = Money::cents((string) $line->unit_price);
            $totalCents = Money::cents((string) $line->total);
            // Free scheme goods add nothing to the MRP bill or the trade discount.
            $isFreeLine = (bool) $line->getAttribute('is_free');
            $mrpBillCents = $isFreeLine ? 0 : $mrpCents * $qty;
            $commissionCents = max(0, $mrpBillCents - $totalCents);
            $remarkPct = $mrpCents > 0 && ! $isFreeLine
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
                'batches' => $usesOrderCopy && (filled($orderBatch) || filled($orderExpiry))
                    ? [['batch_number' => $orderBatch, 'expires_on' => $formatExpiry($orderExpiry ? (string) $orderExpiry : null), 'quantity' => null]]
                    : array_map(fn (array $batch): array => [...$batch, 'expires_on' => $formatExpiry($batch['expires_on'])], $sentBatches),
                'quantity' => $qty,
                'mrp' => Money::decimal($mrpCents),
                'rate' => Money::decimal($rateCents),
                'mrp_bill' => Money::decimal($mrpBillCents),
                'commission' => Money::decimal($commissionCents),
                'total' => Money::decimal($totalCents),
                'remark' => $isFreeLine ? __('schemes_commission.free_line_label') : ($remarkPct > 0 ? $remarkPct.'%' : '—'),
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
