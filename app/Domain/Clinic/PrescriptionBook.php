<?php

namespace App\Domain\Clinic;

use App\Domain\Finance\Percentage;
use App\Models\PlaceCode;
use App\Models\Prescription;
use App\Models\PrescriptionLine;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use App\Support\PhoneIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Patients and prescriptions. Dealer and sub-dealer prescriptions are typed by hand
 * (medicine, dose, price, discount) and never touch company stock. The owner-doctor's
 * clinic prescriptions pick company products; ClinicDispensing takes them out of stock.
 */
final class PrescriptionBook
{
    /** Most medicines one prescription may hold. */
    public const MaxLines = 30;

    /**
     * @param  array{name: string, age?: int|null, sex?: string|null, phone?: string|null, place_code_id?: int|null, notes?: string|null}  $data
     */
    public function savePatient(User $prescriber, ?PrescriptionPatient $patient, array $data): PrescriptionPatient
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
        if ($patient === null) {
            $patient = PrescriptionPatient::query()->create([...$values, 'prescriber_id' => $prescriber->id]);
            Audit::record($prescriber->id, 'prescription.patient_created', (string) $patient->id, []);

            return $patient;
        }
        $patient->update($values);

        return $patient;
    }

    /**
     * Writes a prescription. Partners type every medicine; the owner-doctor picks products,
     * and the medicine name and MRP are copied from the product.
     *
     * @param  array{patient_id: int, prescribed_on: string, complaints?: string|null, diagnosis?: string|null, advice?: string|null, follow_up_on?: string|null, lines: list<array<string, mixed>>}  $data
     */
    public function create(User $prescriber, array $data): Prescription
    {
        abort_unless(PrescriptionAccess::canPrescribe($prescriber), 403);
        $patient = PrescriptionPatient::query()->findOrFail($data['patient_id']);
        // A prescription is always written for one of the prescriber's own patients.
        abort_unless((int) $patient->prescriber_id === (int) $prescriber->id, 404);
        $clinic = PrescriptionAccess::isOwnerDoctor($prescriber);

        return DB::transaction(function () use ($prescriber, $patient, $data, $clinic): Prescription {
            $lines = $this->buildLines($data['lines'], $clinic);
            if ($clinic) {
                $this->assertPriceFloor($lines);
            }
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
                'follow_up_on' => $data['follow_up_on'] ?? null,
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
                    'dose' => $line['dose'],
                    'frequency' => $line['frequency'],
                    'duration' => $line['duration'],
                    'instructions' => $line['instructions'],
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

            return $prescription->load('lines');
        });
    }

    /**
     * Prescriptions are seen by their own prescriber and by the owner-doctor.
     */
    public function assertVisible(User $viewer, Prescription $prescription): void
    {
        abort_unless(PrescriptionAccess::enabled(), 404);
        abort_unless(
            (int) $prescription->prescriber_id === (int) $viewer->id || PrescriptionAccess::seesAllPatients($viewer),
            404,
        );
    }

    public function assertOwnPatient(User $viewer, PrescriptionPatient $patient): void
    {
        abort_unless((int) $patient->prescriber_id === (int) $viewer->id, 404);
    }

    /**
     * Patient row for the screen: the phone is masked the way the app masks phones elsewhere,
     * except for the prescriber who saved it.
     *
     * @return array<string, mixed>
     */
    public function patientRow(PrescriptionPatient $patient, User $viewer): array
    {
        $own = (int) $patient->prescriber_id === (int) $viewer->id;

        return [
            'id' => $patient->id,
            'name' => $patient->name,
            'age' => $patient->age,
            'sex' => $patient->sex,
            'phone' => $own ? $patient->phone : PhoneIdentity::masked($patient->phone),
            'phone_masked' => PhoneIdentity::masked($patient->phone),
            'place_code_id' => $patient->place_code_id,
            'place' => $patient->placeCode ? trim(PlaceCode::displayLabel($patient->placeCode->code).' '.PlaceCode::displayLabel($patient->placeCode->name)) : null,
            'notes' => $own ? $patient->notes : null,
            'own' => $own,
            'prescriber' => $own ? null : $patient->prescriber?->name,
            'created_at' => $patient->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rawLines
     * @return list<array{product_id: int|null, medicine_name: string, dose: string|null, frequency: string|null, duration: string|null, instructions: string|null, unit_cents: int, quantity: int, discount_cents: int, gross_cents: int, mrp_unit_cents: int|null, mrp_cents: int}>
     */
    private function buildLines(array $rawLines, bool $clinic): array
    {
        if ($rawLines === [] || count($rawLines) > self::MaxLines) {
            throw ValidationException::withMessages(['lines' => __('prescription.err_lines_count', ['max' => self::MaxLines])]);
        }
        $products = $clinic
            ? Product::query()->whereIn('id', array_filter(array_map(fn (array $line): int => (int) ($line['product_id'] ?? 0), $rawLines)))->get(['id', 'name', 'mrp'])->keyBy('id')
            : collect();
        $lines = [];
        foreach ($rawLines as $index => $line) {
            $productId = null;
            $mrpUnit = null;
            $name = trim((string) ($line['medicine_name'] ?? ''));
            if ($clinic) {
                $product = $products->get((int) ($line['product_id'] ?? 0));
                if ($product === null) {
                    throw ValidationException::withMessages(["lines.$index.product_id" => __('prescription.err_product_required')]);
                }
                $productId = (int) $product->id;
                $mrpUnit = Money::cents((string) $product->mrp);
                $name = (string) $product->name;
            } elseif ($name === '') {
                throw ValidationException::withMessages(["lines.$index.medicine_name" => __('prescription.err_medicine_required')]);
            }
            $quantity = (int) ($line['quantity'] ?? 1);
            $unit = Money::cents(number_format((float) ($line['unit_price'] ?? 0), 2, '.', ''));
            $discount = Money::cents(number_format((float) ($line['discount'] ?? 0), 2, '.', ''));
            $gross = $unit * $quantity;
            if ($discount > $gross) {
                throw ValidationException::withMessages(["lines.$index.discount" => __('prescription.err_discount_too_big')]);
            }
            $lines[] = [
                'product_id' => $productId,
                'medicine_name' => mb_substr($name, 0, 160),
                'dose' => $this->text($line['dose'] ?? null),
                'frequency' => $this->text($line['frequency'] ?? null),
                'duration' => $this->text($line['duration'] ?? null),
                'instructions' => $this->text($line['instructions'] ?? null),
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
     * In "block" mode the owner may not price a clinic line below 30% of its MRP; the
     * dispense step checks again.
     *
     * @param  list<array{medicine_name: string, gross_cents: int, discount_cents: int, mrp_cents: int}>  $lines
     */
    private function assertPriceFloor(array $lines): void
    {
        $dispensing = app(ClinicDispensing::class);
        if ($dispensing->priceFloor()['mode'] !== 'block') {
            return;
        }
        $percent = Percentage::from(ClinicDispensing::ManufacturerMrpPercent);
        $below = [];
        foreach ($lines as $line) {
            if ($percent->of($line['mrp_cents'], 'half_up') > $line['gross_cents'] - $line['discount_cents']) {
                $below[] = $line['medicine_name'];
            }
        }
        if ($below !== []) {
            throw ValidationException::withMessages(['lines' => __('prescription.err_below_floor', ['items' => implode(', ', $below), 'percent' => '30'])]);
        }
    }

    private function text(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
