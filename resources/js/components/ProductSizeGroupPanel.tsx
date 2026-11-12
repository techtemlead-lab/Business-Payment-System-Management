import { useEffect, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { useT } from '../shared/i18n/t';
import { ProductStatusBadge } from './ProductDrawer';

interface GroupSize {
    id: number;
    name: string;
    sku: string;
    label: string;
    active: boolean;
    archived: boolean;
    is_stock_paused: boolean;
    current: boolean;
}

interface Candidate {
    id: number;
    name: string;
    sku: string;
    label: string | null;
    in_group: boolean;
}

/** The other sizes of this product, and adding or taking out a size. */
export default function ProductSizeGroupPanel({ productId, canEdit }: { productId: number; canEdit: boolean }) {
    const translate = useT();
    const t = (key: string) => translate('size_groups', key);
    const sizes: GroupSize[] = usePage<any>().props.size_group?.sizes ?? [];
    const [search, setSearch] = useState('');
    const [candidates, setCandidates] = useState<Candidate[] | null>(null);
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        const term = search.trim();
        if (!canEdit || term.length < 2) {
            setCandidates(null);
            return undefined;
        }
        const request = new AbortController();
        const timer = window.setTimeout(() => {
            fetch(`/commands/products/${productId}/size-group/candidates?${new URLSearchParams({ q: term })}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: request.signal,
            })
                .then(response => (response.ok ? response.json() : { items: [] }))
                .then(body => setCandidates(body.items ?? []))
                .catch(() => undefined);
        }, 250);
        return () => {
            window.clearTimeout(timer);
            request.abort();
        };
    }, [search, canEdit, productId, sizes.length]);

    const whileBusy = { preserveScroll: true, onStart: () => setBusy(true), onFinish: () => setBusy(false) };
    const add = (otherId: number) => router.post(`/commands/products/${productId}/size-group`, { with_product_id: otherId }, { ...whileBusy, onSuccess: () => setSearch('') });
    const remove = (sizeId: number) => router.delete(`/commands/products/${sizeId}/size-group`, whileBusy);

    return (
        <section className="panel" data-testid="product-size-group">
            <h2>{t('panel_title')}</h2>
            {sizes.length === 0 ? <p className="cell-sub">{t('no_sizes')}</p> : (
                <ul className="os-sizes">
                    {sizes.map(size => (
                        <li key={size.id}>
                            <span className="os-size-who">
                                <b>{size.label}</b>
                                <small className="cell-sub">{size.current ? t('this_product') : size.name} · {size.sku}</small>
                            </span>
                            <ProductStatusBadge product={size} />
                            {canEdit && (size.current
                                ? <a className="button small" href="#pack-size">{t('change_size')}</a>
                                : <Link className="button small" href={`/products/${size.id}#pack-size`}>{t('change_size')}</Link>)}
                            {canEdit && <button type="button" className="button small" disabled={busy} onClick={() => remove(size.id)}>{t('remove')}</button>}
                        </li>
                    ))}
                </ul>
            )}
            {canEdit && (
                <label>
                    {t('add_size_label')}
                    <input
                        type="search"
                        value={search}
                        placeholder={t('search_placeholder')}
                        maxLength={100}
                        onChange={event => setSearch(event.target.value)}
                        onKeyDown={event => { if (event.key === 'Enter') event.preventDefault(); }}
                    />
                </label>
            )}
            {candidates !== null && candidates.length === 0 && <p className="cell-sub">{t('no_matches')}</p>}
            {candidates !== null && candidates.length > 0 && (
                <ul className="os-sizes">
                    {candidates.map(candidate => (
                        <li key={candidate.id}>
                            <span className="os-size-who">
                                <b>{candidate.name}</b>
                                <small className="cell-sub">{candidate.label ?? t('no_size_written')} · {candidate.sku}{candidate.in_group ? ` · ${t('has_other_sizes')}` : ''}</small>
                            </span>
                            <button type="button" className="button small" disabled={busy} onClick={() => add(candidate.id)}>{t('add')}</button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
