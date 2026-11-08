<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Clinic\PrescriptionAccess;
use App\Domain\Clinic\PrescriptionTemplates;

/**
 * The prescriber's own tools on the prescriptions page. Unlike patients, these never
 * include another prescriber's records, even for the owner-doctor.
 */
final class PrescriptionToolsSection implements WorkspaceSection
{
    public function __construct(private PrescriptionTemplates $templates) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'prescriptions') {
            return [];
        }
        abort_unless(PrescriptionAccess::canPrescribe($context->user), 403);

        return ['prescription_tools' => [
            'templates' => $this->templates->listFor($context->user),
        ]];
    }
}
