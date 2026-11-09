<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The activity log shows each action by its plain name, so that name is what a search must find.
 */
class AuditSearchVisibleWordsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'name' => 'Owner Person']);
        Audit::record($this->owner->id, 'stock.lot_held', 'BATCH-9');
        Audit::record($this->owner->id, 'order.approved', 'ORD-1');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function namesOnScreen(): array
    {
        return [
            'english name' => ['put on hold'],
            'english name in another case' => ['BATCH PUT'],
            'bangla name' => ['আটকে রাখা'],
        ];
    }

    #[DataProvider('namesOnScreen')]
    public function test_the_name_shown_on_the_row_finds_the_action(string $search): void
    {
        $this->assertSame(['stock.lot_held'], $this->search($search));
    }

    public function test_a_bangla_name_typed_with_the_other_unicode_spelling_is_found(): void
    {
        Audit::record($this->owner->id, 'payment.verified', 'PAY-1');
        $label = (string) __('portal.event_payment_verified', [], 'bn');
        $this->assertStringContainsString("\u{09AF}\u{09BC}", $label);

        $this->assertSame(['payment.verified'], $this->search(str_replace("\u{09AF}\u{09BC}", "\u{09DF}", $label)));
    }

    public function test_the_hidden_code_no_longer_matches(): void
    {
        $this->assertSame([], $this->search('lot_held'));
    }

    public function test_record_and_person_still_match(): void
    {
        $this->assertSame(['order.approved'], $this->search('ORD-1'));
        $this->assertCount(2, $this->search('Owner Person'));
    }

    public function test_a_record_number_typed_in_bangla_digits_is_found(): void
    {
        Audit::record($this->owner->id, 'account.updated', '12345');

        $this->assertSame(['account.updated'], $this->search('১২৩৪৫'));
    }

    /**
     * @return list<string> The actions on the page, newest first.
     */
    private function search(string $words): array
    {
        $actions = [];
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/audit?q='.urlencode($words))
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$actions): void {
                $actions = array_column($page->toArray()['props']['data']['events']['data'], 'action');
            });

        return $actions;
    }
}
