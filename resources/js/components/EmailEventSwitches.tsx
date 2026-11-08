import React from 'react';
import { useForm } from '@inertiajs/react';

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
    const form = useForm({
        enabled: panel.events.filter(event => event.enabled).map(event => event.key),
        reason: '',
    });
    const toggle = (key: string, on: boolean) => {
        form.setData('enabled', on ? [...form.data.enabled, key] : form.data.enabled.filter(item => item !== key));
    };

    return (
        <section className="panel narrow-panel ops-mt-4">
            <h3>{text.title}</h3>
            <p className="muted">{text.hint}</p>
            {!panel.mail_ready && <div className="notice ops-mb-3" role="status">{text.not_ready}</div>}
            <form className="action-form" onSubmit={event => {
                event.preventDefault();
                form.transform(input => ({ ...input, version: panel.version }));
                form.post('/commands/operations/integrations/email-events', { preserveScroll: true, onSuccess: () => form.setData('reason', '') });
            }}>
                <div className="import-options ops-mb-3">
                    {panel.events.map(item => (
                        <label key={item.key} className="import-choice">
                            <input type="checkbox" checked={form.data.enabled.includes(item.key)} onChange={event => toggle(item.key, event.target.checked)} disabled={!canEdit} />
                            {item.label}
                        </label>
                    ))}
                </div>
                <label>{text.reason}
                    <textarea required minLength={5} maxLength={1000} rows={2} value={form.data.reason} onChange={event => form.setData('reason', event.target.value)} disabled={!canEdit} />
                </label>
                {Object.keys(form.errors).length > 0 && <div role="alert" className="error">{Object.values(form.errors).join(' ')}</div>}
                <button className="button dark" disabled={!canEdit || form.processing}>{form.processing ? text.saving : text.save}</button>
            </form>

            <h3 className="ops-mt-4">{text.failures_title}</h3>
            <p className="muted">{text.failures_hint}</p>
            {panel.failures.length === 0 ? <p className="muted">{text.failures_none}</p> : (
                <div className="table-wrap">
                    <table>
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
    );
}
