<?php

namespace App\Domain\Finance;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Geography\PlaceGeographyResolver;
use App\Jobs\ApplyDataImport;
use App\Models\AreaDealerAssignment;
use App\Models\DealerRank;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OpeningBalanceImport
{
    public function __construct(
        private CommissionWallet $wallets,
        private ImportWorkbook $workbooks,
        private PlaceGeographyResolver $geography,
    ) {}

    public function authorize(User $actor, string $type, string $action): void
    {
        abort_unless(isset(ImportWorkbook::Columns[$type]) && $actor->status === 'active' && $actor->staff(), 403);
        $modules = match ($type) {
            'partners' => ['accounts'], 'products' => ['catalog', 'pricing', 'inventory'], default => ['finance'],
        };
        foreach ($modules as $module) {
            abort_unless($actor->canAct($module, $action), 403);
        }
    }

    public function preview(User $actor, string $type, UploadedFile $file, string $source): string
    {
        $this->authorize($actor, $type, 'create');
        [$rows, $summary] = $this->workbooks->validate($type, $this->workbooks->read($file->getRealPath(), $type));
        $hash = hash_file('sha256', $file->getRealPath());
        if (DB::table('data_import_batches')->where('type', $type)->where('file_hash', $hash)->where('status', 'applied')->exists()) {
            throw ValidationException::withMessages(['file' => __('portal.err_import_already_applied')]);
        }
        $id = (string) Str::uuid();
        $path = $file->storeAs('data-imports', $id.'.xlsx', 'local');
        if (! $path) {
            throw ValidationException::withMessages(['file' => __('portal.err_import_store_failed')]);
        }
        try {
            DB::transaction(function () use ($actor, $id, $type, $file, $source, $hash, $path, $rows, $summary): void {
                $this->authorize($actor->fresh(), $type, 'create');
                DB::table('data_import_batches')->insert(['id' => $id, 'type' => $type, 'status' => $summary['invalid_rows'] ? 'invalid' : 'preview',
                    'filename' => Str::limit(basename($file->getClientOriginalName()), 240, ''), 'file_path' => $path, 'file_hash' => $hash,
                    'source_reference' => $source, 'rows' => json_encode($rows, JSON_THROW_ON_ERROR), 'summary' => json_encode($summary, JSON_THROW_ON_ERROR),
                    'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
                Audit::record($actor->id, 'data_import.previewed', $id, ['type' => $type, 'file_hash' => $hash, 'summary' => $summary]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return $id;
    }

    public function queue(User $actor, string $id, string $reason): void
    {
        DB::transaction(function () use ($actor, $id, $reason): void {
            CommissionWallet::lock();
            $batch = DB::table('data_import_batches')->where('id', $id)->lockForUpdate()->first();
            abort_unless($batch, 404);
            $this->authorize($actor->fresh(), $batch->type, $batch->type === 'opening_balances' ? 'approve' : 'create');
            if (in_array($batch->status, ['queued', 'applied'], true)) {
                return;
            }
            if ($batch->status !== 'preview') {
                throw ValidationException::withMessages(['import' => __('portal.err_import_queue_preview_only')]);
            }
            if ($batch->type === 'opening_balances') {
                $this->assertOpeningAllowed();
                if ($batch->created_by === $actor->id) {
                    throw ValidationException::withMessages(['import' => __('portal.err_import_separate_reviewer')]);
                }
            }
            DB::table('data_import_batches')->where('id', $id)->update(['status' => 'queued', 'review_reason' => $reason, 'updated_at' => now()]);
            $dispatch = ApplyDataImport::dispatch($id, $actor->id, $reason);
            if (config('queue.default') === 'database' && ! config('queue.connections.database.connection')) {
                $dispatch->beforeCommit();
            } else {
                $dispatch->afterCommit();
            }
            Audit::record($actor->id, 'data_import.queued', $id, ['reason' => $reason]);
        }, 3);
    }

    public function apply(User $actor, string $id, string $reason): void
    {
        DB::transaction(function () use ($actor, $id, $reason): void {
            CommissionWallet::lock();
            $batch = DB::table('data_import_batches')->where('id', $id)->lockForUpdate()->first();
            abort_unless($batch, 404);
            $this->authorize($actor->fresh(), $batch->type, $batch->type === 'opening_balances' ? 'approve' : 'create');
            if ($batch->status === 'applied') {
                return;
            }
            if (! in_array($batch->status, ['preview', 'queued'], true)) {
                throw ValidationException::withMessages(['import' => __('portal.err_import_apply_preview_only')]);
            }
            if ($batch->type === 'opening_balances') {
                $this->assertOpeningAllowed();
                if ($batch->created_by === $actor->id) {
                    throw ValidationException::withMessages(['import' => __('portal.err_import_separate_reviewer')]);
                }
            }
            if (! Storage::disk('local')->exists($batch->file_path) || ! hash_equals($batch->file_hash, hash_file('sha256', Storage::disk('local')->path($batch->file_path)))) {
                throw ValidationException::withMessages(['import' => __('portal.err_import_file_missing')]);
            }
            if (DB::table('data_import_batches')->where('type', $batch->type)->where('file_hash', $batch->file_hash)->where('status', 'applied')->exists()) {
                throw ValidationException::withMessages(['import' => __('portal.err_import_duplicate_apply')]);
            }
            $stored = json_decode($batch->rows, true, 512, JSON_THROW_ON_ERROR);
            [$rows, $summary] = $this->workbooks->validate($batch->type, array_map(fn (array $row): array => ['row' => $row['row'], 'data' => $row['data']], $stored));
            if ($summary['invalid_rows']) {
                throw ValidationException::withMessages(['import' => __('portal.err_import_stale_rows')]);
            }
            foreach ($rows as $row) {
                match ($batch->type) {
                    'partners' => $this->createPartner($row['data']),
                    'products' => $this->createProduct($actor, $row['data'], $id),
                    default => $this->createOpening($actor, $row['data'], $id),
                };
            }
            DB::table('data_import_batches')->where('id', $id)->update(['status' => 'applied', 'applied_by' => $actor->id, 'applied_at' => now(), 'review_reason' => $reason, 'updated_at' => now()]);
            Audit::record($actor->id, 'data_import.applied', $id, ['type' => $batch->type, 'file_hash' => $batch->file_hash, 'summary' => $summary, 'reason' => $reason, 'prepared_by' => $batch->created_by]);
        }, 3);
    }

    public function releaseHold(User $actor, int $id, string $reason): void
    {
        $this->authorize($actor, 'opening_balances', 'approve');
        CommissionWallet::requireActive();
        DB::transaction(function () use ($actor, $id, $reason): void {
            CommissionWallet::lock();
            $this->authorize($actor->fresh(), 'opening_balances', 'approve');
            $entry = DB::table('opening_balance_entries')->where('id', $id)->lockForUpdate()->first();
            abort_unless($entry, 404);
            if ($entry->released_at) {
                return;
            }
            abort_unless(User::whereKey($entry->user_id)->where('status', 'active')->exists(), 422, __('portal.err_import_partner_inactive'));
            $amount = Money::cents($entry->held);
            if ($amount <= 0 || $this->wallets->debit($entry->user_id, $amount, true)['unrecovered'] !== 0) {
                throw ValidationException::withMessages(['hold' => __('portal.err_import_hold_mismatch')]);
            }
            $this->wallets->journal('opening.hold_release.'.$id, __('portal.jr_opening_hold_release'), [
                ['account' => 'commission_held_liability', 'user_id' => $entry->user_id, 'debit' => $amount, 'credit' => 0],
                ['account' => 'wallet_liability', 'user_id' => $entry->user_id, 'debit' => 0, 'credit' => $amount],
            ]);
            $this->wallets->credit($entry->user_id, $amount);
            DB::table('opening_balance_entries')->where('id', $id)->update(['held_released' => $entry->held, 'released_by' => $actor->id, 'released_at' => now(), 'release_reason' => $reason, 'updated_at' => now()]);
            Audit::record($actor->id, 'opening.hold_released', (string) $id, ['amount' => $entry->held, 'reason' => $reason]);
        }, 3);
    }

    private function assertOpeningAllowed(): void
    {
        if (config('madina.financial_activation') || DB::table('financial_verifications')->whereNotNull('verified_at')->exists()) {
            throw ValidationException::withMessages(['import' => __('portal.err_import_opening_locked')]);
        }
    }

    /** @param array<string, string> $data */
    private function createPartner(array $data): void
    {
        $role = (string) $data['role'];
        $place = PlaceCode::findByCode((string) $data['place_code']);
        if (! $place) {
            throw ValidationException::withMessages(['place_code' => __('portal.err_wb_place_unknown')]);
        }
        if ($role === 'sub_dealer' && $place->level !== PlaceCode::LevelUnion) {
            throw ValidationException::withMessages(['place_code' => __('portal.err_wb_place_sub_level')]);
        }
        if ($role === 'dealer' && $place->level === PlaceCode::LevelManagement) {
            throw ValidationException::withMessages(['place_code' => __('portal.err_wb_place_management')]);
        }
        if ($role === 'dealer' && AreaDealerAssignment::query()->where('place_code_id', $place->id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['place_code' => __('portal.err_wb_place_taken')]);
        }

        $path = $this->geography->expandFromAnchor($place);
        $sponsor = $data['sponsor_code'] !== '' ? User::where('dealer_code', $data['sponsor_code'])->value('id') : null;
        $profile = [
            'imported_account' => true,
            'requires_password_setup' => true,
            'place_code' => $place->code,
            'place_code_id' => $place->id,
            'area_code' => $role === 'sub_dealer' ? (string) $data['dealer_code'] : $place->code,
            'upazila' => $path['upazila'] ?? null,
            'union' => $path['union'] ?? null,
            'union_place_code' => $role === 'sub_dealer' ? $place->code : null,
        ];
        $partner = (new User)->forceFill([
            'dealer_code' => $data['dealer_code'],
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'role' => $role,
            'sponsor_id' => $sponsor,
            'division' => $path['division'] ?? null,
            'district' => $path['district'] ?? null,
            'status' => 'active',
            'directory_visible' => false,
            'password' => Str::random(64),
            'profile' => $profile,
        ]);
        $partner->save();
        ReferralTreePath::attachUser($partner->id, $sponsor);
        DB::table('wallets')->insert(['user_id' => $partner->id, 'created_at' => now(), 'updated_at' => now()]);
        DealerRank::firstOrCreate(['user_id' => $partner->id], ['current_rank' => 'dealer']);

        if ($role === 'dealer') {
            AreaDealerAssignment::create([
                'user_id' => $partner->id,
                'level' => $place->level,
                'place_code_id' => $place->id,
                'zone_name' => $place->name.' ('.$place->code.')',
                'division' => $path['division'],
                'district' => $path['district'],
                'upazila' => $path['upazila'],
                'union' => $path['union'],
                'monthly_quota' => match ($place->level) {
                    'division' => '50000.00',
                    'district' => '40000.00',
                    'upazila' => '20000.00',
                    default => '10000.00',
                },
                'territory_key' => hash('sha256', $place->level.'|code:'.$place->code),
                'is_active' => true,
            ]);
        }
    }

    /** @param array<string, string> $data */
    private function createProduct(User $actor, array $data, string $batchId): void
    {
        $product = Product::create([...$data, 'active' => false]);
        if ((int) $data['stock'] > 0) {
            DB::table('stock_movements')->insert(['product_id' => $product->id, 'quantity' => $data['stock'], 'kind' => 'opening_import',
                'reference' => $batchId.'.'.$product->id, 'reason' => __('portal.stock_reason_opening_import'), 'actor_id' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @param array<string, string> $data */
    private function createOpening(User $actor, array $data, string $batchId): void
    {
        $this->assertOpeningAllowed();
        $partner = User::where('dealer_code', $data['dealer_code'])->lockForUpdate()->firstOrFail();
        $available = Money::cents($data['available_commission']);
        $held = Money::cents($data['held_commission']);
        $total = Money::cents($data['historical_order_total']);
        $paid = Money::cents($data['historical_order_paid']);
        $date = CarbonImmutable::parse($data['as_of_date'], 'Asia/Dhaka')->startOfDay()->utc();
        $orderId = null;
        $lines = [];
        if ($available + $held > 0) {
            $lines = [
                ['account' => 'opening_balance_equity', 'user_id' => null, 'debit' => $available + $held, 'credit' => 0],
                ['account' => 'wallet_liability', 'user_id' => $partner->id, 'debit' => 0, 'credit' => $available],
                ['account' => 'commission_held_liability', 'user_id' => $partner->id, 'debit' => 0, 'credit' => $held],
            ];
            $this->wallets->credit($partner->id, $available);
            $this->wallets->credit($partner->id, $held, true);
        }
        if ($total > 0) {
            $orderId = (string) Str::uuid();
            DB::table('orders')->insert(['id' => $orderId, 'number' => 'OPEN-'.$data['reference'], 'user_id' => $partner->id, 'status' => 'delivered',
                'total' => Money::decimal($total), 'paid' => Money::decimal($paid), 'is_opening_balance' => true, 'idempotency_key' => $batchId,
                'payload_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'commission_policy_snapshot' => json_encode(['source' => 'opening_import', 'non_commissionable' => true], JSON_THROW_ON_ERROR),
                'note' => __('portal.ob_order_note'), 'credit_authorized_by' => $actor->id,
                'credit_authorized_at' => now(), 'created_at' => $date, 'updated_at' => now()]);
            if ($paid > 0) {
                $claim = DB::table('payment_claims')->insertGetId(['order_id' => $orderId, 'channel' => __('portal.opening_balance'), 'reference' => 'OPEN-'.$data['reference'],
                    'amount' => Money::decimal($paid), 'status' => 'verified', 'verified_by' => $actor->id, 'reason' => __('portal.ob_claim_reason'), 'created_at' => $date, 'updated_at' => now()]);
                DB::table('receipts')->insert(['order_id' => $orderId, 'claim_id' => $claim, 'channel' => __('portal.opening_balance'), 'reference' => 'OPEN-'.$data['reference'], 'amount' => Money::decimal($paid), 'created_at' => $date, 'updated_at' => now()]);
            }
            $lines = [...$lines,
                ['account' => 'opening_receivables', 'user_id' => $partner->id, 'debit' => $total - $paid, 'credit' => 0],
                ['account' => 'opening_collection_clearing', 'user_id' => null, 'debit' => $paid, 'credit' => 0],
                ['account' => 'opening_balance_equity', 'user_id' => null, 'debit' => 0, 'credit' => $total],
            ];
        }
        if ($lines !== []) {
            $this->wallets->journal('opening.import.'.$batchId.'.'.$partner->id, __('portal.ob_journal_approved', ['reference' => $data['reference']]), $lines);
        }
        DB::table('opening_balance_entries')->insert(['import_id' => $batchId, 'user_id' => $partner->id, 'reference' => $data['reference'], 'as_of_date' => $data['as_of_date'],
            'available' => Money::decimal($available), 'held' => Money::decimal($held), 'order_id' => $orderId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
