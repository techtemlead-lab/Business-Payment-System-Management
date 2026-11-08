<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Clinic\PatientAccessLog;
use App\Domain\Clinic\PrescriberReport;
use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionFollowUps;
use App\Domain\Clinic\PrescriptionTemplates;
use Carbon\CarbonImmutable;

/**
 * The prescriber's own tools on the prescriptions page. Unlike patients, these never
 * include another prescriber's records, even for the owner-doctor.
 */
final class PrescriptionToolsSection implements WorkspaceSection
{
    public function __construct(
        private PrescriptionTemplates $templates,
        private PrescriptionFollowUps $followUps,
        private PrescriberReport $report,
        private PatientAccessLog $accessLog,
    ) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'prescriptions') {
            return [];
        }
        abort_unless(PrescriptionAccess::canPrescribe($context->user), 403);
        $timezone = (string) config('madina.business_timezone', 'Asia/Dhaka');
        $from = SectionDates::optionalDate($context->request->query('from'));
        $to = SectionDates::optionalDate($context->request->query('to'));

        return ['prescription_tools' => [
            'templates' => $this->templates->listFor($context->user),
            'follow_ups' => $this->followUps->forPrescriber($context->user),
            'access_log' => $this->accessLog->forPrescriber($context->user),
            'report' => $this->report->forPrescriber(
                $context->user,
                $from ? CarbonImmutable::createFromFormat('Y-m-d', $from, $timezone) : CarbonImmutable::now($timezone)->startOfMonth(),
                $to ? CarbonImmutable::createFromFormat('Y-m-d', $to, $timezone) : CarbonImmutable::now($timezone),
            ),
        ]];
    }
}
