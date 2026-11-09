import React from 'react';
import { Link, router, usePage } from '@inertiajs/react';
import { Trophy } from 'lucide-react';
import { Table, money, type Row } from './UI';
import { ReportFilterSheet } from './BranchPerformanceHub';
import OfficePage from './office/OfficePage';
import Figures from './office/Figures';
import type { OfficeHub } from './OfficeHubTabs';
import { useT } from '../shared/i18n/t';
import { businessMonth, localizeDigits } from '../business-date';

/** One place on the board. Amount is null when the owner hides the taka figures. */
export interface TopSellerEntry {
    rank: number;
    name: string;
    dealer_code: string | null;
    place: string | null;
    amount: string | null;
    percent: number;
    is_me: boolean;
    /** Office view only: the dealer chose to stay out of the public dealer list. */
    hidden?: boolean;
}

export interface TopSellersPayload {
    month: string;
    months: string[];
    show_amounts: boolean;
    division: string | null;
    /** The division's place code id: divisions are matched by code, never by name. */
    division_id?: number | null;
    national: TopSellerEntry[];
    divisional: TopSellerEntry[];
    me?: {
        national_rank: number;
        division_rank: number | null;
        amount: string | null;
        percent: number;
        in_top: boolean;
    } | null;
    can_rank?: boolean;
    /** Office view only: divisions to compare. */
    divisions?: { id: number; name: string }[];
}

interface TopSellersBoardProps {
    board: TopSellersPayload;
    /** Office view: lets staff pick any division and marks hidden dealers. */
    staff?: boolean;
}

const MEDALS: Record<number, string> = { 1: 'is-gold', 2: 'is-silver', 3: 'is-bronze' };

/**
 * Monthly top-sellers board. Ranks dealers by their own purchases only; it never
 * shows income or commission.
 */
export default function TopSellersBoard({ board, staff = false }: TopSellersBoardProps) {
    return staff ? <OfficeTopSellers board={board} /> : <PartnerTopSellers board={board} />;
}

/** The office page: one table of the month's top 10, for the whole country or one division. */
function OfficeTopSellers({ board }: { board: TopSellersPayload }) {
    const translate = useT();
    const { url, props } = usePage<any>();
    const hub: OfficeHub | null = props.data?.hub ?? null;
    const address = new URL(url || '/', 'http://top-sellers.local');
    const showsDivision = address.searchParams.get('board') === 'division' && board.division !== null;
    const entries = showsDivision ? board.divisional : board.national;
    const chosenDivision = board.division_id != null ? String(board.division_id) : '';

    const period = address.searchParams.get('period') ?? '';
    const pickedFrom = address.searchParams.get('from') ?? '';
    const pickedTo = address.searchParams.get('to') ?? '';

    const visit = (next: { month?: string; division?: string; period?: string; from?: string; to?: string }) => {
        const division = next.division ?? chosenDivision;
        const nextPeriod = next.month !== undefined ? '' : (next.period ?? period);
        const keepsDates = next.month === undefined && next.period === undefined;
        const from = nextPeriod ? (next.from ?? (keepsDates ? pickedFrom : '')) : '';
        const to = nextPeriod ? (next.to ?? (keepsDates ? pickedTo : '')) : '';
        router.get('/top-sellers', {
            month: nextPeriod ? undefined : (next.month ?? board.month),
            period: nextPeriod || undefined,
            from: from || undefined,
            to: to || undefined,
            division: division || undefined,
            board: showsDivision && division ? 'division' : undefined,
        }, { preserveState: true, preserveScroll: true, replace: true });
    };
    const boardHref = (division: boolean): string => {
        const params = new URLSearchParams(address.search);
        if (division) {
            params.set('board', 'division');
        } else {
            params.delete('board');
        }
        const query = params.toString();

        return query ? `${address.pathname}?${query}` : address.pathname;
    };
    /** One date shows that day; a second date makes it a range. */
    const chooseDates = (from: string, to: string) => {
        if (from && to) {
            visit({ period: 'custom', from, to });
        } else if (from) {
            visit({ period: 'day', from, to: '' });
        } else {
            visit({ month: board.month });
        }
    };
    const datePickers = (
        <>
            <label>{translate('office_reports', 'top_sellers_from')}<input type="date" value={pickedFrom} onChange={(event) => chooseDates(event.target.value, pickedTo)} /></label>
            <label>{translate('office_reports', 'top_sellers_to')}<input type="date" value={pickedTo} disabled={!pickedFrom} onChange={(event) => chooseDates(pickedFrom, event.target.value)} /></label>
        </>
    );
    const topTenTotal = entries.reduce((sum, entry) => sum + Math.round(Number(entry.amount ?? 0) * 100), 0) / 100;
    const divisionPicker = (
        <select aria-label={translate('portal', 'top_sellers_division_pick')} value={chosenDivision} onChange={(event) => visit({ division: event.target.value })}>
            <option value="">{translate('portal', 'top_sellers_division_none')}</option>
            {(board.divisions ?? []).map((division) => (
                <option key={division.id} value={String(division.id)}>{division.name}</option>
            ))}
        </select>
    );

    return (
        <div data-tour="top-sellers-board">
            <OfficePage title={hub?.label ?? translate('portal', 'top_sellers_title')} hub={hub}>
                <Figures
                    items={[
                        { key: 'highest', label: translate('office_reports', 'top_sellers_highest'), value: entries[0]?.amount ?? '0.00' },
                        { key: 'ten_total', label: translate('office_reports', 'top_sellers_ten_total'), value: topTenTotal.toFixed(2) },
                    ]}
                />
                <div className="money-table">
                    {board.division && (
                        <div className="mt-segments">
                            <div className="office-switch" role="group" aria-label={translate('portal', 'top_sellers_title')}>
                                <Link href={boardHref(false)} preserveState preserveScroll replace className={showsDivision ? undefined : 'is-active'} aria-current={showsDivision ? undefined : 'true'}>{translate('portal', 'top_sellers_national')}</Link>
                                <Link href={boardHref(true)} preserveState preserveScroll replace className={showsDivision ? 'is-active' : undefined} aria-current={showsDivision ? 'true' : undefined}>{translate('portal', 'top_sellers_division', { division: board.division })}</Link>
                            </div>
                        </div>
                    )}
                    <div className="mt-toolbar">
                        <ReportFilterSheet><label>{translate('portal', 'top_sellers_division_pick')}{divisionPicker}</label>{datePickers}</ReportFilterSheet>
                        <div className="mt-views" role="group" aria-label={translate('portal', 'top_sellers_pick_month')}>
                            {board.months.map((month, index) => (
                                <button key={month} type="button" className="mt-view" aria-pressed={!period && month === board.month} onClick={() => visit({ month })}>
                                    {index === 0 ? translate('portal', 'top_sellers_this_month') : businessMonth(month)}
                                </button>
                            ))}
                            {(['today', 'week', 'year'] as const).map((choice) => (
                                <button key={choice} type="button" className="mt-view" aria-pressed={period === choice} onClick={() => visit({ period: choice })}>
                                    {translate('portal', `orders_period_${choice}`)}
                                </button>
                            ))}
                        </div>
                        <div className="mt-filters">{divisionPicker}{datePickers}</div>
                    </div>
                    <Table<Row>
                        rows={entries as unknown as Row[]}
                        searchPlaceholder={translate('office_reports', 'top_sellers_search')}
                        empty={translate('portal', 'top_sellers_empty')}
                        columns={[
                            { key: 'rank', title: translate('office_reports', 'top_sellers_col_rank'), align: 'right', width: 64, sortValue: (row) => Number(row.rank), render: (row) => localizeDigits(String(row.rank)) },
                            {
                                key: 'name',
                                title: translate('office_reports', 'top_sellers_col_dealer'),
                                render: (row) => (
                                    <>
                                        {row.name}
                                        <small className="cell-sub">{[row.dealer_code, row.place, row.hidden ? translate('portal', 'top_sellers_hidden') : null].filter(Boolean).join(' · ')}</small>
                                    </>
                                ),
                            },
                            { key: 'amount', title: translate('office_reports', 'top_sellers_col_amount'), money: true, sortValue: (row) => Number(row.amount ?? 0), render: (row) => money(row.amount) },
                        ]}
                    />
                </div>
            </OfficePage>
        </div>
    );
}

/** The dealer and sub-dealer page. */
function PartnerTopSellers({ board }: { board: TopSellersPayload }) {
    const translate = useT();

    const visit = (next: { month?: string }) => {
        router.get('/top-sellers', { month: next.month ?? board.month }, { preserveState: true, preserveScroll: true, replace: true });
    };

    const me = board.me ?? null;

    return (
        <div className="tsb" data-tour="top-sellers-board">
            <section className="panel tsb-head">
                <div className="tsb-title">
                    <span className="tsb-title-icon" aria-hidden="true"><Trophy size={22} /></span>
                    <div>
                        <h2>{translate('portal', 'top_sellers_title')}</h2>
                        <p className="muted">{translate('portal', 'top_sellers_hint')}</p>
                    </div>
                </div>
                <div className="tsb-months" role="group" aria-label={translate('portal', 'top_sellers_pick_month')}>
                    {board.months.map((month, index) => (
                        <button
                            key={month}
                            type="button"
                            className={`tsb-month${month === board.month ? ' is-active' : ''}`}
                            aria-pressed={month === board.month}
                            onClick={() => visit({ month })}
                        >
                            {index === 0 ? translate('portal', 'top_sellers_this_month') : businessMonth(month)}
                        </button>
                    ))}
                </div>
            </section>

            {me && (
                <div className={`tsb-me${me.in_top ? ' is-top' : ''}`} role="status">
                    <b>{me.in_top
                        ? translate('portal', 'top_sellers_you_in_top', { rank: String(me.national_rank) })
                        : translate('portal', 'top_sellers_you_are_at', { rank: String(me.national_rank) })}</b>
                    {me.division_rank !== null && board.division && (
                        <small>{translate('portal', 'top_sellers_you_in_division', { rank: String(me.division_rank), division: board.division })}</small>
                    )}
                    <span className="tsb-bar" aria-hidden="true"><i style={{ width: `${me.percent}%` }} /></span>
                    {me.amount !== null && <small>{translate('portal', 'top_sellers_your_purchases', { amount: money(me.amount) })}</small>}
                </div>
            )}
            {!me && board.can_rank && (
                <div className="tsb-me is-empty" role="status">
                    <small>{translate('portal', 'top_sellers_not_on_board')}</small>
                </div>
            )}

            <div className="tsb-boards">
                <BoardList
                    title={translate('portal', 'top_sellers_national')}
                    entries={board.national}
                    showAmounts={board.show_amounts}
                />
                {board.division ? (
                    <BoardList
                        title={translate('portal', 'top_sellers_division', { division: board.division })}
                        entries={board.divisional}
                        showAmounts={board.show_amounts}
                    />
                ) : (
                    <section className="panel tsb-list">
                        <h3>{translate('portal', 'top_sellers_division_title')}</h3>
                        <p className="muted tsb-empty">{translate('portal', 'top_sellers_no_division')}</p>
                    </section>
                )}
            </div>

            <p className="tsb-note">{translate('portal', 'top_sellers_note')}</p>
        </div>
    );
}

function BoardList({ title, entries, showAmounts }: { title: string; entries: TopSellerEntry[]; showAmounts: boolean }) {
    const translate = useT();

    return (
        <section className="panel tsb-list">
            <h3>{title}</h3>
            {entries.length === 0 ? (
                <p className="muted tsb-empty">{translate('portal', 'top_sellers_empty')}</p>
            ) : (
                <ol className="tsb-rows">
                    {entries.map((entry) => {
                        const details = [entry.dealer_code, entry.place].filter((part): part is string => Boolean(part));

                        return (
                            <li
                                key={`${entry.rank}-${entry.dealer_code ?? entry.name}`}
                                className={`tsb-row ${MEDALS[entry.rank] ?? ''}${entry.is_me ? ' is-me' : ''}`}
                            >
                                <span className="tsb-rank" aria-label={translate('portal', 'top_sellers_rank_label', { rank: String(entry.rank) })}>
                                    {localizeDigits(String(entry.rank))}
                                </span>
                                <div className="tsb-who">
                                    <div className="tsb-name">
                                        <b>{entry.name}</b>
                                        {details.length > 0 && <span className="tsb-details"> · {details.join(' · ')}</span>}
                                        {entry.is_me && <span className="tsb-you">{translate('portal', 'top_sellers_you')}</span>}
                                    </div>
                                    <span className="tsb-bar" aria-hidden="true"><i style={{ width: `${entry.percent}%` }} /></span>
                                </div>
                                {showAmounts && entry.amount !== null && <span className="tsb-amount">{money(entry.amount)}</span>}
                            </li>
                        );
                    })}
                </ol>
            )}
        </section>
    );
}
