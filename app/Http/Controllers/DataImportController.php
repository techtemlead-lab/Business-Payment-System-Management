<?php

namespace App\Http\Controllers;

use App\Domain\Finance\ImportWorkbook;
use App\Domain\Finance\OpeningBalanceImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\Response;

class DataImportController extends Controller
{
    public function index(Request $request, OpeningBalanceImport $imports): Response
    {
        $actor = $request->user();
        $types = array_values(array_filter(array_keys(ImportWorkbook::Columns), fn (string $type): bool => match ($type) {
            'partners' => $actor->canAct('accounts', 'view'),
            'products' => $actor->canAct('catalog', 'view') && $actor->canAct('pricing', 'view') && $actor->canAct('inventory', 'view'),
            default => $actor->canAct('finance', 'view'),
        }));
        abort_unless($actor->staff() && $types !== [], 403);
        $request->validate(['batch' => 'nullable|uuid', 'row_page' => 'nullable|integer|min:1|max:100']);
        $query = DB::table('data_import_batches')->whereIn('type', $types);
        $batches = (clone $query)->orderByDesc('created_at')->paginate(15, ['id', 'type', 'status', 'filename', 'source_reference', 'summary', 'created_by', 'applied_by', 'applied_at', 'created_at'])->withQueryString();
        $batch = $request->filled('batch') ? (clone $query)->where('id', $request->input('batch'))->first() : null;
        abort_if($request->filled('batch') && ! $batch, 404);
        $preview = null;
        if ($batch) {
            $imports->authorize($actor, $batch->type, 'view');
            $rows = json_decode($batch->rows, true, 512, JSON_THROW_ON_ERROR);
            $page = $request->integer('row_page', 1);
            $preview = [...collect((array) $batch)->except(['rows', 'file_path'])->all(),
                'summary' => json_decode($batch->summary, true, 512, JSON_THROW_ON_ERROR),
                'rows' => array_slice($rows, ($page - 1) * 50, 50), 'row_page' => $page, 'row_pages' => (int) ceil(count($rows) / 50)];
        }
        $data = ['types' => array_map(fn (string $type): array => ['key' => $type, 'columns' => ImportWorkbook::Columns[$type],
            'can_create' => match ($type) {
                'partners' => $actor->canAct('accounts', 'create'),
                'products' => $actor->canAct('catalog', 'create') && $actor->canAct('pricing', 'create') && $actor->canAct('inventory', 'create'),
                default => $actor->canAct('finance', 'create'),
            }], $types), 'batches' => $batches, 'preview' => $preview,
            'openingLocked' => (bool) config('madina.financial_activation') || DB::table('financial_verifications')->whereNotNull('verified_at')->exists(),
            'templateBase' => '/commands/operations/imports/templates/'];

        return $request->expectsJson() ? response()->json(['data' => $data]) : Inertia::render('DataImports', $data)->toResponse($request);
    }

    public function preview(Request $request, OpeningBalanceImport $imports): Response
    {
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(ImportWorkbook::Columns))],
            'source_reference' => 'required|string|min:5|max:150', 'file' => 'required|file|mimes:xlsx|extensions:xlsx|max:10240']);
        $id = $imports->preview($request->user(), $data['type'], $request->file('file'), $data['source_reference']);

        return $request->expectsJson() ? response()->json(['data' => ['id' => $id]], 201)
            : redirect()->route('company.imports', ['batch' => $id])->with('success', 'Workbook validated. Review the rows before importing.');
    }

    public function apply(Request $request, string $id, OpeningBalanceImport $imports): Response
    {
        $data = $request->validate(['reason' => FinanceOperationsController::Reason, 'confirmed' => 'required|accepted']);
        $queued = config('queue.default') !== 'sync';
        $queued ? $imports->queue($request->user(), $id, $data['reason']) : $imports->apply($request->user(), $id, $data['reason']);

        $status = DB::table('data_import_batches')->where('id', $id)->value('status');

        return $request->expectsJson() ? response()->json(['data' => ['id' => $id, 'status' => $status]], $status === 'queued' ? 202 : 200)
            : redirect()->route('company.imports', ['batch' => $id])->with('success', $queued ? 'Import request accepted. The status below shows processing progress.' : 'Import completed. Existing records were preserved.');
    }

    public function template(Request $request, string $type, OpeningBalanceImport $imports): Response
    {
        abort_unless(isset(ImportWorkbook::Columns[$type]), 404);
        $imports->authorize($request->user(), $type, 'view');

        return response()->streamDownload(function () use ($type): void {
            $writer = new Writer;
            $writer->openToFile('php://output');
            try {
                $writer->getCurrentSheet()->setName('Data');
                $writer->addRow(Row::fromValuesWithStyle(ImportWorkbook::Columns[$type], (new Style)->withFontBold(true)->withFontColor('FFFFFF')->withBackgroundColor('153D30')));
                $writer->addNewSheetAndMakeItCurrent()->setName('Instructions');
                $notes = [
                    'Madina Natural Health Care | Excel data import',
                    'Fill the Data worksheet. Keep every column name unchanged; columns may be reordered.',
                    'Maximum: 5,000 rows / 10 MB. Values only: formulas, macros and external links are not accepted.',
                    'Existing records are never overwritten. Correct errors in Excel and upload a new preview.',
                    'Keep phone numbers, partner codes, references and dates as Text. Dates: YYYY-MM-DD. Currency: BDT.',
                    'Import partner accounts first, then products and opening balances. Import sponsors before their referrals.',
                    'Partner role: dealer or sub_dealer. Email and phone must be unique. No passwords are imported.',
                    'Imported partners require a password setup invitation through Accounts. They are hidden from the public directory.',
                    'Products are imported as unpublished drafts with an audited opening stock movement.',
                    'Historical order total/paid describes already delivered goods and collections; outstanding dues equal total minus paid.',
                    'Historical orders never generate new commissions or stock movements. Enter zero for amounts that do not apply.',
                    'Opening balances require a separate Finance reviewer and are locked after opening verification or financial activation.',
                    'Opening available/held commission is a brought-forward balance, not a new earning.',
                    'Starting from zero requires no import. Import only the approved records you want to carry forward.',
                ];
                foreach ($notes as $note) {
                    $writer->addRow(new Row([new StringCell($note)]));
                }
            } finally {
                $writer->close();
            }
        }, 'Madina-'.$type.'-import-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function source(Request $request, string $id, OpeningBalanceImport $imports): Response
    {
        $batch = DB::table('data_import_batches')->where('id', $id)->first();
        abort_unless($batch, 404);
        $imports->authorize($request->user(), $batch->type, 'view');
        abort_unless(Storage::disk('local')->exists($batch->file_path), 404);

        return Storage::disk('local')->download($batch->file_path, 'import-'.$batch->id.'.xlsx');
    }

    public function releaseHold(Request $request, int $id, OpeningBalanceImport $imports): Response
    {
        $data = $request->validate(['reason' => FinanceOperationsController::Reason]);
        $imports->releaseHold($request->user(), $id, $data['reason']);

        return $request->expectsJson() ? response()->json(['data' => ['released' => true]]) : back()->with('success', 'Opening commission hold released.');
    }
}
