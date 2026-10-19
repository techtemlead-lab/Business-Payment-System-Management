<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

final class Audit
{
    public static function record(?int $actor, string $action, string $subject, array $metadata = []): void
    {
        DB::table('audit_events')->insert(['actor_id' => $actor, 'action' => $action, 'subject' => $subject, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'created_at' => now()]);
    }

    public static function notice(int $user, string $title, string $body): void
    {
        DB::table('account_notices')->insert(['user_id' => $user, 'title' => $title, 'body' => $body, 'created_at' => now(), 'updated_at' => now()]);
    }
}
