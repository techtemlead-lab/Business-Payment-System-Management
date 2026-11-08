import React, { useEffect, useState } from 'react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { MapPin } from 'lucide-react';
import Shell from '../components/Shell';
import { Empty, Pagination, Row, date } from '../components/UI';
import { useT } from '../shared/i18n/t';

type Option = { id: string; name: string };
type Provider = { key: string; label: string; levels: string[]; required: string[]; can_fetch: boolean; option_count: number; fetched_at: string | null };

/** One level of the courier's own areas: a number and name, with a pick list when one was fetched. */
function LevelFields({ level, required, value, name, options, onChange }: {
    level: string; required: boolean; value: string; name: string; options: Option[]; onChange: (id: string, name: string) => void;
}) {
    const translate = useT();
    const label = translate('courier_areas', `level_${level}_label`);
    const suffix = required ? '' : ` ${translate('courier_areas', 'optional')}`;

    return (
        <>
            {options.length > 0 && (
                <label>{label} — {translate('courier_areas', 'pick_from_list')}
                    <select value={value} onChange={event => onChange(event.target.value, options.find(option => option.id === event.target.value)?.name ?? '')}>
                        <option value="">{translate('portal', 'select_placeholder')}</option>
                        {options.map(option => <option key={option.id} value={option.id}>{option.name}</option>)}
                    </select>
                </label>
            )}
            <label>{translate('courier_areas', 'field_id', { level: label })}{suffix}
                <input value={value} required={required} maxLength={40} onChange={event => onChange(event.target.value, name)} />
            </label>
            <label>{translate('courier_areas', 'field_name', { level: label })} {translate('courier_areas', 'optional')}
                <input value={name} maxLength={150} onChange={event => onChange(value, event.target.value)} />
            </label>
        </>
    );
}

function MapForm({ place, provider, firstLevelOptions, onClose }: { place: Row; provider: Provider; firstLevelOptions: Option[]; onClose: () => void }) {
    const translate = useT();
    const mapping: Row = place.mapping ?? {};
    const form = useForm<Row>({
        provider: provider.key, place_code_id: place.id,
        city_id: mapping.city_id ?? '', city_name: mapping.city_name ?? '',
        zone_id: mapping.zone_id ?? '', zone_name: mapping.zone_name ?? '',
        area_id: mapping.area_id ?? '', area_name: mapping.area_name ?? '',
    });
    const [lists, setLists] = useState<Record<string, Option[]>>({ [provider.levels[0]]: firstLevelOptions });

    /** Loads the level below once the level above is chosen. */
    useEffect(() => {
        provider.levels.forEach((level, index) => {
            const parent = index > 0 ? String(form.data[`${provider.levels[index - 1]}_id`] ?? '') : '';
            if (index === 0 || parent === '') {
                return;
            }
            const params = new URLSearchParams({ provider: provider.key, level, parent });
            fetch(`/operations/courier-areas/options?${params}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(response => response.json())
                .then(json => setLists(current => ({ ...current, [level]: json?.data ?? [] })))
                .catch(() => setLists(current => ({ ...current, [level]: [] })));
        });
    }, [provider.key, form.data.city_id, form.data.zone_id]);

    return (
        <form className="action-form ops-mt-3" onSubmit={event => { event.preventDefault(); form.post('/commands/operations/courier-areas/mappings', { preserveScroll: true, onSuccess: onClose }); }}>
            {provider.levels.map(level => (
                <LevelFields
                    key={level}
                    level={level}
                    required={provider.required.includes(level)}
                    value={String(form.data[`${level}_id`] ?? '')}
                    name={String(form.data[`${level}_name`] ?? '')}
                    options={lists[level] ?? []}
                    onChange={(id, name) => form.setData({ ...form.data, [`${level}_id`]: id, [`${level}_name`]: name })}
                />
            ))}
            {Object.keys(form.errors).length > 0 && <div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
            <div className="ops-row is-wrap">
                <button className="button dark small" disabled={form.processing}>{translate('courier_areas', 'save_button')}</button>
                <button type="button" className="button small" onClick={onClose}>{translate('courier_areas', 'cancel_button')}</button>
                {place.mapping && (
                    <button type="button" className="button small" disabled={form.processing} onClick={() => {
                        if (window.confirm(translate('courier_areas', 'remove_confirm'))) {
                            router.post('/commands/operations/courier-areas/mappings/remove', { provider: provider.key, place_code_id: place.id }, { preserveScroll: true, onSuccess: onClose });
                        }
                    }}>{translate('courier_areas', 'remove_button')}</button>
                )}
            </div>
        </form>
    );
}

export default function CourierAreas({ provider: providerKey, providers, filters, summary, firstLevelOptions, places }: {
    provider: string; providers: Provider[]; filters: { view: string; level: string; q: string };
    summary: { in_use: number; unmapped: number }; firstLevelOptions: Option[]; places: Row;
}) {
    const translate = useT();
    const { flash, errors } = usePage<any>().props;
    const provider = providers.find(item => item.key === providerKey) ?? providers[0];
    const rows: Row[] = places.data ?? [];
    const [openId, setOpenId] = useState<number | null>(null);
    const visit = (next: Row) => router.get('/operations/courier-areas', Object.fromEntries(Object.entries({ provider: providerKey, ...filters, ...next }).filter(([, value]) => value)), { preserveState: true });

    return (
        <Shell section="service-integrations">
            <Head title={translate('courier_areas', 'page_title')} />
            <div className="import-workspace">
                <div className="section-header">
                    <div>
                        <h2>{translate('courier_areas', 'page_title')}</h2>
                        <p className="muted">{translate('courier_areas', 'page_hint')}</p>
                        <Link className="text-link" href="/operations/service-integrations">{translate('courier_areas', 'back_to_integrations')}</Link>
                    </div>
                    <MapPin size={30} aria-hidden="true" />
                </div>
                {flash?.success && <div className="notice" role="status">{flash.success}</div>}
                {errors?.provider && <div role="alert" className="error">{String(errors.provider)}</div>}

                <section className="panel">
                    <div className="ops-row is-wrap ops-mb-3">
                        {providers.map(item => (
                            <button key={item.key} type="button" className={`button small ${item.key === providerKey ? 'dark' : ''}`} onClick={() => visit({ provider: item.key })}>{item.label}</button>
                        ))}
                    </div>
                    {provider.can_fetch
                        ? <button type="button" className="button" onClick={() => router.post('/commands/operations/courier-areas/fetch', { provider: provider.key }, { preserveScroll: true })}>{translate('courier_areas', 'fetch_button')}</button>
                        : <p className="muted">{translate('courier_areas', 'fetch_needs_keys')}</p>}
                    <p className="muted ops-mt-3">
                        {provider.option_count > 0 && provider.fetched_at
                            ? translate('courier_areas', 'fetch_saved_count', { count: provider.option_count, date: date(provider.fetched_at) })
                            : translate('courier_areas', 'fetch_none_yet')}
                    </p>
                    <p><strong>{translate('courier_areas', 'summary', { unmapped: summary.unmapped, in_use: summary.in_use })}</strong></p>
                </section>

                <section className="panel">
                    <form className="filter-bar" onSubmit={event => {
                        event.preventDefault();
                        const fields = new FormData(event.currentTarget);
                        visit({ view: String(fields.get('view') ?? ''), level: String(fields.get('level') ?? ''), q: String(fields.get('q') ?? ''), page: undefined });
                    }}>
                        <label className="muted ops-stack is-tight ops-fs-2xs">{translate('courier_areas', 'view_label')}
                            <select name="view" defaultValue={filters.view}>
                                {['unmapped', 'in_use', 'all'].map(view => <option key={view} value={view}>{translate('courier_areas', `view_${view}`)}</option>)}
                            </select>
                        </label>
                        <label className="muted ops-stack is-tight ops-fs-2xs">{translate('courier_areas', 'level_label')}
                            <select name="level" defaultValue={filters.level}>
                                <option value="">{translate('courier_areas', 'level_any')}</option>
                                {['division', 'district', 'upazila', 'union'].map(level => <option key={level} value={level}>{translate('courier_areas', `level_${level}`)}</option>)}
                            </select>
                        </label>
                        <label className="muted ops-stack is-tight ops-fs-2xs">{translate('courier_areas', 'search_label')}
                            <input name="q" type="search" defaultValue={filters.q} maxLength={60} />
                        </label>
                        <button className="button small" type="submit">{translate('courier_areas', 'apply')}</button>
                    </form>

                    {rows.map(place => (
                        <article key={place.id} className="panel ops-mt-3">
                            <div className="panel-heading">
                                <div>
                                    <h3>{place.code}</h3>
                                    <p className="muted">{place.name} · {translate('courier_areas', `level_${place.level}`)} · {translate('courier_areas', 'col_partners')}: {place.partners}</p>
                                    {place.mapping && (
                                        <p className="muted">{provider.levels.map(level => place.mapping[`${level}_id`] ? `${translate('courier_areas', `level_${level}_label`)} ${place.mapping[`${level}_id`]}${place.mapping[`${level}_name`] ? ` (${place.mapping[`${level}_name`]})` : ''}` : null).filter(Boolean).join(' · ')}</p>
                                    )}
                                </div>
                                <div className="ops-row">
                                    <strong>{place.mapping ? translate('courier_areas', 'status_mapped') : place.inherited_from ? translate('courier_areas', 'status_inherited', { code: place.inherited_from }) : translate('courier_areas', 'status_unmapped')}</strong>
                                    <button type="button" className="button small" onClick={() => setOpenId(openId === place.id ? null : place.id)}>{translate('courier_areas', 'map_button')}</button>
                                </div>
                            </div>
                            {openId === place.id && <MapForm place={place} provider={provider} firstLevelOptions={firstLevelOptions} onClose={() => setOpenId(null)} />}
                        </article>
                    ))}
                    {!rows.length && <Empty title={translate('courier_areas', 'empty_title')} body={translate('courier_areas', 'empty_body')} />}
                    <Pagination page={places} />
                </section>
            </div>
        </Shell>
    );
}
