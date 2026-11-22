import React, { useState, useMemo, useEffect } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { MapPin, Search, Filter, Building2, Compass } from 'lucide-react';
import { FormErrors, Modal, money, number, Table, Pagination, type Row } from './UI';
import { actionAllowed } from './action-permissions';
import { t, useT } from '../shared/i18n/t';
import SearchSelect from './SearchSelect';
import { useConfirm } from './ConfirmSheet';
import { FilterChips } from './FilterChips';

interface AreaAssignment {
    id: number | string;
    /** The stored seat behind this row; empty when the row is only the dealer's own place with no seat saved. */
    assignment_id?: number | null;
    /** This month's area share held for this seat's holder because the target is not met (e.g. "15000.00"). */
    held_amount?: string;
    user_id: number;
    level: 'division' | 'district' | 'upazila' | 'union';
    zone_name: string;
    place_code_id?: number | null;
    division: string | null;
    district: string | null;
    upazila: string | null;
    union: string | null;
    monthly_quota: string | number;
    is_active: boolean;
    created_at: string;
    place_code?: {
        id: number;
        code: string;
        name: string;
        level: string;
    } | null;
    user?: {
        id: number;
        name: string;
        dealer_code: string;
        phone: string;
        district: string;
        division: string;
    };
}

interface DealerOption {
    id: number;
    name: string;
    dealer_code: string;
    district: string;
    division: string;
}

interface PlaceCodeOption {
    id: number;
    code: string;
    name: string;
    level: 'division' | 'district' | 'upazila' | 'union';
    parent_id: number | null;
}

interface AreaDealersHubProps {
    /** One server page of dealer seats. */
    assignments?: (Row & { data: AreaAssignment[]; total: number }) | null;
    /** Count, quota and per-division figures over every dealer, not just this page. */
    summary?: { total: number; total_quota: string; divisions: { division: string; count: number; total_quota: string }[] };
    areaFilters?: { division?: string; level?: string; q?: string };
    /** The area dealer fund percentage from the commission rules. */
    areaPoolRate?: string;
    /** Seat texts sent by the server, with the amounts left as :name for this page to fill. */
    areaText?: Record<string, string>;
    divisions: string[];
    dealers_list: DealerOption[];
    place_codes?: PlaceCodeOption[];
}

const levelMeta: Record<string, { labelKey: string; tone: string; defaultQuota: number }> = {
    division: { labelKey: 'area_lvl_division', tone: 'tone-info', defaultQuota: 50000 },
    district: { labelKey: 'area_lvl_district', tone: 'tone-ok', defaultQuota: 40000 },
    upazila: { labelKey: 'area_lvl_upazila', tone: 'tone-violet', defaultQuota: 20000 },
    union: { labelKey: 'area_lvl_union', tone: 'tone-warn', defaultQuota: 10000 },
};

/** One searchable list: there can be thousands of dealers or places. */
function FindAndPick({ title, options, value, onChange, disabled, error }: {
    title: string;
    options: { value: string; label: string }[];
    value: string;
    onChange: (value: string) => void;
    disabled?: boolean;
    error?: string;
}) {
    const translate = useT();

    return (
        <label>
            {title}
            <SearchSelect ariaLabel={title} required disabled={disabled} value={value} onChange={(picked) => onChange(picked)} emptyLabel={translate('portal', 'select_placeholder')} options={options} />
            {error && <span className="field-error" role="alert">{error}</span>}
        </label>
    );
}

/** Changes only the monthly target of one seat. The server checks the amount again. */
function EditAreaTargetDialog({ seat, text, onClose }: { seat: AreaAssignment; text: Record<string, string>; onClose: () => void }) {
    const translate = useT();
    const title = t({ seat: text }, 'seat', 'area_seat_edit');
    const form = useForm({ monthly_quota: String(seat.monthly_quota) });

    return (
        <Modal title={title} onClose={onClose}>
            <form className="action-form" aria-label={title} onSubmit={(event) => { event.preventDefault(); form.post(`/commands/area-dealers/${seat.assignment_id}`, { preserveScroll: true, onSuccess: onClose }); }}>
                <div className="ops-bold">{seat.zone_name}</div>
                <label>
                    {translate('office_partners', 'area_assign_quota')}
                    <input type="number" min={0} step="0.01" required autoFocus value={form.data.monthly_quota} onFocus={(event) => event.target.select()} onChange={(event) => form.setData('monthly_quota', event.target.value)} />
                    <span className="ops-meta">{t({ seat: text }, 'seat', 'area_seat_target_hint', { amount: money(form.data.monthly_quota, { symbol: false }) })}</span>
                    {form.errors.monthly_quota && <span className="field-error" role="alert">{form.errors.monthly_quota}</span>}
                </label>
                <FormErrors errors={form.errors} besideFields={['monthly_quota']} />
                <div className="ops-row is-end is-loose">
                    <button type="button" className="button" disabled={form.processing} onClick={onClose}>{translate('portal', 'emp_cancel')}</button>
                    <button className="button dark" disabled={form.processing}>{form.processing ? translate('portal', 'saving') : t({ seat: text }, 'seat', 'area_seat_save')}</button>
                </div>
            </form>
        </Modal>
    );
}

/** Gives an active dealer one free place. The server repeats every check, including one dealer per place. */
function AddAreaDealerDialog({ dealers, places, text, onClose }: { dealers: DealerOption[]; places: PlaceCodeOption[]; text: Record<string, string>; onClose: () => void }) {
    const translate = useT();
    const title = translate('office_partners', 'area_assign');
    const form = useForm({ user_id: '', level: '', place_code_id: '', monthly_quota: '' });
    const dealerOptions = useMemo(() => dealers.map((dealer) => ({ value: String(dealer.id), label: [dealer.name, dealer.dealer_code, dealer.district].filter(Boolean).join(' · ') })), [dealers]);
    const placeOptions = useMemo(() => places.filter((place) => place.level === form.data.level).map((place) => ({ value: String(place.id), label: `${place.code} · ${place.name}` })), [places, form.data.level]);
    const changeLevel = (level: string) => form.setData((current) => ({ ...current, level, place_code_id: '', monthly_quota: level ? String(levelMeta[level].defaultQuota) : '' }));

    return (
        <Modal title={title} onClose={onClose}>
            <form className="action-form" aria-label={title} onSubmit={(event) => { event.preventDefault(); form.post('/commands/area-dealers', { preserveScroll: true, onSuccess: onClose }); }}>
                <FindAndPick
                    title={translate('office_partners', 'area_assign_dealer')}
                    options={dealerOptions}
                    value={form.data.user_id}
                    onChange={(value) => form.setData('user_id', value)}
                    error={form.errors.user_id}
                />
                <label>
                    {translate('office_partners', 'area_assign_level')}
                    <select required value={form.data.level} onChange={(event) => changeLevel(event.target.value)}>
                        <option value="">{translate('portal', 'select_placeholder')}</option>
                        {Object.entries(levelMeta).map(([level, meta]) => <option key={level} value={level}>{translate('portal', meta.labelKey)}</option>)}
                    </select>
                    {form.errors.level && <span className="field-error" role="alert">{form.errors.level}</span>}
                </label>
                <FindAndPick
                    key={form.data.level}
                    title={translate('office_partners', 'area_assign_place')}
                    options={placeOptions}
                    value={form.data.place_code_id}
                    onChange={(value) => form.setData('place_code_id', value)}
                    disabled={form.data.level === ''}
                    error={form.errors.place_code_id}
                />
                <label>
                    {translate('office_partners', 'area_assign_quota')}
                    <input type="number" min={0} step="0.01" required value={form.data.monthly_quota} onFocus={(event) => event.target.select()} onChange={(event) => form.setData('monthly_quota', event.target.value)} />
                    {form.data.monthly_quota !== '' && <span className="ops-meta">{t({ seat: text }, 'seat', 'area_seat_target_hint', { amount: money(form.data.monthly_quota, { symbol: false }) })}</span>}
                    {form.errors.monthly_quota && <span className="field-error" role="alert">{form.errors.monthly_quota}</span>}
                </label>
                <FormErrors errors={form.errors} besideFields={['user_id', 'level', 'place_code_id', 'monthly_quota']} />
                <div className="ops-row is-end is-loose">
                    <button type="button" className="button" disabled={form.processing} onClick={onClose}>{translate('portal', 'emp_cancel')}</button>
                    <button className="button dark" disabled={form.processing}>{form.processing ? translate('portal', 'saving') : title}</button>
                </div>
            </form>
        </Modal>
    );
}

export default function AreaDealersHub({ assignments: page, summary, areaFilters = {}, areaPoolRate, areaText = {}, divisions, dealers_list, place_codes = [] }: AreaDealersHubProps) {
    const translate = useT();
    const { url } = usePage();
    const { staffActions } = usePage<any>().props;
    const [assigning, setAssigning] = useState(false);
    const [editing, setEditing] = useState<AreaAssignment | null>(null);
    const canAssign = actionAllowed('/commands/area-dealers', staffActions);
    const seatText = (key: string, replace: Record<string, string> = {}) => t({ seat: areaText }, 'seat', key, replace);
    const { confirm, sheet } = useConfirm();
    const closeSeat = async (seat: AreaAssignment) => {
        if (!(await confirm({ title: translate('payouts', 'cs_check_title'), sentence: seatText('area_seat_close_confirm', { zone: seat.zone_name }), tone: 'danger', confirmLabel: seatText('area_seat_close') }))) {
            return;
        }
        router.delete(`/commands/area-dealers/${seat.assignment_id}`, { preserveScroll: true });
    };
    // The table is one server page (25 rows); the division cards and totals come from the server over every dealer.
    const assignments: AreaAssignment[] = page?.data ?? [];
    const selectedDivision = areaFilters.division || 'all';
    const selectedLevel = areaFilters.level || 'all';
    const [searchQuery, setSearchQuery] = useState(areaFilters.q ?? '');
    useEffect(() => setSearchQuery(areaFilters.q ?? ''), [areaFilters.q]);

    const reload = (next: { area_division?: string; level?: string; q?: string }) => {
        const query = { area_division: selectedDivision === 'all' ? '' : selectedDivision, level: selectedLevel === 'all' ? '' : selectedLevel, q: searchQuery.trim(), ...next };
        const params = Object.fromEntries(Object.entries(query).filter(([, value]) => value && value !== 'all'));
        router.get(new URL(url || '/', 'http://list.local').pathname, params, { preserveState: true, preserveScroll: true, replace: true, only: ['data', 'filters'] });
    };
    const setSelectedDivision = (division: string) => reload({ area_division: division });
    const setSelectedLevel = (level: string) => reload({ level });

    const divisionStats = useMemo(() => {
        const stats: Record<string, { count: number; totalQuota: string }> = {};
        divisions.forEach(d => { stats[d] = { count: 0, totalQuota: '0.00' }; });
        (summary?.divisions ?? []).forEach(row => { stats[row.division] = { count: row.count, totalQuota: row.total_quota }; });
        return stats;
    }, [summary, divisions]);

    const totalCount = summary?.total ?? page?.total ?? 0;
    const totalActiveQuota = summary?.total_quota ?? '0.00';
    const levelLabel = (level: string) => translate('portal', levelMeta[level]?.labelKey ?? 'area_lvl_district');

    return (
        <div className="hub">
            {sheet}
            <div className="hub-grid">
                <div className="hub-tile">
                    <div className="hub-label ops-row">
                        <MapPin size={16} /> {translate('portal', 'area_kpi_total')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        {number(totalCount)}
                    </div>
                    <div className="hub-hint">{translate('portal', 'area_kpi_total_hint')}</div>
                </div>

                <div className="hub-tile is-accent tone-ok">
                    <div className="hub-label ops-row">
                        <Building2 size={16} /> {translate('portal', 'area_kpi_quota')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        {money(totalActiveQuota)}
                    </div>
                    <div className="hub-hint">{translate('portal', 'area_kpi_quota_hint')}</div>
                </div>

                <div className="hub-tile is-accent tone-info">
                    <div className="hub-label ops-row">
                        <Compass size={16} /> {translate('portal', 'area_kpi_pool')}
                    </div>
                    <div className="ops-figure ops-mt-1">
                        {areaPoolRate ? translate('portal', 'area_kpi_pool_value', { rate: areaPoolRate }) : '—'}
                    </div>
                    <div className="hub-hint">{translate('portal', 'area_kpi_pool_hint')}</div>
                </div>
            </div>

            <div className="ops-card ops-stack">
                <div className="hub-label">
                    {translate('portal', 'area_regional')}
                </div>
                <div className="ops-row is-wrap">
                    <button
                        type="button"
                        onClick={() => setSelectedDivision('all')}
                        className={`adh-division is-all${selectedDivision === 'all' ? ' is-selected' : ''}`}
                    >
                        {translate('portal', 'area_all_divisions', { count: String(totalCount) })}
                    </button>
                    {divisions.map(div => {
                        const st = divisionStats[div] || { count: 0, totalQuota: '0.00' };
                        const isSelected = selectedDivision === div;
                        return (
                            <button
                                key={div}
                                type="button"
                                onClick={() => setSelectedDivision(div)}
                                className={`adh-division${isSelected ? ' is-selected' : ''}`}
                            >
                                <span>{div}</span>
                                <span className={`adh-division-count${st.count > 0 ? ' tone-ok' : ''}`}>
                                    {number(st.count)}
                                </span>
                            </button>
                        );
                    })}
                </div>
            </div>

            <div className="ops-card is-tight ops-row is-between is-wrap is-loose">
                <div className="ops-row is-wrap is-loose">
                    <form className="adh-search" role="search" onSubmit={e => { e.preventDefault(); reload({ q: searchQuery.trim() }); }}>
                        <Search size={16} />
                        <input
                            type="search"
                            maxLength={100}
                            aria-label={translate('portal', 'area_search_ph')}
                            placeholder={translate('portal', 'area_search_ph')}
                            value={searchQuery}
                            onChange={e => setSearchQuery(e.target.value)}
                        />
                    </form>

                    <div className="ops-row is-snug">
                        <Filter size={15} className="ops-muted" />
                        <FilterChips
                            label={translate('portal', 'area_all_tiers')}
                            value={selectedLevel}
                            onChange={setSelectedLevel}
                            options={[
                                { value: 'all', label: translate('portal', 'area_all_tiers') },
                                { value: 'division', label: translate('portal', 'area_tier_division') },
                                { value: 'district', label: translate('portal', 'area_tier_district') },
                                { value: 'upazila', label: translate('portal', 'area_tier_upazila') },
                                { value: 'union', label: translate('portal', 'area_tier_union') },
                            ]}
                        />
                    </div>
                </div>

                <div className="ops-row is-wrap">
                    <Link
                        href="/accounts/create?intent=dealer"
                        className="button"
                    >
                        {translate('portal', 'area_add_dealer')}
                    </Link>
                    {canAssign && (
                        <button type="button" className="button dark" onClick={() => setAssigning(true)}>
                            {translate('office_partners', 'area_assign')}
                        </button>
                    )}
                </div>
            </div>

            <div className="ops-card adh-table">
                <Table
                    tools={false}
                    rows={assignments}
                    totalCount={page?.total}
                    empty={translate('portal', 'area_empty')}
                    columns={[
                        {
                            key: 'zone_name',
                            title: translate('portal', 'area_col_zone'),
                            sortable: true,
                            render: (a: AreaAssignment) => (
                                <div>
                                    <div className="ops-bold ops-text">{a.zone_name}</div>
                                    {a.place_code?.code && (
                                        <div className="adh-code tone-teal">
                                            {translate('portal', 'area_code', { code: a.place_code.code })}
                                        </div>
                                    )}
                                    <div className="ops-meta">{a.place_code ? translate('portal', 'area_holds_code') : translate('portal', 'area_no_place')}</div>
                                </div>
                            ),
                        },
                        {
                            key: 'dealer',
                            title: translate('portal', 'area_col_dealer'),
                            sortable: true,
                            sortValue: (a: AreaAssignment) => a.user?.name || '',
                            render: (a: AreaAssignment) => a.user ? (
                                <div>
                                    <div className="hub-strong">{a.user.name}</div>
                                    <div className="ops-meta">
                                        {a.user.dealer_code} {a.user.phone ? `• ${a.user.phone}` : ''}
                                    </div>
                                </div>
                            ) : (
                                <span className="ops-faint">{translate('portal', 'area_unassigned')}</span>
                            ),
                        },
                        {
                            key: 'level',
                            title: translate('portal', 'area_col_tier'),
                            sortable: true,
                            render: (a: AreaAssignment) => {
                                const levelConfig = levelMeta[a.level] || levelMeta.district;
                                return a.place_code ? (
                                    <span className={`adh-chip ${levelConfig.tone}`}>
                                        {levelLabel(a.level)}
                                    </span>
                                ) : '—';
                            },
                        },
                        {
                            key: 'location',
                            title: translate('portal', 'area_col_location'),
                            sortable: true,
                            sortValue: (a: AreaAssignment) => [a.division, a.district, a.upazila].filter(Boolean).join(' '),
                            render: (a: AreaAssignment) => (
                                <div>
                                    <div>{a.division || '—'}</div>
                                    {a.district && <div className="ops-meta">{translate('portal', 'area_district', { name: a.district })}</div>}
                                    {a.upazila && <div className="ops-meta">{translate('portal', 'area_upazila', { name: a.upazila })}</div>}
                                </div>
                            ),
                        },
                        {
                            key: 'monthly_quota',
                            title: translate('portal', 'area_col_quota'),
                            align: 'right',
                            sortable: true,
                            sortValue: (a: AreaAssignment) => Number(a.monthly_quota) || 0,
                            render: (a: AreaAssignment) => (
                                <div>
                                    <b className="adh-quota">
                                        {money(a.monthly_quota)}
                                    </b>
                                    {Number(a.held_amount ?? 0) > 0 && (
                                        <div className="ops-meta tone-warn">{seatText('area_seat_held_quota', { amount: money(a.held_amount, { symbol: false }) })}</div>
                                    )}
                                </div>
                            ),
                        },
                        {
                            key: 'is_active',
                            title: translate('portal', 'area_col_status'),
                            sortable: true,
                            render: (a: AreaAssignment) => (
                                <span className={`adh-chip is-sm ${a.is_active ? 'tone-ok' : 'tone-danger'}`}>
                                    {a.place_code ? translate('portal', 'area_active') : translate('portal', 'area_no_place')}
                                </span>
                            ),
                        },
                        ...(canAssign ? [{
                            key: 'seat_actions',
                            title: '',
                            render: (a: AreaAssignment) => a.assignment_id ? (
                                <div className="ops-row is-snug is-wrap">
                                    <button type="button" className="button small" onClick={() => setEditing(a)}>{seatText('area_seat_edit')}</button>
                                    <button type="button" className="button small" onClick={() => closeSeat(a)}>{seatText('area_seat_close')}</button>
                                </div>
                            ) : null,
                        }] : []),
                    ]}
                />
                <Pagination page={page ?? undefined} />
            </div>

            {assigning && <AddAreaDealerDialog dealers={dealers_list} places={place_codes} text={areaText} onClose={() => setAssigning(false)} />}
            {editing && <EditAreaTargetDialog seat={editing} text={areaText} onClose={() => setEditing(null)} />}
        </div>
    );
}
