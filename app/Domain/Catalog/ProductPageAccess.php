<?php

namespace App\Domain\Catalog;

use App\Models\User;

/**
 * Who may open the product page and the New Product form. The product list opens for more
 * people than these do, so the list asks here before it offers either one.
 */
final class ProductPageAccess
{
    public static function mayOpen(User $user): bool
    {
        return $user->staff() && ($user->allows('catalog') || $user->allows('inventory') || $user->allows('pricing') || $user->allows('cost_read'));
    }

    /** The form always sends prices and the opening stock, so it needs all three rights. */
    public static function mayCreate(User $user): bool
    {
        return $user->canAct('catalog', 'create') && $user->canAct('pricing', 'create') && $user->canAct('inventory', 'create');
    }
}
