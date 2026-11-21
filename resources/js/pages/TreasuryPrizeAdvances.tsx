import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import { date, money } from '../components/UI';
import TreasuryPage from '../components/treasury/TreasuryPage';
import { GiveAdvanceForm, OpenAdvancesTable, type AdvanceFunds, type AdvancePartner, type OpenAdvance, type PrizeKind } from '../components/treasury/PrizeAdvances';
import { useT } from '../shared/i18n/t';

type FinishedAdvance = {
    id: number;
    name: string;
    code: string | null;
    kind: PrizeKind;
    amount: string;
    status: 'settled' | 'recovered' | 'written_off';
    settled: string;
    closed: string | null;
    given_on: string;
    closed_on: string | null;
    note: string | null;
};

/** Bike and car prizes paid in advance (owners and finance only): the open ones with their age, the give form, and the finished ones. */
export default function TreasuryPrizeAdvances({ open, finished, can_give, funds, preset }: {
    open: OpenAdvance[];
    finished: FinishedAdvance[];
    can_give: boolean;
    funds: AdvanceFunds;
    preset: { partner: AdvancePartner | null; kind: PrizeKind };
}) {
    const translate = useT();
    const query = new URLSearchParams(usePage().url.split('?')[1] ?? '');
    // The partner page and the fund page open this page with the give form already up.
    const [giving, setGiving] = useState(() => can_give && (preset.partner !== null || query.get('give') === '1'));

    return (
        <TreasuryPage
            title={translate('prize_advances', 'title')}
            back={{ href: '/treasury/funds', label: translate('treasury', 'title') }}
            action={can_give ? <button type="button" className="button dark" onClick={() => setGiving(true)}>{translate('prize_advances', 'give_button')}</button> : undefined}
        >
            <p className="muted">{translate('prize_advances', 'intro')}</p>
            <section className="panel">
                <h2>{translate('prize_advances', 'open_title')}</h2>
                <OpenAdvancesTable rows={open} canClose={can_give} />
            </section>
            {finished.length > 0 && (
                <section className="panel">
                    <h2>{translate('prize_advances', 'finished_title')}</h2>
                    <div className="table-wrap">
                        <table className="data-table">
                            <thead>
                                <tr>
                                    <th>{translate('prize_advances', 'col_partner')}</th>
                                    <th>{translate('prize_advances', 'col_kind')}</th>
                                    <th>{translate('prize_advances', 'col_given')}</th>
                                    <th>{translate('prize_advances', 'col_status')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {finished.map((row) => (
                                    <tr key={row.id}>
                                        <td><b>{row.name}</b>{row.code && <small className="cell-sub">{row.code}</small>}</td>
                                        <td>{translate('prize_advances', `kind_${row.kind}`)}</td>
                                        <td>{money(row.amount)}<small className="cell-sub">{date(row.given_on)}</small></td>
                                        <td>
                                            {translate('prize_advances', `status_${row.status}`)}
                                            {row.closed_on && <small className="cell-sub">{date(row.closed_on)}</small>}
                                            {row.note && <small className="cell-sub">{row.note}</small>}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            {giving && <GiveAdvanceForm funds={funds} preset={preset} onClose={() => setGiving(false)} />}
        </TreasuryPage>
    );
}
