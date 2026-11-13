import React from 'react';
import { useForm } from '@inertiajs/react';
import { useReasonField } from '../shared/reasonFields';

type EmailEventRow = { key: string; label: string; enabled: boolean };
type EmailFailure = { id: number; event: string; recipient: string; reason: string | null; created_at: string };

export type EmailEventPanel = {
    version: number;
    mail_ready: boolean;
    events: EmailEventRow[];
    failures: EmailFailure[];
    text: Record<string, string>;
};

/** The owner's on/off box for each event email, and the emails the mail server refused. */
export default function EmailEventSwitches({ panel, canEdit }: { panel: EmailEventPanel; canEdit: boolean }) {
    const { text } = panel;
    const changeReason = useReasonField('settings.email_events');
    const form = useForm({
        enabled: panel.events.filter(event => event.enabled).map(event => event.key),
        reason: '',
    });
    const toggle = (key: string, on: boolean) => {
        form.setData('enabled', on ? [...form.data.enabled, key] : form.data.enabled.filter(item => item !== key));
    };

    return (
        <>
            <section className="set-block">
                <h3>{text.title}</h3>
                {!panel.mail_ready && <p className="set-line" role="status">{text.not_ready}</p>}
                <form className="action-form" onSubmit={event => {
                    event.preventDefault();
                    form.transform(input => ({ ...input, version: panel.version }));
                    form.post('/commands/operations/integrations/email-events', { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
                }}>
                    <div className="set-toggles">
                        {panel.events.map(item => (
                            <label key={item.key} className="set-toggle">
                                <input type="checkbox" checked={form.data.enabled.includes(item.key)} onChange={event => toggle(item.key, event.target.checked)} disabled={!canEdit} />
                                <span><b>{item.label}</b></span>
                            </label>
                        ))}
                    </div>
                    {changeReason.shown && <label>{changeReason.label(text.reason)}
                        <textarea required={changeReason.required} minLength={changeReason.required ? 5 : undefined} maxLength={1000} rows={2} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} disabled={!canEdit} />
                    </label>}
                    {Object.keys(form.errors).length > 0 && <div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
                    <button className="button dark set-save" disabled={!canEdit || form.processing}>{form.processing ? text.saving : text.save}</button>
                </form>
            </section>

            <section className="set-block">
                <h3>{text.failures_title}</h3>
                {panel.failures.length === 0 ? <p className="set-line">{text.failures_none}</p> : (
                    <div className="table-wrap">
                        <table className="cleanup-table">
                            <thead>
                                <tr><th>{text.failures_when}</th><th>{text.failures_event}</th><th>{text.failures_to}</th><th>{text.failures_reason}</th></tr>
                            </thead>
                            <tbody>
                                {panel.failures.map(failure => (
                                    <tr key={failure.id}>
                                        <td>{failure.created_at}</td>
                                        <td>{failure.event}</td>
                                        <td>{failure.recipient}</td>
                                        <td>{failure.reason}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </section>
        </>
    );
}
