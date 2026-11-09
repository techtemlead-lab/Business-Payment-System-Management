import { useEffect, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { type Row, FormErrors } from './UI';
import { useT } from '../shared/i18n/t';

type DeletionCheck = {
    outcome: 'deleted' | 'closed';
    dealer_code: string | null;
    records: { key: string; label: string; count: number }[];
};

/**
 * The owner's Delete action inside the account dialog. The server says first whether the
 * partner will be deleted for good or closed because records must be kept.
 */
export default function PartnerAccountDelete({ account, onDone }: { account: Row; onDone: () => void }) {
    const translate = useT();
    const { auth } = usePage<any>().props;
    const allowed = ['super_admin', 'md', 'chairman'].includes(String(auth?.user?.role)) && ['dealer', 'sub_dealer'].includes(String(account.role));
    const [open, setOpen] = useState(false);
    const [check, setCheck] = useState<DeletionCheck | null>(null);
    const [failed, setFailed] = useState(false);
    const form = useForm({ reason: '', confirmation: '' });

    const load = () => {
        setFailed(false);
        setCheck(null);
        fetch(`/commands/accounts/${account.id}/deletion-check`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((json: DeletionCheck) => setCheck(json))
            .catch(() => setFailed(true));
    };

    useEffect(() => {
        if (allowed && open) {
            load();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [account.id, allowed, open]);

    if (!allowed) {
        return null;
    }

    const willDelete = check?.outcome === 'deleted';

    return (
        <section className="panel">
            <div className="panel-heading">
                <div>
                    <h3 className="ops-row is-inline"><Trash2 size={18} aria-hidden="true" /> {translate('portal', 'partner_delete_title')}</h3>
                    <p className="muted">{translate('portal', 'partner_delete_intro')}</p>
                </div>
            </div>
            {!open && <button type="button" className="button danger" onClick={() => setOpen(true)}>{translate('portal', 'partner_delete_open')}</button>}
            {open && !check && !failed && <p className="muted">{translate('portal', 'partner_delete_checking')}</p>}
            {open && failed && (
                <p className="error" role="alert">
                    {translate('portal', 'partner_delete_check_failed')}{' '}
                    <button type="button" className="button small" onClick={load}>{translate('portal', 'notice_panel_retry')}</button>
                </p>
            )}
            {open && check && (
                <>
                    <p className="error" role="alert">{translate('portal', willDelete ? 'partner_delete_will_delete' : 'partner_delete_will_close')}</p>
                    {check.records.length > 0 && (
                        <>
                            <p className="muted">{translate('portal', 'partner_delete_records_heading')}</p>
                            <ul className="aed-docs">
                                {check.records.map((record) => <li key={record.key}>{record.label}: <strong>{record.count}</strong></li>)}
                            </ul>
                        </>
                    )}
                    {check.dealer_code ? (
                        <form
                            className="action-form"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.delete(`/commands/accounts/${account.id}`, { preserveScroll: true, onSuccess: onDone });
                            }}
                        >
                            <label>
                                {translate('portal', 'partner_delete_reason')}
                                <textarea required minLength={5} maxLength={1000} value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} />
                            </label>
                            <label>
                                {translate('portal', 'partner_delete_type_code', { code: check.dealer_code })}
                                <input required autoComplete="off" maxLength={60} value={form.data.confirmation} onChange={(event) => form.setData('confirmation', event.target.value)} />
                            </label>
                            <FormErrors errors={form.errors} />
                            <div className="ops-row is-inline">
                                <button className="button danger" disabled={form.processing || form.data.confirmation.trim() !== check.dealer_code}>
                                    {translate('portal', willDelete ? 'partner_delete_confirm_delete' : 'partner_delete_confirm_close')}
                                </button>
                                <button type="button" className="button" onClick={() => setOpen(false)}>{translate('portal', 'cancel')}</button>
                            </div>
                        </form>
                    ) : (
                        <p className="muted">{translate('portal', 'partner_delete_no_code')}</p>
                    )}
                </>
            )}
        </section>
    );
}
