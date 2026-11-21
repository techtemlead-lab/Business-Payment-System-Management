<?php

namespace App\Http\Controllers;

use App\Domain\Payouts\PayoutBatch;
use App\Domain\Payouts\PayoutBatchFiles;
use App\Models\User;
use App\Support\ReasonFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bank and wallet files of approved withdrawals (owner and finance staff only). A new file marks
 * the withdrawals it lists as "in a bank file"; see {@see PayoutBatchFiles}.
 */
class PayoutBatchController extends Controller
{
    /** A new file for the month (or the same file again for the same batch id). */
    public function export(Request $request, PayoutBatchFiles $files): StreamedResponse
    {
        $this->authorizeFinance($request->user());
        $data = $request->validate([
            'batch_id' => 'required|uuid',
            'month' => 'nullable|date_format:Y-m',
            'channel' => ['nullable', Rule::in(PayoutBatch::Channels)],
        ]);
        $month = $data['month'] ?? now()->timezone('Asia/Dhaka')->format('Y-m');
        $channel = $data['channel'] ?? null;
        $file = $files->export($request->user(), strtolower($data['batch_id']), $month, $channel);
        $name = 'Madina-payout-batch-'.$month.'-'.($channel ?? 'all').($file['batch_id'] !== null ? '-'.PayoutBatchFiles::shortId($file['batch_id']) : '').'.csv';

        return $this->csv($file['rows'], $name);
    }

    /** Downloads an earlier file again on purpose: only its withdrawals still waiting for the bank. */
    public function downloadAgain(Request $request, string $id, PayoutBatchFiles $files): StreamedResponse
    {
        $this->authorizeFinance($request->user());
        $file = $files->downloadAgain($request->user(), strtolower($id));

        return $this->csv($file['rows'], 'Madina-payout-batch-'.$file['batch']->month.'-'.($file['batch']->channel ?? 'all').'-'.PayoutBatchFiles::shortId($id).'-again.csv');
    }

    /** The bank did not send this withdrawal: recorded by one person, carried out by a second. */
    public function notSent(Request $request, string $id, PayoutBatchFiles $files): JsonResponse|RedirectResponse
    {
        $this->authorizeFinance($request->user());
        $data = $request->validate(['note' => 'required|string|min:5|max:1000']);
        $done = $files->markNotSent($request->user(), $id, ReasonFields::text($data['note']));

        return $request->expectsJson()
            ? response()->json(['data' => ['id' => $id, 'status' => 'approved', 'in_bank_file' => ! $done, 'waiting_second' => ! $done]])
            : back()->with('success', __($done ? 'payouts.flash_not_sent' : 'payouts.flash_not_sent_recorded'));
    }

    /** @param list<list<string>> $rows */
    private function csv(array $rows, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($rows): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, PayoutBatch::FileColumns, ',', '"', '');
            foreach ($rows as $row) {
                fputcsv($output, $row, ',', '"', '');
            }
            fclose($output);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    /** Owner and finance staff only, as on the accounting page. */
    private function authorizeFinance(User $actor): void
    {
        abort_unless($actor->staff() && $actor->allows('finance'), 403);
    }
}
