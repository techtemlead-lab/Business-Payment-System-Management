<?php

namespace App\Domain\Clinic;

use App\Domain\Finance\Percentage;
use App\Models\PlaceCode;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\PrescriptionPatient;
use App\Models\PrescriptionPatientConsent;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\PhoneIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Patients and prescriptions. Every prescriber picks medicines from the company's product
 * list; a medicine typed by hand is allowed only while the owners' switch is on
 * (PrescriptionSettings). Dealer and sub-dealer prescriptions carry the dealer's own prices
 * and never touch company stock. Clinic prescriptions, by the owner-doctor and office accounts
 * switched on for company stock, are taken out of stock by ClinicDispensing, product lines
 * only. Patients and prescriptions are private to the person who wrote them: nobody else can
 * read them, the owner included.
 */
final class PrescriptionBook
{
    public function __construct(private PrescriptionSettings $settings) {}

    /** Most medicines one prescription may hold. */
    public const MaxLines = 30;

    /** Patients offered at once in the prescription picker; a search reaches the rest. */
    public const PatientOptionLimit = 30;

    /**
     * @param  array{name: string, age?: int|null, sex?: string|null, phone?: string|null, place_code_id?: int|null, notes?: string|null, allergies?: string|null, patient_consent?: bool|int|string|null}  $data
     * @param  array{source: string, statement: string, consultation_request_id?: int|null}|null  $consent  Null means the prescriber confirmed it themselves.
     */
    public function savePatient(User $prescriber, ?PrescriptionPatient $patient, array $data, ?array $consent = null): PrescriptionPatient
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        if ($patient !== null) {
            $this->assertOwnPatient($prescriber, $patient);
        }
        $values = [
            'name' => trim($data['name']),
            'age' => $data['age'] ?? null,
            'sex' => $data['sex'] ?? null,
            'phone' => filled($data['phone'] ?? null) ? preg_replace('/[^\d+]/', '', (string) $data['phone']) : null,
            'place_code_id' => $data['place_code_id'] ?? null,
            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
        ];
        // A safety field: a save that doesn't mention allergies must not wipe them.
        if (array_key_exists('allergies', $data)) {
            $values['allergies'] = filled($data['allergies']) ? trim((string) $data['allergies']) : null;
        }
        if ($patient === null) {
            return DB::transaction(function () use ($values, $prescriber, $consent): PrescriptionPatient {
                $created = PrescriptionPatient::query()->create([...$values, 'prescriber_id' => $prescriber->id]);
                Audit::record($prescriber->id, 'prescription.patient_created', (string) $created->id, []);
                $this->recordConsent($created, $prescriber, $consent);

                return $created;
            });
        }
        $patient->update($values);
        if (filter_var($data['patient_consent'] ?? false, FILTER_VALIDATE_BOOLEAN) && ! $patient->consent()->exists()) {
            $this->recordConsent($patient, $prescriber, null);
        }

        return $patient;
    }

    /**
     * @param  array{source: string, statement: string, consultation_request_id?: int|null}|null  $consent
     */
    private function recordConsent(PrescriptionPatient $patient, User $prescriber, ?array $consent): void
    {
        $source = $consent['source'] ?? PrescriptionPatientConsent::SourcePrescriber;
        PrescriptionPatientConsent::query()->create([
            'patient_id' => $patient->id,
            'prescriber_id' => $prescriber->id,
            'source' => $source,
            'statement' => $consent['statement'] ?? (string) __('prescriber_public.patient_consent_label'),
            'consultation_request_id' => $consent['consultation_request_id'] ?? null,
            'confirmed_at' => now(),
        ]);
        Audit::record($prescriber->id, 'prescription.patient_consent_recorded', (string) $patient->id, ['source' => $source]);
    }

    /**
     * Writes a prescription. Partners type every medicine; company-stock prescribers pick
     * products, and the medicine name and MRP are copied from the product. The patient is one
     * of the prescriber's own, or a new one saved by the same request. Medicines are optional
     * when the prescription holds a test, a food or advice.
     *
     * @param  array{patient_id?: int|null, new_patient?: array{name: string, age?: int|null, sex?: string|null, phone?: string|null, place_code_id?: int|null, notes?: string|null, allergies?: string|null, patient_consent?: bool|int|string|null}|null, prescribed_on: string, complaints?: string|null, diagnosis?: string|null, advice?: string|null, vitals?: array<string, mixed>|null, follow_up_on?: string|null, investigations?: array<int, string|null>|null, foods_allowed?: array<int, string|null>|null, foods_avoided?: array<int, string|null>|null, lines?: array<int, array<string, mixed>>|null, confirm_below_floor?: bool|null}  $data
     */
    public function create(User $prescriber, array $data): Prescription
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        $newPatient = $data['new_patient'] ?? null;
        $ownPatient = null;
        if ($newPatient === null) {
            $ownPatient = PrescriptionPatient::query()->findOrFail($data['patient_id'] ?? 0);
            // A prescription is always written for one of the prescriber's own patients.
            abort_unless((int) $ownPatient->prescriber_id === (int) $prescriber->id, 404);
        }
        $clinic = PrescriptionAccess::usesCompanyStock($prescriber);
        $rawLines = array_values($data['lines'] ?? []);
        $lists = [
            'investigations' => PrescriptionLists::clean($data['investigations'] ?? null),
            'foods_allowed' => PrescriptionLists::clean($data['foods_allowed'] ?? null),
            'foods_avoided' => PrescriptionLists::clean($data['foods_avoided'] ?? null),
        ];
        // The writing form always sends its empty medicine row. That row is not a medicine, so it
        // must not block a prescription that holds only a test, a food or advice.
        if (! $this->hasMedicineLine($rawLines) && array_filter($lists) === [] && $this->text($data['advice'] ?? null) === null) {
            throw ValidationException::withMessages(['lines' => __('prescription.err_nothing_written')]);
        }

        return DB::transaction(function () use ($prescriber, $ownPatient, $newPatient, $data, $clinic, $rawLines, $lists): Prescription {
            $lines = $this->buildLines($rawLines, $clinic);
            // A new patient is saved inside this transaction, so a prescription that fails leaves no patient behind.
            $patient = $ownPatient ?? $this->savePatient($prescriber, null, $newPatient);
            // The price floor is about what the clinic sells: company products, not hand-typed medicines.
            $belowFloor = $clinic ? $this->linesBelowFloor(array_values(array_filter($lines, fn (array $line): bool => $line['product_id'] !== null))) : [];
            $overridden = ClinicDispensing::enforcePriceFloor($prescriber, $belowFloor, (bool) ($data[ClinicDispensing::ConfirmBelowFloorField] ?? false), 'lines');
            $subtotal = array_sum(array_column($lines, 'gross_cents'));
            $discount = array_sum(array_column($lines, 'discount_cents'));
            $prescription = Prescription::query()->create([
                'number' => 'RX-TMP-'.bin2hex(random_bytes(6)),
                'kind' => $clinic ? Prescription::KindClinic : Prescription::KindPartner,
                'prescriber_id' => $prescriber->id,
                'patient_id' => $patient->id,
                'prescribed_on' => $data['prescribed_on'],
                'complaints' => $data['complaints'] ?? null,
                'diagnosis' => $data['diagnosis'] ?? null,
                'advice' => $data['advice'] ?? null,
                'vitals' => Vitals::clean($data['vitals'] ?? null),
                'follow_up_on' => $data['follow_up_on'] ?? null,
                ...$lists,
                'subtotal' => Money::decimal($subtotal),
                'discount_total' => Money::decimal($discount),
                'total' => Money::decimal($subtotal - $discount),
                'mrp_total' => Money::decimal(array_sum(array_column($lines, 'mrp_cents'))),
                'status' => Prescription::StatusIssued,
            ]);
            $prescription->forceFill(['number' => sprintf('RX-%06d', $prescription->id)])->save();
            foreach ($lines as $position => $line) {
                PrescriptionLine::query()->create([
                    'prescription_id' => $prescription->id,
                    'product_id' => $line['product_id'],
                    'medicine_name' => $line['medicine_name'],
                    'strength' => $line['strength'],
                    'dose' => $line['dose'],
                    'frequency' => $line['frequency'],
                    'duration' => $line['duration'],
                    'instructions' => $line['instructions'],
                    'dose_schedule' => $line['dose_schedule'],
                    'food_timing' => $line['food_timing'],
                    'duration_count' => $line['duration_count'],
                    'duration_unit' => $line['duration_unit'],
                    'unit_price' => Money::decimal($line['unit_cents']),
                    'quantity' => $line['quantity'],
                    'discount' => Money::decimal($line['discount_cents']),
                    'line_total' => Money::decimal($line['gross_cents'] - $line['discount_cents']),
                    'mrp' => $line['mrp_unit_cents'] === null ? null : Money::decimal($line['mrp_unit_cents']),
                    'position' => $position,
                ]);
            }
            Audit::record($prescriber->id, 'prescription.created', (string) $prescription->id, [
                'number' => $prescription->number,
                'kind' => $prescription->kind,
                'total' => $prescription->total,
            ]);
            if ($overridden) {
                Audit::record($prescriber->id, ClinicDispensing::BelowFloorOverrideAction, (string) $prescription->id, [
                    'stage' => 'prescribed',
                    'number' => $prescription->number,
                    'items' => $belowFloor,
                    'price' => $prescription->total,
                    'mrp' => $prescription->mrp_total,
                ]);
            }

            return $prescription->load('lines');
        });
    }

    /**
     * A prescription is seen only by the person who wrote it, not even the owner can open
     * someone else's. A partner whose prescribing was turned off loses their old prescriptions
     * too, the print page included.
     */
    public function assertVisible(User $viewer, Prescription $prescription): void
    {
        abort_unless(PrescriptionAccess::enabled(), 404);
        abort_unless(PrescriptionAccess::canPrescribe($viewer), 403);
        abort_unless((int) $prescription->prescriber_id === (int) $viewer->id, 404);
    }

    public function assertOwnPatient(User $viewer, PrescriptionPatient $patient): void
    {
        abort_unless((int) $patient->prescriber_id === (int) $viewer->id, 404);
    }

    /**
     * The prescriber's own patients for the prescription picker, newest first; a search by
     * name reaches older ones.
     *
     * @return list<array{id: int, name: string, age: int|null, allergies: string|null}>
     */
    public function patientOptions(User $prescriber, string $search = ''): array
    {
        $term = trim($search);

        return PrescriptionPatient::query()
            ->visibleTo($prescriber)
            ->when($term !== '', fn ($query) => $query->where('name', 'ilike', '%'.$term.'%'))
            ->orderByDesc('id')
            ->limit(self::PatientOptionLimit)
            ->get(['id', 'name', 'age', 'allergies'])
            ->map(fn (PrescriptionPatient $patient): array => ['id' => $patient->id, 'name' => $patient->name, 'age' => $patient->age, 'allergies' => $patient->allergies])
            ->values()
            ->all();
    }

    /**
     * Patient row for the prescriber's own screen, with the full phone because they need to
     * call the patient. Nobody else's patient ever reaches this row.
     *
     * @return array<string, mixed>
     */
    public function patientRow(PrescriptionPatient $patient, User $viewer): array
    {
        $this->assertOwnPatient($viewer, $patient);

        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'age' => $patient->age,
            'sex' => $patient->sex,
            'phone' => $patient->phone,
            'phone_masked' => PhoneIdentity::masked($patient->phone),
            'place_code_id' => $patient->place_code_id,
            'place' => $patient->placeCode ? trim(PlaceCode::displayLabel($patient->placeCode->code).' '.PlaceCode::displayLabel($patient->placeCode->name)) : null,
            'notes' => $patient->notes,
            'consent_at' => $patient->consent?->confirmed_at?->toIso8601String(),
            'allergies' => $patient->allergies,
            'own' => true,
            'created_at' => $patient->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rawLines
     * @return list<array{product_id: int|null, medicine_name: string, strength: string|null, dose: string|null, frequency: string|null, duration: string|null, instructions: string|null, dose_schedule: string|null, food_timing: string|null, duration_count: int|null, duration_unit: string|null, unit_cents: int, quantity: int, discount_cents: int, gross_cents: int, mrp_unit_cents: int|null, mrp_cents: int}>
     */
    private function buildLines(array $rawLines, bool $clinic): array
    {
        if (count($rawLines) > self::MaxLines) {
            throw ValidationException::withMessages(['lines' => __('prescription.err_lines_count', ['max' => self::MaxLines])]);
        }
        $productIds = array_filter(array_map(fn (array $line): int => (int) ($line['product_id'] ?? 0), $rawLines));
        $products = $productIds === []
            ? collect()
            : Product::query()->where('active', true)->whereIn('id', $productIds)->get(['id', 'name', 'mrp'])->keyBy('id');
        $otherMedicinesAllowed = null;
        $lines = [];
        foreach ($rawLines as $index => $line) {
            if ($this->isBlankLine($line)) {
                continue;
            }
            $productId = null;
            $mrpUnit = null;
            $strength = null;
            $name = trim((string) ($line['medicine_name'] ?? ''));
            $typedByHand = ! filled($line['product_id'] ?? null);
            if ($typedByHand) {
                // Read once, and only when a prescription really holds a hand-typed medicine.
                $otherMedicinesAllowed ??= $this->settings->otherMedicinesAllowed();
                if (! $otherMedicinesAllowed) {
                    // A completely empty row is skipped above. This row was started, but no product was chosen.
                    throw ValidationException::withMessages($name === ''
                        ? ["lines.$index.product_id" => __('prescription.err_product_required')]
                        : ["lines.$index.medicine_name" => __('prescription.err_other_medicine_off')]);
                }
                if ($name === '') {
                    throw ValidationException::withMessages(["lines.$index.medicine_name" => __('prescription.err_medicine_required')]);
                }
                $strength = $this->text($line['strength'] ?? null);
            } else {
                $product = $products->get((int) $line['product_id']);
                if ($product === null) {
                    throw ValidationException::withMessages(["lines.$index.product_id" => __('prescription.err_product_required')]);
                }
                $productId = (int) $product->id;
                $name = (string) $product->name;
                // Only a clinic line leaves company stock, so only it carries the MRP its sale is split by.
                $mrpUnit = $clinic ? Money::cents((string) $product->mrp) : null;
            }
            $quantity = (int) ($line['quantity'] ?? 1);
            // In the clinic a hand-typed medicine is bought elsewhere: it has no price here, whatever was sent.
            $priced = ! ($clinic && $typedByHand);
            $unit = $priced ? Money::cents(number_format((float) ($line['unit_price'] ?? 0), 2, '.', '')) : 0;
            $discount = $priced ? Money::cents(number_format((float) ($line['discount'] ?? 0), 2, '.', '')) : 0;
            $gross = $unit * $quantity;
            if ($discount > $gross) {
                throw ValidationException::withMessages(["lines.$index.discount" => __('prescription.err_discount_too_big')]);
            }
            $lines[] = [
                'product_id' => $productId,
                'medicine_name' => mb_substr($name, 0, 160),
                'strength' => $strength,
                'dose' => $this->text($line['dose'] ?? null),
                'frequency' => $this->text($line['frequency'] ?? null),
                'duration' => $this->text($line['duration'] ?? null),
                'instructions' => $this->text($line['instructions'] ?? null),
                'dose_schedule' => $this->text($line['dose_schedule'] ?? null),
                'food_timing' => $this->text($line['food_timing'] ?? null),
                'duration_count' => filled($line['duration_count'] ?? null) ? (int) $line['duration_count'] : null,
                'duration_unit' => $this->text($line['duration_unit'] ?? null),
                'unit_cents' => $unit,
                'quantity' => $quantity,
                'discount_cents' => $discount,
                'gross_cents' => $gross,
                'mrp_unit_cents' => $mrpUnit,
                'mrp_cents' => ($mrpUnit ?? 0) * $quantity,
            ];
        }

        return $lines;
    }

    /**
     * The clinic lines priced below 30% of their MRP, or every line when only the whole
     * prescription falls below it. The dispense step checks again.
     *
     * @param  list<array{medicine_name: string, gross_cents: int, discount_cents: int, mrp_cents: int}>  $lines
     * @return list<string>
     */
    private function linesBelowFloor(array $lines): array
    {
        $percent = Percentage::from(ClinicDispensing::ManufacturerMrpPercent);
        $below = [];
        $mrp = 0;
        $price = 0;
        foreach ($lines as $line) {
            $linePrice = $line['gross_cents'] - $line['discount_cents'];
            if ($percent->of($line['mrp_cents'], 'half_up') > $linePrice) {
                $below[] = $line['medicine_name'];
            }
            $mrp += $line['mrp_cents'];
            $price += $linePrice;
        }
        if ($below === [] && $percent->of($mrp, 'half_up') > $price) {
            $below = array_column($lines, 'medicine_name');
        }

        return $below;
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * The empty medicine row the writing form sends before anyone has chosen a product or typed
     * a name. A dose, a price or a quantity other than the row's starting 1 means they started it.
     *
     * @param  array<string, mixed>  $line
     */
    private function isBlankLine(array $line): bool
    {
        if (filled($line['product_id'] ?? null) || $this->text($line['medicine_name'] ?? null) !== null) {
            return false;
        }
        foreach (['strength', 'dose', 'frequency', 'duration', 'instructions', 'dose_schedule', 'food_timing', 'duration_unit'] as $field) {
            if ($this->text($line[$field] ?? null) !== null) {
                return false;
            }
        }
        if (filled($line['duration_count'] ?? null) || (int) ($line['quantity'] ?? 1) !== 1) {
            return false;
        }
        $unit = Money::cents(number_format((float) ($line['unit_price'] ?? 0), 2, '.', ''));
        $discount = Money::cents(number_format((float) ($line['discount'] ?? 0), 2, '.', ''));

        return $unit === 0 && $discount === 0;
    }

    /**
     * @param  list<array<string, mixed>>  $rawLines
     */
    private function hasMedicineLine(array $rawLines): bool
    {
        foreach ($rawLines as $line) {
            if (! $this->isBlankLine($line)) {
                return true;
            }
        }

        return false;
    }
}
