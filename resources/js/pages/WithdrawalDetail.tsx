import React from 'react';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Download, Upload, ShieldCheck } from 'lucide-react';
import Shell from '../components/Shell';
import { Row, Badge, ActionForm, money, date } from '../components/UI';
import { useT } from '../shared/i18n/t';

export default function WithdrawalDetail({ withdrawal: w, events }: { withdrawal: Row; events: Row[] }) {
    const translate = useT();
    const { auth, financeActive, staffActions, portalBase: base, paymentAccounts = [] } = usePage<any>().props;
    const finance = financeActive && auth.capabilities.includes('finance') && (staffActions?.finance?.approve ?? true);
    const form = useForm<{ payment_account_id: string; receipt: File | null; payment_reference: string; paid_at: string; note: string }>({
        payment_account_id: '', receipt: null, payment_reference: '', paid_at: '', note: '',
    });

    return (
        <Shell section="withdrawals">
            <Head title={translate('portal', 'wd_head_title')} />
            <Link className="text-link" href={base + '/withdrawals'}>{translate('portal', 'wd_back')}</Link>
            <div className="order-title">
                <div>
                    <h2>{money(w.amount)}</h2>
                    <p>{translate('portal', 'wd_requested')} {date(w.created_at)} · {w.channel}</p>
                </div>
                <Badge status={w.status} />
            </div>
            <section className="panel">
                <h2>{translate('portal', 'wd_manual_title')}</h2>
                <dl className="profile-grid">
                    <div><dt>{translate('portal', 'wd_destination')}</dt><dd>{w.destination_for_payment ?? w.destination_masked}</dd></div>
                    <div><dt>{translate('portal', 'wd_payment_ref')}</dt><dd>{w.payment_reference ?? translate('portal', 'wd_not_recorded')}</dd></div>
                    <div><dt>{translate('portal', 'wd_payment_date')}</dt><dd>{w.paid_at ? date(w.paid_at) : translate('portal', 'wd_not_recorded')}</dd></div>
                </dl>
                {w.has_receipt
                    ? <a className="button" href={`${base}/withdrawals/${w.id}/receipt`}><Download size={17} />{translate('portal', 'wd_download_receipt')}</a>
                    : <p className="muted">{translate('portal', 'wd_receipt_pending')}</p>}
            </section>
            <section className="panel">
                <h2>{translate('portal', 'wd_history_title')}</h2>
                {events.map((e, i) => (
                    <div key={i} className="timeline-item">
                        <i /><div><b>{e.event} · {e.name}</b><p>{e.note}</p><small>{date(e.created_at)}</small></div>
                    </div>
                ))}
            </section>
            {finance && ['requested', 'approved'].includes(w.status) && (
                <section className="panel narrow-panel">
                    <h2>{translate('portal', 'wd_review_title')}</h2>
                    <ActionForm
                        action={`/commands/withdrawals/${w.id}/review`}
                        fields={[
                            {
                                name: 'decision',
                                label: translate('portal', 'wd_decision'),
                                required: true,
                                options: [
                                    ...(w.status === 'requested' ? [{ value: 'approved', label: translate('portal', 'wd_approve') }] : []),
                                    { value: 'rejected', label: translate('portal', 'wd_reject') },
                                ],
                            },
                            { name: 'reason', label: translate('portal', 'wd_review_reason'), type: 'textarea', required: true },
                        ]}
                        submit={translate('portal', 'wd_record_review')}
                    />
                </section>
            )}
            {finance && w.status === 'approved' && (
                <section className="panel narrow-panel">
                    <h2>{translate('portal', 'wd_record_title')}</h2>
                    <div className="notice">
                        <ShieldCheck size={20} />
                        <p>{translate('portal', 'wd_record_notice')}</p>
                    </div>
                    <form className="action-form" onSubmit={e => {
                        e.preventDefault();
                        form.post(`/commands/withdrawals/${w.id}/record-payment`, { forceFormData: true });
                    }}>
                        <label>{translate('portal', 'wd_payout_account')}
                            <select required={paymentAccounts.some((a: Row) => a.is_active && a.pays_out)} value={form.data.payment_account_id} onChange={e => form.setData('payment_account_id', e.target.value)}>
                                <option value="">{translate('portal', 'wd_choose_account')}</option>
                                {paymentAccounts.filter((a: Row) => a.is_active && a.pays_out).map((a: Row) => (
                                    <option key={a.id} value={a.id}>{a.label} · {a.channel}</option>
                                ))}
                            </select>
                        </label>
                        <label>{translate('portal', 'wd_payment_ref')}<input required maxLength={100} value={form.data.payment_reference} onChange={e => form.setData('payment_reference', e.target.value)} /></label>
                        <label>{translate('portal', 'wd_paid_at')}<input required type="datetime-local" value={form.data.paid_at} onChange={e => form.setData('paid_at', e.target.value)} /></label>
                        <label>{translate('portal', 'wd_upload_receipt')}<input required type="file" accept="application/pdf,image/jpeg,image/png" onChange={e => form.setData('receipt', e.target.files?.[0] ?? null)} /></label>
                        <label>{translate('portal', 'wd_payment_note')}<textarea required maxLength={1000} value={form.data.note} onChange={e => form.setData('note', e.target.value)} /></label>
                        {form.progress && <progress max={100} value={form.progress.percentage} aria-label={translate('portal', 'wd_upload_progress')} />}
                        {Object.keys(form.errors).length > 0 && <div className="error">{Object.values(form.errors).join(' ')}</div>}
                        <button disabled={form.processing} className="button dark">
                            <Upload size={17} />{form.processing ? translate('portal', 'wd_recording') : translate('portal', 'wd_record_payment')}
                        </button>
                    </form>
                </section>
            )}
        </Shell>
    );
}
