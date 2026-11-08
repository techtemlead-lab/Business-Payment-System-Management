<?php

namespace App\Domain\Clinic;

use App\Models\Prescription;
use App\Models\User;
use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Patients who should have come back: follow-up dates that are today or already past.
 * A follow-up stops being open when the prescriber marks it done, or writes a newer
 * prescription for the same patient. Only the prescriber who wrote it sees it, including
 * for the owner-doctor.
 */
final class PrescriptionFollowUps
{
    /** Rows shown on the page; the counts still cover everything. */
    public const ListLimit = 100;

    /**
     * The prescriber's open follow-ups, oldest date first.
     *
     * @return array{due_today: int, overdue: int, rows: list<array{id: int, number: string, patient: string|null, phone: string|null, follow_up_on: string, days_late: int, diagnosis: string|null}>}
     */
    public function forPrescriber(User $prescriber): array
    {
        $today = $this->today();
        $counts = $this->open($today)
            ->where('prescriber_id', $prescriber->id)
            ->toBase()
            ->selectRaw('count(*) filter (where follow_up_on = ?) as due_today, count(*) filter (where follow_up_on < ?) as overdue', [$today, $today])
            ->first();
        $rows = $this->open($today)
            ->where('prescriber_id', $prescriber->id)
            ->with('patient:id,name,phone')
            ->orderBy('follow_up_on')
            ->orderBy('id')
            ->limit(self::ListLimit)
            ->get(['id', 'number', 'patient_id', 'follow_up_on', 'diagnosis'])
            ->map(fn (Prescription $prescription): array => [
                'id' => $prescription->id,
                'number' => $prescription->number,
                'patient' => $prescription->patient?->name,
                'phone' => $prescription->patient?->phone,
                'follow_up_on' => $prescription->follow_up_on->toDateString(),
                'days_late' => (int) $prescription->follow_up_on->startOfDay()->diffInDays(CarbonImmutable::parse($today)->startOfDay(), true),
                'diagnosis' => $prescription->diagnosis,
            ])
            ->values()
            ->all();

        return ['due_today' => (int) $counts->due_today, 'overdue' => (int) $counts->overdue, 'rows' => $rows];
    }

    /**
     * Open follow-up counts for every prescriber who has any.
     *
     * @return array<int, array{due_today: int, overdue: int}>
     */
    public function countsByPrescriber(): array
    {
        $today = $this->today();

        return $this->open($today)
            ->toBase()
            ->selectRaw('prescriber_id, count(*) filter (where follow_up_on = ?) as due_today, count(*) filter (where follow_up_on < ?) as overdue', [$today, $today])
            ->groupBy('prescriber_id')
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->prescriber_id => ['due_today' => (int) $row->due_today, 'overdue' => (int) $row->overdue]])
            ->all();
    }

    public function markDone(User $prescriber, Prescription $prescription): void
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        abort_unless((int) $prescription->prescriber_id === (int) $prescriber->id, 404);
        abort_if($prescription->follow_up_on === null, 422);
        $closed = Prescription::query()->whereKey($prescription->id)->whereNull('follow_up_done_at')->update(['follow_up_done_at' => now()]);
        if ($closed > 0) {
            Audit::record($prescriber->id, 'prescription.follow_up_done', (string) $prescription->id, ['number' => $prescription->number]);
        }
    }

    public function today(): string
    {
        return CarbonImmutable::now((string) config('madina.business_timezone', 'Asia/Dhaka'))->toDateString();
    }

    /** @return Builder<Prescription> */
    private function open(string $today): Builder
    {
        return Prescription::query()
            ->where('follow_up_on', '<=', $today)
            ->whereNull('follow_up_done_at')
            ->whereNotExists(function (QueryBuilder $newer): void {
                $newer->selectRaw('1')
                    ->from('prescriptions as newer')
                    ->whereColumn('newer.patient_id', 'prescriptions.patient_id')
                    ->whereColumn('newer.prescriber_id', 'prescriptions.prescriber_id')
                    ->whereColumn('newer.id', '>', 'prescriptions.id');
            });
    }
}
