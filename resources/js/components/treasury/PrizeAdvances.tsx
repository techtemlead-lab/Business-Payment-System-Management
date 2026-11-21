import { useEffect, useRef, useState } from 'react';
import { Link, useForm } from '@inertiajs/react';
import { DateInput, FormErrors, Modal, label, money, number } from '../UI';
import { useT } from '../../shared/i18n/t';
import { randomUuid } from '../../shared/randomUuid';
import { businessTodayIso } from '../../business-date';
import { FUNDS_COMMAND_ROOT } from './FundSpendForm';

export const PRIZE_ADVANCES_PAGE = '/treasury/prize-advances';
const COMMAND_ROOT = '/commands/prize-advances';

export type PrizeKind = 'bike' | 'car';

export type OpenAdvance = {
    id: number;
    partner_id: number;
    name: string;
    code: string | null;
    role: string;
    partner_active: boolean;
    kind: PrizeKind;
    amount: string;
    settled: string;
    left: string;
    given_on: string;
    days: number;
    reason: string;
};

export type AdvancePartner = { id: number; name: string; code: string | null; role: string };

export type AdvanceFunds = Record<PrizeKind, { award: string | null; balance: string }>;

/**
 * Bike and car prizes paid in advance and not settled yet, oldest first, with their age. The Super Admin can mark what
 * is left recovered or written off from here; everyone else only sees the lines.
 */
export function OpenAdvancesTable({ rows, canClose }: { rows: OpenAdvance[]; canClose: boolean }) {
    const translate = useT();
    const [closing, setClosing] = useState<{ row: OpenAdvance; outcome: 'recover' | 'write-off' } | null>(null);
    if (rows.length === 0) {
        return <p className="muted">{translate('prize_advances', 'open_empty')}</p>;
    }

    return (
        <>
            <div className="table-wrap">
                <table className="data-table">
                    <thead>
                        <tr>
                            <th>{translate('prize_advances', 'col_partner')}</th>
                            <th>{translate('prize_advances', 'col_kind')}</th>
                            <th>{translate('prize_advances', 'col_given')}</th>
                            <th>{translate('prize_advances', 'col_left')}</th>
                            <th>{translate('prize_advances', 'col_days')}</th>
                            {canClose && <th />}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.map((row) => (
                            <tr key={row.id}>
                                <td>
                                    <b>{row.name}</b>
                                    <small className="cell-sub">{[row.code, label(row.role, translate)].filter(Boolean).join(' · ')}</small>
                                    {!row.partner_active && <small className="cell-sub error">{translate('prize_advances', 'partner_inactive')}</small>}
                                    <small className="cell-sub">{row.reason}</small>
                                </td>
                                <td>{translate('prize_advances', `kind_${row.kind}`)}</td>
                                <td>
                                    {money(row.amount)}
                                    {Number(row.settled) > 0 && <small className="cell-sub">{translate('prize_advances', 'partly_settled', { amount: money(row.settled) })}</small>}
                                </td>
                                <td><b>{money(row.left)}</b></td>
                                <td>{translate('prize_advances', 'days', { days: number(row.days) })}</td>
                                {canClose && (
                                    <td>
                                        <div className="ops-row is-inline">
                                            <button type="button" className="button small" onClick={() => setClosing({ row, outcome: 'recover' })}>{translate('prize_advances', 'recover_button')}</button>
                                            <button type="button" className="button small" onClick={() => setClosing({ row, outcome: 'write-off' })}>{translate('prize_advances', 'writeoff_button')}</button>
                                        </div>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {closing && <CloseAdvance row={closing.row} outcome={closing.outcome} onClose={() => setClosing(null)} />}
        </>
    );
}

/** Marks what is left of an advance recovered (with its receipt number) or written off; a double click counts once. */
function CloseAdvance({ row, outcome, onClose }: { row: OpenAdvance; outcome: 'recover' | 'write-off'; onClose: () => void }) {
    const translate = useT();
    const recovering = outcome === 'recover';
    const requestKey = useRef(randomUuid());
    const form = useForm({ reference: '', note: '', confirm: false, request_key: requestKey.current });
    const amount = money(row.left);

    return (
        <Modal title={translate('prize_advances', recovering ? 'recover_title' : 'writeoff_title')} onClose={onClose}>
            <form className="action-form" onSubmit={(event) => {
                event.preventDefault();
                form.post(`${COMMAND_ROOT}/${row.id}/${outcome}`, { preserveScroll: true, onSuccess: onClose });
            }}>
                <p className="muted">{translate('prize_advances', recovering ? 'recover_intro' : 'writeoff_intro')}</p>
                <p><b>{row.name}</b> · {translate('prize_advances', `kind_${row.kind}`)} · {amount}</p>
                {recovering && <label>{translate('prize_advances', 'fld_reference')}<input required minLength={3} maxLength={100} value={form.data.reference} onChange={(event) => form.setData('reference', event.target.value)} /></label>}
                <label>{translate('prize_advances', 'fld_note')}<textarea required minLength={5} maxLength={1000} value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} /></label>
                <label className="check-line">
                    <input type="checkbox" required checked={form.data.confirm} onChange={(event) => form.setData('confirm', event.target.checked)} />
                    {translate('prize_advances', recovering ? 'recover_confirm' : 'writeoff_confirm', { amount })}
                </label>
                <FormErrors errors={form.errors} />
                <div className="ops-row is-inline">
                    <button type="submit" className="button dark" disabled={form.processing}>{translate('prize_advances', recovering ? 'recover_submit' : 'writeoff_submit', { amount })}</button>
                    <button type="button" className="button small" onClick={onClose}>{translate('prize_advances', 'close')}</button>
                </div>
            </form>
        </Modal>
    );
}

/** The Super Admin pays a bike or car prize in advance. The amount starts at the prize's set amount; the fund's balance is shown. */
export function GiveAdvanceForm({ funds, preset, onClose }: { funds: AdvanceFunds; preset: { partner: AdvancePartner | null; kind: PrizeKind }; onClose: () => void }) {
    const translate = useT();
    const requestKey = useRef(randomUuid());
    const [partner, setPartner] = useState<AdvancePartner | null>(preset.partner);
    const form = useForm({
        partner_id: preset.partner ? String(preset.partner.id) : '',
        kind: preset.kind as string,
        amount: funds[preset.kind]?.award ?? '',
        reason: '',
        given_on: businessTodayIso(),
        confirm: false,
        request_key: requestKey.current,
    });
    const kind = form.data.kind as PrizeKind;
    const chooseKind = (next: PrizeKind) => {
        form.setData((data) => ({ ...data, kind: next, amount: funds[next]?.award ?? '' }));
    };

    return (
        <Modal title={translate('prize_advances', 'give_title')} onClose={onClose}>
            <form className="action-form" onSubmit={(event) => {
                event.preventDefault();
                form.post(COMMAND_ROOT, { preserveScroll: true, onSuccess: onClose });
            }}>
                <p className="muted">{translate('prize_advances', 'intro')}</p>
                <div>
                    <span>{translate('prize_advances', 'fld_partner')}</span>
                    {partner ? (
                        <p>
                            <b>{partner.name}</b> {[partner.code, label(partner.role, translate)].filter(Boolean).join(' · ')}{' '}
                            <button type="button" className="button small" onClick={() => { setPartner(null); form.setData('partner_id', ''); }}>{translate('prize_advances', 'partner_change')}</button>
                        </p>
                    ) : (
                        <PartnerFinder onPick={(picked) => { setPartner(picked); form.setData('partner_id', String(picked.id)); }} />
                    )}
                </div>
                <label>{translate('prize_advances', 'fld_kind')}
                    <select required value={kind} onChange={(event) => chooseKind(event.target.value as PrizeKind)}>
                        <option value="bike">{translate('prize_advances', 'kind_bike')}</option>
                        <option value="car">{translate('prize_advances', 'kind_car')}</option>
                    </select>
                </label>
                <p className="muted">{translate('prize_advances', 'fund_balance', { amount: money(funds[kind]?.balance ?? '0') })}</p>
                <label>{translate('prize_advances', 'fld_amount')}
                    <input required inputMode="decimal" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} />
                    <small className="muted">{funds[kind]?.award ? translate('prize_advances', 'amount_hint', { amount: money(funds[kind].award) }) : translate('prize_advances', 'amount_hint_unset')}</small>
                </label>
                <label>{translate('prize_advances', 'fld_given_on')}<DateInput required value={form.data.given_on} onChange={(event) => form.setData('given_on', event.target.value)} /></label>
                <label>{translate('prize_advances', 'fld_reason')}<textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} /></label>
                <label className="check-line">
                    <input type="checkbox" required checked={form.data.confirm} onChange={(event) => form.setData('confirm', event.target.checked)} />
                    {translate('prize_advances', 'confirm', { amount: money(form.data.amount || '0') })}
                </label>
                <FormErrors errors={form.errors} />
                <div className="ops-row is-inline">
                    <button type="submit" className="button dark" disabled={form.processing || !form.data.partner_id}>{translate('prize_advances', 'submit')}</button>
                    <button type="button" className="button small" onClick={onClose}>{translate('prize_advances', 'close')}</button>
                </div>
            </form>
        </Modal>
    );
}

/** Finds an active dealer or sub-dealer by name, code or phone, with the same search the fund spend form uses. */
function PartnerFinder({ onPick }: { onPick: (partner: AdvancePartner) => void }) {
    const translate = useT();
    const [query, setQuery] = useState('');
    const [found, setFound] = useState<AdvancePartner[]>([]);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => {
        window.clearTimeout(timer.current);
        if (query.trim().length < 2) {
            setFound([]);
            return undefined;
        }
        timer.current = window.setTimeout(() => {
            fetch(`${FUNDS_COMMAND_ROOT}/partners?q=${encodeURIComponent(query.trim())}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then((response) => (response.ok ? response.json() : null))
                .then((data) => setFound(data?.partners ?? []))
                .catch(() => setFound([]));
        }, 300);

        return () => window.clearTimeout(timer.current);
    }, [query]);

    return (
        <>
            <input aria-label={translate('prize_advances', 'partner_search')} placeholder={translate('prize_advances', 'partner_search')} value={query} onChange={(event) => setQuery(event.target.value)} />
            {found.length > 0 && (
                <ul className="pick-list">
                    {found.map((partner) => (
                        <li key={partner.id}>
                            <button type="button" className="text-link" onClick={() => onPick(partner)}>
                                {partner.name} {[partner.code, label(partner.role, translate)].filter(Boolean).join(' · ')}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}

/** On the bike and car fund pages: the advances given from this fund and not settled yet, with a way to the full list. */
export function FundAdvancesPanel({ advances, fundKey }: { advances: { open: OpenAdvance[]; can_give: boolean }; fundKey: string }) {
    const translate = useT();
    const kind = fundKey === 'car' ? 'car' : 'bike';

    return (
        <section className="panel">
            <div className="ops-row is-inline">
                <h2>{translate('prize_advances', 'open_title')}</h2>
                <Link className="text-link" href={PRIZE_ADVANCES_PAGE}>{translate('prize_advances', 'open_all')}</Link>
                {advances.can_give && <Link className="button small" href={`${PRIZE_ADVANCES_PAGE}?kind=${kind}&give=1`}>{translate('prize_advances', 'give_button')}</Link>}
            </div>
            <OpenAdvancesTable rows={advances.open} canClose={false} />
        </section>
    );
}
