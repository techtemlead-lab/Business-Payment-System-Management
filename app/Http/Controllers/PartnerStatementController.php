<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerWalletStatement;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\PartnerHubTabs;
use App\Support\PdfFonts;
use App\Support\TableWorkbook;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The party ledger (পার্টির হিসাব): every money movement for one partner in a
 * date range with a running balance. A partner sees only their own; office
 * staff with the accounts or finance permission can open any partner's.
 */
class PartnerStatementController extends Controller
{
    /** The PDF stays readable and quick up to this many lines; Excel has no limit. */
    private const PdfLineLimit = 5000;

    public function __construct(private PartnerWalletStatement $statements) {}

    public function show(Request $request, ?User $partner = null): Response
    {
        $partner = $this->partner($request, $partner);
        [$from, $to] = $this->range($request);
        $ledger = $this->statements->ledger($partner, $from, $to);
        $staffView = $partner->id !== $request->user()->id;

        return Inertia::render('PartnerStatement', [
            'partner' => $partner->only(['id', 'name', 'dealer_code', 'role', 'phone', 'district', 'division']),
            'range' => ['from' => $from, 'to' => $to],
            'statement' => $ledger,
            'staffView' => $staffView,
            'statementBase' => $staffView ? '/accounts/'.$partner->id.'/statement' : '/statement',
            'partner_hub' => $staffView ? null : PartnerHubTabs::money('full-report'),
        ]);
    }

    /** A partner's own ledger as Excel or PDF. */
    public function download(Request $request, string $format): BinaryFileResponse
    {
        return $this->file($request, $format, null);
    }

    /** Any partner's ledger as Excel or PDF, for office staff. */
    public function downloadFor(Request $request, User $partner, string $format): BinaryFileResponse
    {
        return $this->file($request, $format, $partner);
    }

    private function file(Request $request, string $format, ?User $partner): BinaryFileResponse
    {
        $partner = $this->partner($request, $partner);
        [$from, $to] = $this->range($request);
        Audit::record($request->user()->id, 'report.exported', 'partner-statement', ['partner_id' => $partner->id, 'format' => $format, 'from' => $from, 'to' => $to]);

        return $format === 'pdf' ? $this->pdf($partner, $from, $to) : $this->xlsx($partner, $from, $to);
    }

    /** The partner whose ledger is asked for, after the permission check. */
    private function partner(Request $request, ?User $partner): User
    {
        $viewer = $request->user();
        if ($partner === null) {
            abort_unless(in_array($viewer->role, ['dealer', 'sub_dealer'], true), 403);

            return $viewer;
        }
        abort_unless($viewer->staff() || $viewer->usingStaffWorkspace(), 403);
        abort_unless($viewer->allows('accounts') || $viewer->allows('finance'), 403);
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 404);

        return $partner;
    }

    /**
     * The chosen Dhaka dates; this month so far when none are given.
     *
     * @return array{0: string, 1: string}
     */
    private function range(Request $request): array
    {
        $data = $request->validate([
            'from' => 'nullable|date_format:Y-m-d',
            'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);
        $today = CarbonImmutable::now('Asia/Dhaka');

        return [
            $data['from'] ?? $today->startOfMonth()->toDateString(),
            $data['to'] ?? $today->toDateString(),
        ];
    }

    private function xlsx(User $partner, string $from, string $to): BinaryFileResponse
    {
        $ledger = $this->statements->ledger($partner, $from, $to, 1);
        $label = fn (string $key): string => __('portal.'.$key);
        $columns = [
            ['key' => 'date', 'label' => $label('col_date')],
            ['key' => 'description', 'label' => $label('stmt_col_what')],
            ['key' => 'reference', 'label' => $label('stmt_col_reference')],
            ['key' => 'money_in', 'label' => $label('stmt_col_in'), 'type' => 'money', 'total' => true],
            ['key' => 'money_out', 'label' => $label('stmt_col_out'), 'type' => 'money', 'total' => true],
            ['key' => 'balance', 'label' => $label('stmt_col_balance'), 'type' => 'money'],
        ];
        $rows = (function () use ($partner, $from, $to, $ledger, $label) {
            yield ['date' => $this->dhakaDate($from), 'description' => $label('opening_balance'), 'balance' => $ledger['opening_balance']];
            foreach ($this->statements->ledgerLinesFor($partner, $from, $to) as $line) {
                yield ['date' => $this->dhakaDateTime($line['occurred_at']), ...$line];
            }
            yield ['date' => $this->dhakaDate($to), 'description' => $label('closing_balance'), 'balance' => $ledger['closing_balance']];
        })();

        return TableWorkbook::download(
            'Madina-statement-'.$this->safeCode($partner).'-'.$from.'-'.$to.'.xlsx',
            $label('stmt_title'),
            $columns,
            $rows,
            [
                [$label('col_partner'), $partner->name.' ('.($partner->dealer_code ?: '—').')'],
                [__('portal.ops_period'), __('portal.ops_period_range', ['from' => $from, 'to' => $to])],
                [$label('opening_balance'), $ledger['opening_balance']],
                [$label('stmt_col_in'), $ledger['total_in']],
                [$label('stmt_col_out'), $ledger['total_out']],
                [$label('closing_balance'), $ledger['closing_balance']],
            ],
        );
    }

    private function pdf(User $partner, string $from, string $to): BinaryFileResponse
    {
        $ledger = $this->statements->ledger($partner, $from, $to, 1);
        if ($ledger['line_count'] > self::PdfLineLimit) {
            throw ValidationException::withMessages(['format' => __('portal.ops_val_pdf_row_limit')]);
        }
        $t = fn (string $key, array $replace = []): string => __('portal.'.$key, $replace);
        $money = fn (string $value): string => (str_starts_with($value, '-') ? '-' : '').Money::grouped(ltrim($value, '-'));
        $html = '<style>body{font-family:freeserif;font-size:9pt;color:#153d30}table{border-collapse:collapse;width:100%}th{background:#153d30;color:white}td,th{padding:5px;border:1px solid #cddbd4}td.num,th.num{text-align:right;white-space:nowrap}tbody tr:nth-child(even) td{background:#f5f9f7}tr.edge td{background:#e8f0ec;font-weight:bold}tfoot th{background:#e8f0ec;color:#153d30}h1{font-size:18pt}small{color:#52665d}</style>'
            .'<h1>Madina Natural Health Care</h1><h2>'.e($t('stmt_title')).'</h2>'
            .'<p>'.e($partner->name).' · '.e((string) ($partner->dealer_code ?: '—')).' · '.e(trim(($partner->district ?? '').', '.($partner->division ?? ''), ', ')).'</p>'
            .'<p>'.e($from).' — '.e($to).' · BDT · Asia/Dhaka · '.e($t('ops_export_records')).': '.$ledger['line_count'].'</p>'
            .'<table><thead><tr><th>'.e($t('col_date')).'</th><th>'.e($t('stmt_col_what')).'</th><th>'.e($t('stmt_col_reference')).'</th><th class="num">'.e($t('stmt_col_in')).'</th><th class="num">'.e($t('stmt_col_out')).'</th><th class="num">'.e($t('stmt_col_balance')).'</th></tr></thead><tbody>'
            .'<tr class="edge"><td>'.e($this->dhakaDate($from)).'</td><td>'.e($t('opening_balance')).'</td><td></td><td></td><td></td><td class="num">'.e($money($ledger['opening_balance'])).'</td></tr>';
        foreach ($this->statements->ledgerLinesFor($partner, $from, $to) as $line) {
            $html .= '<tr><td>'.e($this->dhakaDateTime($line['occurred_at'])).'</td><td>'.e($line['description']).'</td><td>'.e((string) ($line['reference'] ?? '')).'</td>'
                .'<td class="num">'.($line['money_in'] !== '0.00' ? e($money($line['money_in'])) : '').'</td>'
                .'<td class="num">'.($line['money_out'] !== '0.00' ? e($money($line['money_out'])) : '').'</td>'
                .'<td class="num">'.e($money($line['balance'])).'</td></tr>';
        }
        $html .= '<tr class="edge"><td>'.e($this->dhakaDate($to)).'</td><td>'.e($t('closing_balance')).'</td><td></td><td></td><td></td><td class="num">'.e($money($ledger['closing_balance'])).'</td></tr>'
            .'</tbody><tfoot><tr><th>'.e($t('ops_export_total')).'</th><th></th><th></th><th class="num">'.e($money($ledger['total_in'])).'</th><th class="num">'.e($money($ledger['total_out'])).'</th><th></th></tr></tfoot></table>'
            .'<p><small>'.e($t('ops_export_generated_footer', ['when' => now('Asia/Dhaka')->format('d M Y H:i')])).'</small></p>';
        Storage::disk('local')->makeDirectory('report-exports');
        $path = Storage::disk('local')->path('report-exports/'.Str::uuid().'.pdf');
        try {
            $pdf = new Mpdf(['tempDir' => Storage::disk('local')->path('report-exports'), 'format' => 'A4', ...PdfFonts::options()]);
            $pdf->SetTitle($t('stmt_title'));
            $pdf->SetHTMLFooter('<div style="text-align:right;font-size:8pt">'.e($t('ops_export_pdf_footer')).'</div>');
            $pdf->WriteHTML($html);
            $pdf->Output($path, Destination::FILE);
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }

        return response()->download($path, 'Madina-statement-'.$this->safeCode($partner).'-'.$from.'-'.$to.'.pdf', ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    private function dhakaDate(string $date): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $date, 'Asia/Dhaka')->format('d M Y');
    }

    private function dhakaDateTime(string $moment): string
    {
        return CarbonImmutable::parse($moment)->timezone('Asia/Dhaka')->format('d M Y H:i');
    }

    private function safeCode(User $partner): string
    {
        return preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($partner->dealer_code ?: $partner->id)) ?: (string) $partner->id;
    }
}
