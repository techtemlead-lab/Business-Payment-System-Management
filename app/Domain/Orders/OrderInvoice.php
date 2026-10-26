<?php

namespace App\Domain\Orders;

use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
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
            'margin_top' => 12,
            'margin_bottom' => 14,
        ]);
        $pdf->WriteHTML($html);
        $pdf->Output($path, Destination::FILE);

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(Order $order, string $watermark): array
    {
        $order->loadMissing(['user', 'lines']);
        $buyer = $order->user;
        $profile = is_array($buyer?->profile) ? $buyer->profile : [];
        $company = [
            'name' => (string) config('madina.company_name', 'Madina Natural Health Care'),
            'tagline' => (string) __('portal.brand_company_bar'),
            'address' => (string) config('madina.company_address', 'Dhaka, Bangladesh'),
            'phone' => (string) config('madina.company_phone', ''),
            'email' => (string) config('madina.company_email', ''),
        ];

        $lines = $order->lines->map(fn ($line) => [
            'name' => $line->name,
            'sku' => $line->sku ?? '',
            'quantity' => (int) $line->quantity,
            'unit_price' => Money::decimal(Money::cents((string) $line->unit_price)),
            'total' => Money::decimal(Money::cents((string) $line->total)),
        ])->all();

        $receipts = DB::table('receipts')->where('order_id', $order->id)->sum('amount');

        return [
            'company' => $company,
            'order' => [
                'number' => $order->number,
                'created_at' => optional($order->created_at)?->timezone((string) config('madina.business_timezone', config('app.timezone')))->format('d M Y'),
                'status' => $order->status,
                'total' => Money::decimal(Money::cents((string) $order->total)),
                'paid' => Money::decimal(Money::cents((string) $order->paid)),
                'due' => $order->outstanding(),
                'receipts' => Money::decimal(Money::cents((string) $receipts)),
            ],
            'buyer' => [
                'name' => $buyer?->name,
                'code' => $buyer?->dealer_code,
                'role' => $buyer?->role,
                'phone' => $buyer?->phone,
                'email' => $buyer?->email,
                'shop' => $profile['shop_name'] ?? $profile['pharmacy'] ?? null,
                'area' => $profile['place_code'] ?? $profile['area_code'] ?? null,
                'division' => $buyer?->division,
                'district' => $buyer?->district,
            ],
            'lines' => $lines,
            'watermark' => $watermark,
            'watermark_label' => match ($watermark) {
                'dealer' => 'DEALER COPY',
                'sub_dealer' => 'SUB-DEALER COPY',
                default => null,
            },
        ];
    }
}
