<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Money;
use App\Support\PdfFonts;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The printed statement of one income part for the chosen days: the partner's name, code and who
 * referred them on top, then one line per day it came, with a total at the bottom.
 */
final class PartnerIncomeStatementPdf
{
    public function __construct(private PartnerIncomeBook $income) {}

    public function download(Request $request, User $partner, string $part): BinaryFileResponse
    {
        $statement = $this->income->statement($request, $partner, $part);
        if ($statement['capped']) {
            throw ValidationException::withMessages(['part' => __('portal.ops_val_pdf_row_limit')]);
        }
        $t = fn (string $key, array $replace = []): string => __('income.'.$key, $replace);
        $money = fn (string $value): string => (str_starts_with($value, '-') ? '-' : '').Money::grouped(ltrim($value, '-'));
        $partWord = PartnerIncomeBook::partWord($part);
        $sponsor = User::query()->find($partner->sponsor_id);
        $referredBy = $sponsor === null ? '—' : $sponsor->name.($sponsor->dealer_code ? ' ('.$sponsor->dealer_code.')' : '');
        $head = fn (string $label, string $value): string => '<tr><td class="k">'.e($label).'</td><td>'.e($value).'</td></tr>';

        $html = '<style>body{font-family:freeserif;font-size:10pt;color:#153d30}table{border-collapse:collapse;width:100%}table.list th{background:#153d30;color:white}table.list td,table.list th{padding:5px;border:1px solid #cddbd4}td.num,th.num{text-align:right;white-space:nowrap}table.list tbody tr:nth-child(even) td{background:#f5f9f7}table.list tfoot th{background:#e8f0ec;color:#153d30}table.who td{padding:2px 6px 2px 0;border:0}td.k{color:#52665d;width:28%}h1{font-size:18pt;margin-bottom:0}h2{font-size:14pt}small{color:#52665d}</style>'
            .'<h1>Madina Natural Health Care</h1><h2>'.e($partWord).' — '.e($t('pdf_title')).'</h2>'
            .'<table class="who">'
            .$head($t('pdf_partner'), $partner->name)
            .$head($t('pdf_code'), (string) ($partner->dealer_code ?: '—'))
            .$head($t('pdf_referred_by'), $referredBy)
            .$head($t('pdf_period'), $this->periodWord($statement['from'], $statement['until']))
            .'</table><br>'
            .'<table class="list"><thead><tr><th>'.e($t('col_date')).'</th><th>'.e($t('col_what')).'</th><th>'.e($t('col_source')).'</th><th>'.e($t('col_status')).'</th><th class="num">'.e($t('col_amount')).'</th></tr></thead><tbody>';
        foreach ($statement['rows'] as $row) {
            $source = trim(($row['order_number'] ?? '').' '.($row['from_name'] ?? ''));
            $percent = (float) ($row['percent'] ?? 0) > 0 ? ' '.rtrim(rtrim(number_format((float) $row['percent'], 2, '.', ''), '0'), '.').'%' : '';
            $html .= '<tr><td>'.e(CarbonImmutable::parse($row['occurred_at'])->timezone('Asia/Dhaka')->format('d M Y')).'</td><td>'.e($row['what'].$percent).'</td><td>'.e($source).'</td><td>'.e($t('status_'.$row['status'])).'</td><td class="num">'.e($money($row['amount'])).'</td></tr>';
        }
        $html .= '</tbody><tfoot><tr><th colspan="4">'.e($t('pdf_total', ['count' => $statement['count']])).'</th><th class="num">'.e($money($statement['total'])).'</th></tr></tfoot></table>'
            .'<p><small>BDT · Asia/Dhaka · '.e($t('pdf_generated', ['when' => now('Asia/Dhaka')->format('d M Y H:i')])).'</small></p>';

        Storage::disk('local')->makeDirectory('report-exports');
        $path = Storage::disk('local')->path('report-exports/'.Str::uuid().'.pdf');
        try {
            $pdf = new Mpdf(['tempDir' => Storage::disk('local')->path('report-exports'), 'format' => 'A4', ...PdfFonts::options()]);
            $pdf->SetTitle($partWord.' — '.$t('pdf_title'));
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        $code = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($partner->dealer_code ?: $partner->id)) ?: (string) $partner->id;

        return response()->download($path, 'Madina-income-'.$part.'-'.$code.'.pdf', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    private function periodWord(?CarbonImmutable $from, ?CarbonImmutable $until): string
    {
        if ($from === null || $until === null) {
            return (string) __('income.pdf_all_time');
        }
        $first = $from->timezone('Asia/Dhaka');
        $last = $until->timezone('Asia/Dhaka')->subSecond();

        return $first->format('d M Y').' — '.$last->format('d M Y');
    }
}
