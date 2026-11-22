import { Link, router, usePage } from '@inertiajs/react';
import { date, money } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { WithdrawalRequest } from './types';
import { useConfirm } from '../ConfirmSheet';

/** Requests the office has not paid yet, and recent refusals with the office's reason. */
export default function WithdrawalRequests({ requests }: { requests: WithdrawalRequest[] }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const { confirm, sheet } = useConfirm();

    if (requests.length === 0) {
        return null;
    }

    return (
        <section className="mm-requests" aria-labelledby="mm-requests-title">
            <h2 id="mm-requests-title">{translate('my_money', 'requests_heading')}</h2>
            {sheet}
            <ul>
                {requests.map((request) => (
                    <li key={request.id}>
                        <Link className="mm-request" href={`${base}/withdrawals/${request.id}`}>
                            <time dateTime={request.created_at}>{date(request.created_at)}</time>
                            <strong>{money(request.amount)}</strong>
                            <span>{translate('my_money', `request_status_${request.status}`)}</span>
                            {request.reason && <small>{request.reason}</small>}
                            {request.on_hold && <small>{translate('payouts', 'partner_on_hold')}</small>}
                        </Link>
                        {/* Owner decision D4: a partner may take back a request nobody has approved yet. */}
                        {request.status === 'requested' && (
                            <button type="button" className="text-link" onClick={async () => {
                                const answer = await confirm({
                                    title: translate('payouts', 'wd_cancel_title'),
                                    sentence: translate('payouts', 'cancel_confirm'),
                                    amount: request.amount,
                                    facts: [{ label: translate('payouts', 'cs_fact_request'), value: date(request.created_at) }],
                                    tone: 'danger',
                                    confirmLabel: translate('payouts', 'cancel_request'),
                                    cancelLabel: translate('payouts', 'wd_cancel_keep'),
                                });
                                if (answer) {
                                    router.post(`/commands/withdrawals/${request.id}/cancel`, {}, { preserveScroll: true });
                                }
                            }}>{translate('payouts', 'cancel_request')}</button>
                        )}
                    </li>
                ))}
            </ul>
        </section>
    );
}
