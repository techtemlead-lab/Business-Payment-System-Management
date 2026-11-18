import { useState, type FormEvent, type ReactNode } from 'react';
import { router } from '@inertiajs/react';
import { Empty, date, money, number } from '../components/UI';
import TreasuryPage from '../components/treasury/TreasuryPage';
import Figures from '../components/office/Figures';
import { isNegative } from '../components/treasury/format';
import { t } from '../shared/i18n/t';

type FundLine = { key: string; name: string; amount: string };

type MissingCost = { product_id: number; name: string; sku: string; units: number; sales: string };

type OverMargin = { product_id: number; name: string; sku: string; dealer_price: string; cost: string; limit: string; over_by: string; cost_percent: string };

type Report = {
    period: { from: string; to: string };
    orders: { count: number; with_commission: number };
    figures: {
        sales: string;
        refunds: string;
        net_sales: string;
        cost_of_goods: string;
        gross_margin: string;
        partners_credited: string;
        partners_waiting: string;
        funds_total: string;
        set_aside_total: string;
        courier: string | null;
        payroll: string;
        office_spending: string;
        profit: string;
    };
    funds: FundLine[];
    kept: { company_share: string; company_profit: string; owners_share: string; total: string };
    cost_basis: { from_batches: string; from_product_price: string; missing_units: number };
    payroll_slips: number;
    plan_share: { limit: string; actual: string | null };
    check: { adds_up: boolean; difference: string };
    warnings: {
        missing_cost: MissingCost[];
        over_margin: OverMargin[];
        no_commission_yet: { orders: number; sales: string };
    };
};

type Say = (key: string, replace?: Record<string, string | number>) => string;

/** A money amount taken away from the line above it: shown with a minus so the sum reads down. */
const less = (value: string) => `− ${money(value)}`;

/** One statement line: the words, an optional note under them, and the amount. */
function Line({ label, note, amount, strong = false }: { label: string; note?: ReactNode; amount: ReactNode; strong?: boolean }) {
    return (
        <tr>
            <td>{strong ? <b>{label}</b> : label}{note && <small className="cell-sub">{note}</small>}</td>
            <td className="is-money" data-part={strong ? 'amount' : undefined}>{amount}</td>
        </tr>
    );
}

function Block({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="tsy-block">
            <h2>{title}</h2>
            <div className="panel table-panel">
                <table className="tsy-lines">
                    <tbody>{children}</tbody>
                </table>
            </div>
        </section>
    );
}

/** Pick a whole month, or two dates. The address keeps the choice, so the page can be shared or reloaded. */
function PeriodPicker({ say, month, period }: { say: Say; month: string | null; period: Report['period'] }) {
    const [from, setFrom] = useState(month ? '' : period.from);
    const [to, setTo] = useState(month ? '' : period.to);
    const show = (event: FormEvent) => {
        event.preventDefault();
        if (from && to) {
            router.get('/treasury/profit', { from, to }, { preserveScroll: true });
        }
    };

    return (
        <form className="tsy-block-tools tsy-month" onSubmit={show}>
            <label>{say('pick_month')}<input type="month" value={month ?? ''} onChange={(event) => event.target.value && router.get('/treasury/profit', { month: event.target.value }, { preserveScroll: true })} /></label>
            <label>{say('pick_from')}<input type="date" value={from} onChange={(event) => setFrom(event.target.value)} /></label>
            <label>{say('pick_to')}<input type="date" value={to} min={from || undefined} onChange={(event) => setTo(event.target.value)} /></label>
            <button type="submit" className="button small" disabled={!from || !to}>{say('pick_show')}</button>
        </form>
    );
}

/**
 * The owner's profit and loss: what the delivered goods sold for, what the commission plan took
 * from those sales, the other costs and what is left, with warnings when a figure is not whole.
 */
export default function TreasuryProfit({ report, month, copy }: { report: Report; month: string | null; copy: Record<string, string> }) {
    const say: Say = (key, replace = {}) => t({ profit_loss: copy }, 'profit_loss', key, replace);
    const { figures, warnings, kept } = report;
    const loss = isNegative(figures.profit);
    const profitWord = say(loss ? 'line_loss' : 'line_profit');
    const unposted = warnings.no_commission_yet;

    return (
        <TreasuryPage title={say('title')} subtitle={say('subtitle', { from: date(report.period.from), to: date(report.period.to) })} showTabs>
            <div className="tsy-block-head">
                <p className="tsy-line">{say('how_counted')}</p>
                <PeriodPicker key={`${report.period.from}-${report.period.to}`} say={say} month={month} period={report.period} />
            </div>

            {warnings.missing_cost.length > 0 && (
                <div className="notice warning" role="alert">
                    <b>{say('warn_missing_title')}</b>
                    <p>{say('warn_missing_body')}</p>
                    <div className="table-wrap">
                        <table className="tsy-lines">
                            <thead><tr><th>{say('col_product')}</th><th className="is-money">{say('col_units')}</th><th className="is-money">{say('col_sales')}</th></tr></thead>
                            <tbody>
                                {warnings.missing_cost.map((product) => (
                                    <tr key={product.product_id}>
                                        <td><b>{product.name}</b><small className="cell-sub">{product.sku}</small></td>
                                        <td className="is-money">{number(product.units)}</td>
                                        <td className="is-money">{money(product.sales)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {warnings.over_margin.length > 0 && (
                <div className="notice warning" role="alert">
                    <b>{say('warn_margin_title')}</b>
                    <p>{say('warn_margin_body', { limit: report.plan_share.limit })}</p>
                    <div className="table-wrap">
                        <table className="tsy-lines">
                            <thead>
                                <tr>
                                    <th>{say('col_product')}</th>
                                    <th className="is-money">{say('col_dealer_price')}</th>
                                    <th className="is-money">{say('col_cost')}</th>
                                    <th className="is-money">{say('col_limit')}</th>
                                    <th className="is-money">{say('col_over_by')}</th>
                                    <th className="is-money">{say('col_cost_percent')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {warnings.over_margin.map((product) => (
                                    <tr key={product.product_id}>
                                        <td><b>{product.name}</b><small className="cell-sub">{product.sku}</small></td>
                                        <td className="is-money">{money(product.dealer_price)}</td>
                                        <td className="is-money">{money(product.cost)}</td>
                                        <td className="is-money">{money(product.limit)}</td>
                                        <td className="is-money is-negative"><b>{money(product.over_by)}</b></td>
                                        <td className="is-money">{number(product.cost_percent)}%</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}

            {unposted.orders > 0 && <div className="notice warning" role="status">{say('warn_unposted', { orders: unposted.orders, amount: money(unposted.sales) })}</div>}

            {report.orders.count === 0 ? (
                <Empty title={say('empty_title')} body={say('empty_body')} />
            ) : (
                <>
                    <Figures
                        items={[
                            { key: 'net_sales', label: say('card_net_sales'), value: figures.net_sales },
                            { key: 'cost', label: say('card_cost'), value: figures.cost_of_goods },
                            { key: 'set_aside', label: say('card_set_aside'), value: figures.set_aside_total },
                            { key: 'profit', label: say(loss ? 'card_loss' : 'card_profit'), value: figures.profit, headline: true },
                        ]}
                    />
                    <p className="tsy-line">{say('orders_line', { orders: report.orders.count, posted: report.orders.with_commission })}</p>

                    <Block title={say('block_sales')}>
                        <Line label={say('line_sales')} amount={money(figures.sales)} />
                        <Line label={say('line_refunds')} amount={less(figures.refunds)} />
                        <Line label={say('line_net_sales')} amount={money(figures.net_sales)} strong />
                        <Line
                            label={say('line_cost')}
                            note={say('cost_basis', { batch: money(report.cost_basis.from_batches), product: money(report.cost_basis.from_product_price) })}
                            amount={less(figures.cost_of_goods)}
                        />
                        <Line label={say('line_gross')} amount={money(figures.gross_margin)} strong />
                    </Block>

                    <Block title={say('block_plan')}>
                        <Line label={say('line_partners_credited')} amount={less(figures.partners_credited)} />
                        <Line label={say('line_partners_waiting')} amount={less(figures.partners_waiting)} />
                        {report.funds.map((fund) => <Line key={fund.key} label={fund.name} amount={less(fund.amount)} />)}
                        <Line
                            label={say('line_set_aside')}
                            note={report.plan_share.actual !== null ? say('plan_share', { actual: number(report.plan_share.actual), limit: report.plan_share.limit }) : undefined}
                            amount={less(figures.set_aside_total)}
                            strong
                        />
                    </Block>

                    <Block title={say('block_costs')}>
                        <Line label={say('line_courier')} amount={<span className="muted">{say('courier_not_recorded')}</span>} />
                        <Line label={say('line_payroll')} note={say('payroll_note', { slips: report.payroll_slips })} amount={less(figures.payroll)} />
                        <Line label={say('line_office')} note={say('office_note')} amount={less(figures.office_spending)} />
                    </Block>

                    <Block title={say('block_result')}>
                        <Line label={profitWord} amount={<span className={loss ? 'is-negative' : undefined}>{money(figures.profit)}</span>} strong />
                    </Block>

                    <Block title={say('block_kept')}>
                        <Line label={say('kept_company_share')} amount={money(kept.company_share)} />
                        <Line label={say('kept_company_profit')} amount={money(kept.company_profit)} />
                        <Line label={say('kept_owners_share')} amount={money(kept.owners_share)} />
                        <Line label={say('kept_total')} note={say('kept_note')} amount={money(kept.total)} strong />
                    </Block>

                    <p className="tsy-line">{report.check.adds_up ? say('check_ok') : say('check_off', { amount: money(report.check.difference) })}</p>
                </>
            )}
        </TreasuryPage>
    );
}
