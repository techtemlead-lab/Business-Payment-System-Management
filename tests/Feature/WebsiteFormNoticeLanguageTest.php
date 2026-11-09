<?php

namespace Tests\Feature;

use App\Domain\Content\GrievanceInbox;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A notice from a website form reads fully in the language of the person reading it,
 * whatever language the visitor was using.
 */
class WebsiteFormNoticeLanguageTest extends TestCase
{
    use RefreshDatabase;

    private User $banglaOwner;

    private User $englishOwner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        $this->banglaOwner = User::factory()->staff('super_admin')->create(['locale' => 'bn']);
        $this->englishOwner = User::factory()->staff('md')->create(['locale' => 'en']);
    }

    public function test_a_partner_application_notice_is_fully_in_each_readers_language(): void
    {
        $this->onPortal('public')
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->from('/partnership')
            ->post('/partnership/apply', [
                'name' => 'Rahim',
                'email' => 'rahim.apply@example.test',
                'phone' => '01720000295',
                'date_of_birth' => '1990-01-01',
                'role' => 'sub_dealer',
                'division' => 'Rangpur',
                'district' => 'Rangpur',
            ])
            ->assertRedirect('/partnership#partner-apply-status');

        $bangla = $this->noticeFor($this->banglaOwner);
        $english = $this->noticeFor($this->englishOwner);

        $this->assertSame(__('portal.notice_admin_partner_apply_title', [], 'bn'), $bangla->title);
        $this->assertSame('Rahim সাব-ডিলার হিসেবে যোগ দিতে আবেদন করেছেন। মোবাইল: 01720000295, জেলা: Rangpur।', $bangla->body);
        $this->assertSame('New partner application', $english->title);
        $this->assertSame('Rahim applied to join as Sub-dealer. Mobile: 01720000295, District: Rangpur.', $english->body);
    }

    public function test_a_website_enquiry_notice_uses_the_topic_name_in_each_readers_language(): void
    {
        $this->onPortal('public')
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->from('/contact')
            ->post('/enquiries', [
                'name' => 'Karim',
                'phone' => '01720000296',
                'topic' => 'dealer_enquiry',
                'message' => 'Please call me about becoming a dealer.',
            ])
            ->assertRedirect('/contact#enquiry-status');

        $bangla = $this->noticeFor($this->banglaOwner);
        $english = $this->noticeFor($this->englishOwner);

        $this->assertSame(__('portal.notice_admin_enquiry_title', [], 'bn'), $bangla->title);
        $this->assertStringContainsString(__('portal.enquiry_topic_dealer', [], 'bn'), $bangla->body);
        $this->assertStringContainsString(__('portal.enquiry_topic_dealer', [], 'en'), $english->body);
        $this->assertStringNotContainsString('dealer_enquiry', $bangla->body);
        $this->assertStringNotContainsString('dealer_enquiry', $english->body);
    }

    public function test_a_website_feedback_notice_names_where_it_came_from_in_each_readers_language(): void
    {
        $this->onPortal('public')
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->from('/feedback')
            ->post('/feedback', ['name' => 'Salma', 'rating' => 5, 'message' => 'Very good service from the company, thank you.'])
            ->assertRedirect('/feedback');

        $this->assertSame(__('portal.notice_admin_feedback_body', ['name' => 'Salma', 'source' => __('portal.feedback_source_public', [], 'bn')], 'bn'), $this->noticeFor($this->banglaOwner)->body);
        $this->assertSame(__('portal.notice_admin_feedback_body', ['name' => 'Salma', 'source' => __('portal.feedback_source_public', [], 'en')], 'en'), $this->noticeFor($this->englishOwner)->body);
    }

    public function test_a_product_review_notice_is_in_each_readers_language(): void
    {
        $product = Product::factory()->create(['active' => true, 'name' => 'Ajmer Lobon']);

        $this->onPortal('public')
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->post('/products/'.$product->id.'/reviews', ['name' => 'Jamal', 'rating' => 4, 'message' => 'Good product, my family has been using it.'])
            ->assertRedirect();

        $this->assertSame(__('product_facts.notice_review_title', [], 'bn'), $this->noticeFor($this->banglaOwner)->title);
        $this->assertSame(__('product_facts.notice_review_title', [], 'en'), $this->noticeFor($this->englishOwner)->title);
    }

    public function test_a_complaint_notice_is_in_each_readers_language(): void
    {
        app()->setLocale('en');

        app(GrievanceInbox::class)->submit([
            'name' => 'Nasima',
            'phone' => '01720000297',
            'topic' => GrievanceInbox::TopicProductProblem,
            'message' => 'The bottle arrived broken at my shop.',
            'product_name' => 'Ajmer Lobon',
        ]);

        $this->assertStringContainsString(__('public_trust.topic_product_problem', [], 'bn'), $this->noticeFor($this->banglaOwner)->body);
        $this->assertStringContainsString(__('public_trust.topic_product_problem', [], 'en'), $this->noticeFor($this->englishOwner)->body);
    }

    private function noticeFor(User $reader): object
    {
        return DB::table('account_notices')->where('user_id', $reader->id)->sole();
    }
}
