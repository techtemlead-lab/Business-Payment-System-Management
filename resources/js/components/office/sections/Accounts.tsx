import { useMemo, useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Download, Plus } from 'lucide-react';
import { useT } from '../../../shared/i18n/t';
import AccountEditor from '../../AccountEditor';
import MoneyTable, { type MoneyColumn, type MoneyTableData } from '../../MoneyTable';
import { SavedFilterPanel } from '../../OfficeListTools';
import { Badge, Modal, date, label, money, number, type Row } from '../../UI';
import MoreMenu from '../MoreMenu';
import PartnerFrame from './PartnerFrame';

const FILE_FILTER_KEYS = ['division', 'district', 'rank', 'no_order_days'];
const BRANCH_KEYS = ['branch_id', 'all_branches'];
const STATUS_ORDER = ['active', 'pending', 'suspended'];
const CLOSED = 'closed';

type AreaOption = { division_id: number; division: string; districts: { id: number; name: string }[] };

/** Everything in the address that the file download and a saved filter need, from what the table really used. */
function chosenFilters(state: MoneyTableData<Row>['state'], isClosedView: boolean, url: string): Record<string, string> {
    const inAddress = new URL(url || '/', 'http://partners.local').searchParams;
    const chosen: Record<string, string> = {};
    if (state.q) {
        chosen.q = state.q;
    }
    if (isClosedView) {
        chosen.status = CLOSED;
    } else if (state.filters.status) {
        chosen.status = state.filters.status;
    }
    FILE_FILTER_KEYS.forEach((key) => {
        if (state.filters[key]) {
            chosen[key] = state.filters[key];
        }
    });
    BRANCH_KEYS.forEach((key) => {
        const value = inAddress.get(key);
        if (value) {
            chosen[key] = value;
        }
    });

    return chosen;
}

/** Status, division, district, rank and "no order lately" choices. Values are place code ids, never names. */
function partnerFilters(section: string, data: Row, division: string, translate: ReturnType<typeof useT>): MoneyTableData<Row>['filters'] {
    const counts = (data.account_status_counts ?? {}) as Record<string, number>;
    const areas = (data.account_areas ?? []) as AreaOption[];
    const chosenDivision = areas.find((area) => String(area.division_id) === division);
    const districts = chosenDivision
        ? chosenDivision.districts.map((district) => ({ value: String(district.id), label: district.name }))
        : areas.flatMap((area) => area.districts.map((district) => ({ value: String(district.id), label: `${district.name} (${area.division})` })));
    const statuses = [...STATUS_ORDER, ...Object.keys(counts).filter((status) => !STATUS_ORDER.includes(status) && status !== CLOSED)];

    return [
        { key: 'status', label: translate('portal', 'col_status'), options: statuses.map((status) => ({ value: status, label: label(status, translate), count: Number(counts[status] ?? 0) })) },
        { key: 'division', label: translate('portal', 'acct_filter_division'), options: areas.map((area) => ({ value: String(area.division_id), label: area.division })) },
        { key: 'district', label: translate('portal', 'acct_filter_district'), options: districts },
        ...(section === 'accounts' ? [{ key: 'rank', label: translate('portal', 'acct_filter_rank'), options: ['dealer', 'mo', 'am', 'sm', 'me'].map((rank) => ({ value: rank, label: translate('portal', `net_rank_${rank}`) })) }] : []),
        { key: 'no_order_days', label: translate('portal', 'acct_filter_any_order'), options: ['30', '60', '90'].map((days) => ({ value: days, label: translate('portal', 'acct_filter_no_order_days', { days }) })) },
    ];
}

/** Dealers or sub-dealers: one toolbar and one table. A row opens the side panel with the rest of the facts and the buttons. */
export default function Accounts({ section, data }: { section: string; data: Row }) {
    const translate = useT();
    const { url, props } = usePage<any>();
    const { portalBase: base, auth, staffActions } = props;
    const currentUser = auth?.user;
    const canCreate = staffActions?.accounts?.create ?? true;
    const canEdit = staffActions?.accounts?.edit ?? true;
    const [opened, setOpened] = useState<Row | null>(null);
    const [editing, setEditing] = useState<Row | null>(null);
    const [selecting, setSelecting] = useState(false);

    const table = data.accounts_table as Omit<MoneyTableData<Row>, 'rows' | 'filters'>;
    const isClosedView = (new URL(url || '/', 'http://partners.local').searchParams.get('status') ?? '') === CLOSED;
    const closedCount = Number(data.account_status_counts?.[CLOSED] ?? 0);
    const openCount = Object.entries(data.account_status_counts ?? {}).reduce((sum, [status, count]) => (status === CLOSED ? sum : sum + Number(count ?? 0)), 0);

    const tableData: MoneyTableData<Row> = useMemo(() => ({
        ...table,
        rows: data.accounts,
        filters: partnerFilters(section, data, table.state.filters.division ?? '', translate).filter((filter) => filter.key !== 'status' || !isClosedView),
    }), [data, table, section, translate, isClosedView]);

    const chosen = chosenFilters(table.state, isClosedView, url);
    const exportHref = `/exports/accounts?${new URLSearchParams({ section, ...chosen }).toString()}`;
    const canEditRow = (row: Row) => !(row.id === currentUser?.id || (!canEdit && !['super_admin', 'md', 'chairman'].includes(currentUser?.role)));

    const columns: MoneyColumn<Row>[] = [
        { key: 'name', title: translate('portal', 'col_partner'), sortKey: 'name', phone: 'title', render: (row) => <><b>{row.name}</b><small className="cell-sub">{[row.dealer_code, row.profile?.shop_name].filter(Boolean).join(' · ')}</small></> },
        { key: 'area', title: translate('office_partners', 'col_area'), sortKey: 'place', phone: 'sub', render: (row) => <>{row.profile?.place_code || row.profile?.area_code || '—'}{(row.district || row.division) && <small className="cell-sub">{[row.district, row.division].filter(Boolean).join(', ')}</small>}</> },
        ...(section === 'accounts' ? [{ key: 'rank', title: translate('portal', 'acct_col_rank'), sortKey: 'rank', phone: 'hide' as const, render: (row: Row) => translate('portal', `net_rank_${row.current_rank || 'dealer'}`) }] : []),
        { key: 'last_order_at', title: translate('portal', 'acct_col_last_order'), sortKey: 'last_order_at', phone: 'hide', render: (row) => (row.last_order_at ? date(row.last_order_at) : <small className="cell-sub">{translate('portal', 'acct_never_ordered')}</small>) },
        { key: 'month_purchase', title: translate('portal', 'acct_col_month_purchase'), sortKey: 'month_purchase', money: true, phone: 'amount' },
        { key: 'status', title: translate('portal', 'col_status'), sortKey: 'status', phone: 'sub', render: (row) => <Badge status={row.status} /> },
    ];

    const action = canCreate ? (
        <Link className="button dark" href={`/accounts/create?intent=${section === 'accounts' ? 'dealer' : 'sub_dealer'}`}>
            <Plus size={17} aria-hidden="true" />
            {translate('portal', section === 'accounts' ? 'acct_heading' : 'sd_add')}
        </Link>
    ) : undefined;

    /** Moving between the two views keeps the search and the other choices. */
    const closedViewHref = (toClosed: boolean) => {
        const next = new URL(url || '/', 'http://partners.local');
        next.searchParams.delete('page');
        next.searchParams.delete('status');
        if (toClosed) {
            next.searchParams.set('status', CLOSED);
        }
        const query = next.searchParams.toString();

        return `${base}/${section}${query ? `?${query}` : ''}`;
    };

    const invite = (row: Row) => router.post(`/commands/accounts/${row.id}/invite`, {}, { preserveScroll: true, onSuccess: () => setOpened(null) });

    const viewSwitch = (
        <div className="office-switch" role="group" aria-label={translate('portal', 'col_status')}>
            <Link className={isClosedView ? undefined : 'is-active'} aria-current={isClosedView ? undefined : 'true'} href={closedViewHref(false)} only={['data']} preserveState preserveScroll>
                {translate('office_partners', 'view_open')} ({number(openCount)})
            </Link>
            <Link className={isClosedView ? 'is-active' : undefined} aria-current={isClosedView ? 'true' : undefined} href={closedViewHref(true)} only={['data']} preserveState preserveScroll>
                {translate('office_partners', 'view_closed')} ({number(closedCount)})
            </Link>
        </div>
    );

    return (
        <PartnerFrame data={data} action={action}>
            <div data-tour="accounts-table">
                <MoneyTable
                    data={tableData}
                    prop="data"
                    columns={columns}
                    searchPlaceholder={translate('office_partners', 'search_partners')}
                    hideViewChips
                    segments={viewSwitch}
                    bulk={{ kind: 'partners', selecting }}
                    onRowOpen={(row) => setOpened(row)}
                    actions={(
                        <MoreMenu
                            label={translate('treasury', 'downloads')}
                            icon={<Download size={14} aria-hidden="true" />}
                            groups={[{
                                key: 'files',
                                items: [
                                    { key: 'excel', label: translate('portal', 'acct_export_excel'), href: exportHref },
                                    { key: 'select', label: translate('office_shell', selecting ? 'stop_selecting' : 'select_rows'), onSelect: () => { setSelecting((current) => !current); } },
                                ],
                            }]}
                        >
                            <SavedFilterPanel listKey={section} section={section} filters={chosen} />
                        </MoreMenu>
                    )}
                    empty={translate('office_partners', isClosedView ? 'empty_closed' : 'empty_partners')}
                />
            </div>

            {opened && (
                <Modal
                    variant="drawer"
                    title={opened.name}
                    badge={<Badge status={opened.status} />}
                    onClose={() => setOpened(null)}
                    footer={(
                        <>
                            <button type="button" className="button dark" disabled={!canEditRow(opened)} onClick={() => { setEditing(opened); setOpened(null); }}>{translate('portal', 'edit_status')}</button>
                            <button type="button" className="button" disabled={opened.status !== 'active' || !canEdit} onClick={() => invite(opened)}>{translate('portal', 'send_invitation')}</button>
                            <Link className="button" href={`${base}/accounts/${opened.id}/statement`}>{translate('portal', 'stmt_open')}</Link>
                            <Link className="button" href={`${base}/accounts/${opened.id}/team-orders`}>{translate('portal', 'team_orders_short')}</Link>
                        </>
                    )}
                >
                    <PartnerDetails partner={opened} isDealerList={section === 'accounts'} />
                </Modal>
            )}

            {editing && (
                <Modal variant="wide" title={translate('portal', 'modal_edit_account')} onClose={() => setEditing(null)}>
                    <AccountEditor account={editing} onDone={() => setEditing(null)} />
                </Modal>
            )}
        </PartnerFrame>
    );
}

/** The facts that left the table: only what the row already holds, so nothing more is fetched and masked numbers stay masked. */
function PartnerDetails({ partner, isDealerList }: { partner: Row; isDealerList: boolean }) {
    const translate = useT();
    const place = partner.profile?.place_code || partner.profile?.area_code;
    const areaLine = [partner.profile?.union, partner.profile?.upazila, partner.district, partner.division].filter(Boolean).join(', ');
    const sponsor = partner.sponsor?.name
        ? `${partner.sponsor.name}${partner.sponsor.dealer_code ? ` · ${partner.sponsor.dealer_code}` : ''}`
        : partner.profile?.refer_code || null;
    const lines: [string, React.ReactNode][] = [
        [translate('office_partners', 'detail_code'), partner.dealer_code],
        [translate('portal', 'col_type'), label(partner.role, translate)],
        [translate('office_partners', 'detail_phone'), partner.phone],
        [translate('office_partners', 'detail_email'), partner.email],
        [translate('portal', 'net_sponsor'), sponsor],
        [translate('portal', 'col_place'), place],
        [translate('office_partners', 'detail_area'), areaLine],
        [translate('office_partners', 'detail_shop'), partner.profile?.shop_name],
        [translate('office_partners', 'detail_name_bn'), partner.profile?.name_bn],
        [translate('portal', 'col_joined'), partner.created_at ? date(partner.created_at) : null],
        ...(isDealerList ? [[translate('portal', 'acct_col_rank'), translate('portal', `net_rank_${partner.current_rank || 'dealer'}`)] as [string, React.ReactNode]] : []),
        [translate('portal', 'acct_col_last_order'), partner.last_order_at ? date(partner.last_order_at) : translate('portal', 'acct_never_ordered')],
        [translate('portal', 'acct_col_month_purchase'), money(partner.month_purchase ?? 0)],
    ];

    return (
        <dl className="office-facts">
            {lines.filter(([, value]) => value !== null && value !== undefined && value !== '').map(([name, value]) => (
                <div key={name}><dt>{name}</dt><dd>{value}</dd></div>
            ))}
        </dl>
    );
}
