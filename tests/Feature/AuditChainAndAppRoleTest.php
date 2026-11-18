<?php

namespace Tests\Feature;

use App\Support\Audit;
use App\Support\AuditChain;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SA-04: the audit trail cannot be truncated, its rows are sealed in a hash chain, and the
 * website's own database login cannot rewrite or empty it.
 */
class AuditChainAndAppRoleTest extends TestCase
{
    use RefreshDatabase;

    private const AppRole = 'madina_app_ff_test';

    public function test_new_records_are_sealed_and_the_chain_verifies(): void
    {
        Audit::record(null, 'test.first', 'one', ['n' => 1]);
        Audit::record(null, 'test.second', 'two', ['n' => 2]);

        $chain = app(AuditChain::class);
        $this->assertGreaterThanOrEqual(2, $chain->seal());
        $this->assertSame(0, $chain->seal());

        $result = $chain->verify();
        $this->assertTrue($result['ok']);
        $this->assertSame(0, $result['unsealed']);
        $this->assertSame(64, strlen($result['anchor']['hash']));

        $this->artisan('audit:verify-integrity')->assertSuccessful();
    }

    public function test_a_sealed_record_cannot_be_changed_resealed_deleted_or_truncated(): void
    {
        Audit::record(null, 'test.sealed', 'one');
        app(AuditChain::class)->seal();
        $id = (int) DB::table('audit_events')->where('action', 'test.sealed')->value('id');

        foreach ([
            fn () => DB::table('audit_events')->where('id', $id)->update(['subject' => 'changed']),
            fn () => DB::table('audit_events')->where('id', $id)->update(['row_hash' => str_repeat('a', 64)]),
            fn () => DB::table('audit_events')->where('id', $id)->delete(),
            fn () => DB::statement('TRUNCATE audit_events'),
        ] as $attempt) {
            try {
                DB::transaction($attempt);
                $this->fail('The audit trail accepted a change.');
            } catch (QueryException $exception) {
                $this->assertStringContainsString('audit_events', $exception->getMessage());
            }
        }
        $this->assertSame('one', DB::table('audit_events')->where('id', $id)->value('subject'));
    }

    public function test_an_unsealed_record_cannot_be_edited_while_it_is_sealed(): void
    {
        Audit::record(null, 'test.unsealed', 'one');
        $id = (int) DB::table('audit_events')->where('action', 'test.unsealed')->value('id');

        $this->expectException(QueryException::class);
        DB::transaction(fn () => DB::table('audit_events')->where('id', $id)->update([
            'subject' => 'changed', 'chain_seq' => 999999, 'prev_hash' => str_repeat('0', 64), 'row_hash' => str_repeat('b', 64),
        ]));
    }

    public function test_the_website_login_cannot_truncate_or_rewrite_the_audit_trail_but_can_write_rows(): void
    {
        Config::set('database.app_role.password', str_repeat('x', 32));
        $this->artisan('madina:db-app-role', ['--username' => self::AppRole, '--connection' => 'pgsql'])->assertSuccessful();
        Audit::record(null, 'test.role', 'one');

        $role = DB::table('pg_roles')->where('rolname', self::AppRole)->first(['rolsuper', 'rolcreaterole', 'rolcreatedb']);
        $this->assertFalse((bool) $role->rolsuper);
        $this->assertFalse((bool) $role->rolcreaterole);
        $this->assertFalse((bool) $role->rolcreatedb);

        DB::statement('SET LOCAL ROLE '.self::AppRole);
        // Ordinary work and sealing still work.
        Audit::record(null, 'test.role.as_app', 'two');
        app(AuditChain::class)->seal();
        $this->assertTrue(app(AuditChain::class)->verify()['ok']);

        foreach (['TRUNCATE audit_events', 'ALTER TABLE audit_events DISABLE TRIGGER ALL', "DELETE FROM migrations WHERE migration = 'x'"] as $statement) {
            try {
                DB::transaction(fn () => DB::statement($statement));
                $this->fail('The website login was allowed: '.$statement);
            } catch (QueryException $exception) {
                $this->assertMatchesRegularExpression('/permission denied|must be owner/i', $exception->getMessage());
            }
        }
        DB::statement('RESET ROLE');
    }
}
