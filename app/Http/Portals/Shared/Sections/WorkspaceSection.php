<?php

namespace App\Http\Portals\Shared\Sections;

/**
 * One area of the portal workspace. The controller asks every area in a fixed
 * order and merges what each returns into the page's `data` prop.
 */
interface WorkspaceSection
{
    /**
     * Props this area adds for the requested section, or an empty array when the
     * section is not one of its own.
     *
     * @param  array<string, mixed>  $data  Props built by the areas asked before this one.
     * @return array<string, mixed>
     */
    public function data(SectionContext $context, array $data): array;
}
