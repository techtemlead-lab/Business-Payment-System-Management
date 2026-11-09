<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\JoinQr;
use App\Support\PdfFonts;
use App\Support\PortalHost;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Printable partner identity card — common in Bangladesh dealer networks.
 * Preview artifact only; does not post financial entries.
 */
final class PartnerIdCard
{
    /** The standard bank-card size, CR80 (ISO/IEC 7810 ID-1). */
    private const CardWidthMm = 85.6;

    private const CardHeightMm = 54.0;

    private const CardMarginMm = 2.5;

    /** A long name or place steps the text down until the whole card fits on one page. */
    private const TextScales = [1.0, 0.92, 0.84, 0.76, 0.68, 0.6, 0.52];

    public function downloadPdf(User $user): BinaryFileResponse
    {
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);

        $locale = $user->preferredLocale();
        $t = static fn (string $key, array $replace = []) => __('portal.'.$key, $replace, $locale);

        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $profile = is_array($user->profile) ? $user->profile : [];
        $code = (string) ($user->dealer_code ?: 'PARTNER');
        $refer = (string) ($user->refer_code ?: $code);
        $joinUrl = PortalHost::url('public', '/join/'.$refer);
        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', $code) ?: 'partner';
        $path = $dir.'/partner-id-'.$safeCode.'-'.now('Asia/Dhaka')->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';

        $shop = $user->role === 'sub_dealer'
            ? (string) ($profile['shop_name'] ?? $profile['pharmacy'] ?? '')
            : '';
        $location = collect([$profile['upazila'] ?? null, $user->district, $user->division])
            ->filter(fn ($part) => filled($part))
            ->implode(', ');
        $roleKey = 'acct_role_'.$user->role;
        $roleLabel = __('portal.'.$roleKey, [], $locale);
        if ($roleLabel === 'portal.'.$roleKey) {
            $roleLabel = ucfirst(str_replace('_', ' ', (string) $user->role));
        }
        $issued = now('Asia/Dhaka')->format('d M Y');

        $qrPng = $dir.'/partner-id-qr-'.$safeCode.'-'.Str::lower(Str::random(6)).'.png';
        // The card's QR opens the public check page, so anyone can see the card is genuine.
        file_put_contents($qrPng, JoinQr::png(PortalHost::url('public', DealerVerification::path($user->dealer_code ?: $refer)), 140));

        // Pixel sizes are worked out from the text size, so a smaller step shrinks everything together.
        $html = fn (float $scale): string => $this->cardHtml($scale, [
            'brand' => $t('brand_madina_natural_health_care'),
            'role' => $t('pdf_id_card_role', ['role' => $roleLabel]),
            'name' => (string) $user->name,
            'place' => ($shop !== '' ? $shop.' · ' : '').($location !== '' ? $location : $t('pdf_location_bangladesh')),
            'code_label' => $t('pdf_id_card_dealer_code'),
            'code' => $code,
            'refer_label' => $t('pdf_id_card_refer_code'),
            'refer' => $refer,
            'mobile_label' => $t('pdf_id_card_mobile'),
            'mobile' => (string) ($user->phone ?: '—'),
            'link_label' => $t('pdf_id_card_join_link'),
            'link' => $joinUrl,
            'qr' => $qrPng,
            'scan' => __('public_trust.id_card_scan_to_verify', [], $locale),
            'foot' => $t('pdf_id_card_foot', ['when' => $issued]),
        ]);

        try {
            foreach (self::TextScales as $scale) {
                $pdf = new Mpdf([
                    'tempDir' => $dir,
                    'format' => [self::CardWidthMm, self::CardHeightMm],
                    'margin_left' => self::CardMarginMm,
                    'margin_right' => self::CardMarginMm,
                    'margin_top' => self::CardMarginMm,
                    'margin_bottom' => self::CardMarginMm,
                    ...PdfFonts::options(),
                ]);
                $pdf->SetTitle($t('pdf_id_card_doc_title', ['code' => $code]));
                $pdf->WriteHTML($html($scale));
                if ($pdf->page === 1) {
                    break;
                }
            }
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            @unlink($qrPng);
            throw $exception;
        }
        @unlink($qrPng);

        return response()->download(
            $path,
            'Madina-partner-id-'.$safeCode.'.pdf',
            ['Cache-Control' => 'private, no-store'],
        )->deleteFileAfterSend();
    }

    /**
     * @param  array{brand: string, role: string, name: string, place: string, code_label: string, code: string, refer_label: string, refer: string, mobile_label: string, mobile: string, link_label: string, link: string, qr: string, scan: string, foot: string}  $card
     */
    private function cardHtml(float $scale, array $card): string
    {
        $pt = static fn (float $size): string => round($size * $scale, 2).'pt';
        $mm = static fn (float $size): string => round($size * max($scale, 0.8), 2).'mm';

        return '<html><head><style>
            body{font-family:freeserif,sans-serif;color:#011627;margin:0;line-height:1.12}
            .frame{position:fixed;left:0;top:0;width:'.(self::CardWidthMm - 2 * self::CardMarginMm).'mm;height:'.(self::CardHeightMm - 2 * self::CardMarginMm).'mm;border:1.5px solid #2e3192;border-radius:8px}
            .card{padding:6px 8px;width:100%}
            .row{width:100%}
            .brand{font-size:'.$pt(8).';letter-spacing:.02em;text-transform:uppercase;color:#2e3192;font-weight:700}
            .role{display:inline-block;margin-top:1px;padding:0 5px;background:#eef0fb;color:#2e3192;font-size:'.$pt(8).';border-radius:4px}
            h1{font-size:'.$pt(12).';margin:1px 0 0}
            .meta{font-size:'.$pt(8).';color:#475569;margin:0 0 2px}
            table{width:100%;border-collapse:collapse;font-size:'.$pt(8).'}
            td{padding:.5px 0;vertical-align:top}
            td.k{width:30%;color:#64748b}
            td.v{font-weight:600}
            .code{font-size:'.$pt(10).';letter-spacing:.06em;color:#ef4a23}
            .foot{margin-top:2px;padding-top:2px;border-top:1px solid #e2e8f0;font-size:'.$pt(6).';color:#64748b}
            .url{word-break:break-all;font-size:'.$pt(6.5).';color:#334155}
            .qr{width:'.$mm(22).';height:'.$mm(22).'}
        </style></head><body><div class="frame"></div><div class="card">
            <table class="row"><tr>
            <td style="width:72%;padding-right:6px;vertical-align:top">
            <div class="brand">'.e($card['brand']).'</div>
            <div class="role">'.e($card['role']).'</div>
            <h1>'.e($card['name']).'</h1>
            <p class="meta">'.e($card['place']).'</p>
            <table>
                <tr><td class="k">'.e($card['code_label']).'</td><td class="v">'.e($card['code']).'</td></tr>
                <tr><td class="k">'.e($card['refer_label']).'</td><td class="v code">'.e($card['refer']).'</td></tr>
                <tr><td class="k">'.e($card['mobile_label']).'</td><td class="v">'.e($card['mobile']).'</td></tr>
                <tr><td class="k">'.e($card['link_label']).'</td><td class="url">'.e($card['link']).'</td></tr>
            </table>
            </td>
            <td style="width:28%;text-align:center;vertical-align:middle">
                <img class="qr" src="'.e($card['qr']).'" alt="'.e($card['scan']).'"/>
                <div style="font-size:'.$pt(6).';color:#64748b;margin-top:2px">'.e($card['scan']).'</div>
            </td>
            </tr></table>
            <div class="foot">'.e($card['foot']).'</div>
        </div></body></html>';
    }
}
