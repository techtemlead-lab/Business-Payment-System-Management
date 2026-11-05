<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Accounts\PartnerAgreement;
use App\Models\User;

/**
 * The dealer agreement. Partners read it and press "I have read and agree";
 * owners edit and publish versions; staff who manage accounts see who agreed.
 */
final class AgreementSection implements WorkspaceSection
{
    public function __construct(private PartnerAgreement $agreement) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'agreement') {
            return [];
        }

        return in_array($context->user->role, PartnerAgreement::PartnerRoles, true)
            ? ['agreement' => $this->forPartner($context)]
            : ['agreement' => $this->forOffice($context->user, $context)];
    }

    /**
     * The latest version in both languages, and whether this partner agreed to it.
     *
     * @return array<string, mixed>
     */
    private function forPartner(SectionContext $context): array
    {
        $user = $context->user;
        $status = $context->request->attributes->get(PartnerAgreement::RequestAttribute) ?? $this->agreement->status($user);
        $latest = $this->agreement->latest();

        return [
            'mode' => 'partner',
            'required' => $status['required'],
            'blocking' => $this->agreement->blocks($user, $status),
            'accepted' => $status['accepted'],
            'latest' => $latest ? [
                'id' => (int) $latest->id,
                'version' => $latest->version,
                'body_bn' => $latest->body_bn,
                'body_en' => $latest->body_en,
                'published_at' => $latest->published_at?->toIso8601String(),
            ] : null,
            'accepted_versions' => $this->agreement->acceptedBy($user),
        ];
    }

    /**
     * The switch, every version, and the list of partners with what they accepted.
     *
     * @return array<string, mixed>
     */
    private function forOffice(User $user, SectionContext $context): array
    {
        abort_unless($user->owner() || $user->allows('accounts'), 403);
        $versions = $this->agreement->history();
        $search = $context->request->filled('q') ? (string) $context->request->input('q') : null;

        return [
            'mode' => 'office',
            'can_edit' => $user->owner(),
            'setting' => $this->agreement->setting(),
            'versions' => $versions,
            'summary' => $this->agreement->summary($versions[0]['id'] ?? null),
            'partners' => $this->agreement->partnerList($search),
        ];
    }
}
