import React, { useState } from 'react';
import { Link, router } from '@inertiajs/react';
import { Modal, Row, money, number } from './UI';
import { useT } from '../shared/i18n/t';
import ProductRemoveDialog, { removalLine, restoreProduct } from './ProductRemoveDialog';

export function ProductStatusBadge({ product }: { product: Row }) {
    const translate = useT();
    const t = (key: string) => translate('office_stock', key);
    if (product.active === false) {
        return <span className="badge archived">{t('status_archived')}</span>;
    }
    if (product.is_stock_paused) {
        return <span className="badge held">{t('status_orders_stopped')}</span>;
    }

    return <span className="badge published">{t('status_published')}</span>;
}

function Fact({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div className="os-fact">
            <dt>{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

/** One product's facts and its commands: stop orders, archive, delete or restore. */
export default function ProductDrawer({ product, canStock, canEditCatalog, onClose }: {
    product: Row;
    canStock: boolean;
    canEditCatalog: boolean;
    onClose: () => void;
}) {
    const translate = useT();
    const t = (key: string) => translate('office_stock', key);
    const [asking, setAsking] = useState(false);
    const removal = product.removal;
    const isArchived = product.active === false;
    const canRemove = canEditCatalog && Boolean(removal);
    const photo = product.image_url || product.image_fallback?.url;
    const line = removalLine(removal, isArchived, translate);
    const commands = (
        <div className="os-drawer-actions">
            <Link className="button dark" href={`/products/${product.id}`}>{t('edit')}</Link>
            {!isArchived && (
                <button type="button" className="button" disabled={!canStock} onClick={() => router.post(`/commands/products/${product.id}/toggle-stock`, {}, { preserveScroll: true })}>
                    {translate('portal', product.is_stock_paused ? 'resume_ordering' : 'pause_ordering')}
                </button>
            )}
            {isArchived && canEditCatalog && <button type="button" className="button dark" onClick={() => restoreProduct({ id: product.id, name: String(product.name) })}>{t('button_restore')}</button>}
            {canRemove && (!isArchived || removal.can_delete) && (
                <button type="button" className="button" onClick={() => setAsking(true)}>{t(removal.can_delete ? 'button_delete' : 'button_archive')}</button>
            )}
        </div>
    );

    return (
        <>
            <Modal title={String(product.name)} onClose={onClose} variant="drawer" badge={<ProductStatusBadge product={product} />} footer={commands}>
                <div className="os-drawer">
                    {photo && <img className="os-drawer-photo" src={photo} alt={translate('office_stock', 'photo_alt', { name: String(product.name) })} loading="lazy" />}
                    <dl className="os-facts">
                        <Fact label={t('sku')}>{product.sku || '—'}</Fact>
                        <Fact label={t('category')}>{[product.category, product.sub_category].filter(Boolean).join(' › ') || '—'}</Fact>
                        <Fact label={t('unit')}>{product.unit || '—'}</Fact>
                        <Fact label={t('mrp')}>{money(product.mrp)}</Fact>
                        {product.dealer_price !== undefined && <Fact label={t('dealer_price')}>{product.dealer_price != null ? money(product.dealer_price) : '—'}</Fact>}
                        {product.sub_dealer_price !== undefined && <Fact label={t('sub_dealer_price')}>{product.sub_dealer_price != null ? money(product.sub_dealer_price) : '—'}</Fact>}
                        {product.cost_price !== undefined && product.cost_price !== null && <Fact label={t('cost_price')}>{money(product.cost_price)}</Fact>}
                        {product.stock !== undefined && <Fact label={t('stock_now')}>{number(product.stock)}</Fact>}
                        {Number(product.on_hold ?? 0) > 0 && <Fact label={t('stock_on_hold')}>{number(product.on_hold)}</Fact>}
                        {Number(product.with_courier ?? 0) > 0 && <Fact label={t('stock_with_courier')}>{number(product.with_courier)}</Fact>}
                    </dl>
                    {isArchived && <p className="os-remove-line is-strong">{t('archived_line')}</p>}
                    {line && canRemove && <p className="os-remove-line">{line}</p>}
                </div>
            </Modal>
            {asking && removal && (
                <ProductRemoveDialog
                    product={{ id: product.id, name: String(product.name), active: !isArchived }}
                    removal={removal}
                    onClose={() => setAsking(false)}
                />
            )}
        </>
    );
}
