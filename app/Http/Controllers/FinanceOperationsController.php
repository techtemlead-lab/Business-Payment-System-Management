<?php

namespace App\Http\Controllers;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Commissions\PolicyScheduling;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Finance\PayrollService;
use App\Domain\Finance\ReportData;
use App\Domain\Finance\ReserveService;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

class FinanceOperationsController extends Controller
{
    public const Amount = ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/D'];

    public const Reason = 'required|string|min:5|max:1000';

    public function account(Request $request, PaymentAccounts $accounts, ?int $id = null): Response
    {
        $id = $accounts->save($request->user(), $request->all(), $id);

        return $this->done($request, ['id' => $id]);
    }

    public function accounts(Request $request, PaymentAccounts $accounts): Response
    {
        return response()->json(['data' => $accounts->listing($request->user(), ! $request->user()->allows('finance'))]);
    }

    public function contract(Request $request, PayrollService $payroll): Response
    {
        abort_unless($request->user()->allows('finance'), 403);
        $data = $request->validate(['staff_id' => 'required|integer|exists:users,id', 'effective_month' => 'required|date_format:Y-m',
            'base_salary' => self::Amount, 'allowance' => self::Amount, 'deduction' => self::Amount, 'reason' => self::Reason]);

        return $this->done($request, ['id' => $payroll->contract($request->user(), $data)]);
    }

    public function prepare(Request $request, PayrollService $payroll): Response
    {
        $data = $request->validate(['period_month' => 'required|date_format:Y-m']);

        return $this->done($request, ['ids' => $payroll->prepare($request->user(), $data['period_month'])]);
    }

    public function payrollReview(Request $request, string $id, PayrollService $payroll): Response
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => self::Reason]);
        $payroll->review($request->user(), $id, $data['decision'], $data['reason']);

        return $this->done($request);
    }

    public function payrollPay(Request $request, string $id, PayrollService $payroll): Response
    {
        $payroll->pay($request->user(), $id, $this->payment($request), $request->file('receipt'));

        return $this->done($request);
    }

    public function schedule(Request $request, PolicyScheduling $schedules): Response
    {
        abort_unless($request->user()->allows('finance'), 403);
        $data = $request->validate(['scope' => 'required|in:global,buyer,recipient', 'user_id' => 'nullable|required_unless:scope,global|integer|exists:users,id',
            'rules' => 'required|array|min:1', 'effective_at' => 'required|date', 'expires_at' => 'nullable|date|after:effective_at', 'reason' => self::Reason]);
        $data['effective_at'] = CarbonImmutable::parse($data['effective_at'], 'Asia/Dhaka')->utc()->toIso8601String();
        if (! empty($data['expires_at'])) {
            $data['expires_at'] = CarbonImmutable::parse($data['expires_at'], 'Asia/Dhaka')->utc()->toIso8601String();
        }

        return $this->done($request, ['id' => $schedules->propose($request->user(), $data)]);
    }

    public function scheduleReview(Request $request, int $id, PolicyScheduling $schedules): Response
    {
        $data = $request->validate(['decision' => 'required|in:approved,cancelled', 'reason' => self::Reason]);
        $schedules->review($request->user(), $id, $data['decision'], $data['reason']);

        return $this->done($request);
    }

    public function reserve(Request $request, ReserveService $reserves): Response
    {
        abort_unless($request->user()->allows('finance'), 403);
        $data = $request->validate(['program' => ['required', Rule::in(array_keys(ReserveService::Programs))],
            'user_id' => 'nullable|required_unless:program,marketing|prohibited_if:program,marketing|integer|exists:users,id',
            'payee_name' => 'nullable|required_if:program,marketing|string|max:150', 'amount' => [...self::Amount, 'gt:0'],
            'request_key' => 'required|uuid', 'reason' => self::Reason]);
        $data['user_id'] = isset($data['user_id']) ? (int) $data['user_id'] : null;

        return $this->done($request, ['id' => $reserves->request($request->user(), $data)]);
    }

    public function reserveReview(Request $request, int $id, ReserveService $reserves): Response
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected,cancelled', 'reason' => self::Reason]);
        if ($data['decision'] === 'cancelled') {
            $reserves->cancel($request->user(), $id, $data['reason']);
        } else {
            $reserves->review($request->user(), $id, $data['decision'], $data['reason']);
        }

        return $this->done($request);
    }

    public function reservePay(Request $request, int $id, ReserveService $reserves): Response
    {
        $reserves->pay($request->user(), $id, $this->payment($request), $request->file('receipt'));

        return $this->done($request);
    }

    public function release(Request $request, int $id, ReserveService $reserves): Response
    {
        $data = $request->validate(['reason' => self::Reason]);
        $reserves->release($request->user(), $id, $data['reason']);

        return $this->done($request);
    }

    public function recover(Request $request, int $id, CommissionWallet $wallets): Response
    {
        abort_unless($request->user()->allows('finance'), 403);
        CommissionWallet::requireActive();
        $data = [...$this->payment($request), ...$request->validate(['amount' => [...self::Amount, 'gt:0']])];
        $path = $request->file('receipt')->storeAs('reserve-recoveries', Str::uuid().'.'.$request->file('receipt')->guessExtension(), 'local');
        try {
            DB::transaction(function () use ($request, $id, $data, $path, $wallets): void {
                CommissionWallet::lock();
                $debt = DB::table('reserve_expense_clawbacks')->where('id', $id)->lockForUpdate()->first();
                abort_unless($debt, 404);
                $amount = Money::cents($data['amount']);
                if ($amount > Money::cents($debt->amount) - Money::cents($debt->recovered)) {
                    throw ValidationException::withMessages(['amount' => 'Recovery cannot exceed the outstanding company clawback.']);
                }
                $account = DB::table('payment_accounts')->where('id', $data['payment_account_id'])->where('is_active', true)->where('collects_payments', true)->first();
                abort_unless($account, 422, 'Choose an active collection account.');
                $reference = Str::upper(trim($data['payment_reference']));
                if (DB::table('reserve_recoveries')->where('payment_reference', $reference)->exists() || DB::table('receipts')->where('reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['payment_reference' => 'This collection reference is already recorded.']);
                }
                $recovery = DB::table('reserve_recoveries')->insertGetId(['clawback_id' => $id, 'amount' => Money::decimal($amount), 'payment_reference' => $reference,
                    'payment_account_id' => $account->id, 'actor_id' => $request->user()->id, 'paid_at' => $data['paid_at'], 'reason' => $data['reason'],
                    'receipt_path' => $path, 'receipt_hash' => hash_file('sha256', $request->file('receipt')->getRealPath()), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('reserve_expense_clawbacks')->where('id', $id)->update(['recovered' => Money::decimal(Money::cents($debt->recovered) + $amount), 'updated_at' => now()]);
                $wallets->journal('reserve.recovery.'.$recovery, 'Company reserve clawback recovered', [
                    ['account' => 'manual_collection_clearing', 'user_id' => null, 'debit' => $amount, 'credit' => 0],
                    ['account' => 'company_reserve_clawback_receivable', 'user_id' => null, 'debit' => 0, 'credit' => $amount],
                ]);
                Audit::record($request->user()->id, 'reserve.recovered', (string) $id, ['recovery_id' => $recovery, 'reason' => $data['reason'],
                    'payment_account_id' => $account->id, 'payment_account_version' => $account->version, 'account_snapshot' => encrypt((array) $account)]);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return $this->done($request);
    }

    public function opening(Request $request, FinancialReadiness $readiness): Response
    {
        $data = $request->validate(['wallet_available' => self::Amount, 'wallet_held' => self::Amount, 'order_paid' => self::Amount,
            'withdrawal_paid' => self::Amount, 'source_reference' => 'required|string|min:5|max:150', 'evidence' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240']);
        $id = $readiness->submit($request->user(), collect($data)->only(['wallet_available', 'wallet_held', 'order_paid', 'withdrawal_paid'])->all(), $data['source_reference'], $request->file('evidence'));

        return $this->done($request, ['id' => $id]);
    }

    public function openingVerify(Request $request, int $id, FinancialReadiness $readiness): Response
    {
        $readiness->verify($request->user(), $id);

        return $this->done($request);
    }

    public function receipt(Request $request, string $kind, string $id): Response
    {
        $table = match ($kind) {
            'payroll' => 'payroll_entries', 'reserve' => 'reserve_disbursements', 'recovery' => 'reserve_recoveries', 'opening' => 'financial_verifications', default => abort(404)
        };
        $row = DB::table($table)->where('id', $id)->first();
        abort_unless($row && ($request->user()->allows('finance') || ($kind === 'payroll' && $row->staff_id === $request->user()->id)), 404);
        $path = $kind === 'opening' ? $row->evidence_path : $row->receipt_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        Audit::record($request->user()->id, $kind.'.receipt_downloaded', $id);

        return Storage::disk('local')->download($path, $kind.'-'.$id.'.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Request $request, ReportData $reports): Response
    {
        $data = $request->validate(['type' => ['required', Rule::in(ReportData::Types)], 'format' => 'required|in:xlsx,pdf',
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 366) {
            throw ValidationException::withMessages(['to' => 'Choose a date range of at most 366 days.']);
        }
        $report = $reports->query($request->user(), $data['type'], $data['from'], $data['to']);
        $reportName = match ($data['type']) {
            'products' => 'Delivered product sales by order date', 'refunds' => 'Commission refund adjustments', default => Str::headline($data['type'])
        };
        $reportNote = match ($data['type']) {
            'products' => 'Gross delivered product lines. Commission refund adjustments are reported separately; they do not specify physical return quantities.',
            'refunds' => 'Commission accounting adjustments by adjustment date. These records do not confirm execution of a bank refund.', default => 'Authorized records only; statuses are shown as recorded.'
        };
        Storage::disk('local')->makeDirectory('report-exports');
        $path = Storage::disk('local')->path('report-exports/'.Str::uuid().'.'.$data['format']);
        $totals = array_fill_keys($report['money'], 0);
        try {
            if ($data['format'] === 'xlsx') {
                $writer = new Writer(new Options(DEFAULT_COLUMN_WIDTH: 22));
                $writer->openToFile($path);
                try {
                    $writer->getCurrentSheet()->setName(Str::headline($data['type']))->setSheetView(new SheetView(freezeRow: 2));
                    $writer->addRow(Row::fromValuesWithStyle(array_map(fn ($v) => Str::headline($v), $report['columns']), (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('153D30')));
                    $rowCount = 0;
                    foreach ($report['query']->cursor() as $item) {
                        $rowCount++;
                        $cells = [];
                        foreach ($report['columns'] as $column) {
                            $value = $item->{$column} ?? '';
                            if (in_array($column, ['status', 'program', 'kind', 'account'], true) || $column === 'category' && $data['type'] === 'commissions') {
                                $value = Str::headline((string) $value);
                            }
                            if ($value !== '' && str_ends_with($column, '_at')) {
                                $value = CarbonImmutable::parse($value, 'UTC')->timezone('Asia/Dhaka')->format('d M Y H:i');
                            }
                            if (in_array($column, $report['money'], true)) {
                                $totals[$column] += Money::cents((string) ($value === '' ? '0' : $value));
                            }
                            $cells[] = in_array($column, $report['money'], true) ? new NumericCell((float) ($value === '' ? 0 : $value), (new Style)->withFormat('#,##0.00')) : new StringCell((string) $value);
                        }
                        $writer->addRow(new Row($cells));
                    }
                    $writer->addRow(Row::fromValues(array_map(fn ($column) => isset($totals[$column]) ? Money::decimal($totals[$column]) : ($column === $report['columns'][0] ? 'TOTAL' : ''), $report['columns'])));
                    $writer->addNewSheetAndMakeItCurrent()->setName('Report summary');
                    foreach ([['Madina Natural Health Care'], [$reportName.' report'], ['Period', $data['from'].' through '.$data['to']],
                        ['Currency', 'BDT'], ['Timezone', 'Asia/Dhaka'], ['Records', $rowCount], ['Generated', now()->timezone('Asia/Dhaka')->format('d M Y H:i')],
                        ['Scope', $reportNote]] as $summaryRow) {
                        $writer->addRow(Row::fromValues($summaryRow));
                    }
                    foreach ($totals as $column => $amount) {
                        $writer->addRow(new Row([new StringCell(Str::headline($column)), new NumericCell($amount / 100, (new Style)->withFormat('#,##0.00'))]));
                    }
                } finally {
                    $writer->close();
                }
            } else {
                $items = $report['query']->limit(5001)->get();
                if ($items->count() > 5000) {
                    throw ValidationException::withMessages(['format' => 'PDF supports up to 5,000 rows. Narrow the date range or select Excel for the full dataset.']);
                }
                $html = '<style>body{font-family:freeserif;font-size:9pt;color:#153d30}table{border-collapse:collapse;width:100%}th{background:#153d30;color:white}td,th{padding:5px;border:1px solid #cddbd4}h1{font-size:18pt}small{color:#52665d}</style><h1>Madina Natural Health Care</h1><h2>'.e($reportName).' report</h2><p>'.e($data['from']).' — '.e($data['to']).' · BDT · Asia/Dhaka</p><p><small>'.e($reportNote).'</small></p><table><thead><tr>';
                foreach ($report['columns'] as $column) {
                    $html .= '<th>'.e(Str::headline($column)).'</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($items as $item) {
                    $html .= '<tr>';
                    foreach ($report['columns'] as $column) {
                        $value = (string) ($item->{$column} ?? '');
                        if (in_array($column, ['status', 'program', 'kind', 'account'], true) || $column === 'category' && $data['type'] === 'commissions') {
                            $value = Str::headline($value);
                        }
                        if ($value !== '' && str_ends_with($column, '_at')) {
                            $value = CarbonImmutable::parse($value, 'UTC')->timezone('Asia/Dhaka')->format('d M Y H:i');
                        }
                        if (isset($totals[$column])) {
                            $totals[$column] += Money::cents($value === '' ? '0' : $value);
                        }
                        $html .= '<td>'.e($value).'</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody><tfoot><tr>';
                foreach ($report['columns'] as $column) {
                    $html .= '<th>'.e(isset($totals[$column]) ? Money::decimal($totals[$column]) : ($column === $report['columns'][0] ? 'TOTAL' : '')).'</th>';
                }
                $html .= '</tr></tfoot></table><p><small>Generated '.e(now()->timezone('Asia/Dhaka')->format('d M Y H:i')).' · Amounts in BDT.</small></p>';
                $pdf = new Mpdf(['tempDir' => Storage::disk('local')->path('report-exports'), 'format' => 'A4-L', 'default_font' => 'freeserif', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
                $pdf->SetTitle('Madina '.Str::headline($data['type']).' report');
                $pdf->SetHTMLFooter('<div style="text-align:right;font-size:8pt">Madina Natural Health Care · Page {PAGENO} of {nbpg}</div>');
                $pdf->WriteHTML($html);
                $pdf->Output($path, Destination::FILE);
            }
        } catch (\Throwable $exception) {
            @unlink($path);
            throw $exception;
        }
        Audit::record($request->user()->id, 'report.exported', $data['type'], $data);

        return response()->download($path, 'Madina-'.$data['type'].'-'.$data['from'].'-'.$data['to'].'.'.$data['format'], ['Cache-Control' => 'private, no-store'])->deleteFileAfterSend();
    }

    /** @return array<string, mixed> */
    private function payment(Request $request): array
    {
        abort_unless($request->user()->allows('finance'), 403);
        $data = $request->validate(['payment_account_id' => 'required|integer|exists:payment_accounts,id', 'payment_reference' => 'required|string|max:100',
            'paid_at' => ['required', 'date', function (string $attribute, mixed $value, \Closure $fail): void {
                if (CarbonImmutable::parse($value, 'Asia/Dhaka')->isFuture()) {
                    $fail('Payment time cannot be in the future.');
                }
            }], 'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', 'reason' => self::Reason]);
        $data['paid_at'] = CarbonImmutable::parse($data['paid_at'], 'Asia/Dhaka')->utc()->toDateTimeString();

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function done(Request $request, array $data = []): Response
    {
        return $request->expectsJson() ? response()->json(['data' => $data, 'message' => 'Operation recorded.']) : back()->with('success', 'Operation recorded successfully.');
    }
}
