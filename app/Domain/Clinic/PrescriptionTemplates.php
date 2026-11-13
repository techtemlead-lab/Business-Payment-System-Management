<?php

namespace App\Domain\Clinic;

use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\PrescriptionTemplate;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saved sets of medicines and advice. Each template belongs to one prescriber and is never
 * shown to anyone else, the owner-doctor included. Using one only fills in the form; the
 * prescription is still checked and priced by PrescriptionBook when it is saved.
 */
final class PrescriptionTemplates
{
    /** Most templates one prescriber may keep. */
    public const MaxTemplates = 100;

    /** Copies a prescriber's own prescription into a named template. */
    public function saveFromPrescription(User $prescriber, Prescription $prescription, string $name): PrescriptionTemplate
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        abort_unless((int) $prescription->prescriber_id === (int) $prescriber->id, 404);
        if (PrescriptionTemplate::query()->where('prescriber_id', $prescriber->id)->count() >= self::MaxTemplates) {
            throw ValidationException::withMessages(['name' => __('prescription.err_template_limit', ['max' => self::MaxTemplates])]);
        }
        $lines = $prescription->lines->map(fn (PrescriptionLine $line): array => $line->toDraft())->values()->all();
        if ($lines === []) {
            // These templates hold medicines; foods have their own templates.
            throw ValidationException::withMessages(['name' => __('prescription.err_template_no_medicine')]);
        }

        try {
            $template = DB::transaction(fn (): PrescriptionTemplate => PrescriptionTemplate::query()->create([
                'prescriber_id' => $prescriber->id,
                'name' => trim($name),
                'advice' => $prescription->advice,
                'lines' => $lines,
            ]));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('prescription.err_template_name_taken')]);
        }
        Audit::record($prescriber->id, 'prescription.template_saved', (string) $template->id, ['from_prescription' => $prescription->number]);

        return $template;
    }

    public function delete(User $prescriber, PrescriptionTemplate $template): void
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        abort_unless((int) $template->prescriber_id === (int) $prescriber->id, 404);
        $template->delete();
        Audit::record($prescriber->id, 'prescription.template_deleted', (string) $template->id, []);
    }

    /**
     * The viewer's own templates for the page, by name.
     *
     * @return list<array{id: int, name: string, advice: string|null, lines: list<array<string, mixed>>}>
     */
    public function listFor(User $prescriber): array
    {
        return PrescriptionTemplate::query()
            ->where('prescriber_id', $prescriber->id)
            ->orderBy('name')
            ->limit(self::MaxTemplates)
            ->get(['id', 'name', 'advice', 'lines'])
            ->map(fn (PrescriptionTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'advice' => $template->advice,
                'lines' => $template->lines,
            ])
            ->values()
            ->all();
    }
}
