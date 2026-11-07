import React from 'react';
import { router } from '@inertiajs/react';
import { MapPin, Trophy } from 'lucide-react';
import { money } from './UI';
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
    const translate = useT();

    const visit = (next: { month?: string; division?: string }) => {
        router.get('/top-sellers', {
            month: next.month ?? board.month,
            division: staff ? ((next.division ?? (board.division_id != null ? String(board.division_id) : '')) || undefined) : undefined,
        }, { preserveState: true, preserveScroll: true, replace: true });
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
                {staff && (
                    <label className="tsb-division-pick">
                        <span><MapPin size={14} /> {translate('portal', 'top_sellers_division_pick')}</span>
                        <select value={board.division_id != null ? String(board.division_id) : ''} onChange={(event) => visit({ division: event.target.value })}>
                            <option value="">{translate('portal', 'top_sellers_division_none')}</option>
                            {(board.divisions ?? []).map((division) => (
                                <option key={division.id} value={String(division.id)}>{division.name}</option>
                            ))}
                        </select>
                    </label>
                )}
            </section>

            {!staff && me && (
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
            {!staff && !me && board.can_rank && (
                <div className="tsb-me is-empty" role="status">
                    <small>{translate('portal', 'top_sellers_not_on_board')}</small>
                </div>
            )}

            <div className="tsb-boards">
                <BoardList
                    title={translate('portal', 'top_sellers_national')}
                    entries={board.national}
                    showAmounts={board.show_amounts}
                    staff={staff}
                />
                {board.division ? (
                    <BoardList
                        title={translate('portal', 'top_sellers_division', { division: board.division })}
                        entries={board.divisional}
                        showAmounts={board.show_amounts}
                        staff={staff}
                    />
                ) : !staff && (
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

function BoardList({ title, entries, showAmounts, staff }: { title: string; entries: TopSellerEntry[]; showAmounts: boolean; staff: boolean }) {
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
                                        {staff && entry.hidden && <span className="tsb-hidden">{translate('portal', 'top_sellers_hidden')}</span>}
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
