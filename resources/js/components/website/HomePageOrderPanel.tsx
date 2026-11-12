import { useEffect, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { ListOrdered, Save } from 'lucide-react';
import { useT } from '../../shared/i18n/t';
import { FeaturedOrderList, type FeaturedOrderProduct } from './FeaturedOrderList';

/**
 * The numbered list of home page products with its own Save button. The same panel sits on the
 * product page's "home page list" tab and on the website screen, so the order is found from either.
 */
export function HomePageOrderPanel({
    saved,
    candidates,
    otherListInUse = false,
}: {
    /** The list as the server has it, first place first. */
    saved: FeaturedOrderProduct[];
    /** Products on sale that can be added to the list. */
    candidates: (FeaturedOrderProduct & { category?: string })[];
    /** True while the home page shows the separate hand-picked list, so this order is not used. */
    otherListInUse?: boolean;
}) {
    const translate = useT();
    const { auth, staffActions, errors } = usePage<any>().props;
    const canEdit = auth.capabilities.includes('catalog') && (staffActions?.catalog?.edit ?? true);

    // The list follows the server only when the saved list itself changes,
    // so a search or an unrelated save never wipes changes that are not saved yet.
    const [order, setOrder] = useState<FeaturedOrderProduct[]>(saved);
    const [saving, setSaving] = useState(false);
    const savedIds = saved.map(product => product.id).join(',');
    const changed = order.map(product => product.id).join(',') !== savedIds;
    useEffect(() => setOrder(saved), [savedIds]);

    const save = () => {
        router.post('/commands/products/featured-order', { product_ids: order.map(product => product.id) }, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <section id="home-page-order" className="ed-card is-soft is-lg ed-mb-lg">
            <div className="ed-head">
                <h3 className="ed-title is-flush ed-row">
                    <ListOrdered size={20} className="icon-ok" /> {translate('portal', 'sce_home_order_title')}
                </h3>
                {canEdit && (
                    <button type="button" className="button dark" disabled={!changed || saving} onClick={save}>
                        <Save size={16} />
                        {saving ? translate('portal', 'cms_saving') : translate('portal', 'sce_home_order_save')}
                    </button>
                )}
            </div>
            {otherListInUse && <div className="ed-strong is-sm tone-warn" role="status">{translate('portal', 'sce_home_order_not_in_use')}</div>}
            {!canEdit && <p className="ed-desc is-sm">{translate('portal', 'sce_home_order_view_only')}</p>}
            <FeaturedOrderList
                items={order}
                candidates={candidates}
                canEdit={canEdit}
                changed={changed}
                error={errors?.product_ids}
                onChange={setOrder}
            />
        </section>
    );
}
