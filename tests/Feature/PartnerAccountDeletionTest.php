<?php

namespace Tests\Feature;

use App\Domain\Accounts\AccountPhoneRules;
use App\Domain\Accounts\DealerVerification;
use App\Domain\Accounts\PartnerAccountDeletion;
use App\Http\Middleware\EnforceStaffActions;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\DealerRank;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class PartnerAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => [], 'madina.preview_mode' => false]);
        $this->owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);
    }

    public function test_a_clean_partner_is_deleted_and_the_phone_and_email_can_be_used_again(): void
    {
        $this->seedClassicPlaceCodes();
        $partner = $this->cleanPartner(['phone' => '01711112222', 'email' => 'reuse@example.com', 'nid' => '1985123456789']);
        DB::table('account_notices')->insert(['user_id' => $partner->id, 'title' => 'Welcome', 'body' => 'Hello', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 'partner-session', 'user_id' => $partner->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        DB::table('password_reset_tokens')->insert(['email' => 'reuse@example.com', 'token' => 'hashed', 'created_at' => now()]);

        $this->removePartner($partner)
            ->assertRedirect()
            ->assertSessionHas('success', __('portal.flash_partner_deleted', ['code' => $partner->dealer_code]));

        $this->assertDatabaseMissing('users', ['id' => $partner->id]);
        $this->assertDatabaseMissing('wallets', ['user_id' => $partner->id]);
        $this->assertDatabaseMissing('account_notices', ['user_id' => $partner->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'partner-session']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'reuse@example.com']);
        $this->assertSame([], app(AccountPhoneRules::class)->holders('01711112222'));

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm(['phone' => '01711112222', 'email' => 'reuse@example.com']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/accounts');
        $this->assertDatabaseHas('users', ['email' => 'reuse@example.com', 'phone' => '01711112222']);

        $audit = DB::table('audit_events')->where('action', 'account.deleted')->where('subject', (string) $partner->id)->sole();
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame((int) $this->owner->id, (int) $audit->actor_id);
        $this->assertSame('deleted', $metadata['outcome']);
        $this->assertSame('Account was made by mistake', $metadata['reason']);
        $this->assertStringNotContainsString('01711112222', (string) $audit->metadata);
        $this->assertStringNotContainsString('1985123456789', (string) $audit->metadata);
    }

    public function test_deleting_a_partner_removes_their_private_files(): void
    {
        $partner = $this->cleanPartner();
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts/'.$partner->id.'/kyc-documents', [
            'kind' => 'photo',
            'document' => UploadedFile::fake()->image('photo.jpg', 200, 200),
        ])->assertRedirect();
        $documentPath = DB::table('kyc_documents')->where('user_id', $partner->id)->value('disk_path');
        Storage::disk('local')->assertExists($documentPath);
        $this->assertNotEmpty(Storage::disk('public')->allFiles('partner-photos/'.$partner->id));

        $this->removePartner($partner)->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $partner->id]);
        $this->assertDatabaseMissing('kyc_documents', ['user_id' => $partner->id]);
        Storage::disk('local')->assertMissing($documentPath);
        $this->assertSame([], Storage::disk('public')->allFiles('partner-photos/'.$partner->id));
    }

    public function test_an_approved_application_does_not_block_deletion(): void
    {
        $partner = $this->cleanPartner();
        $reviewer = User::factory()->staff('employee')->create();
        $applicationId = (string) Str::uuid();
        DB::table('dealer_applications')->insert([
            'id' => $applicationId,
            'email' => $partner->email,
            'data' => json_encode(['name' => $partner->name]),
            'status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'user_id' => $partner->id,
            'source' => 'public',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->removePartner($partner)->assertSessionHas('success', __('portal.flash_partner_deleted', ['code' => $partner->dealer_code]));

        $this->assertDatabaseMissing('users', ['id' => $partner->id]);
        $this->assertDatabaseMissing('dealer_applications', ['id' => $applicationId]);
    }

    public function test_a_partner_with_an_order_is_closed_and_the_order_stays(): void
    {
        $partner = $this->cleanPartner(['directory_visible' => true, 'locale' => 'en', 'phone' => '01711113333']);
        $order = $this->orderFor($partner);
        DB::table('sessions')->insert(['id' => 'open-session', 'user_id' => $partner->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $response = $this->removePartner($partner);

        $response->assertRedirect()->assertSessionHas('success');
        $flash = (string) session('success');
        $this->assertStringContainsString($partner->dealer_code, $flash);
        $this->assertStringContainsString(__('portal.partner_delete_record_orders').': 1', $flash);

        $this->assertDatabaseHas('users', ['id' => $partner->id, 'status' => 'suspended', 'directory_visible' => false]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'user_id' => $partner->id]);
        $this->assertDatabaseHas('wallets', ['user_id' => $partner->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'open-session']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $partner->id, 'title' => __('portal.notice_account_suspended_title', [], 'en')]);
        $this->assertSame('account.suspended', config('madina.sms.sent')[0]['event'] ?? null);

        $audit = DB::table('audit_events')->where('action', 'account.closed')->where('subject', (string) $partner->id)->sole();
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertSame('closed', $metadata['outcome']);
        $this->assertSame('Account was made by mistake', $metadata['reason']);
        $this->assertSame(1, $metadata['records']['orders.user_id']);
        $this->assertStringNotContainsString('01711113333', (string) $audit->metadata);
    }

    public function test_a_partner_closed_with_no_reason_is_told_none_was_written(): void
    {
        $partner = $this->cleanPartner(['locale' => 'en', 'phone' => '01711114444']);
        $this->orderFor($partner);

        $this->removePartner($partner, ['reason' => ''])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['id' => $partner->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $partner->id,
            'body' => __('portal.notice_account_suspended_body', ['reason' => __('reason_fields.not_written', [], 'en')], 'en'),
        ]);
        $this->assertSame(
            __('sms.account_suspended', ['reason' => __('reason_fields.not_written', [], 'bn')], 'bn'),
            config('madina.sms.sent')[0]['message'] ?? null,
        );
        $audit = DB::table('audit_events')->where('action', 'account.closed')->where('subject', (string) $partner->id)->sole();
        $this->assertSame('', json_decode((string) $audit->metadata, true)['reason']);
    }

    public function test_closing_an_already_suspended_partner_does_not_send_another_notice(): void
    {
        $partner = $this->cleanPartner(['status' => 'suspended']);
        $this->orderFor($partner);

        $this->removePartner($partner)->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['id' => $partner->id, 'status' => 'suspended']);
        $this->assertDatabaseMissing('account_notices', ['user_id' => $partner->id]);
        $this->assertSame([], config('madina.sms.sent'));
    }

    public function test_a_partner_with_people_under_them_is_closed(): void
    {
        $partner = $this->cleanPartner();
        $child = $this->cleanPartner(['sponsor_id' => $partner->id]);

        $this->removePartner($partner)->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['id' => $partner->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('users', ['id' => $child->id, 'sponsor_id' => $partner->id, 'status' => 'active']);
    }

    public function test_a_brand_new_dealer_with_an_area_seat_is_deleted_and_the_seat_goes_with_the_account(): void
    {
        $this->seedClassicPlaceCodes();
        $dealer = $this->newDealerThroughTheForm();
        $this->assertDatabaseHas('area_dealer_assignments', ['user_id' => $dealer->id, 'is_active' => true]);

        $this->actingAs($this->owner)->onPortal('sadmin')->getJson('/commands/accounts/'.$dealer->id.'/deletion-check')
            ->assertJsonPath('outcome', PartnerAccountDeletion::OutcomeDeleted)
            ->assertJsonPath('records', []);

        $this->removePartner($dealer)->assertSessionHas('success', __('portal.flash_partner_deleted', ['code' => $dealer->dealer_code]));

        $this->assertDatabaseMissing('users', ['id' => $dealer->id]);
        $this->assertDatabaseMissing('area_dealer_assignments', ['user_id' => $dealer->id]);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm(['phone' => '01711115555', 'email' => 'same-seat@example.com']))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/accounts');
    }

    public function test_a_dealer_with_an_area_seat_and_an_order_is_still_closed(): void
    {
        $this->seedClassicPlaceCodes();
        $dealer = $this->newDealerThroughTheForm();
        $order = $this->orderFor($dealer);

        $this->removePartner($dealer)->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['id' => $dealer->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    public function test_a_closed_account_leaves_the_normal_lists_and_shows_under_the_closed_tab(): void
    {
        $open = $this->cleanPartner();
        $suspended = $this->cleanPartner(['status' => 'suspended']);
        $closed = $this->cleanPartner();
        $this->orderFor($closed);

        $this->removePartner($closed)->assertSessionHas('success');

        $this->assertNotNull($closed->fresh()->closed_at);
        $this->assertSame([$open->id], $this->listedIds('/sub-dealers?status=active'));
        $this->assertSame([$suspended->id], $this->listedIds('/sub-dealers?status=suspended'));
        $this->assertEqualsCanonicalizing([$open->id, $suspended->id], $this->listedIds('/sub-dealers'));
        $this->assertSame([$closed->id], $this->listedIds('/sub-dealers?status=closed'));
        $this->assertSame([], $this->listedIds('/accounts?status=closed'));

        $counts = $this->listPage('/sub-dealers')['props']['data']['account_status_counts'];
        $this->assertEqualsCanonicalizing(['active' => 1, 'suspended' => 1, 'closed' => 1], $counts);
    }

    public function test_a_closed_account_stays_private_and_cannot_be_used(): void
    {
        $dealer = User::factory()->dealer()->create(['name' => 'Closed Directory Dealer', 'directory_visible' => true, 'phone' => '01711116666', 'password' => 'SyntheticPassword123']);
        DB::table('wallets')->insert(['user_id' => $dealer->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->orderFor($dealer);

        $this->onPortal('public')->get('/dealers')->assertOk()->assertSee('Closed Directory Dealer');
        $this->assertTrue(app(DealerVerification::class)->lookup($dealer->dealer_code)['valid']);

        $this->removePartner($dealer)->assertSessionHas('success');

        $this->onPortal('public')->get('/dealers')->assertOk()->assertDontSee('Closed Directory Dealer');
        $this->assertFalse(app(DealerVerification::class)->lookup($dealer->dealer_code)['valid']);

        auth()->forgetGuards();
        $this->onPortal('dealer')->post('/login', ['email' => '01711116666', 'password' => 'SyntheticPassword123'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_setting_a_closed_account_back_to_active_reopens_it(): void
    {
        $partner = $this->cleanPartner(['locale' => 'en', 'phone' => '01711117777']);
        $this->orderFor($partner);
        $this->removePartner($partner)->assertSessionHas('success');
        $this->assertNotNull($partner->fresh()->closed_at);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->patch('/commands/accounts/'.$partner->id, ['status' => 'active', 'reason' => 'Closed by mistake, opening again'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('users', ['id' => $partner->id, 'status' => 'active', 'closed_at' => null]);
        $this->assertSame([$partner->id], $this->listedIds('/sub-dealers'));
        $this->assertSame([], $this->listedIds('/sub-dealers?status=closed'));
    }

    public function test_changing_other_details_of_a_closed_account_keeps_it_closed(): void
    {
        $partner = $this->cleanPartner();
        $this->orderFor($partner);
        $this->removePartner($partner)->assertSessionHas('success');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->patch('/commands/accounts/'.$partner->id, ['name' => 'Corrected Name', 'reason' => 'Fixing a spelling mistake'])
            ->assertSessionHasNoErrors();

        $fresh = $partner->fresh();
        $this->assertSame('suspended', $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $this->assertSame([$partner->id], $this->listedIds('/sub-dealers?status=closed'));
    }

    public function test_a_wallet_with_a_balance_keeps_the_account(): void
    {
        $partner = $this->cleanPartner();
        DB::table('wallets')->where('user_id', $partner->id)->update(['available' => '10.00']);

        $this->removePartner($partner)->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['id' => $partner->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('wallets', ['user_id' => $partner->id, 'available' => '10.00']);
    }

    public function test_the_check_tells_the_owner_what_will_happen(): void
    {
        $clean = $this->cleanPartner();
        $withOrder = $this->cleanPartner();
        $this->orderFor($withOrder);

        $this->actingAs($this->owner)->onPortal('sadmin')->getJson('/commands/accounts/'.$clean->id.'/deletion-check')
            ->assertOk()
            ->assertJsonPath('outcome', PartnerAccountDeletion::OutcomeDeleted)
            ->assertJsonPath('dealer_code', $clean->dealer_code)
            ->assertJsonPath('records', []);

        $this->actingAs($this->owner)->onPortal('sadmin')->getJson('/commands/accounts/'.$withOrder->id.'/deletion-check')
            ->assertOk()
            ->assertJsonPath('outcome', PartnerAccountDeletion::OutcomeClosed)
            ->assertJsonPath('records.0.key', 'orders')
            ->assertJsonPath('records.0.count', 1);

        $this->assertDatabaseHas('users', ['id' => $clean->id, 'status' => 'active']);
        $this->assertDatabaseHas('users', ['id' => $withOrder->id, 'status' => 'active']);
    }

    public function test_a_wrong_typed_code_is_refused(): void
    {
        $partner = $this->cleanPartner();

        $this->removePartner($partner, ['confirmation' => 'NOT-THE-CODE'])
            ->assertSessionHasErrors(['confirmation' => __('portal.partner_delete_err_code')]);

        $this->assertDatabaseHas('users', ['id' => $partner->id, 'status' => 'active']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.deleted', 'subject' => (string) $partner->id]);
    }

    public function test_a_reason_is_required(): void
    {
        $partner = $this->cleanPartner();
        $this->reasonFields(['partner.account_delete' => 'required']);
        // The staff action check lets only the Super Admin through today, and that account is never
        // made to write a reason. It is set aside here so the rule is checked for the next owner.
        $this->withoutMiddleware(EnforceStaffActions::class);
        $managingDirector = User::factory()->staff('md')->create();

        $this->actingAs($managingDirector)->onPortal('sadmin')
            ->delete('/commands/accounts/'.$partner->id, ['reason' => 'no', 'confirmation' => $partner->dealer_code])
            ->assertSessionHasErrors('reason');

        $this->assertDatabaseHas('users', ['id' => $partner->id]);
    }

    public function test_the_super_admin_is_never_made_to_write_a_reason(): void
    {
        $partner = $this->cleanPartner();
        $this->reasonFields(['partner.account_delete' => 'required']);

        $this->removePartner($partner, ['reason' => ''])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $partner->id]);
        $audit = DB::table('audit_events')->where('action', 'account.deleted')->where('subject', (string) $partner->id)->sole();
        $this->assertSame('', json_decode((string) $audit->metadata, true)['reason']);
    }

    public function test_finance_staff_and_a_dealer_are_refused(): void
    {
        $partner = $this->cleanPartner();
        $finance = User::factory()->staff('finance')->create();
        $dealer = User::factory()->dealer()->create();

        foreach ([[$finance, 'manage'], [$dealer, 'dealer']] as [$actor, $portal]) {
            $this->actingAs($actor)->onPortal($portal)->getJson('/commands/accounts/'.$partner->id.'/deletion-check')->assertForbidden();
            $this->actingAs($actor)->onPortal($portal)
                ->delete('/commands/accounts/'.$partner->id, ['reason' => 'Should not work', 'confirmation' => $partner->dealer_code])
                ->assertForbidden();
        }

        $this->assertDatabaseHas('users', ['id' => $partner->id, 'status' => 'active']);
    }

    public function test_staff_and_owner_accounts_cannot_be_deleted_this_way(): void
    {
        $staff = User::factory()->staff('employee')->create(['dealer_code' => 'STAFF-1']);
        $otherOwner = User::factory()->staff('md')->create(['dealer_code' => 'OWNER-1']);

        foreach ([$staff, $otherOwner] as $target) {
            $this->removePartner($target)->assertForbidden();
            $this->assertDatabaseHas('users', ['id' => $target->id, 'status' => 'active']);
        }
    }

    public function test_every_foreign_key_to_users_outside_the_allow_list_counts_as_history(): void
    {
        $foreignKeys = collect(Schema::getTables())
            ->flatMap(fn (array $table): array => collect(Schema::getForeignKeys($table['name']))
                ->where('foreign_table', 'users')
                ->map(fn (array $foreignKey): string => $table['name'].'.'.implode(',', $foreignKey['columns']))
                ->all());

        $deletion = app(PartnerAccountDeletion::class);
        $this->assertEqualsCanonicalizing($foreignKeys->all(), $deletion->userForeignKeys());

        $expected = $foreignKeys
            ->reject(fn (string $reference): bool => in_array($reference, PartnerAccountDeletion::DisposableColumns, true))
            ->merge(PartnerAccountDeletion::UnconstrainedColumns)
            ->unique()
            ->all();
        $this->assertEqualsCanonicalizing($expected, $deletion->historyColumns(), 'A foreign key to users is not treated as history, so deleting such a partner could lose that record.');

        foreach ([...PartnerAccountDeletion::DisposableColumns, ...PartnerAccountDeletion::PartlyDisposableColumns] as $reference) {
            [$table, $column] = explode('.', $reference, 2);
            $this->assertTrue(Schema::hasColumn($table, $column), $reference.' is on the allow-list but does not exist.');
        }
    }

    public function test_a_table_added_later_protects_the_account_by_itself(): void
    {
        Schema::create('later_partner_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained('users');
        });
        $partner = $this->cleanPartner();
        DB::table('later_partner_records')->insert(['partner_id' => $partner->id]);

        $this->assertContains('later_partner_records.partner_id', app(PartnerAccountDeletion::class)->historyColumns());
        $this->removePartner($partner)->assertSessionHas('success');

        $this->assertDatabaseHas('users', ['id' => $partner->id, 'status' => 'suspended']);
        $this->assertDatabaseHas('later_partner_records', ['partner_id' => $partner->id]);
        $this->assertStringContainsString(__('portal.partner_delete_record_other').': 1', (string) session('success'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function cleanPartner(array $overrides = []): User
    {
        $partner = User::factory()->subDealer()->create(['directory_visible' => false]);
        $partner->forceFill($overrides)->save();
        DB::table('wallets')->insert(['user_id' => $partner->id, 'created_at' => now(), 'updated_at' => now()]);
        ReferralTreePath::attachUser($partner->id, $partner->sponsor_id);
        DealerRank::firstOrCreate(['user_id' => $partner->id], ['current_rank' => 'dealer']);

        return $partner;
    }

    private function newDealerThroughTheForm(): User
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm(['phone' => '01711114444', 'email' => 'seated@example.com']))
            ->assertRedirect('/accounts');

        return User::query()->where('email', 'seated@example.com')->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function listPage(string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json();
    }

    /** @return list<int> */
    private function listedIds(string $path): array
    {
        return collect($this->listPage($path)['props']['data']['accounts']['data'])->pluck('id')->all();
    }

    private function orderFor(User $partner): Order
    {
        return Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $partner->id,
            'status' => 'completed',
            'total' => '100.00',
            'paid' => '100.00',
            'due_credit' => '0.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function removePartner(User $partner, array $overrides = []): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->delete('/commands/accounts/'.$partner->id, [
            'reason' => 'Account was made by mistake',
            'confirmation' => $partner->dealer_code,
            ...$overrides,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dealerForm(array $overrides = []): array
    {
        return [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456700',
            'date_of_birth' => '1985-05-01',
            'name' => 'Replacement Dealer',
            'name_bn' => 'নতুন ডিলার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-102',
            'reason' => 'Replacement account',
            ...$overrides,
        ];
    }
}
