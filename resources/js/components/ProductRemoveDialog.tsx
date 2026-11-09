import React, { useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import { FormErrors, Modal } from './UI';
import { useT } from '../shared/i18n/t';
import { localizeDigits } from '../business-date';

export type ProductRemoval = { can_delete: boolean; reasons: string[] };

export type RemovableProduct = { id: number | string; name: string; active?: boolean };

/** The server's reasons as one sentence, with digits in the reader's language. */
export function removalReasons(removal: ProductRemoval | null | undefined): string {
    return localizeDigits((removal?.reasons ?? []).join(', '));
}

/** Bring an archived product back on sale. */
export function restoreProduct(product: RemovableProduct): void {
    router.post(`/commands/products/${product.id}/restore`, {}, { preserveScroll: true });
}

/** The one line beside the remove button: what will really happen, before anyone presses it. */
export function removalLine(removal: ProductRemoval | null | undefined, isArchived: boolean, translate: ReturnType<typeof useT>): string {
    if (!removal) {
        return '';
    }
    if (isArchived) {
        return removal.can_delete ? translate('office_stock', 'can_delete_line') : translate('office_stock', 'archived_keeps_line', { reasons: removalReasons(removal) });
    }

    return removal.can_delete ? translate('office_stock', 'can_delete_line') : translate('office_stock', 'cannot_delete_line', { reasons: removalReasons(removal) });
}

/**
 * Asks for a reason and says exactly what will happen first. A product with stock or history can
 * only be archived; an unused one is deleted for good. The server checks this again when pressed.
 */
export default function ProductRemoveDialog({ product, removal, onClose, onDone = onClose }: {
    product: RemovableProduct;
    removal: ProductRemoval;
    onClose: () => void;
    /** Runs after the server has archived or deleted the product. */
    onDone?: () => void;
}) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string>) => translate('office_stock', key, replace);
    const form = useForm({ reason: '' });
    const willDelete = removal.can_delete;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.delete(`/commands/products/${product.id}`, { onSuccess: onDone });
    };

    return (
        <Modal title={t(willDelete ? 'delete_title' : 'archive_title')} onClose={onClose}>
            <form className="os-remove" onSubmit={submit}>
                <FormErrors errors={form.errors} />
                {willDelete
                    ? <p className="os-remove-line is-danger">{t('delete_what_happens', { name: product.name })}</p>
                    : (
                        <>
                            <p className="os-remove-line is-strong">{t('archive_why', { name: product.name, reasons: removalReasons(removal) })}</p>
                            <p className="os-remove-line">{t('archive_what_happens')}</p>
                        </>
                    )}
                <label className="os-remove-reason">
                    {t('remove_reason')}
                    <textarea value={form.data.reason} rows={3} maxLength={1000} onChange={(event) => form.setData('reason', event.target.value)} />
                </label>
                <div className="os-remove-actions">
                    <button type="submit" className={willDelete ? 'button os-danger' : 'button dark'} disabled={form.processing}>{t(willDelete ? 'confirm_delete' : 'confirm_archive')}</button>
                    <button type="button" className="button" onClick={onClose}>{t('cancel')}</button>
                </div>
            </form>
        </Modal>
    );
}

/** The remove or restore block of the product editor. */
export function ProductRemovePanel({ product, removal }: { product: RemovableProduct; removal: ProductRemoval }) {
    const translate = useT();
    const t = (key: string, replace?: Record<string, string>) => translate('office_stock', key, replace);
    const [asking, setAsking] = useState(false);
    const isArchived = product.active === false;

    return (
        <section className="panel pe-danger-zone tone-danger">
            <h2 className="pe-danger-title">{t(isArchived ? 'status_archived' : (removal.can_delete ? 'delete_title' : 'archive_title'))}</h2>
            <p className="pe-danger-body">{isArchived ? t('archived_line') : t(removal.can_delete ? 'delete_what_happens' : 'archive_what_happens', { name: product.name })}</p>
            {!isArchived && !removal.can_delete && <p className="pe-danger-body"><b>{removalLine(removal, false, translate)}</b></p>}
            {isArchived && !removal.can_delete && <p className="pe-danger-body">{removalLine(removal, true, translate)}</p>}
            <div className="ops-row is-wrap">
                {isArchived && <button type="button" className="button small dark" onClick={() => restoreProduct(product)}>{t('button_restore')}</button>}
                {(!isArchived || removal.can_delete) && (
                    <button type="button" className="button small pe-danger-button" onClick={() => setAsking(true)}>
                        {t(removal.can_delete ? 'button_delete' : 'button_archive')}
                    </button>
                )}
            </div>
            {asking && <ProductRemoveDialog product={product} removal={removal} onClose={() => setAsking(false)} />}
        </section>
    );
}
