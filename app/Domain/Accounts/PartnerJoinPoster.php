<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\JoinQr;
use App\Support\PortalHost;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A5 field join poster for shop walls — common Bangladesh dealer recruitment tool.
 * Preview artifact only; does not post financial entries.
 */
final class PartnerJoinPoster
{
    public function downloadPdf(User $user): BinaryFileResponse
    {
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);
        abort_unless(filled($user->refer_code), 404);

        $locale = $user->preferredLocale();
        $t = static fn (string $key, array $replace = []) => __('portal.'.$key, $replace, $locale);

        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $profile = is_array($user->profile) ? $user->profile : [];
        $code = (string) ($user->dealer_code ?: 'PARTNER');
        $refer = (string) $user->refer_code;
        $joinUrl = PortalHost::url('public', '/join/'.$refer);
        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', $code) ?: 'partner';
        $path = $dir.'/join-poster-'.$safeCode.'-'.now('Asia/Dhaka')->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';

        $shop = (string) ($profile['shop_name'] ?? $profile['pharmacy'] ?? '');
        $location = collect([$profile['upazila'] ?? null, $user->district, $user->division])
            ->filter(fn ($part) => filled($part))
            ->implode(', ');
        $roleKey = 'acct_role_'.$user->role;
        $roleLabel = __('portal.'.$roleKey, [], $locale);
        if ($roleLabel === 'portal.'.$roleKey) {
            $roleLabel = str_replace('_', ' ', (string) $user->role);
        }
        $issued = now('Asia/Dhaka')->format('d M Y');

        $qrPng = $dir.'/join-poster-qr-'.$safeCode.'-'.Str::lower(Str::random(6)).'.png';
        file_put_contents($qrPng, JoinQr::png($joinUrl, 280));

        $html = '<html><head><style>
            body{font-family:freeserif,sans-serif;color:#011627;margin:0}
            .sheet{border:3px solid #2e3192;border-radius:8px;padding:18px 16px;text-align:center}
            .brand{font-size:11pt;letter-spacing:.12em;text-transform:uppercase;color:#2e3192;font-weight:700}
            .tag{display:inline-block;margin:8px 0 4px;padding:3px 10px;background:#eef0fb;color:#2e3192;font-size:9pt;border-radius:4px}
            h1{font-size:18pt;margin:10px 0 4px;line-height:1.2}
            .lead{font-size:11pt;color:#334155;margin:0 0 12px}
            .qr{width:55mm;height:55mm;margin:6px auto}
            .code{font-size:20pt;letter-spacing:.14em;color:#ef4a23;font-weight:700;margin:8px 0 4px}
            .meta{font-size:9pt;color:#475569;margin:0 0 6px}
            .url{word-break:break-all;font-size:8pt;color:#334155;margin:8px 0}
            .foot{margin-top:12px;padding-top:8px;border-top:1px solid #e2e8f0;font-size:7pt;color:#64748b}
        </style></head><body><div class="sheet">
            <div class="brand">'.e($t('brand_madina_natural_health_care')).'</div>
            <div class="tag">'.e($t('pdf_join_poster_tag', ['role' => $roleLabel])).'</div>
            <h1>'.e($t('pdf_join_poster_headline', ['name' => $user->name])).'</h1>
            <p class="lead">'.e($t('pdf_join_poster_lead')).'</p>
            <img class="qr" src="'.e($qrPng).'" alt="'.e($t('pdf_id_card_scan')).'"/>
            <div class="code">'.e($refer).'</div>
            <p class="meta">'.e($shop !== '' ? $shop.' · ' : '').e($location !== '' ? $location : $t('pdf_location_bangladesh')).'</p>
            <p class="url">'.e($joinUrl).'</p>
            <div class="foot">'.e($t('pdf_join_poster_foot', ['when' => $issued])).'</div>
        </div></body></html>';

        try {
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => 'A5',
                'margin_left' => 8,
                'margin_right' => 8,
                'margin_top' => 8,
                'margin_bottom' => 8,
                'default_font' => 'freeserif',
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
            ]);
            $pdf->SetTitle($t('pdf_join_poster_doc_title', ['code' => $refer]));
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            @unlink($qrPng);
            throw $exception;
        }
        @unlink($qrPng);

        return response()->download(
            $path,
            'Madina-join-poster-'.$safeCode.'.pdf',
            ['Cache-Control' => 'private, no-store'],
        )->deleteFileAfterSend();
    }
}
