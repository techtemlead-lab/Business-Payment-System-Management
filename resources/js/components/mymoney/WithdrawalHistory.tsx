import { Link, router, usePage } from '@inertiajs/react';
import { ExternalLink, FileText, Printer } from 'lucide-react';
import { Badge, dateTimeSeconds, money, number } from '../UI';
import { useConfirm } from '../ConfirmSheet';
import { ProofLink, proofKindOf } from '../ProofViewer';
import { useT } from '../../shared/i18n/t';
import { channelName } from '../../shared/payoutMethods';
import type { WithdrawalHistoryData, WithdrawalHistoryRow } from './types';

const STATUS_OPTIONS = ['all', 'paid', 'requested', 'approved', 'rejected', 'cancelled', 'returned'] as const;
const CHANNEL_OPTIONS = ['all', 'Office', 'Bank', 'bKash', 'Nagad', 'Rocket'] as const;

function methodDetails(row: WithdrawalHistoryRow, translate: ReturnType<typeof useT>): string[] {
    const parts: string[] = [];
    if (row.destination_masked && row.destination_masked !== '—') {
        parts.push(row.destination_masked);
    }
    if (row.bank_name) {
        parts.push(row.bank_name);
    }
    if (row.received_by) {
        parts.push(`${translate('portal', 'pay_fld_received_by')}: ${row.received_by}`);
    }
    if (row.payment_reference) {
        parts.push(`${translate('portal', 'wd_payment_ref')}: ${row.payment_reference}`);
    }

    return parts;
}

/**
 * Full lifetime withdrawal history account: every withdrawal record with exact second-level timestamps,
 * payment channel, masked destination/office details, payment reference, receipt/proof links, and filters.
 */
export default function WithdrawalHistory({ data, allowCancel = true }: { data?: WithdrawalHistoryData | null; allowCancel?: boolean }) {
    const translate = useT();
    const { url, props } = usePage<any>();
    const base: string = props.portalBase ?? '';
    const { confirm, sheet } = useConfirm();

    if (!data) {
        return null;
    }

    const { summary, filters, rows } = data;
    const visit = (patch: Record<string, string | number | undefined>) => {
        const current = new URL(url || '/my-money', 'http://wallet.local');
        const params: Record<string, string> = {};
        current.searchParams.forEach((value, key) => {
            params[key] = value;
        });
        for (const [key, value] of Object.entries(patch)) {
            if (value === undefined || value === '' || value === 'all') {
                delete params[key];
            } else {
                params[key] = String(value);
            }
        }
        router.get(current.pathname, params, {
            only: ['withdrawal_history'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    return (
        <section id="mm-withdrawals" className="mm-wd-history" aria-labelledby="mm-wd-history-title" data-testid="withdrawal-history">
            <div className="mm-wd-head">
                <div>
                    <h2 id="mm-wd-history-title">{translate('my_money', 'wd_history_heading')}</h2>
                    <p className="muted">{translate('my_money', 'wd_history_intro')}</p>
                </div>
                <dl className="mm-wd-summary">
                    <div>
                        <dt>{translate('my_money', 'wd_summary_paid')}</dt>
                        <dd>{money(summary.paid_total)} <small>({number(summary.paid_count)})</small></dd>
                    </div>
                    {summary.pending_count > 0 && (
                        <div>
                            <dt>{translate('my_money', 'wd_summary_pending')}</dt>
                            <dd>{money(summary.pending_total)} <small>({number(summary.pending_count)})</small></dd>
                        </div>
                    )}
                    <div>
                        <dt>{translate('my_money', 'wd_summary_total')}</dt>
                        <dd>{number(summary.total_count)}</dd>
                    </div>
                </dl>
            </div>

            {sheet}

            <div className="mm-wd-filters">
                <div className="mm-wd-chips" role="group" aria-label={translate('my_money', 'wd_filter_status')}>
                    {STATUS_OPTIONS.map((status) => (
                        <button
                            key={status}
                            type="button"
                            className={`mm-wd-chip${filters.status === status ? ' is-on' : ''}`}
                            aria-pressed={filters.status === status}
                            onClick={() => visit({ wd_status: status, wd_page: undefined })}
                        >
                            {translate('my_money', `wd_filter_${status}`)}
                        </button>
                    ))}
                </div>
                <label className="mm-wd-channel">
                    <span>{translate('my_money', 'wd_filter_channel')}</span>
                    <select
                        value={filters.channel}
                        onChange={(event) => visit({ wd_channel: event.target.value, wd_page: undefined })}
                    >
                        {CHANNEL_OPTIONS.map((channel) => (
                            <option key={channel} value={channel}>
                                {channel === 'all' ? translate('my_money', 'wd_filter_all') : channelName(channel, translate)}
                            </option>
                        ))}
                    </select>
                </label>
            </div>

            {rows.data.length === 0 ? (
                <p className="mm-wd-empty muted">{translate('my_money', 'wd_history_empty')}</p>
            ) : (
                <>
                    <div className="mm-wd-table-wrap">
                        <table className="mm-wd-table">
                            <thead>
                                <tr>
                                    <th>{translate('my_money', 'wd_col_time')}</th>
                                    <th>{translate('my_money', 'wd_col_method')}</th>
                                    <th className="num">{translate('my_money', 'wd_col_amount')}</th>
                                    <th>{translate('my_money', 'wd_col_status')}</th>
                                    <th>{translate('my_money', 'wd_col_docs')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.data.map((row) => {
                                    const details = methodDetails(row, translate);
                                    const withheld = Number(row.withheld_amount) > 0;

                                    return (
                                        <tr key={row.id}>
                                            <td data-label={translate('my_money', 'wd_col_time')}>
                                                <div className="mm-wd-time">
                                                    <time dateTime={row.created_at}>{dateTimeSeconds(row.created_at)}</time>
                                                    {row.paid_at && (
                                                        <small>
                                                            {translate('my_money', 'wd_paid_time')}: <time dateTime={row.paid_at}>{dateTimeSeconds(row.paid_at)}</time>
                                                        </small>
                                                    )}
                                                </div>
                                            </td>
                                            <td data-label={translate('my_money', 'wd_col_method')}>
                                                <div className="mm-wd-method">
                                                    <strong>{channelName(row.channel, translate)}</strong>
                                                    {details.length > 0 && <small>{details.join(' · ')}</small>}
                                                    {row.payout_line && <small className="mm-wd-note">{row.payout_line}</small>}
                                                </div>
                                            </td>
                                            <td className="num" data-label={translate('my_money', 'wd_col_amount')}>
                                                <strong>{money(row.amount)}</strong>
                                                {withheld && (
                                                    <small className="mm-wd-net">
                                                        {translate('my_money', 'wd_net_label')}: {money(row.net_amount)}
                                                    </small>
                                                )}
                                            </td>
                                            <td data-label={translate('my_money', 'wd_col_status')}>
                                                <div className="mm-wd-status">
                                                    {row.status === 'returned' ? (
                                                        <span className="badge rejected">{translate('payouts', 'status_returned')}</span>
                                                    ) : (
                                                        <Badge status={row.status} />
                                                    )}
                                                    {row.on_hold && <small className="mm-wd-warn">{translate('payouts', 'partner_on_hold')}</small>}
                                                    {row.reason && <small>{row.reason}</small>}
                                                </div>
                                            </td>
                                            <td data-label={translate('my_money', 'wd_col_docs')}>
                                                <div className="mm-wd-docs">
                                                    <Link className="button small" href={`${base}${row.detail_url}`}>
                                                        <FileText size={14} aria-hidden="true" />
                                                        {translate('my_money', 'wd_open_detail')}
                                                    </Link>
                                                    {row.receipt_print_url && (
                                                        <a className="button small" href={`${base}${row.receipt_print_url}`} target="_blank" rel="noopener">
                                                            <Printer size={14} aria-hidden="true" />
                                                            {row.receipt_number ?? translate('portal', 'prc_view_print')}
                                                        </a>
                                                    )}
                                                    {row.has_proof && row.proof_url && (
                                                        <ProofLink
                                                            className="button small"
                                                            href={`${base}${row.proof_url}`}
                                                            kind={row.proof_is_image ? 'image' : proofKindOf(null)}
                                                            title={translate('office_withdrawal', 'proof_title')}
                                                        >
                                                            <ExternalLink size={14} aria-hidden="true" />
                                                            {translate('my_money', 'wd_view_proof')}
                                                        </ProofLink>
                                                    )}
                                                    {allowCancel && row.can_cancel && (
                                                        <button
                                                            type="button"
                                                            className="button small danger"
                                                            onClick={async () => {
                                                                const answer = await confirm({
                                                                    title: translate('payouts', 'wd_cancel_title'),
                                                                    sentence: translate('payouts', 'cancel_confirm'),
                                                                    amount: row.amount,
                                                                    facts: [
                                                                        { label: translate('payouts', 'cs_fact_method'), value: channelName(row.channel, translate) },
                                                                        { label: translate('payouts', 'cs_fact_request'), value: dateTimeSeconds(row.created_at) },
                                                                    ],
                                                                    tone: 'danger',
                                                                    confirmLabel: translate('payouts', 'cancel_request'),
                                                                    cancelLabel: translate('payouts', 'wd_cancel_keep'),
                                                                });
                                                                if (answer) {
                                                                    router.post(`/commands/withdrawals/${row.id}/cancel`, {}, { preserveScroll: true });
                                                                }
                                                            }}
                                                        >
                                                            {translate('payouts', 'cancel_request')}
                                                        </button>
                                                    )}
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    {rows.last_page > 1 && (
                        <div className="mm-wd-pages">
                            <button
                                type="button"
                                className="button small"
                                disabled={rows.current_page <= 1}
                                onClick={() => visit({ wd_page: rows.current_page - 1 })}
                            >
                                {translate('my_money', 'wd_page_prev')}
                            </button>
                            <span>
                                {translate('my_money', 'wd_page_of', {
                                    current: number(rows.current_page),
                                    total: number(rows.last_page),
                                })}
                            </span>
                            <button
                                type="button"
                                className="button small"
                                disabled={rows.current_page >= rows.last_page}
                                onClick={() => visit({ wd_page: rows.current_page + 1 })}
                            >
                                {translate('my_money', 'wd_page_next')}
                            </button>
                        </div>
                    )}
                </>
            )}
        </section>
    );
}
