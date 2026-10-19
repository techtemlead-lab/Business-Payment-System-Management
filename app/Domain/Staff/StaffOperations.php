<?php

namespace App\Domain\Staff;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionWallet;
use App\Models\DealerRank;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StaffOperations
{
    public function __construct(private TerritoryAccess $territories) {}

    /** @param array<string, mixed> $data */
    public function target(User $actor, array $data): int
    {
        abort_unless($actor->allows('staff_admin'), 403);
        CommissionQualification::period($data['period_month']);

        return DB::transaction(function () use ($actor, $data): int {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('staff_admin'), 403);
            $staff = User::findOrFail($data['staff_id']);
            if (! $staff->allows('field_sales') || $staff->id === $actor->id || $data['period_month'] < CommissionQualification::currentMonth()) {
                throw ValidationException::withMessages(['staff_id' => 'Set a current or future target for another active sales representative.']);
            }
            $regions = $this->territories->territories($staff->id);
            if ($regions === []) {
                throw ValidationException::withMessages(['staff_id' => 'Assign a territory before setting a target.']);
            }
            $old = DB::table('staff_targets')->where('staff_id', $staff->id)->where('period_month', $data['period_month'])->first();
            if ($old && (int) ($data['version'] ?? 0) !== $old->version) {
                throw ValidationException::withMessages(['version' => 'The target changed. Reload before saving.']);
            }
            $record = ['target' => $data['target'], 'bonus' => $data['bonus'], 'territories' => json_encode($regions, JSON_THROW_ON_ERROR),
                'version' => ($old->version ?? 0) + 1, 'created_by' => $actor->id, 'updated_at' => now()];
            $id = $old ? $old->id : DB::table('staff_targets')->insertGetId([...$record, 'staff_id' => $staff->id,
                'period_month' => $data['period_month'], 'created_at' => now()]);
            if ($old) {
                DB::table('staff_targets')->where('id', $id)->update($record);
            }
            Audit::record($actor->id, 'staff.target_saved', (string) $id, ['version' => $record['version'],
                'before' => $old ? collect($old)->only(['target', 'bonus', 'territories', 'version'])->all() : null,
                'after' => collect($record)->only(['target', 'bonus', 'territories', 'version'])->all()]);
            Audit::notice($staff->id, 'Monthly target updated', $data['period_month'].': target '.$data['target'].' BDT; achievement bonus '.$data['bonus'].' BDT.');

            return $id;
        }, 3);
    }

    /** @param array<string, mixed> $data */
    public function apply(User $actor, array $data): string
    {
        abort_unless($actor->allows('sr_onboarding'), 403);

        return DB::transaction(function () use ($actor, $data): string {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('sr_onboarding'), 403);
            $this->territories->assertGeography($actor->fresh(), $data);
            if (User::whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->exists()
                || DB::table('dealer_applications')->whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->where('status', 'pending')->exists()) {
                throw ValidationException::withMessages(['email' => 'An account or pending application already uses this email.']);
            }
            if (! empty($data['sponsor_id'])) {
                $sponsor = $this->territories->assertPartner($actor, (int) $data['sponsor_id'], 'sr_onboarding');
                if ($sponsor->role !== 'dealer' || $sponsor->status !== 'active') {
                    throw ValidationException::withMessages(['sponsor_id' => 'Choose an active dealer sponsor in your territory.']);
                }
            }
            $id = (string) Str::uuid();
            DB::table('dealer_applications')->insert(['id' => $id, 'submitted_by' => $actor->id, 'email' => mb_strtolower(trim($data['email'])),
                'data' => json_encode($data, JSON_THROW_ON_ERROR), 'status' => 'pending', 'source' => 'staff',
                'created_at' => now(), 'updated_at' => now()]);
            Audit::record($actor->id, 'dealer_application.submitted', $id);

            return $id;
        }, 3);
    }

    public function review(User $actor, string $id, string $decision, string $reason): void
    {
        abort_unless($actor->allows('accounts'), 403);
        DB::transaction(function () use ($actor, $id, $decision, $reason): void {
            CommissionWallet::lock();
            abort_unless($actor->fresh()->allows('accounts'), 403);
            $application = DB::table('dealer_applications')->where('id', $id)->lockForUpdate()->first();
            abort_unless($application, 404);
            if ($application->status !== 'pending' || ($application->submitted_by !== null && (int) $application->submitted_by === $actor->id)) {
                throw ValidationException::withMessages(['application' => 'A different authorized reviewer must review a pending application.']);
            }
            $userId = null;
            if ($decision === 'approved') {
                $data = json_decode($application->data, true, flags: JSON_THROW_ON_ERROR);
                if (User::whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->exists()) {
                    throw ValidationException::withMessages(['email' => 'This email already has an account.']);
                }
                $sponsorId = $data['sponsor_id'] ?? null;
                if (empty($sponsorId) && ! empty($data['sponsor_code'])) {
                    $sponsorId = User::where('dealer_code', $data['sponsor_code'])->where('role', 'dealer')->where('status', 'active')->value('id');
                    if (! $sponsorId) {
                        throw ValidationException::withMessages(['sponsor_code' => 'The nominated sponsor code is not an active dealer.']);
                    }
                }
                if (! empty($sponsorId) && ! User::whereKey($sponsorId)->where('status', 'active')->where('role', 'dealer')->exists()) {
                    throw ValidationException::withMessages(['sponsor_id' => 'The nominated sponsor is no longer an active dealer.']);
                }
                $user = User::create(['name' => $data['name'], 'email' => $application->email, 'phone' => $data['phone'],
                    'role' => $data['role'], 'status' => 'active', 'password' => Str::random(48),
                    'dealer_code' => 'MN-'.Str::upper(Str::random(12)), 'sponsor_id' => $sponsorId,
                    'division' => $data['division'], 'district' => $data['district'],
                    'profile' => ['upazila' => $data['upazila'] ?? null, 'union' => $data['union'] ?? null, 'area_code' => $data['area_code'] ?? null,
                        'application_source' => $application->source ?? 'staff', 'application_message' => $data['message'] ?? null]]);
                ReferralTreePath::attachUser($user->id, $user->sponsor_id);
                DealerRank::firstOrCreate(['user_id' => $user->id]);
                DB::table('wallets')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                $userId = $user->id;
                DB::afterCommit(fn () => Password::sendResetLink(['email' => $user->email]));
            }
            DB::table('dealer_applications')->where('id', $id)->update(['status' => $decision, 'reviewed_by' => $actor->id,
                'user_id' => $userId, 'reason' => $reason, 'updated_at' => now()]);
            if ($application->submitted_by !== null) {
                Audit::notice((int) $application->submitted_by, 'Dealer application '.$decision, $application->email.': '.$reason);
            }
            Audit::record($actor->id, 'dealer_application.'.$decision, $id, ['reason' => $reason, 'user_id' => $userId, 'source' => $application->source ?? 'staff']);
        }, 3);
    }
}
