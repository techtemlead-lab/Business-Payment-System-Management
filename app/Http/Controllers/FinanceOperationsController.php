<?php

namespace App\Http\Controllers;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Commissions\PolicyScheduling;
use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FinancialReadiness;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Finance\PayrollService;
use App\Domain\Finance\ReportData;
use App\Domain\Finance\ReserveService;
use App\Domain\Inventory\BranchOperations;
use App\Domain\Payouts\PayoutReceipts;
use App\Support\Audit;
use App\Support\Money;
use App\Support\SpreadsheetCell;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
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
        $user = $request->user();
        abort_unless($user->allows('finance') || $accounts->mayReceiveCollectionAccounts($user), 403);
        $finance = $user->allows('finance');

        return response()->json(['data' => $accounts->listing($user, ! $finance, true)]);
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
        $data = $request->validate(['decision' => 'required|in:approved,rejected,cancelled', 'reason' => self::Reason]);
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
        abort_unless($request->user()->allows('finance'), 403);
        // Owner decision: every manual payout records how it was paid and gets a numbered receipt.
        $data = $request->validate([...PayoutReceipts::rules(),
            'payment_account_id' => 'nullable|required_unless:method,cash|integer|exists:payment_accounts,id',
            'paid_at' => ['required', 'date', function (string $attribute, mixed $value, \Closure $fail): void {
                if (CarbonImmutable::parse($value, 'Asia/Dhaka')->isFuture()) {
                    $fail(__('portal.err_wd_paid_at_future'));
                }
            }],
            'receipt' => 'nullable|required_unless:method,cash|file|mimes:pdf,jpg,jpeg,png|max:5120', 'reason' => self::Reason]);
        $data['paid_at'] = CarbonImmutable::parse($data['paid_at'], 'Asia/Dhaka')->utc()->toDateTimeString();
        $number = $reserves->pay($request->user(), $id, $data, $request->file('receipt'));

        return $this->done($request, ['receipt_number' => $number]);
    }

    public function reassignHeld(Request $request, int $id, ReserveService $reserves): Response
    {
        $data = $request->validate(['user_id' => 'required|integer|exists:users,id', 'reason' => self::Reason, 'request_key' => 'required|uuid']);
        $reserves->reassignHeld($request->user(), $id, (int) $data['user_id'], $data['reason'], $data['request_key']);

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
                    throw ValidationException::withMessages(['amount' => __('portal.ops_val_recovery_exceeds')]);
                }
                $account = DB::table('payment_accounts')->where('id', $data['payment_account_id'])->where('is_active', true)->where('collects_payments', true)->first();
                abort_unless($account, 422, __('portal.err_collection_account'));
                $reference = Str::upper(trim($data['payment_reference']));
                if (DB::table('reserve_recoveries')->where('payment_reference', $reference)->exists() || DB::table('receipts')->where('reference', $reference)->exists()) {
                    throw ValidationException::withMessages(['payment_reference' => __('portal.ops_val_collection_ref_taken')]);
                }
                $recovery = DB::table('reserve_recoveries')->insertGetId(['clawback_id' => $id, 'amount' => Money::decimal($amount), 'payment_reference' => $reference,
                    'payment_account_id' => $account->id, 'actor_id' => $request->user()->id, 'paid_at' => $data['paid_at'], 'reason' => $data['reason'],
                    'receipt_path' => $path, 'receipt_hash' => hash_file('sha256', $request->file('receipt')->getRealPath()), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('reserve_expense_clawbacks')->where('id', $id)->update(['recovered' => Money::decimal(Money::cents($debt->recovered) + $amount), 'updated_at' => now()]);
                $wallets->journal('reserve.recovery.'.$recovery, __('portal.jr_reserve_recovery'), [
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
            'withdrawal_paid' => self::Amount, 'source_reference' => 'required|string|min:5|max:150', 'evidence' => 'required|file|mimes:pdf,jpg,jpeg,png,xlsx|max:10240']);
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
            'payroll' => 'payroll_entries', 'reserve' => 'reserve_disbursements', 'recovery' => 'reserve_recoveries', 'opening' => 'financial_verifications',
            'payment' => 'payment_claims', default => abort(404)
        };
        $row = DB::table($table)->where('id', $id)->first();
        abort_unless($row && ($request->user()->allows('finance') || ($kind === 'payroll' && $row->staff_id === $request->user()->id)), 404);
        // The owners' own fund stays out of staff sight, receipts included.
        abort_if($kind === 'reserve' && in_array($row->fund_key, CompanyFunds::OwnerOnly, true) && ! $request->user()->owner(), 404);
        // Payment proof is the dealer's screenshot of the transfer, checked by finance before approval.
        $path = match ($kind) {
            'opening' => $row->evidence_path,
            'payment' => $row->proof_path,
            default => $row->receipt_path,
        };
        abort_unless($path && Storage::disk('local')->exists($path), 404);
        Audit::record($request->user()->id, $kind.'.receipt_downloaded', $id);

        return Storage::disk('local')->download($path, $kind.'-'.$id.'.'.pathinfo($path, PATHINFO_EXTENSION), ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function export(Request $request, ReportData $reports): Response
    {
        $data = $request->validate(['type' => ['required', Rule::in(ReportData::Types)], 'format' => 'required|in:xlsx,pdf',
            'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from',
            'account_type' => ['nullable', Rule::in(ReportData::AccountTypes)], 'fund' => 'nullable|string|max:40', 'head' => 'nullable|integer|min:1']);
        if (CarbonImmutable::parse($data['from'])->diffInDays(CarbonImmutable::parse($data['to'])) > 366) {
            throw ValidationException::withMessages(['to' => __('portal.ops_val_date_range')]);
        }
        $accountType = in_array($data['type'], ReportData::AccountTypeReports, true) ? ($data['account_type'] ?? null) : null;
        $report = $reports->query($request->user(), $data['type'], $data['from'], $data['to'], $accountType, ['fund' => $data['fund'] ?? null, 'head' => $data['head'] ?? null]);
        $this->limitReportToBranch($request, $data['type'], $report['query']);
        $partnerScope = in_array($data['type'], ReportData::AccountTypeReports, true) ? __('portal.ops_report_account_'.($accountType ?? 'all')) : null;
        $reportKey = str_replace('-', '_', $data['type']);
        $langFile = $data['type'] === 'fund-spending' ? 'funds' : 'portal';
        $reportNameKey = $langFile.'.ops_export_name_'.$reportKey;
        $reportNoteKey = $langFile.'.ops_export_note_'.$reportKey;
        $reportName = __($reportNameKey);
        if ($reportName === $reportNameKey) {
            $reportName = Str::headline($data['type']);
        }
        $reportNote = __($reportNoteKey);
        if ($reportNote === $reportNoteKey) {
            $reportNote = __('portal.ops_export_note_default');
        }
        $reportHeading = __('portal.ops_export_heading', ['name' => $reportName]);
        $generatedAt = now()->timezone('Asia/Dhaka')->format('d M Y H:i');
        Storage::disk('local')->makeDirectory('report-exports');
        $path = Storage::disk('local')->path('report-exports/'.Str::uuid().'.'.$data['format']);
        $totals = array_fill_keys(array_diff($report['money'], ['unit_price']), 0);
        try {
            if ($data['format'] === 'xlsx') {
                $writer = new Writer(new Options(DEFAULT_COLUMN_WIDTH: 22));
                $writer->openToFile($path);
                try {
                    $writer->getCurrentSheet()->setName(Str::headline($data['type']))->setSheetView(new SheetView(freezeRow: 2));
                    $writer->addRow(Row::fromValuesWithStyle(array_map(fn ($v) => $this->reportColumnLabel($v), $report['columns']), (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('153D30')));
                    $rowCount = 0;
                    foreach ($report['query']->cursor() as $item) {
                        $rowCount++;
                        $cells = [];
                        foreach ($report['columns'] as $column) {
                            $value = $item->{$column} ?? '';
                            if (in_array($column, ['status', 'program', 'kind', 'account'], true) || $column === 'category' && $data['type'] === 'commissions') {
                                $value = $this->reportValueLabel((string) $value);
                            }
                            if ($value !== '' && str_ends_with($column, '_at')) {
                                $value = CarbonImmutable::parse($value, 'UTC')->timezone('Asia/Dhaka')->format('d M Y H:i');
                            }
                            if (isset($totals[$column])) {
                                $totals[$column] += $this->signedCents((string) $value);
                            }
                            $cells[] = in_array($column, $report['money'], true) ? new NumericCell((float) ($value === '' ? 0 : $value), (new Style)->withFormat('#,##0.00'))
                                : (in_array($column, ['quantity', 'dispatched', 'order_count', 'partner_count', 'days_outstanding'], true) ? new NumericCell((int) $value) : new StringCell(SpreadsheetCell::text($value)));
                        }
                        $writer->addRow(new Row($cells));
                    }
                    $totalStyle = (new Style)->withFontBold(true)->withBackgroundColor('E8F0EC');
                    $writer->addRow(new Row(array_map(fn ($column) => isset($totals[$column])
                        ? new NumericCell($totals[$column] / 100, $totalStyle->withFormat('#,##0.00'))
                        : new StringCell($column === $report['columns'][0] ? __('portal.ops_export_total') : '', $totalStyle), $report['columns'])));
                    $writer->addNewSheetAndMakeItCurrent()->setName(__('portal.ops_export_summary_sheet'));
                    foreach ([
                        ['Madina Natural Health Care'],
                        [$reportHeading],
                        [__('portal.ops_period'), $data['from'].' '.__('portal.ops_through').' '.$data['to']],
                        [__('portal.ops_export_currency'), 'BDT'],
                        [__('portal.ops_export_timezone'), 'Asia/Dhaka'],
                        [__('portal.ops_export_records'), $rowCount],
                        [__('portal.ops_export_generated'), $generatedAt],
                        [__('portal.ops_export_scope'), $reportNote],
                        ...($partnerScope !== null ? [[__('portal.ops_report_account_type'), $partnerScope]] : []),
                    ] as $summaryRow) {
                        $writer->addRow(Row::fromValues($summaryRow));
                    }
                    foreach ($totals as $column => $amount) {
                        $writer->addRow(new Row([new StringCell($this->reportColumnLabel($column)), new NumericCell($amount / 100, (new Style)->withFormat('#,##0.00'))]));
                    }
                } finally {
                    $writer->close();
                }
            } else {
                $items = $report['query']->limit(5001)->get();
                if ($items->count() > 5000) {
                    throw ValidationException::withMessages(['format' => __('portal.ops_val_pdf_row_limit')]);
                }
                $html = '<style>body{font-family:freeserif;font-size:9pt;color:#153d30}table{border-collapse:collapse;width:100%}th{background:#153d30;color:white}td,th{padding:5px;border:1px solid #cddbd4}td.num,th.num{text-align:right;white-space:nowrap}tbody tr:nth-child(even) td{background:#f5f9f7}tfoot th{background:#e8f0ec;color:#153d30}h1{font-size:18pt}small{color:#52665d}</style><h1>Madina Natural Health Care</h1><h2>'.e($reportHeading).'</h2><p>'.e($data['from']).' — '.e($data['to']).' · BDT · Asia/Dhaka · '.e(__('portal.ops_export_records')).': '.$items->count().($partnerScope !== null ? ' · '.e($partnerScope) : '').'</p><p><small>'.e($reportNote).'</small></p><table><thead><tr>';
                foreach ($report['columns'] as $column) {
                    $numeric = in_array($column, $report['money'], true);
                    $html .= '<th'.($numeric ? ' class="num"' : '').'>'.e($this->reportColumnLabel($column)).'</th>';
                }
                $html .= '</tr></thead><tbody>';
                foreach ($items as $item) {
                    $html .= '<tr>';
                    foreach ($report['columns'] as $column) {
                        $value = (string) ($item->{$column} ?? '');
                        if (in_array($column, ['status', 'program', 'kind', 'account'], true) || $column === 'category' && $data['type'] === 'commissions') {
                            $value = $this->reportValueLabel($value);
                        }
                        if ($value !== '' && str_ends_with($column, '_at')) {
                            $value = CarbonImmutable::parse($value, 'UTC')->timezone('Asia/Dhaka')->format('d M Y H:i');
                        }
                        if (isset($totals[$column])) {
                            $totals[$column] += $this->signedCents((string) $value);
                        }
                        $numeric = in_array($column, $report['money'], true);
                        if ($numeric && $value !== '') {
                            $value = $this->signedGrouped($value);
                        }
                        $html .= '<td'.($numeric ? ' class="num"' : '').'>'.e($value).'</td>';
                    }
                    $html .= '</tr>';
                }
                $html .= '</tbody><tfoot><tr>';
                foreach ($report['columns'] as $column) {
                    $html .= isset($totals[$column])
                        ? '<th class="num">'.e($this->signedGrouped($this->signedDecimal($totals[$column]))).'</th>'
                        : '<th>'.e($column === $report['columns'][0] ? __('portal.ops_export_total') : '').'</th>';
                }
                $html .= '</tr></tfoot></table><p><small>'.e(__('portal.ops_export_generated_footer', ['when' => $generatedAt])).'</small></p>';
                $pdf = new Mpdf(['tempDir' => Storage::disk('local')->path('report-exports'), 'format' => 'A4-L', 'default_font' => 'freeserif', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
                $pdf->SetTitle(__('portal.ops_export_pdf_title', ['name' => $reportName]));
                $pdf->SetHTMLFooter('<div style="text-align:right;font-size:8pt">'.e(__('portal.ops_export_pdf_footer')).'</div>');
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
                    $fail(__('portal.err_wd_paid_at_future'));
                }
            }], 'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', 'reason' => self::Reason]);
        $data['paid_at'] = CarbonImmutable::parse($data['paid_at'], 'Asia/Dhaka')->utc()->toDateTimeString();

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function done(Request $request, array $data = []): Response
    {
        return $request->expectsJson()
            ? response()->json(['data' => $data, 'message' => __('portal.flash_finance_operation')])
            : back()->with('success', __('portal.flash_finance_operation'));
    }

    /**
     * The payments page's file follows the top-bar branch switcher. Any other order-based
     * report stays inside the branch for branch staff, and is unchanged for everyone else.
     */
    private function limitReportToBranch(Request $request, string $type, Builder $report): void
    {
        $actor = $request->user();
        $branches = app(BranchOperations::class);
        if (! $actor->staff() || ! ($actor->allows('finance') || $actor->allows('reports'))
            || ! in_array($type, ReportData::OrderBasedReports, true)
            || ($type !== 'collections' && $branches->canViewAllBranches($actor))) {
            return;
        }
        $branches->scopeFor($actor, $request)->restrict($report, 'o.fulfillment_branch_id');
    }

    /** Report column header in the viewer's language, e.g. "dealer_code" → "Dealer code". */
    private function reportColumnLabel(string $column): string
    {
        foreach (['portal.rpt_col_'.$column, 'funds.rpt_col_'.$column] as $key) {
            $label = __($key);
            if ($label !== $key) {
                return $label;
            }
        }

        return Str::headline($column);
    }

    /** Status / kind values in the viewer's language, falling back to a readable label. */
    private function reportValueLabel(string $value): string
    {
        if ($value === '') {
            return '';
        }
        foreach (['portal.status_'.$value, 'portal.rpt_val_'.$value] as $key) {
            $label = __($key);
            if ($label !== $key) {
                return $label;
            }
        }

        return Str::headline($value);
    }

    /** Signed amount in cents; report rows (e.g. reversals) may legitimately be negative. */
    private function signedCents(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }
        $negative = str_starts_with($value, '-');
        $absolute = ltrim($value, '-+');
        if (str_contains($absolute, '.')) {
            [$whole, $fraction] = explode('.', $absolute, 2);
            $absolute = $whole.'.'.substr($fraction, 0, 2);
        }
        $cents = Money::cents($absolute === '' ? '0' : $absolute);

        return $negative ? -$cents : $cents;
    }

    private function signedDecimal(int $cents): string
    {
        return ($cents < 0 ? '-' : '').Money::decimal(abs($cents));
    }

    /** Lakh-grouped amount for printed reports, keeping a minus sign. */
    private function signedGrouped(string $value): string
    {
        $cents = $this->signedCents($value);

        return ($cents < 0 ? '-' : '').Money::grouped(Money::decimal(abs($cents)));
    }
}
