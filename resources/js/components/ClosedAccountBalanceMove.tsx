import { useEffect, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Landmark } from 'lucide-react';
import { type Row, FormErrors, money } from './UI';
import { useT } from '../shared/i18n/t';
import { randomUuid } from '../shared/randomUuid';

type BalanceCheck = { closed: boolean; amount: string; can_move: boolean };

/**
 * The Super Admin's "move this closed account's money to the company fund" action. The server says how much is in
 * the wallet first; that exact amount is sent back, so a balance that changed in between is refused, and the one
 * request key makes a double click count once.
 */
export default function ClosedAccountBalanceMove({ account, onDone }: { account: Row; onDone?: () => void }) {
    const translate = useT();
    const { auth } = usePage<any>().props;
    const allowed = String(auth?.user?.role) === 'super_admin' && ['dealer', 'sub_dealer'].includes(String(account.role)) && Boolean(account.closed_at);
    const [check, setCheck] = useState<BalanceCheck | null>(null);
    const [failed, setFailed] = useState(false);
    const requestKey = useRef(randomUuid());
    const form = useForm({ reason: '', confirm: false, expected_amount: '', request_key: requestKey.current });

    const load = () => {
        setFailed(false);
        setCheck(null);
        fetch(`/commands/accounts/${account.id}/closed-balance`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((json: BalanceCheck) => setCheck(json))
            .catch(() => setFailed(true));
    };

    useEffect(() => {
        if (allowed) {
            load();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [account.id, allowed]);

    if (!allowed) {
        return null;
    }

    const amount = check ? money(check.amount, { symbol: false }) : '';

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h3 className="ops-row is-inline"><Landmark size={18} aria-hidden="true" /> {translate('money_core', 'cb_title')}</h3>
                    <p className="muted">{translate('money_core', 'cb_intro')}</p>
                </div>
            </div>
            {!check && !failed && <p className="muted">{translate('money_core', 'cb_checking')}</p>}
            {failed && (
                <p className="error" role="alert">
                    {translate('money_core', 'cb_failed')}{' '}
                    <button type="button" className="button small" onClick={load}>{translate('portal', 'notice_panel_retry')}</button>
                </p>
            )}
            {check && (
                <>
                    <p>{translate('money_core', 'cb_amount')}: <strong>{money(check.amount)}</strong></p>
                    {!check.can_move ? <p className="muted">{translate('money_core', 'cb_none')}</p> : (
                        <form
                            className="action-form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.transform((data) => ({ ...data, expected_amount: check.amount }));
                                form.post(`/commands/accounts/${account.id}/closed-balance/to-company`, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        requestKey.current = randomUuid();
                                        form.reset();
                                        form.setData('request_key', requestKey.current);
                                        load();
                                        onDone?.();
                                    },
                                    onError: () => load(),
                                });
                            }}
                        >
                            <label>
                                {translate('money_core', 'cb_reason')}
                                <textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                            </label>
                            <label className="import-confirmation">
                                <input type="checkbox" required checked={form.data.confirm} onChange={(event) => form.setData('confirm', event.target.checked)} /> {translate('money_core', 'cb_confirm', { amount })}
                            </label>
                            <FormErrors errors={form.errors} />
                            <button className="button danger" disabled={form.processing || !form.data.confirm || form.data.reason.trim().length < 5}>
                                {translate('money_core', 'cb_button', { amount })}
                            </button>
                        </form>
                    )}
                </>
            )}
        </section>
    );
}
