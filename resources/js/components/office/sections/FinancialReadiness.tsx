import { useState } from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import OfficePage from '../OfficePage';
import { actionAllowed } from '../../action-permissions';
import { Badge, Modal, Pagination, Table, date, dateTime, label, type Row } from '../../UI';
import Figures from '../Figures';
import { useT } from '../../../shared/i18n/t';
import SettingsForm, { settingsBack, type SettingsAction, type SettingsField } from './SettingsForm';

const root = '/commands/operations';

/** The opening check: do the money records match, is the server ready, and which opening balances wait for a second person. */
export default function FinancialReadiness({ readiness, deploymentStatus, startupMode, tables = [], secondApprovers = [] }: Row) {
    const translate = useT();
    const page = usePage<any>();
    const { auth, financeActive, staffActions } = page.props;
    const capabilities: string[] = auth.capabilities;
    const [selected, setSelected] = useState<SettingsAction | null>(null);
    const search = new URLSearchParams(page.url.split('?')[1] ?? '');
    const reviewsTable: Row | undefined = (tables as Row[]).find((table) => table.key === 'opening_reviews');
    const views = ['checks', ...(deploymentStatus ? ['live'] : []), ...(reviewsTable ? ['reviews'] : [])];
    const requested = search.get('view') ?? '';
    const view = views.includes(requested) ? requested : 'checks';
    const viewTitle: Record<string, string> = {
        checks: translate('portal', 'ops_ledger_reconciliation'),
        live: translate('portal', 'ops_deployment_readiness'),
        reviews: translate('office_settings', 'readiness_reviews_tab'),
    };
    const hub = views.length > 1 ? {
        home: 'financial-readiness',
        label: translate('portal', 'financial_readiness'),
        tabs: views.map((key) => ({ key, label: viewTitle[key], href: key === 'checks' ? '/operations/financial-readiness' : `/operations/financial-readiness?view=${key}`, active: key === view })),
    } : undefined;
    const checkLabel = (key: string) => {
        const localized = translate('portal', `ops_chk_${key}`);
        return localized === `ops_chk_${key}` ? label(key, translate) : localized;
    };
    const reasonFor = (place: string): SettingsField => ({ name: 'reason', label: translate('portal', 'ops_fld_reason'), type: 'textarea', required: true, place });
    const confirmAlone = (text: string): SettingsField => ({ name: 'self_approve', label: text, required: true, options: [{ value: '1', label: translate('self_approval', 'confirm_yes') }] });
    const aloneFor = (people: number[]) => auth.user.role === 'super_admin' && (secondApprovers as number[]).filter((id) => !people.includes(id)).length === 0;
    const freshStart: SettingsAction | null = auth.user.role === 'super_admin' && readiness && !readiness.opening_verified ? {
        title: translate('self_approval', 'fresh_start_title'),
        action: `${root}/opening-balances/fresh-start`,
        fields: [confirmAlone(`${translate('self_approval', 'fresh_start_intro')} ${translate('self_approval', 'fresh_start_confirm')}`), reasonFor('money.opening_fresh_start')],
    } : null;
    const openingBalances: SettingsAction = {
        title: translate('portal', 'ops_submit_opening_balances'),
        action: `${root}/opening-balances`,
        fields: [
            { name: 'wallet_available', label: translate('portal', 'ops_fld_wallet_available'), type: 'number', required: true },
            { name: 'wallet_held', label: translate('portal', 'ops_fld_wallet_held'), type: 'number', required: true },
            { name: 'order_paid', label: translate('portal', 'ops_fld_order_paid'), type: 'number', required: true },
            { name: 'withdrawal_paid', label: translate('portal', 'ops_fld_withdrawal_paid'), type: 'number', required: true },
            { name: 'source_reference', label: translate('portal', 'ops_fld_source_ref'), required: true },
            { name: 'evidence', label: translate('portal', 'ops_fld_evidence'), type: 'file', required: true, accept: 'application/pdf,image/jpeg,image/png,.xlsx' },
        ],
    };
    const headerActions = [freshStart, openingBalances].filter((item): item is SettingsAction => item !== null && actionAllowed(item.action, staffActions, 'post'));
    const reviewAction = (row: Row): SettingsAction | null => {
        if (row.verified_at) {
            return null;
        }
        const verify: SettingsAction = { title: translate('portal', 'ops_verify_opening_evidence'), action: `${root}/opening-balances/${row.id}/verify`, fields: [reasonFor('money.opening_verify')] };
        if (row.created_by !== auth.user.id) {
            return verify;
        }
        if (aloneFor([row.created_by])) {
            return { ...verify, title: `${verify.title} (${translate('self_approval', 'alone_tag')})`, fields: [reasonFor('money.opening_verify'), confirmAlone(translate('self_approval', 'confirm_label'))] };
        }

        return null;
    };
    const checkRows: Row[] = readiness ? [
        { name: translate('portal', 'ops_opening_evidence'), status: readiness.opening_verified ? 'passed' : 'needs_review', word: translate('portal', readiness.opening_verified ? 'ops_verified' : 'ops_awaiting_approval') },
        { name: translate('portal', 'ops_internal_reconciliation'), status: readiness.internally_reconciled ? 'passed' : 'needs_review', word: translate('portal', readiness.internally_reconciled ? 'ops_passed' : 'ops_requires_review') },
        ...Object.entries(readiness.checks).map(([name, passed]) => ({ name: checkLabel(name), status: passed ? 'passed' : 'needs_review' })),
    ] : [];
    const statusColumn = { key: 'status', title: translate('portal', 'ops_result'), render: (row: Row) => row.word ? <span className={`badge ${row.status}`}>{row.word}</span> : <Badge status={row.status} /> };

    return (
        <OfficePage
            title={translate('portal', 'financial_readiness')}
            back={settingsBack(translate)}
            hub={hub}
            action={headerActions.length > 0 && <div className="set-actions">{headerActions.map((item, index) => <button key={item.action} type="button" className={`button${index === headerActions.length - 1 ? ' dark' : ''}`} onClick={() => setSelected(item)}>{item.title}</button>)}</div>}
        >
            {!financeActive && capabilities.includes('finance') && <p className="set-line">{translate('portal', 'ops_preview_finance_notice')}</p>}
            {view === 'checks' && readiness && (
                <>
                    <Figures items={Object.entries(readiness.totals).map(([key, value]) => ({ key, label: checkLabel(key), value: String(value) }))} />
                    <p className="set-line">{translate('portal', 'ops_startup_mode')}: <strong>{startupMode?.label ?? translate('portal', 'ops_startup_not_selected')}</strong></p>
                    <section className="panel table-panel">
                        <Table tools={false} rows={checkRows} columns={[{ key: 'name', title: translate('portal', 'ops_check') }, statusColumn]} />
                    </section>
                </>
            )}
            {view === 'live' && deploymentStatus && (
                <>
                    <section className="panel table-panel">
                        <Table tools={false} rows={Object.entries(deploymentStatus.checks).map(([name, passed]) => ({ name: checkLabel(name), status: passed ? 'passed' : 'needs_review' }))} columns={[{ key: 'name', title: translate('portal', 'ops_service_config') }, statusColumn]} />
                    </section>
                    <GoLiveWarnings status={deploymentStatus} />
                </>
            )}
            {view === 'reviews' && reviewsTable && (
                <section className="panel table-panel">
                    <ReviewSearch tableKey="opening_reviews" filter={reviewsTable.filter} />
                    <Table
                        searchable={false}
                        printable
                        totalCount={reviewsTable.page.total}
                        totalLabel={Number(reviewsTable.page.last_page ?? 1) > 1 ? translate('portal', 'table_page_total') : undefined}
                        rows={reviewsTable.page.data as Row[]}
                        columns={[
                            { key: 'created_at', title: translate('portal', 'col_date'), render: (row: Row) => dateTime(row.created_at) },
                            { key: 'source_reference', title: translate('portal', 'ops_col_source_reference'), render: (row: Row) => <>{row.source_reference}<small className="cell-sub">{row.fingerprint}</small></> },
                            { key: 'verified_at', title: translate('portal', 'col_status'), render: (row: Row) => row.verified_at ? <>{translate('portal', 'ops_verified')}<small className="cell-sub">{date(row.verified_at)}</small></> : translate('portal', 'ops_awaiting_approval') },
                            { key: 'fresh_start', title: translate('office_settings', 'import_mode_fresh'), render: (row: Row) => translate('portal', row.fresh_start ? 'ops_fld_yes' : 'ops_fld_no') },
                            { key: 'actions', title: translate('portal', 'ops_actions'), render: (row: Row) => {
                                const action = reviewAction(row);
                                return (
                                    <div className="ops-row is-wrap">
                                        {action && actionAllowed(action.action, staffActions) && <button type="button" className="button small" onClick={() => setSelected(action)}>{action.title}</button>}
                                        {!row.fresh_start && <a className="button small" href={`${root}/receipts/opening/${row.id}`}>{translate('portal', 'ops_fld_private_evidence')}</a>}
                                    </div>
                                );
                            } },
                        ]}
                    />
                    <Pagination page={reviewsTable.page} />
                </section>
            )}
            {selected && (
                <Modal title={selected.title} onClose={() => setSelected(null)}>
                    <SettingsForm action={selected.action} fields={selected.fields} submitLabel={selected.title} onDone={() => setSelected(null)} />
                </Modal>
            )}
        </OfficePage>
    );
}

/** What to fix before going live: each line says what is missing, and where it is known, which records. */
function GoLiveWarnings({ status }: { status: Row }) {
    const translate = useT();
    const warnings: string[] = Array.isArray(status.warnings) ? status.warnings : [];
    const missing = (['branches', 'area_seats', 'staff_territories'] as const)
        .map((kind) => ({ kind, rows: (status.details?.records_missing_place_codes?.[kind] ?? []) as Row[] }))
        .filter(({ rows }) => rows.length > 0);
    const headOffice = status.details?.head_office_branch_missing_place_code;
    if (warnings.length === 0) {
        return <p className="set-line">{translate('portal', 'ops_no_go_live_warnings')}</p>;
    }

    return (
        <section className="set-sheet is-wide">
            <h2 className="set-title">{translate('office_settings', 'go_live_warnings')}</h2>
            <ul className="opr-warnings">{warnings.map((warning) => <li key={warning}>{translate('portal', `ops_warn_${warning}`)}</li>)}</ul>
            {missing.map(({ kind, rows }) => (
                <div key={kind} className="ops-mt-3">
                    <strong>{translate('portal', `ops_missing_place_${kind}`)}</strong>
                    <ul className="opr-warnings">{rows.map((item) => <li key={item.id}>#{item.id} · {item.label}</li>)}</ul>
                </div>
            ))}
            {headOffice && <p className="ops-mt-3"><strong>{translate('portal', 'ops_head_office_branch_label')}</strong> {headOffice.code} · {headOffice.name} — <Link className="text-link" href="/branches">{translate('portal', 'ops_head_office_open_branches')}</Link></p>}
        </section>
    );
}

/** The search box of the submitted opening balances list; it runs on the server, so it covers every page. */
function ReviewSearch({ tableKey, filter }: { tableKey: string; filter?: Row }) {
    const translate = useT();
    const [query, setQuery] = useState(filter?.q ?? '');
    if (!filter?.searchable) {
        return null;
    }
    const apply = (value: string) => {
        const params = new URLSearchParams(window.location.search);
        [...params.keys()].filter((key) => /^page_\d+$/.test(key)).forEach((key) => params.delete(key));
        if (value) {
            params.set(`${tableKey}_q`, value);
        } else {
            params.delete(`${tableKey}_q`);
        }
        router.get(window.location.pathname, Object.fromEntries(params.entries()), { preserveState: true, preserveScroll: true });
    };

    return (
        <form className="filter-bar order-filters" onSubmit={(event) => { event.preventDefault(); apply(query); }}>
            <div className="order-search">
                <div className="search-input"><input aria-label={translate('portal', 'table_search')} placeholder={translate('portal', 'table_search')} value={query} onChange={(event) => setQuery(event.target.value)} /></div>
                <button className="button small dark" type="submit">{translate('portal', 'search')}</button>
            </div>
            {filter.q && <div className="order-filter-actions"><button type="button" className="text-link" onClick={() => { setQuery(''); apply(''); }}>{translate('portal', 'clear')}</button></div>}
        </form>
    );
}
