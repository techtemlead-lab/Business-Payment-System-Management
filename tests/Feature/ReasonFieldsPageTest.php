<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\OfficeHubs;
use App\Support\ReasonFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReasonFieldsPageTest extends TestCase
{
    use RefreshDatabase;

    private const Address = '/commands/owner/reason-fields';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * The places the settings page lists for an owner, by key.
     *
     * @return array<string, array{key: string, label: string, mode: string, money: bool}>
     */
    private function listed(?User $owner = null): array
    {
        $response = $this->actingAs($owner ?? User::factory()->staff()->create())->onPortal('sadmin')->get('/reason-fields')->assertOk();
        $places = [];
        foreach ($response->viewData('page')['props']['data']['reason_fields']['groups'] as $group) {
            foreach ($group['places'] as $place) {
                $places[$place['key']] = $place;
            }
        }

        return $places;
    }

    /** The owner's choice for a place as saved, before anyone is let off. */
    private function choice(string $place): string
    {
        return app(ReasonFields::class)->modes()[$place];
    }

    /** @return array<string, array{string}> */
    public static function owners(): array
    {
        return ['Super Admin' => ['super_admin'], 'MD' => ['md'], 'Chairman' => ['chairman']];
    }

    #[DataProvider('owners')]
    public function test_each_owner_can_open_the_page(string $role): void
    {
        $this->actingAs(User::factory()->staff($role)->create())->onPortal('sadmin')->get('/reason-fields')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'reason-fields')
                ->where('data.reason_fields.version', 0)
                ->has('data.reason_fields.groups', count(ReasonFields::Groups)));
    }

    public function test_the_page_lists_every_place_with_money_records_compulsory_and_the_rest_optional(): void
    {
        $listed = $this->listed();

        $this->assertSame(array_keys(ReasonFields::Places), array_keys($listed));
        foreach ($listed as $place => $row) {
            $this->assertSame(in_array($place, ReasonFields::MoneyRecords, true), $row['money'], $place);
            $this->assertSame($row['money'] ? ReasonFields::Required : ReasonFields::Optional, $row['mode'], $place);
        }
    }

    public function test_the_money_records_are_the_places_that_alone_say_why_money_moved(): void
    {
        $listed = $this->listed();

        $this->assertTrue($listed['order.refund']['money']);
        $this->assertTrue($listed['money.withdrawal_review']['money']);
        $this->assertTrue($listed['order.commission_decision']['money']);
        $this->assertFalse($listed['product.edit']['money']);
        $this->assertSame([], array_diff(ReasonFields::MoneyRecords, array_keys(ReasonFields::Places)));
        $this->assertCount(17, ReasonFields::MoneyRecords);
    }

    public function test_a_money_record_can_be_made_optional_and_put_back(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->post(self::Address, ['version' => 0, 'modes' => ['order.refund' => 'optional']])->assertSessionHasNoErrors();
        $this->assertSame('optional', $this->listed($owner)['order.refund']['mode']);

        $this->actingAs($owner)->post(self::Address, ['version' => 1, 'modes' => ['order.refund' => 'required']])->assertSessionHasNoErrors();
        $this->assertSame('required', $this->listed($owner)['order.refund']['mode']);
        // Back where it starts, so nothing is left saved for it.
        $this->assertSame('{"modes":{}}', DB::table('business_settings')->where('key', ReasonFields::SettingsKey)->value('value'));
    }

    public function test_office_staff_have_no_such_page(): void
    {
        foreach (['editor', 'finance', 'support'] as $role) {
            $this->actingAs(User::factory()->staff($role)->create())->onPortal('manage')->get('/reason-fields')->assertNotFound();
        }
    }

    public function test_office_staff_cannot_reach_it_through_the_owner_panel_either(): void
    {
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('sadmin')->get('/reason-fields')->assertForbidden();
    }

    public function test_partners_have_no_such_page(): void
    {
        $this->actingAs(User::factory()->dealer()->create())->onPortal('dealer')->get('/reason-fields')->assertNotFound();
        $this->actingAs(User::factory()->subDealer()->create())->onPortal('subdealer')->get('/reason-fields')->assertNotFound();
    }

    public function test_someone_not_signed_in_is_sent_to_sign_in(): void
    {
        $this->onPortal('sadmin')->get('/reason-fields')->assertRedirect('/login');
    }

    #[DataProvider('owners')]
    public function test_each_owner_can_change_a_place_and_the_change_is_recorded(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->post(self::Address, ['version' => 0, 'modes' => ['product.edit' => 'required', 'order.note' => 'off']])
            ->assertRedirect()->assertSessionHasNoErrors();

        $listed = $this->listed($owner);
        $this->assertSame('required', $listed['product.edit']['mode']);
        $this->assertSame('off', $listed['order.note']['mode']);
        $this->assertSame('optional', $listed['product.remove']['mode']);
        $this->assertSame(1, (int) DB::table('business_settings')->where('key', ReasonFields::SettingsKey)->value('version'));

        $event = DB::table('audit_events')->where('action', 'reason_fields.changed')->where('actor_id', $owner->id)->sole();
        $this->assertSame([
            'product.edit' => ['before' => 'optional', 'after' => 'required'],
            'order.note' => ['before' => 'optional', 'after' => 'off'],
        ], json_decode($event->metadata, true)['changes']);
    }

    public function test_a_place_left_out_of_a_later_save_keeps_its_choice(): void
    {
        $owner = User::factory()->staff()->create();
        $this->actingAs($owner)->post(self::Address, ['version' => 0, 'modes' => ['product.edit' => 'required']])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(self::Address, ['version' => 1, 'modes' => ['order.refund' => 'off']])->assertSessionHasNoErrors();

        $this->assertSame('required', $this->choice('product.edit'));
        $this->assertSame('off', $this->choice('order.refund'));
    }

    public function test_putting_a_place_back_to_optional_is_recorded_too(): void
    {
        $owner = User::factory()->staff()->create();
        app(ReasonFields::class)->save($owner, ['product.edit' => 'required'], 0);

        $this->actingAs($owner)->post(self::Address, ['version' => 1, 'modes' => ['product.edit' => 'optional']])->assertSessionHasNoErrors();

        $this->assertSame('optional', $this->choice('product.edit'));
        $this->assertSame(2, DB::table('audit_events')->where('action', 'reason_fields.changed')->count());
    }

    public function test_saving_the_same_choices_again_records_nothing(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->post(self::Address, ['version' => 0, 'modes' => ['product.edit' => 'optional']])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('audit_events')->where('action', 'reason_fields.changed')->count());
        $this->assertSame(0, DB::table('business_settings')->where('key', ReasonFields::SettingsKey)->count());
    }

    public function test_office_staff_and_partners_cannot_change_a_place(): void
    {
        foreach ([User::factory()->staff('editor')->create(), User::factory()->staff('finance')->create(), User::factory()->dealer()->create()] as $person) {
            $this->actingAs($person)->post(self::Address, ['version' => 0, 'modes' => ['product.edit' => 'required']])->assertForbidden();
        }

        $this->assertSame('optional', $this->choice('product.edit'));
    }

    public function test_a_form_left_open_while_another_owner_saved_is_refused(): void
    {
        $owner = User::factory()->staff()->create();
        app(ReasonFields::class)->save(User::factory()->staff('md')->create(), ['product.edit' => 'off'], 0);

        $this->actingAs($owner)->post(self::Address, ['version' => 0, 'modes' => ['product.edit' => 'required']])->assertSessionHasErrors('version');

        $this->assertSame('off', $this->choice('product.edit'));
    }

    public function test_a_choice_must_be_one_of_the_three_and_the_place_must_be_on_the_list(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->post(self::Address, ['version' => 0, 'modes' => ['product.edit' => 'sometimes']])->assertSessionHasErrors('modes.product.edit');
        $this->actingAs($owner)->post(self::Address, ['version' => 0, 'modes' => ['product.unknown' => 'off']])->assertSessionHasErrors('modes');
        $this->actingAs($owner)->post(self::Address, ['modes' => ['product.edit' => 'off']])->assertSessionHasErrors('version');
        $this->actingAs($owner)->post(self::Address, ['version' => 0])->assertSessionHasErrors('modes');

        $this->assertSame('optional', $this->choice('product.edit'));
    }

    /** @return array<string, string> what a page is told about the reason boxes for this person */
    private function toldToPages(User $person, string $portal, string $address): array
    {
        return $this->actingAs($person)->onPortal($portal)->get($address)->assertOk()->viewData('page')['props']['reasonFields'];
    }

    public function test_pages_are_told_only_the_places_that_are_not_optional(): void
    {
        app(ReasonFields::class)->save(User::factory()->staff()->create(), ['product.edit' => 'required', 'order.note' => 'off', 'order.refund' => 'optional'], 0);

        $told = $this->toldToPages(User::factory()->staff('editor')->create(), 'manage', '/catalog');

        $this->assertSame('required', $told['product.edit']);
        $this->assertSame('off', $told['order.note']);
        $this->assertSame('required', $told['payment.verify']);
        $this->assertArrayNotHasKey('order.refund', $told);
        $this->assertArrayNotHasKey('product.remove', $told);
    }

    public function test_the_super_admin_is_shown_no_compulsory_box_but_a_switched_off_box_stays_off(): void
    {
        $owner = User::factory()->staff()->create();
        app(ReasonFields::class)->save($owner, ['product.edit' => 'required', 'order.note' => 'off'], 0);

        $this->assertSame(['order.note' => 'off'], $this->toldToPages($owner, 'sadmin', '/catalog'));
    }

    /** @return array<string, array{string, bool}> */
    public static function whoIsForced(): array
    {
        return [
            'Super Admin' => ['super_admin', false],
            'MD' => ['md', true],
            'Chairman' => ['chairman', true],
            'finance staff' => ['finance', true],
            'dealer' => ['dealer', true],
            'sub-dealer' => ['sub_dealer', true],
        ];
    }

    #[DataProvider('whoIsForced')]
    public function test_a_compulsory_place_forces_everyone_except_the_super_admin(string $role, bool $forced): void
    {
        $person = User::factory()->create(['role' => $role]);
        $this->actingAs($person);
        $rule = ['reason' => ReasonFields::rule('order.refund', 1000, 3)];

        $this->assertSame($forced, ReasonFields::required('order.refund'));
        $this->assertSame($forced, Validator::make(['reason' => ''], $rule)->fails());
        $this->assertSame($forced, Validator::make([], $rule)->fails());
        $this->assertTrue(Validator::make(['reason' => 'Goods ran out'], $rule)->passes());
    }

    public function test_the_person_can_be_named_when_nobody_is_signed_in(): void
    {
        $owner = User::factory()->staff()->create();
        $md = User::factory()->staff('md')->create();

        $this->assertFalse(ReasonFields::required('order.refund', $owner));
        $this->assertTrue(ReasonFields::required('order.refund', $md));
        $this->assertTrue(ReasonFields::required('order.refund'));
        $this->assertSame('optional', ReasonFields::mode('order.refund', $owner));
    }

    public function test_a_place_switched_off_is_off_for_the_super_admin_too(): void
    {
        $owner = User::factory()->staff()->create();
        app(ReasonFields::class)->save($owner, ['order.refund' => 'off'], 0);

        $this->assertSame('off', ReasonFields::mode('order.refund', $owner));
        $this->assertSame('off', ReasonFields::mode('order.refund', User::factory()->staff('finance')->create()));
    }

    public function test_a_compulsory_place_rejects_a_blank_box_and_names_it(): void
    {
        app(ReasonFields::class)->save(User::factory()->staff()->create(), ['product.edit' => 'required'], 0);
        app()->setLocale('bn');

        $blank = Validator::make(['reason' => ''], ['reason' => ReasonFields::rule('product.edit', 1000, 5)]);
        $short = Validator::make(['reason' => 'ঠিক'], ['reason' => ReasonFields::rule('product.edit', 1000, 5)]);
        $written = Validator::make(['reason' => 'দাম বদল হয়েছে'], ['reason' => ReasonFields::rule('product.edit', 1000, 5)]);

        $this->assertSame(['কারণ দিতে হবে।'], $blank->errors()->get('reason'));
        $this->assertTrue($short->fails());
        $this->assertTrue($written->passes());
    }

    #[DataProvider('choicesThatAcceptBlank')]
    public function test_an_optional_or_switched_off_place_accepts_a_blank_or_missing_box(string $mode): void
    {
        if ($mode !== ReasonFields::Optional) {
            app(ReasonFields::class)->save(User::factory()->staff()->create(), ['product.edit' => $mode], 0);
        }
        $rule = ['reason' => ReasonFields::rule('product.edit', 1000, 5)];

        $this->assertTrue(Validator::make(['reason' => ''], $rule)->passes());
        $this->assertTrue(Validator::make(['reason' => null], $rule)->passes());
        $this->assertTrue(Validator::make([], $rule)->passes());
        // A short note is fine while the box is not compulsory; the length limit still holds.
        $this->assertTrue(Validator::make(['reason' => 'ok'], $rule)->passes());
        $this->assertTrue(Validator::make(['reason' => str_repeat('x', 1001)], $rule)->fails());
    }

    /** @return array<string, array{string}> */
    public static function choicesThatAcceptBlank(): array
    {
        return ['optional' => [ReasonFields::Optional], 'off' => [ReasonFields::Off]];
    }

    public function test_a_blank_reason_reads_as_not_written_to_someone_else(): void
    {
        app()->setLocale('bn');

        $this->assertSame('কারণ লেখা হয়নি', ReasonFields::shown(''));
        $this->assertSame('কারণ লেখা হয়নি', ReasonFields::shown(null));
        $this->assertSame('No reason was written', ReasonFields::shown('  ', 'en'));
        $this->assertSame('দাম বদল', ReasonFields::shown(' দাম বদল '));
        $this->assertSame('', ReasonFields::text(null));
        $this->assertSame('দাম বদল', ReasonFields::text(" দাম বদল\n"));
    }

    public function test_asking_about_a_place_that_is_not_on_the_list_is_a_mistake_in_the_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReasonFields::mode('product.no_such_place');
    }

    public function test_the_settings_page_links_to_it_for_owners_only(): void
    {
        $links = fn (User $person, string $portal): array => array_merge(...array_map(
            fn (array $group): array => array_column($group['links'], 'key'),
            OfficeHubs::settingsGroups($person, $portal, true),
        ));

        $this->assertContains('reason-fields', $links(User::factory()->staff()->create(), 'sadmin'));
        $this->assertContains('reason-fields', $links(User::factory()->staff('chairman')->create(), 'sadmin'));
        $this->assertNotContains('reason-fields', $links(User::factory()->staff('finance')->create(), 'manage'));
        $this->assertNotContains('reason-fields', $links(User::factory()->staff('finance')->create(), 'sadmin'));
    }

    public function test_every_place_has_its_area_and_its_name_in_both_languages(): void
    {
        $english = require lang_path('en/reason_fields.php');
        $bangla = require lang_path('bn/reason_fields.php');

        $this->assertSame(array_keys($english), array_keys($bangla));
        foreach (ReasonFields::Places as $place => $group) {
            $this->assertContains($group, ReasonFields::Groups, $place);
            $this->assertArrayHasKey('place_'.str_replace('.', '_', $place), $bangla, $place);
        }
        foreach (ReasonFields::Groups as $group) {
            $this->assertArrayHasKey('group_'.$group, $bangla, $group);
        }
        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('portal.reason_fields', __('portal.reason_fields'), $locale);
            $this->assertNotSame('office_menu.settings_link_reason_fields', __('office_menu.settings_link_reason_fields'), $locale);
        }
    }
}
