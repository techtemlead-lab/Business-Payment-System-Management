<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PartnerProductEnquiryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
    }

    public function test_shared_link_shows_the_form_and_a_plain_link_does_not(): void
    {
        User::factory()->dealer()->create(['refer_code' => 'RAHIM01', 'status' => 'active']);
        $product = Product::factory()->create(['active' => true]);

        $this->onPortal('public')->get('/products/'.$product->id.'?ref=RAHIM01')
            ->assertOk()
            ->assertSee('partner-enquiry', false);
        $this->onPortal('public')->get('/products/'.$product->id)
            ->assertOk()
            ->assertDontSee('name="consent"', false);
    }

    public function test_enquiry_reaches_only_that_partner_as_a_notice_without_sms(): void
    {
        $partner = User::factory()->dealer()->create(['refer_code' => 'RAHIM01', 'locale' => 'en']);
        $other = User::factory()->dealer()->create();
        $owner = User::factory()->staff()->create();
        $product = Product::factory()->create(['active' => true, 'name' => 'Paracetamol']);

        $this->onPortal('public')->post('/products/'.$product->id.'/partner-enquiry', $this->payload())
            ->assertRedirect()
            ->assertSessionHas('partner_enquiry_sent', true);

        $notice = DB::table('account_notices')->where('user_id', $partner->id)->first();
        $this->assertNotNull($notice);
        $this->assertStringContainsString('Paracetamol', (string) $notice->title);
        $this->assertStringContainsString('Karim Mia', (string) $notice->body);
        $this->assertStringContainsString('01711223344', (string) $notice->body);
        $this->assertStringContainsString('Is it in stock?', (string) $notice->body);
        $this->assertSame(0, DB::table('account_notices')->whereIn('user_id', [$other->id, $owner->id])->count());
        $this->assertSame(0, DB::table('sms_logs')->count());
    }

    public function test_consent_and_a_valid_number_are_required(): void
    {
        User::factory()->dealer()->create(['refer_code' => 'RAHIM01']);
        $product = Product::factory()->create(['active' => true]);

        $this->onPortal('public')->post('/products/'.$product->id.'/partner-enquiry', $this->payload(['consent' => '']))
            ->assertSessionHasErrors('consent');
        $this->onPortal('public')->post('/products/'.$product->id.'/partner-enquiry', $this->payload(['phone' => '12345']))
            ->assertSessionHasErrors('phone');
        $this->assertSame(0, DB::table('account_notices')->count());
    }

    public function test_unknown_partner_code_and_filled_honeypot_are_refused(): void
    {
        $product = Product::factory()->create(['active' => true]);
        User::factory()->dealer()->create(['refer_code' => 'RAHIM01']);

        $this->onPortal('public')->post('/products/'.$product->id.'/partner-enquiry', $this->payload(['ref' => 'NOBODY']))
            ->assertNotFound();
        $this->onPortal('public')->post('/products/'.$product->id.'/partner-enquiry', $this->payload(['website' => 'http://spam']))
            ->assertSessionHasErrors('website');
        $this->assertSame(0, DB::table('account_notices')->count());
    }

    public function test_a_visitor_can_only_write_three_times_a_day(): void
    {
        $partner = User::factory()->dealer()->create(['refer_code' => 'RAHIM01']);
        $product = Product::factory()->create(['active' => true]);
        foreach (range(1, 3) as $attempt) {
            $this->travel(2)->minutes();
            $this->onPortal('public')->post('/products/'.$product->id.'/partner-enquiry', $this->payload(['message' => "Question {$attempt}?"]))->assertSessionHasNoErrors();
        }
        $this->travel(2)->minutes();
        $this->onPortal('public')->post('/products/'.$product->id.'/partner-enquiry', $this->payload(['message' => 'Question 4?']))
            ->assertSessionHasErrors('phone');
        $this->assertSame(3, DB::table('account_notices')->where('user_id', $partner->id)->count());
    }

    public function test_the_form_is_rate_limited_per_visitor_address(): void
    {
        User::factory()->dealer()->create(['refer_code' => 'RAHIM01']);
        $product = Product::factory()->create(['active' => true]);
        foreach (range(1, 5) as $attempt) {
            $this->onPortal('public')->post('/products/'.$product->id.'/partner-enquiry', $this->payload(['phone' => '017112233'.($attempt + 10)]))->assertRedirect();
        }

        $this->onPortal('public')->post('/products/'.$product->id.'/partner-enquiry', $this->payload(['phone' => '01711223399']))
            ->assertStatus(429);
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array<string, string>
     */
    private function payload(array $overrides = []): array
    {
        return [...[
            'ref' => 'RAHIM01',
            'name' => 'Karim Mia',
            'phone' => '01711223344',
            'message' => 'Is it in stock?',
            'consent' => '1',
            'website' => '',
        ], ...$overrides];
    }
}
