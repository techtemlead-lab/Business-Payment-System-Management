<?php

namespace App\Domain\Accounts;

use App\Models\User;
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
    public function downloadPdf(User $user): BinaryFileResponse
    {
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);

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
        $roleLabel = $user->role === 'sub_dealer' ? 'Sub-dealer' : 'Dealer';
        $issued = now('Asia/Dhaka')->format('d M Y');

        $html = '<html><head><style>
            body{font-family:freeserif,sans-serif;color:#011627;margin:0}
            .card{border:2px solid #2e3192;border-radius:10px;padding:18px 20px;width:100%}
            .brand{font-size:11pt;letter-spacing:.08em;text-transform:uppercase;color:#2e3192;font-weight:700}
            .role{display:inline-block;margin-top:6px;padding:2px 8px;background:#eef0fb;color:#2e3192;font-size:9pt;border-radius:4px}
            h1{font-size:18pt;margin:14px 0 4px}
            .meta{font-size:10pt;color:#475569;margin:0 0 12px}
            table{width:100%;border-collapse:collapse;font-size:10pt}
            td{padding:4px 0;vertical-align:top}
            td.k{width:28%;color:#64748b}
            td.v{font-weight:600}
            .code{font-size:16pt;letter-spacing:.12em;color:#ef4a23}
            .foot{margin-top:14px;padding-top:10px;border-top:1px solid #e2e8f0;font-size:8pt;color:#64748b}
            .url{word-break:break-all;font-size:8pt;color:#334155}
        </style></head><body><div class="card">
            <div class="brand">Madina Natural Health Care</div>
            <div class="role">'.e($roleLabel).' identity card</div>
            <h1>'.e($user->name).'</h1>
            <p class="meta">'.e($shop !== '' ? $shop.' · ' : '').e($location !== '' ? $location : 'Bangladesh').'</p>
            <table>
                <tr><td class="k">Dealer code</td><td class="v">'.e($code).'</td></tr>
                <tr><td class="k">Refer code</td><td class="v code">'.e($refer).'</td></tr>
                <tr><td class="k">Mobile</td><td class="v">'.e((string) ($user->phone ?: '—')).'</td></tr>
                <tr><td class="k">Status</td><td class="v">'.e(ucfirst((string) $user->status)).'</td></tr>
                <tr><td class="k">Join link</td><td class="url">'.e($joinUrl).'</td></tr>
            </table>
            <div class="foot">Issued '.$issued.' Asia/Dhaka · Company-managed identity · Not a government ID · Preview only</div>
        </div></body></html>';

        try {
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => [90, 55],
                'margin_left' => 4,
                'margin_right' => 4,
                'margin_top' => 4,
                'margin_bottom' => 4,
                'default_font' => 'freeserif',
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
            ]);
            $pdf->SetTitle('Madina partner ID — '.$code);
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return response()->download(
            $path,
            'Madina-partner-id-'.$safeCode.'.pdf',
            ['Cache-Control' => 'private, no-store'],
        )->deleteFileAfterSend();
    }
}
