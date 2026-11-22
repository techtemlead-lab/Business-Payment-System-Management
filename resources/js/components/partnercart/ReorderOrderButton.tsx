import React, { useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { RotateCcw } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { jsonRequest } from '../../shared/jsonRequest';
import { fillCartFromReorder, keepReorderNotes, type ReorderOption } from '../../shared/reorderOrder';
import { repeatChangeNotes } from '../dealertools/RepeatOrderPanel';

/**
 * "Order this again" on a row of the partner's My orders list: the same products and quantities go in the cart
 * (at today's prices; paused or removed products are skipped with a notice) and the new order page opens.
 */
export default function ReorderOrderButton({ orderId }: { orderId: string | number }) {
    const translate = useT();
    const { portalBase, auth } = usePage<any>().props;
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    const run = async (event: React.MouseEvent) => {
        // The row itself opens the order panel; this button only reorders.
        event.stopPropagation();
        setBusy(true);
        setFailed(false);
        try {
            const option = await jsonRequest<ReorderOption & { items: ReorderOption['items'] }>(`${portalBase}/orders/${orderId}/reorder`);
            const filled = fillCartFromReorder(auth?.user?.id, option);
            const notes = repeatChangeNotes(option as any, translate);
            keepReorderNotes(filled ? notes : [...notes, translate('purchase_page', 'reorder_nothing')]);
            router.visit(`${portalBase}/purchase`);
        } catch {
            setFailed(true);
            setBusy(false);
        }
    };

    return (
        <>
            <button type="button" className="button small dark ppm-reorder-btn" disabled={busy} onClick={run}>
                <RotateCcw size={15} /> {translate('purchase_page', busy ? 'reorder_working' : 'reorder_button')}
            </button>
            {failed && <small className="cell-sub" role="alert">{translate('purchase_page', 'reorder_failed')}</small>}
        </>
    );
}
