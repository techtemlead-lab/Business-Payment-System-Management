import React, { useEffect, useState } from 'react';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import Shell from '../components/Shell';
import OfficePage from '../components/office/OfficePage';
import { settingsBack } from '../components/office/sections/SettingsForm';
import { Modal, Pagination, Row, date } from '../components/UI';
import { useT } from '../shared/i18n/t';
import SearchSelect from '../components/SearchSelect';

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
                    <SearchSelect
                        value={value}
                        onChange={picked => onChange(picked, options.find(option => option.id === picked)?.name ?? '')}
                        emptyLabel={translate('portal', 'select_placeholder')}
                        options={options.map(option => ({ value: option.id, label: option.name }))}
                    />
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
        <form className="action-form" onSubmit={event => { event.preventDefault(); form.post('/commands/operations/courier-areas/mappings', { preserveScroll: true, onSuccess: onClose }); }}>
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
    const { errors } = usePage<any>().props;
    const provider = providers.find(item => item.key === providerKey) ?? providers[0];
    const rows: Row[] = places.data ?? [];
    const [openPlace, setOpenPlace] = useState<Row | null>(null);
    const visit = (next: Row) => router.get('/operations/courier-areas', Object.fromEntries(Object.entries({ provider: providerKey, ...filters, ...next }).filter(([, value]) => value)), { preserveState: true });
    const hub = {
        home: 'service-integrations',
        label: translate('courier_areas', 'page_title'),
        tabs: providers.map(item => ({
            key: item.key,
            label: item.label,
            href: `/operations/courier-areas?${new URLSearchParams(Object.entries({ ...filters, provider: item.key }).filter(([, value]) => Boolean(value))).toString()}`,
            active: item.key === providerKey,
        })),
    };
    const stateText = (place: Row) => place.mapping
        ? translate('courier_areas', 'status_mapped')
        : place.inherited_from ? translate('courier_areas', 'status_inherited', { code: place.inherited_from }) : translate('courier_areas', 'status_unmapped');
    const mappingText = (place: Row) => provider.levels
        .map(level => place.mapping[`${level}_id`] ? `${translate('courier_areas', `level_${level}_label`)} ${place.mapping[`${level}_id`]}${place.mapping[`${level}_name`] ? ` (${place.mapping[`${level}_name`]})` : ''}` : null)
        .filter(Boolean).join(' · ');

    return (
        <Shell section="service-integrations">
            <Head title={translate('courier_areas', 'page_title')} />
            <OfficePage
                title={translate('courier_areas', 'page_title')}
                back={settingsBack(translate)}
                hub={hub}
                action={provider.can_fetch && <button type="button" className="button" onClick={() => router.post('/commands/operations/courier-areas/fetch', { provider: provider.key }, { preserveScroll: true })}>{translate('courier_areas', 'fetch_button')}</button>}
            >
                {errors?.provider && <div role="alert" className="error">{String(errors.provider)}</div>}
                {!provider.can_fetch && <p className="set-line">{translate('courier_areas', 'fetch_needs_keys')}</p>}
                <p className="set-line">
                    <strong>{translate('courier_areas', 'summary', { unmapped: String(summary.unmapped), in_use: String(summary.in_use) })}</strong>
                    {provider.option_count > 0 && provider.fetched_at && ` · ${translate('courier_areas', 'fetch_saved_count', { count: String(provider.option_count), date: date(provider.fetched_at) })}`}
                </p>
                <form className="cleanup-filters" onSubmit={event => {
                    event.preventDefault();
                    const fields = new FormData(event.currentTarget);
                    visit({ view: String(fields.get('view') ?? ''), level: String(fields.get('level') ?? ''), q: String(fields.get('q') ?? ''), page: undefined });
                }}>
                    <label>{translate('courier_areas', 'view_label')}
                        <select name="view" defaultValue={filters.view}>
                            {['unmapped', 'in_use', 'all'].map(view => <option key={view} value={view}>{translate('courier_areas', `view_${view}`)}</option>)}
                        </select>
                    </label>
                    <label>{translate('courier_areas', 'level_label')}
                        <select name="level" defaultValue={filters.level}>
                            <option value="">{translate('courier_areas', 'level_any')}</option>
                            {['division', 'district', 'upazila', 'union'].map(level => <option key={level} value={level}>{translate('courier_areas', `level_${level}`)}</option>)}
                        </select>
                    </label>
                    <label>{translate('courier_areas', 'search_label')}
                        <input name="q" type="search" defaultValue={filters.q} maxLength={60} />
                    </label>
                    <button className="button" type="submit">{translate('courier_areas', 'apply')}</button>
                </form>
                {rows.length === 0 ? (
                    <p className="set-line">{translate('courier_areas', 'empty_title')}</p>
                ) : (
                    <section className="panel table-panel">
                        <div className="table-wrap">
                            <table className="cleanup-table">
                                <thead>
                                    <tr>
                                        <th scope="col">{translate('courier_areas', 'col_place')}</th>
                                        <th scope="col">{translate('courier_areas', 'col_partners')}</th>
                                        <th scope="col">{translate('portal', 'col_status')}</th>
                                        <th scope="col"><span className="sr-only">{translate('courier_areas', 'map_button')}</span></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {rows.map(place => (
                                        <tr key={place.id}>
                                            <td><b>{place.code}</b><small className="cell-sub">{place.name} · {translate('courier_areas', `level_${place.level}`)}</small></td>
                                            <td>{place.partners}</td>
                                            <td>{stateText(place)}{place.mapping && <small className="cell-sub">{mappingText(place)}</small>}</td>
                                            <td><button type="button" className="text-link" onClick={() => setOpenPlace(place)}>{translate('courier_areas', 'map_button')}</button></td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <Pagination page={places} />
                    </section>
                )}
                {openPlace && (
                    <Modal title={`${openPlace.code} · ${openPlace.name}`} onClose={() => setOpenPlace(null)}>
                        <MapForm place={openPlace} provider={provider} firstLevelOptions={firstLevelOptions} onClose={() => setOpenPlace(null)} />
                    </Modal>
                )}
            </OfficePage>
        </Shell>
    );
}
