import React from 'react';
import { Head, Link, useForm, usePage, usePoll } from '@inertiajs/react';
import { Download, Upload, ShieldCheck, Check, Clock, Radio, XCircle, Banknote } from 'lucide-react';
import Shell from '../components/Shell';
import { Row, Badge, ActionForm, money, date } from '../components/UI';
import { useT } from '../shared/i18n/t';

function WithdrawalProgressStepper({ withdrawal: w, events }: { withdrawal: Row; events: Row[] }) {
    const translate = useT();
    const t = (k: string, fallback: string) => {
        const val = translate('portal', k);
        return val !== k ? val : fallback;
    };

    const status = (w.status || 'requested').toLowerCase();
    const isTerminal = ['paid', 'rejected'].includes(status);
    const inFlight = ['requested', 'processing', 'approved'].includes(status);

    const reviewEvent = events.find((e) => e.event === 'review' || String(e.event).includes('review') || String(e.event).includes('approve') || String(e.event).includes('reject'));

    // Step 1: Requested
    const step1 = {
        title: t('wd_stage_requested', 'Requested'),
        badge: t('stage_badge_received', 'Received'),
        state: 'completed' as const,
        icon: <Check size={16} />,
        time: w.created_at,
        desc: `${w.destination_for_payment ?? w.destination_masked} · ${w.channel}`,
    };

    // Step 2: Staff Approved / Rejected
    let step2State: 'completed' | 'active' | 'failed' | 'upcoming' = 'upcoming';
    let step2Title = t('wd_stage_staff_approved', 'Staff Approved');
    let step2Badge = t('stage_badge_pending', 'Pending');
    let step2Icon = <Clock size={16} />;
    let step2Desc = t('wd_desc_awaiting_review', 'Awaiting finance review');

    if (status === 'rejected') {
        step2State = 'failed';
        step2Title = t('wd_stage_rejected', 'Rejected');
        step2Badge = t('stage_badge_rejected', 'Rejected');
        step2Icon = <XCircle size={16} />;
        step2Desc = w.reason || t('wd_desc_rejected_reason', 'Payout request rejected');
    } else if (status === 'requested') {
        step2State = 'active';
        step2Badge = t('stage_badge_in_review', 'Under Review');
        step2Icon = <Radio size={16} className="pulsing-icon" />;
        step2Desc = t('wd_desc_review_in_progress', 'Finance verification in progress');
    } else {
        step2State = 'completed';
        step2Badge = t('stage_badge_approved', 'Approved');
        step2Icon = <ShieldCheck size={16} />;
        step2Desc = t('wd_desc_approved_for_payout', 'Approved for disbursement');
    }

    const step2 = {
        title: step2Title,
        badge: step2Badge,
        state: step2State,
        icon: step2Icon,
        time: reviewEvent?.created_at,
        desc: step2Desc,
    };

    // Step 3: Paid & Settled
    let step3State: 'completed' | 'active' | 'upcoming' = 'upcoming';
    let step3Badge = t('stage_badge_pending', 'Pending');
    let step3Icon = <Banknote size={16} />;
    let step3Desc = t('wd_desc_pending_settlement', 'Awaiting disbursement');

    if (status === 'rejected') {
        step3State = 'upcoming';
        step3Badge = t('stage_badge_na', 'N/A');
        step3Desc = t('stage_desc_not_applicable', 'Workflow halted');
    } else if (status === 'paid') {
        step3State = 'completed';
        step3Badge = t('stage_badge_settled', 'Paid & Settled');
        step3Icon = <Check size={16} />;
        step3Desc = w.payment_reference ? `${t('col_reference', 'Ref')}: ${w.payment_reference}` : t('wd_desc_settled_confirmed', 'Payout completed & settled');
    } else if (status === 'approved' || status === 'processing') {
        step3State = 'active';
        step3Badge = t('stage_badge_disbursing', 'Disbursing');
        step3Icon = <Radio size={16} className="pulsing-icon" />;
        step3Desc = t('wd_desc_ready_payment', 'Ready for payment recording');
    }

    const step3 = {
        title: t('wd_stage_paid_settled', 'Paid & Settled'),
        badge: step3Badge,
        state: step3State,
        icon: step3Icon,
        time: w.paid_at,
        desc: step3Desc,
    };

    const steps = [step1, step2, step3];

    return (
        <section
            className="panel withdrawal-progress-stepper"
            style={{
                marginBottom: 24,
                padding: '20px 24px',
                border: '1px solid #e2e8f0',
                borderRadius: 12,
                background: '#ffffff',
                boxShadow: '0 4px 16px rgba(1, 22, 39, 0.04)',
            }}
            data-tour="withdrawal-progress-stepper"
        >
            <style>{`
                .withdrawal-progress-stepper .wd-stepper-head {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 16px;
                    padding-bottom: 14px;
                    margin-bottom: 18px;
                    border-bottom: 1px solid #f1f5f9;
                    flex-wrap: wrap;
                }
                .withdrawal-progress-stepper .wd-stepper-title-area {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .withdrawal-progress-stepper .wd-stepper-title-area h3 {
                    margin: 0;
                    font-size: 16px;
                    font-weight: 700;
                    color: #011627;
                    letter-spacing: -0.02em;
                }
                .withdrawal-progress-stepper .wd-live-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    background: #ecfdf5;
                    color: #006837;
                    border: 1px solid #a7f3d0;
                    font-size: 11px;
                    font-weight: 700;
                    padding: 2px 8px;
                    border-radius: 999px;
                    letter-spacing: 0.05em;
                    text-transform: uppercase;
                }
                .withdrawal-progress-stepper .wd-live-dot {
                    width: 7px;
                    height: 7px;
                    background: #006837;
                    border-radius: 50%;
                    box-shadow: 0 0 0 0 rgba(0, 104, 55, 0.5);
                    animation: wdStepperPulse 1.8s infinite;
                }
                @keyframes wdStepperPulse {
                    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 104, 55, 0.7); }
                    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(0, 104, 55, 0); }
                    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 104, 55, 0); }
                }
                .withdrawal-progress-stepper .wd-stepper-track {
                    display: grid;
                    grid-template-columns: repeat(3, minmax(0, 1fr));
                    gap: 14px;
                }
                @media (max-width: 768px) {
                    .withdrawal-progress-stepper .wd-stepper-track {
                        grid-template-columns: 1fr;
                        gap: 12px;
                    }
                }
                .withdrawal-progress-stepper .wd-step-card {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                    padding: 14px 16px;
                    border-radius: 9px;
                    background: #f8fafc;
                    border: 1px solid #e2e8f0;
                    transition: all 0.2s ease;
                }
                .withdrawal-progress-stepper .wd-step-card.state-completed {
                    background: #f0fdf4;
                    border-color: #bbf7d0;
                }
                .withdrawal-progress-stepper .wd-step-card.state-active {
                    background: #ffffff;
                    border-color: #006837;
                    box-shadow: 0 0 0 1px #006837, 0 4px 12px rgba(0, 104, 55, 0.12);
                }
                .withdrawal-progress-stepper .wd-step-card.state-failed {
                    background: #fef2f2;
                    border-color: #fecaca;
                }
                .withdrawal-progress-stepper .wd-step-card.state-upcoming {
                    opacity: 0.72;
                }
                .withdrawal-progress-stepper .wd-step-top {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 8px;
                }
                .withdrawal-progress-stepper .wd-step-indicator {
                    width: 28px;
                    height: 28px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 12px;
                    font-weight: 700;
                    flex-shrink: 0;
                }
                .withdrawal-progress-stepper .wd-step-indicator.state-completed {
                    background: #006837;
                    color: #ffffff;
                    box-shadow: 0 2px 6px rgba(0, 104, 55, 0.25);
                }
                .withdrawal-progress-stepper .wd-step-indicator.state-active {
                    background: #011627;
                    color: #ffffff;
                    border: 2px solid #006837;
                    animation: wdStepperPulse 2s infinite;
                }
                .withdrawal-progress-stepper .wd-step-indicator.state-failed {
                    background: #dc2626;
                    color: #ffffff;
                }
                .withdrawal-progress-stepper .wd-step-indicator.state-upcoming {
                    background: #e2e8f0;
                    color: #64748b;
                }
                .withdrawal-progress-stepper .wd-step-badge {
                    font-size: 10px;
                    font-weight: 700;
                    padding: 2px 7px;
                    border-radius: 4px;
                    letter-spacing: 0.02em;
                }
                .withdrawal-progress-stepper .wd-step-badge.state-completed {
                    background: #dcfce7;
                    color: #166534;
                }
                .withdrawal-progress-stepper .wd-step-badge.state-active {
                    background: #ecfdf5;
                    color: #006837;
                    border: 1px solid #a7f3d0;
                }
                .withdrawal-progress-stepper .wd-step-badge.state-failed {
                    background: #fee2e2;
                    color: #991b1b;
                }
                .withdrawal-progress-stepper .wd-step-badge.state-upcoming {
                    background: #f1f5f9;
                    color: #64748b;
                }
                .withdrawal-progress-stepper .wd-step-name {
                    font-size: 13px;
                    font-weight: 700;
                    color: #011627;
                    margin: 2px 0 0;
                }
                .withdrawal-progress-stepper .wd-step-desc {
                    font-size: 11px;
                    color: #475569;
                    margin: 0;
                    line-height: 1.4;
                    word-break: break-word;
                }
                .withdrawal-progress-stepper .wd-step-time {
                    font-size: 10px;
                    color: #64748b;
                    margin-top: auto;
                    padding-top: 4px;
                    border-top: 1px dashed rgba(226, 232, 240, 0.8);
                }
                html[data-theme="dark"] .withdrawal-progress-stepper {
                    background: #0d1b2a !important;
                    border-color: #1e293b !important;
                    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4) !important;
                }
                html[data-theme="dark"] .withdrawal-progress-stepper .wd-stepper-title-area h3 {
                    color: #f8fafc !important;
                }
                html[data-theme="dark"] .withdrawal-progress-stepper .wd-stepper-head {
                    border-color: #1e293b !important;
                }
                html[data-theme="dark"] .withdrawal-progress-stepper .wd-step-card {
                    background: #1e293b !important;
                    border-color: #334155 !important;
                }
                html[data-theme="dark"] .withdrawal-progress-stepper .wd-step-card.state-completed {
                    background: #064e3b22 !important;
                    border-color: #065f46 !important;
                }
                html[data-theme="dark"] .withdrawal-progress-stepper .wd-step-card.state-active {
                    background: #0d1b2a !important;
                    border-color: #10b981 !important;
                }
                html[data-theme="dark"] .withdrawal-progress-stepper .wd-step-name {
                    color: #f1f5f9 !important;
                }
                html[data-theme="dark"] .withdrawal-progress-stepper .wd-step-desc {
                    color: #cbd5e1 !important;
                }
            `}</style>

            <div className="wd-stepper-head">
                <div className="wd-stepper-title-area">
                    <h3>{t('wd_progress_title', 'Withdrawal Payout Progress')}</h3>
                    {inFlight && (
                        <span className="wd-live-badge">
                            <span className="wd-live-dot" aria-hidden="true" />
                            {t('live_sync_active', 'Live Updates Active')}
                        </span>
                    )}
                    {isTerminal && (
                        <span
                            style={{
                                fontSize: 11,
                                fontWeight: 700,
                                padding: '2px 8px',
                                borderRadius: 4,
                                background: status === 'paid' ? '#dcfce7' : '#fee2e2',
                                color: status === 'paid' ? '#166534' : '#991b1b',
                            }}
                        >
                            {status === 'paid' ? t('stage_settled', 'Settled') : t('stage_rejected', 'Rejected')}
                        </span>
                    )}
                </div>
                <div style={{ fontSize: 12, color: '#64748b' }}>
                    <span>{t('current_status', 'Status')}: </span>
                    <strong style={{ color: '#011627', textTransform: 'capitalize' }}>
                        {status.replaceAll('_', ' ')}
                    </strong>
                </div>
            </div>

            <div className="wd-stepper-track">
                {steps.map((st, idx) => (
                    <article key={idx} className={`wd-step-card state-${st.state}`}>
                        <div className="wd-step-top">
                            <div className={`wd-step-indicator state-${st.state}`}>
                                {st.icon}
                            </div>
                            <span className={`wd-step-badge state-${st.state}`}>
                                {st.badge}
                            </span>
                        </div>
                        <h4 className="wd-step-name">{st.title}</h4>
                        {st.desc && <p className="wd-step-desc">{st.desc}</p>}
                        {st.time && (
                            <div className="wd-step-time">
                                <span>{date(st.time)}</span>
                            </div>
                        )}
                    </article>
                ))}
            </div>
        </section>
    );
}

export default function WithdrawalDetail({ withdrawal: w, events = [] }: { withdrawal: Row; events: Row[] }) {
    const translate = useT();
    const { auth, financeActive, staffActions, portalBase: base, paymentAccounts = [] } = usePage<any>().props;
    const finance = financeActive && auth.capabilities.includes('finance') && (staffActions?.finance?.approve ?? true);
    const form = useForm<{ payment_account_id: string; receipt: File | null; payment_reference: string; paid_at: string; note: string }>({
        payment_account_id: '', receipt: null, payment_reference: '', paid_at: '', note: '',
    });

    const inFlight = ['requested', 'processing', 'approved'].includes(w.status);
    const { stop } = usePoll(5000, { only: ['withdrawal'] }, { autoStart: inFlight });
    React.useEffect(() => {
        if (!inFlight) {
            stop();
        }
    }, [inFlight, stop]);

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
            <WithdrawalProgressStepper withdrawal={w} events={events} />
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
