import { useEffect, useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { Stethoscope } from 'lucide-react';
import { type Row, FormErrors, dateTime } from './UI';
import { useT } from '../shared/i18n/t';
import { useReasonFields } from '../shared/reasonFields';

type PrescriberStatus = {
    can_edit: boolean;
    types: string[];
    enabled: boolean;
    registration_type: string | null;
    registration_number: string | null;
    note: string | null;
    enabled_by: string | null;
    enabled_at: string | null;
    disabled_by: string | null;
    disabled_at: string | null;
    disable_reason: string | null;
};

/**
 * The per-account prescription switch: for partners on the accounts page and for office
 * accounts on the employees page, where it means prescribing from company stock. Owners and
 * staff with the "Verify prescribers" permission turn it on after checking the papers in person.
 */
export default function PartnerPrescriberControls({ account }: { account: Row }) {
    const translate = useT();
    const reasonOf = useReasonFields();
    const noteBox = reasonOf('partner.prescriber_note');
    const disableReason = reasonOf('partner.prescriber_disable');
    const { auth } = usePage<any>().props;
    const office = !['dealer', 'sub_dealer'].includes(String(account.role));
    const self = Number(auth?.user?.id) === Number(account.id);
    const allowed = Boolean(auth?.prescriberVerifier) && String(account.role) !== 'super_admin';
    const [status, setStatus] = useState<PrescriberStatus | null>(null);
    const [failed, setFailed] = useState(false);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const form = useForm({ registration_type: 'bmdc', registration_number: '', note: '' });

    const load = () => {
        setFailed(false);
        fetch(`/commands/accounts/${account.id}/prescriber`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((response) => (response.ok ? response.json() : Promise.reject(response.status)))
            .then((json: PrescriberStatus) => {
                setStatus(json);
                form.setData({
                    registration_type: json.registration_type ?? json.types[0] ?? 'bmdc',
                    registration_number: json.registration_number ?? '',
                    note: json.note ?? '',
                });
            })
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

    return (
        <section className="panel pbc-panel">
            <div className="panel-heading">
                <div>
                    <h3 className="ops-row is-inline">
                        <Stethoscope size={18} /> {translate('prescription', office ? 'office_title' : 'verify_title')}{' '}
                        {status && <span className={`badge ${status.enabled ? 'active' : 'suspended'}`}>{translate('prescription', status.enabled ? 'verify_status_on' : 'verify_status_off')}</span>}
                    </h3>
                    <p className="muted pbc-hint">{translate('prescription', office ? 'office_body' : 'verify_body', { name: String(account.name ?? '') })}</p>
                    {status?.enabled && status.enabled_by && status.enabled_at && (
                        <p className="muted pbc-hint">{translate('prescription', 'verify_enabled_by', { name: status.enabled_by, date: dateTime(status.enabled_at) })}</p>
                    )}
                    {!status?.enabled && status?.disabled_by && status.disabled_at && (
                        <p className="muted pbc-hint">{translate('prescription', 'verify_disabled_by', { name: status.disabled_by, date: dateTime(status.disabled_at) })}{status.disable_reason ? ` — ${status.disable_reason}` : ''}</p>
                    )}
                </div>
            </div>
            {!status && !failed && <p className="muted">{translate('prescription', 'verify_loading')}</p>}
            {failed && (
                <p className="error" role="alert">
                    {translate('prescription', 'verify_failed')}{' '}
                    <button type="button" className="button small" onClick={load}>{translate('portal', 'notice_panel_retry')}</button>
                </p>
            )}
            {status && (
                <>
                    {self && <p className="muted">{translate('prescription', 'err_self_grant')}</p>}
                    {!self && <form
                        className="action-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(`/commands/accounts/${account.id}/prescriber`, { preserveScroll: true, onSuccess: load });
                        }}
                    >
                        <label>
                            {translate('prescription', 'registration_type')}
                            <select value={form.data.registration_type} onChange={(e) => form.setData('registration_type', e.target.value)}>
                                {status.types.map((type) => <option key={type} value={type}>{translate('prescription', `type_${type}`)}</option>)}
                            </select>
                        </label>
                        <label>
                            {translate('prescription', 'registration_number')}
                            <input required maxLength={60} value={form.data.registration_number} onChange={(e) => form.setData('registration_number', e.target.value)} />
                        </label>
                        {noteBox.shown && (
                            <label>
                                {noteBox.label(translate('prescription', 'registration_note'))}
                                <textarea rows={2} maxLength={1000} required={noteBox.required} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} />
                            </label>
                        )}
                        <FormErrors errors={form.errors} />
                        <button className="button dark" disabled={form.processing}>
                            {translate('prescription', status.enabled ? 'verify_update' : 'verify_enable')}
                        </button>
                    </form>}
                    {status.enabled && (
                        <form
                            className="action-form pbc-disable"
                            onSubmit={(event) => {
                                event.preventDefault();
                                setBusy(true);
                                router.delete(`/commands/accounts/${account.id}/prescriber`, {
                                    data: { reason },
                                    preserveScroll: true,
                                    onSuccess: () => { setReason(''); load(); },
                                    onFinish: () => setBusy(false),
                                });
                            }}
                        >
                            {disableReason.shown && (
                                <label>
                                    {disableReason.label(translate('prescription', 'verify_reason'))}
                                    <input maxLength={500} required={disableReason.required} value={reason} onChange={(e) => setReason(e.target.value)} />
                                </label>
                            )}
                            <button className="button" disabled={busy}>{translate('prescription', 'verify_disable')}</button>
                        </form>
                    )}
                </>
            )}
        </section>
    );
}
