<?php

namespace Tests\Feature;

use App\Support\DeploymentReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

class MailgunHttpTransportTest extends TestCase
{
    use RefreshDatabase;

    private const Key = 'key-test';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mail.default' => 'mailgun_http',
            'mail.mailers.mailgun_http.domain' => 'sandbox-test.mailgun.org',
            'mail.mailers.mailgun_http.secret' => self::Key,
            'mail.mailers.mailgun_http.endpoint' => 'api.mailgun.net',
            'mail.from.address' => 'office@madina.test',
            'mail.from.name' => 'Madina Office',
        ]);
        Mail::purge('mailgun_http');
    }

    /** @return list<array{name: string, contents: mixed, filename?: string}> */
    private function parts(Request $request): array
    {
        return $request->data();
    }

    public function test_a_simple_message_goes_to_the_mailgun_url_with_basic_auth(): void
    {
        Http::fake(['*' => Http::response(['id' => '<x@y>', 'message' => 'Queued. Thank you.'], 200)]);

        Mail::mailer('mailgun_http')->raw('Hello plain', fn ($m) => $m->to('owner@madina.test')->subject('Test subject'));

        Http::assertSent(function (Request $request) {
            $this->assertSame('https://api.mailgun.net/v3/sandbox-test.mailgun.org/messages', $request->url());
            $this->assertSame('Basic '.base64_encode('api:'.self::Key), $request->header('Authorization')[0]);
            $this->assertTrue($request->isMultipart());
            $byName = collect($this->parts($request))->groupBy('name')->map(fn ($g) => $g->pluck('contents')->all());
            $this->assertStringContainsString('office@madina.test', $byName['from'][0]);
            $this->assertSame(['owner@madina.test'], $byName['to']);
            $this->assertSame(['Test subject'], $byName['subject']);
            $this->assertSame(['Hello plain'], $byName['text']);
            $this->assertArrayNotHasKey('html', $byName->all());

            return true;
        });
    }

    public function test_html_and_text_parts_and_reply_to_are_sent(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Queued.'], 200)]);

        Mail::mailer('mailgun_http')->send([], [], function ($m) {
            $m->to('a@madina.test')->subject('Both')->replyTo('help@madina.test')
                ->text('plain body')->html('<p>html body</p>');
        });

        Http::assertSent(function (Request $request) {
            $byName = collect($this->parts($request))->groupBy('name')->map(fn ($g) => $g->pluck('contents')->all());
            $this->assertSame(['plain body'], $byName['text']);
            $this->assertSame(['<p>html body</p>'], $byName['html']);
            $this->assertSame(['help@madina.test'], $byName['h:Reply-To']);

            return true;
        });
    }

    public function test_several_recipients_cc_and_bcc_are_all_sent(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Queued.'], 200)]);

        Mail::mailer('mailgun_http')->raw('Hi', fn ($m) => $m->to(['a@madina.test', 'b@madina.test'])->cc('c@madina.test')->bcc('d@madina.test')->subject('Many'));

        Http::assertSent(function (Request $request) {
            $byName = collect($this->parts($request))->groupBy('name')->map(fn ($g) => $g->pluck('contents')->all());
            $this->assertSame(['a@madina.test', 'b@madina.test'], $byName['to']);
            $this->assertSame(['c@madina.test'], $byName['cc']);
            $this->assertSame(['d@madina.test'], $byName['bcc']);

            return true;
        });
    }

    public function test_an_attachment_is_sent_as_a_file_part(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Queued.'], 200)]);

        Mail::mailer('mailgun_http')->raw('See file', fn ($m) => $m->to('a@madina.test')->subject('File')->attachData('%PDF-1.4 test', 'guide.pdf', ['mime' => 'application/pdf']));

        Http::assertSent(function (Request $request) {
            $file = collect($this->parts($request))->firstWhere('name', 'attachment');
            $this->assertNotNull($file);
            $this->assertSame('guide.pdf', $file['filename']);
            $this->assertSame('%PDF-1.4 test', $file['contents']);

            return true;
        });
    }

    public function test_the_eu_endpoint_is_used_when_configured(): void
    {
        config(['mail.mailers.mailgun_http.endpoint' => 'api.eu.mailgun.net']);
        Mail::purge('mailgun_http');
        Http::fake(['*' => Http::response(['message' => 'Queued.'], 200)]);

        Mail::mailer('mailgun_http')->raw('Hi', fn ($m) => $m->to('a@madina.test')->subject('EU'));

        Http::assertSent(fn (Request $request) => $request->url() === 'https://api.eu.mailgun.net/v3/sandbox-test.mailgun.org/messages');
    }

    public function test_a_refusal_raises_a_readable_exception_without_the_key(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Sandbox subdomains are for test purposes only. Please add your own domain or add the address to authorized recipients in Account Settings.'], 403)]);

        try {
            Mail::mailer('mailgun_http')->raw('Hi', fn ($m) => $m->to('stranger@madina.test')->subject('No'));
            $this->fail('A 403 from Mailgun must raise an exception.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
            $this->assertStringContainsString('authorized recipients', $e->getMessage());
            $this->assertStringNotContainsString(self::Key, $e->getMessage());
            $this->assertStringNotContainsString(self::Key, (string) $e);
        }
    }

    public function test_a_key_echoed_by_the_server_is_masked(): void
    {
        Http::fake(['*' => Http::response('bad credentials for '.self::Key, 401)]);

        try {
            Mail::mailer('mailgun_http')->raw('Hi', fn ($m) => $m->to('a@madina.test')->subject('No'));
            $this->fail('A 401 must raise an exception.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('HTTP 401', $e->getMessage());
            $this->assertStringNotContainsString(self::Key, $e->getMessage());
        }
    }

    public function test_a_connection_failure_raises_a_plain_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.mailgun.net'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Could not reach Mailgun');

        Mail::mailer('mailgun_http')->raw('Hi', fn ($m) => $m->to('a@madina.test')->subject('Down'));
    }

    public function test_a_missing_key_fails_before_any_request(): void
    {
        config(['mail.mailers.mailgun_http.secret' => null]);
        Mail::purge('mailgun_http');
        Http::fake();

        try {
            Mail::mailer('mailgun_http')->raw('Hi', fn ($m) => $m->to('a@madina.test')->subject('No key'));
            $this->fail('Missing key must raise an exception.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('MAILGUN_SECRET', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_the_readiness_warning_clears_with_domain_and_key_and_returns_without_the_key(): void
    {
        config([
            'madina.financial_activation' => false,
            'madina.preview_mode' => false,
        ]);

        $ready = app(DeploymentReadiness::class)->inspect();
        $this->assertNotContains('mail_driver_logging_only', $ready['warnings']);

        config(['mail.mailers.mailgun_http.secret' => null]);
        $noKey = app(DeploymentReadiness::class)->inspect();
        $this->assertContains('mail_driver_logging_only', $noKey['warnings']);

        config(['mail.mailers.mailgun_http.secret' => self::Key, 'mail.mailers.mailgun_http.domain' => '']);
        $noDomain = app(DeploymentReadiness::class)->inspect();
        $this->assertContains('mail_driver_logging_only', $noDomain['warnings']);
    }
}
