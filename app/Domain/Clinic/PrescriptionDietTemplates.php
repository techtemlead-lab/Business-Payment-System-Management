<?php

namespace App\Domain\Clinic;

use App\Models\PrescriptionDietTemplate;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saved lists of foods to eat and to avoid. Each template belongs to one prescriber and is
 * never shown to anyone else, the owner-doctor included. Using one only adds its foods to the
 * form; nothing reaches a prescription until the prescription itself is saved.
 */
final class PrescriptionDietTemplates
{
    /** Most food templates one prescriber may keep. */
    public const MaxTemplates = 100;

    /**
     * Keeps a new template, or changes the prescriber's own one.
     *
     * @param  array{name: string, foods_allowed?: mixed, foods_avoided?: mixed}  $data
     */
    public function save(User $prescriber, ?PrescriptionDietTemplate $template, array $data): PrescriptionDietTemplate
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        if ($template !== null) {
            abort_unless((int) $template->prescriber_id === (int) $prescriber->id, 404);
        }
        $values = [
            'name' => trim($data['name']),
            'foods_allowed' => PrescriptionLists::clean($data['foods_allowed'] ?? null),
            'foods_avoided' => PrescriptionLists::clean($data['foods_avoided'] ?? null),
        ];
        if ($values['foods_allowed'] === null && $values['foods_avoided'] === null) {
            throw ValidationException::withMessages(['foods_allowed' => __('prescription.err_diet_template_empty')]);
        }
        if ($template === null && PrescriptionDietTemplate::query()->where('prescriber_id', $prescriber->id)->count() >= self::MaxTemplates) {
            throw ValidationException::withMessages(['name' => __('prescription.err_template_limit', ['max' => self::MaxTemplates])]);
        }

        try {
            $saved = DB::transaction(function () use ($prescriber, $template, $values): PrescriptionDietTemplate {
                if ($template === null) {
                    return PrescriptionDietTemplate::query()->create([...$values, 'prescriber_id' => $prescriber->id]);
                }
                $template->update($values);

                return $template;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => __('prescription.err_template_name_taken')]);
        }
        Audit::record($prescriber->id, 'prescription.diet_template_saved', (string) $saved->id, ['updated' => $template !== null]);

        return $saved;
    }

    public function delete(User $prescriber, PrescriptionDietTemplate $template): void
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        abort_unless((int) $template->prescriber_id === (int) $prescriber->id, 404);
        $template->delete();
        Audit::record($prescriber->id, 'prescription.diet_template_deleted', (string) $template->id, []);
    }

    /**
     * The viewer's own food templates for the page, by name.
     *
     * @return list<array{id: int, name: string, foods_allowed: list<string>, foods_avoided: list<string>}>
     */
    public function listFor(User $prescriber): array
    {
        return PrescriptionDietTemplate::query()
            ->where('prescriber_id', $prescriber->id)
            ->orderBy('name')
            ->limit(self::MaxTemplates)
            ->get(['id', 'name', 'foods_allowed', 'foods_avoided'])
            ->map(fn (PrescriptionDietTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'foods_allowed' => $template->foods_allowed ?? [],
                'foods_avoided' => $template->foods_avoided ?? [],
            ])
            ->values()
            ->all();
    }
}
