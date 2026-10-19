<?php

namespace App\Domain\Finance;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class PolicyDraftService
{
    public function create(User $actor, array $data): int
    {
        abort_unless($actor->allows('finance'), 403);
        $total = 0;
        try {
            foreach ($data['components'] as $component) {
                $total += Percentage::from((string) $component['rate'])->basisPoints;
            }
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['components' => $exception->getMessage()]);
        }
        if ($total > 10000) {
            throw ValidationException::withMessages(['components' => 'Total allocations cannot exceed 100% of the purchase value.']);
        }

        return DB::transaction(function () use ($actor, $data): int {
            $id = DB::table('policy_drafts')->insertGetId([
                'name' => $data['name'], 'account_type' => $data['account_type'], 'basis' => 'purchase_value',
                'components' => json_encode($data['components'], JSON_THROW_ON_ERROR), 'notes' => $data['notes'],
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'policy.drafted', (string) $id);

            return $id;
        });
    }

    public function publish(User $actor, int $id): void
    {
        abort_unless($actor->allows('finance'), 403);
        DB::transaction(function () use ($actor, $id): void {
            abort_unless($actor->fresh()->allows('finance'), 403);
            $draft = DB::table('policy_drafts')->where('id', $id)->lockForUpdate()->first();
            abort_unless($draft, 404);
            if ($draft->status === 'published') {
                return;
            }
            DB::table('policy_drafts')->where('id', $id)->update([
                'status' => 'published',
                'basis' => 'purchase_value',
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'policy.published', (string) $id, [
                'account_type' => $draft->account_type,
                'name' => $draft->name,
            ]);
        }, 3);
    }
}
