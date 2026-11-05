<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Content\TrainingLibrary;
use App\Models\TrainingItem;

/**
 * The training library: an editor for office staff, and a topic-grouped
 * reading list for dealers and sub-dealers.
 */
final class TrainingSection implements WorkspaceSection
{
    public function __construct(private TrainingLibrary $library) {}

    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'training') {
            return [];
        }
        $user = $context->user;

        if ($context->isStaff) {
            abort_unless($this->library->canManage($user), 403);

            return ['training' => [
                'mode' => 'staff',
                'can_edit' => $user->owner() || $user->canAct('content', 'edit'),
                'can_create' => $user->owner() || $user->canAct('content', 'create'),
                'items' => $this->library->staffItems(),
                'topics' => TrainingItem::Topics,
                'types' => TrainingItem::Types,
                'audiences' => TrainingItem::Audiences,
                'pdf_max_mb' => 20,
                'new_for_days' => TrainingItem::NewForDays,
            ]];
        }

        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);

        return ['training' => [
            'mode' => 'partner',
            'groups' => $this->library->partnerGroups($user),
            'new_for_days' => TrainingItem::NewForDays,
        ]];
    }
}
