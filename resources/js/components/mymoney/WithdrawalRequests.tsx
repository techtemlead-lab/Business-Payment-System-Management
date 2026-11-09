import { Link, usePage } from '@inertiajs/react';
import { date, money } from '../UI';
import { useT } from '../../shared/i18n/t';
import type { WithdrawalRequest } from './types';

/** Requests the office has not paid yet, and recent refusals with the office's reason. */
export default function WithdrawalRequests({ requests }: { requests: WithdrawalRequest[] }) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;

    if (requests.length === 0) {
        return null;
    }

    return (
        <section className="mm-requests" aria-labelledby="mm-requests-title">
            <h2 id="mm-requests-title">{translate('my_money', 'requests_heading')}</h2>
            <ul>
                {requests.map((request) => (
                    <li key={request.id}>
                        <Link className="mm-request" href={`${base}/withdrawals/${request.id}`}>
                            <time dateTime={request.created_at}>{date(request.created_at)}</time>
                            <strong>{money(request.amount)}</strong>
                            <span>{translate('my_money', `request_status_${request.status}`)}</span>
                            {request.reason && <small>{request.reason}</small>}
                        </Link>
                    </li>
                ))}
            </ul>
        </section>
    );
}
