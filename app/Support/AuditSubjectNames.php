<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Turns what the work log stored in its "subject" column (order and application ids, "user:17",
 * "partner_agreement_version:1") into words a person can read: an order number, a name.
 * Anything not recognised is returned as it was saved, so no row is ever blank.
 */
final class AuditSubjectNames
{
    private const Uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param  list<string>  $subjects
     * @return array<string, string> saved subject => readable text, only for subjects that have one
     */
    public static function for(array $subjects): array
    {
        $subjects = array_values(array_unique(array_filter($subjects, fn (string $subject): bool => $subject !== '')));
        $ids = array_values(array_filter($subjects, fn (string $subject): bool => preg_match(self::Uuid, $subject) === 1));
        $users = [];
        $names = [];
        foreach ($subjects as $subject) {
            if (preg_match('/^user:(\d+)$/', $subject, $match) === 1) {
                $users[$subject] = (int) $match[1];
            } elseif (preg_match('/^partner_agreement_version:(\d+)$/', $subject, $match) === 1) {
                $names[$subject] = (string) __('office_shell.audit_subject_agreement_version', ['version' => LocalDigits::text($match[1])]);
            }
        }
        if ($users !== []) {
            $found = DB::table('users')->whereIn('id', array_values($users))->pluck('name', 'id');
            foreach ($users as $subject => $id) {
                if (isset($found[$id])) {
                    $names[$subject] = (string) $found[$id];
                }
            }
        }
        if ($ids !== []) {
            $names += self::fromIds($ids);
        }

        return $names;
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    private static function fromIds(array $ids): array
    {
        $names = [];
        foreach (DB::table('orders')->whereIn('id', $ids)->pluck('number', 'id') as $id => $number) {
            $names[(string) $id] = (string) $number;
        }
        foreach (DB::table('dealer_applications')->whereIn('id', $ids)->selectRaw("id, data->>'name' AS applicant")->get() as $row) {
            $names[(string) $row->id] ??= (string) ($row->applicant ?: __('office_shell.audit_subject_application'));
        }
        foreach (DB::table('withdrawals')->join('users', 'users.id', '=', 'withdrawals.user_id')->whereIn('withdrawals.id', $ids)->select('withdrawals.id', 'users.name')->get() as $row) {
            $names[(string) $row->id] ??= (string) __('office_shell.audit_subject_withdrawal_of', ['name' => $row->name]);
        }

        return $names;
    }
}
