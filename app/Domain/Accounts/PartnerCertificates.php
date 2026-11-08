<?php

namespace App\Domain\Accounts;

use App\Models\DealerRank;
use App\Models\TrainingCompletion;
use App\Models\TrainingItem;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Printable certificates for a partner, made from the company's own records:
 * a yearly income certificate (for a bank or the tax office) and a rank certificate.
 * The rank certificate shows no money, because the MLM Act 2013 (s.23) forbids income claims.
 */
final class PartnerCertificates
{
    /** Commission rows that were taken back, or moved to company profit at month close, do not count as income. */
    private const NotIncome = ['reversed', 'forfeited'];

    /**
     * Commission earned in one Dhaka calendar year by category, and money actually sent out.
     *
     * @return array{year: int, lines: list<array{category: string, label: string, amount: string}>, total: string, withdrawn_net: string, tax_cut: string}
     */
    public function income(User $user, int $year): array
    {
        $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, 'Asia/Dhaka')->utc();
        $until = $from->addYear();
        $rows = DB::table('commission_allocations')
            ->where('user_id', $user->id)
            ->whereNotIn('status', self::NotIncome)
            ->where('created_at', '>=', $from)
            ->where('created_at', '<', $until)
            ->groupBy('category')
            ->selectRaw('category, COALESCE(SUM(amount), 0) AS amount')
            ->orderBy('category')
            ->get();
        $paid = DB::table('withdrawals')
            ->where('user_id', $user->id)
            ->where('status', 'paid')
            ->where('paid_at', '>=', $from)
            ->where('paid_at', '<', $until)
            ->selectRaw('COALESCE(SUM(COALESCE(net_amount, amount)), 0) AS net, COALESCE(SUM(withheld_amount), 0) AS tax')
            ->first();
        $lines = [];
        $total = 0;
        foreach ($rows as $row) {
            $cents = Money::cents((string) $row->amount);
            $total += $cents;
            $key = 'portal.report_cat_'.$row->category;
            $label = __($key);
            $lines[] = [
                'category' => (string) $row->category,
                'label' => $label === $key ? Str::headline((string) $row->category) : $label,
                'amount' => Money::decimal($cents),
            ];
        }

        return [
            'year' => $year,
            'lines' => $lines,
            'total' => Money::decimal($total),
            'withdrawn_net' => Money::decimal(Money::cents((string) ($paid->net ?? '0'))),
            'tax_cut' => Money::decimal(Money::cents((string) ($paid->tax ?? '0'))),
        ];
    }

    public function incomePdf(User $user, int $year): BinaryFileResponse
    {
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);
        $currentYear = (int) now('Asia/Dhaka')->format('Y');
        abort_unless($year >= 2020 && $year <= $currentYear, 404);
        $locale = $user->preferredLocale();
        $t = static fn (string $key, array $replace = []): string => __('portal.'.$key, $replace, $locale);
        $income = $this->income($user, $year);

        $rows = '';
        foreach ($income['lines'] as $line) {
            $key = 'portal.report_cat_'.$line['category'];
            $label = __($key, [], $locale);
            $rows .= '<tr><td>'.e($label === $key ? $line['label'] : $label).'</td><td class="num">৳ '.e(Money::grouped($line['amount'])).'</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="2" class="muted">'.e($t('cert_income_none')).'</td></tr>';
        }
        $body = '<h1>'.e($t('cert_income_title', ['year' => (string) $year])).'</h1>'
            .'<p class="lead">'.e($t('cert_income_lead', ['name' => $user->name, 'code' => (string) ($user->dealer_code ?? '—'), 'role' => $this->roleLabel($user, $locale), 'year' => (string) $year])).'</p>'
            .'<table><thead><tr><th>'.e($t('cert_income_col_kind')).'</th><th class="num">'.e($t('cert_income_col_amount')).'</th></tr></thead><tbody>'.$rows.'</tbody>'
            .'<tfoot><tr><td>'.e($t('cert_income_total')).'</td><td class="num">৳ '.e(Money::grouped($income['total'])).'</td></tr></tfoot></table>'
            .'<table class="small"><tr><td>'.e($t('cert_income_withdrawn')).'</td><td class="num">৳ '.e(Money::grouped($income['withdrawn_net'])).'</td></tr>'
            .'<tr><td>'.e($t('cert_income_tax_cut')).'</td><td class="num">৳ '.e(Money::grouped($income['tax_cut'])).'</td></tr></table>'
            .'<p class="note">'.e($t('cert_income_note')).'</p>';

        return $this->render($user, $body, $t('cert_income_doc_title', ['year' => (string) $year]), 'Madina-income-certificate-'.$year, 'A4', $locale);
    }

    public function rankPdf(User $user): BinaryFileResponse
    {
        abort_unless($user->role === 'dealer', 403);
        $rank = DealerRank::query()->where('user_id', $user->id)->first();
        abort_if(! $rank || in_array((string) $rank->current_rank, ['', 'dealer'], true), 404);
        $locale = $user->preferredLocale();
        $t = static fn (string $key, array $replace = []): string => __('portal.'.$key, $replace, $locale);
        $rankKey = 'portal.net_rank_'.$rank->current_rank;
        $rankName = __($rankKey, [], $locale);
        if ($rankName === $rankKey) {
            $rankName = Str::upper((string) $rank->current_rank);
        }
        $achieved = $rank->achieved_at ? CarbonImmutable::parse($rank->achieved_at)->timezone('Asia/Dhaka')->format('d M Y') : '—';
        $body = '<div class="award"><p class="kicker">'.e($t('cert_rank_kicker')).'</p>'
            .'<h1 class="big">'.e($user->name).'</h1>'
            .'<p class="lead">'.e($t('cert_rank_lead', ['code' => (string) ($user->dealer_code ?? '—')])).'</p>'
            .'<p class="rank">'.e($rankName).'</p>'
            .'<p class="lead">'.e($t('cert_rank_since', ['date' => $achieved])).'</p>'
            .'<p class="note">'.e($t('cert_rank_note')).'</p></div>';

        return $this->render($user, $body, $t('cert_rank_doc_title'), 'Madina-rank-certificate-'.$rank->current_rank, 'A4-L', $locale);
    }

    /** Completion certificate for a training item whose quiz the partner passed. No money figures. */
    public function trainingPdf(User $user, TrainingItem $item, TrainingCompletion $completion): BinaryFileResponse
    {
        abort_unless($completion->passed_at !== null && (int) $completion->user_id === (int) $user->id, 404);
        $locale = $user->preferredLocale();
        $t = static fn (string $key, array $replace = []): string => __('portal.'.$key, $replace, $locale);
        $title = $locale === 'bn' ? $item->title_bn : $item->title_en;
        $passed = CarbonImmutable::parse($completion->passed_at)->timezone('Asia/Dhaka')->format('d M Y');
        $body = '<div class="award"><p class="kicker">'.e($t('cert_training_kicker')).'</p>'
            .'<h1 class="big">'.e($user->name).'</h1>'
            .'<p class="lead">'.e($t('cert_training_lead', ['code' => (string) ($user->dealer_code ?? '—'), 'role' => $this->roleLabel($user, $locale)])).'</p>'
            .'<p class="rank">'.e($title).'</p>'
            .'<p class="lead">'.e($t('cert_training_score', ['score' => (string) $completion->best_score, 'date' => $passed])).'</p>'
            .'<p class="note">'.e($t('cert_training_note')).'</p></div>';

        return $this->render($user, $body, $t('cert_training_doc_title'), 'Madina-training-certificate-'.$item->id, 'A4-L', $locale);
    }

    private function roleLabel(User $user, string $locale): string
    {
        $key = 'portal.acct_role_'.$user->role;
        $label = __($key, [], $locale);

        return $label === $key ? ucfirst(str_replace('_', ' ', (string) $user->role)) : $label;
    }

    private function render(User $user, string $body, string $title, string $fileName, string $format, string $locale): BinaryFileResponse
    {
        $dir = Storage::disk('local')->path('report-exports');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($user->dealer_code ?: 'partner')) ?: 'partner';
        $path = $dir.'/certificate-'.$safeCode.'-'.now('Asia/Dhaka')->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.pdf';
        $issued = now('Asia/Dhaka')->format('d M Y');
        $serial = Str::upper(substr(hash('sha256', $user->id.'|'.$title.'|'.$issued), 0, 10));
        $t = static fn (string $key, array $replace = []): string => __('portal.'.$key, $replace, $locale);
        $html = '<html><head><style>
            body{font-family:freeserif,sans-serif;color:#0b1f33;margin:0}
            .sheet{border:3px double #006a4e;padding:22px 26px}
            .brand{font-size:13pt;color:#006a4e;font-weight:700;text-align:center}
            .sub{font-size:8.5pt;color:#5b6b7a;text-align:center;margin:2px 0 14px}
            h1{font-size:17pt;margin:6px 0 8px;text-align:center}
            h1.big{font-size:24pt;margin:6px 0}
            .lead{font-size:11pt;line-height:1.6;margin:0 0 12px;text-align:center}
            table{width:100%;border-collapse:collapse;margin:8px 0 12px;font-size:10.5pt}
            th,td{border-bottom:1px solid #dfe5e0;padding:6px 8px;text-align:left}
            th{background:#e3f1eb;color:#00482f}
            .num{text-align:right}
            tfoot td{font-weight:700;border-top:2px solid #006a4e}
            table.small{width:60%;margin-left:40%;font-size:10pt}
            .muted{color:#5b6b7a;text-align:center}
            .note{font-size:8.5pt;color:#5b6b7a;margin-top:12px;line-height:1.5}
            .award{text-align:center;padding:20px 10px}
            .kicker{font-size:12pt;color:#5b6b7a;margin:0}
            .rank{font-size:22pt;color:#c2410c;font-weight:700;margin:10px 0}
            .foot{margin-top:18px;padding-top:8px;border-top:1px solid #dfe5e0;font-size:8pt;color:#5b6b7a}
        </style></head><body><div class="sheet">
            <div class="brand">'.e($t('brand_madina_natural_health_care')).'</div>
            <div class="sub">'.e($t('cert_company_line')).'</div>
            '.$body.'
            <div class="foot">'.e($t('cert_foot', ['date' => $issued, 'serial' => $serial])).'</div>
        </div></body></html>';
        try {
            $pdf = new Mpdf([
                'tempDir' => $dir,
                'format' => $format,
                'margin_left' => 12,
                'margin_right' => 12,
                'margin_top' => 12,
                'margin_bottom' => 12,
                'default_font' => 'freeserif',
                'autoScriptToLang' => true,
                'autoLangToFont' => true,
            ]);
            $pdf->SetTitle($title);
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return response()->download($path, $fileName.'.pdf', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }
}
