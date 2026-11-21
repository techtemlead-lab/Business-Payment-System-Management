import React from 'react';
import { Head, Link, router, useForm, usePage, usePoll } from '@inertiajs/react';
import { backgroundRefreshHeaders } from '../shared/backgroundRefresh';
import { Download, Upload, ShieldCheck, Check, Clock, Radio, XCircle, Banknote, Printer } from 'lucide-react';
import Shell from '../components/Shell';
import { Row, Badge, ActionForm, money, date, FormErrors, label } from '../components/UI';
import { useT } from '../shared/i18n/t';
import { useReasonField } from '../shared/reasonFields';
import { channelName, methodForChannel, payoutMethods, type PayoutMethod } from '../shared/payoutMethods';
import WithdrawalFacts, { AmountWords, type WithdrawalFactsData } from '../components/payouts/WithdrawalFacts';
import ReceiptPicker from '../components/payouts/ReceiptPicker';

function WithdrawalProgressStepper({ withdrawal: w, events }: { withdrawal: Row; events: Row[] }) {
    const translate = useT();
    const t = (k: string, fallback: string) => {
        const val = translate('portal', k);
        return val !== k ? val : fallback;
    };

    const status = (w.status || 'requested').toLowerCase();
    const isTerminal = ['paid', 'rejected', 'returned'].includes(status);
    const inFlight = ['requested', 'processing', 'approved'].includes(status);

    const reviewEvent = events.find((e) => e.event === 'review' || String(e.event).includes('review') || String(e.event).includes('approve') || String(e.event).includes('reject'));

    // Step 1: Requested
    const step1 = {
        title: t('wd_stage_requested', 'Requested'),
        badge: t('stage_badge_received', 'Received'),
        state: 'completed' as const,
        icon: <Check size={16} />,
        time: w.created_at,
        desc: `${w.destination_for_payment ?? w.destination_masked} · ${channelName(w.channel, translate)}`,
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
        // Not "Staff Approved" while nobody has approved it yet (MA-06).
        step2Title = translate('payouts', 'stage_in_review');
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
    } else if (status === 'returned') {
        // Paid, then the money came back and went to the wallet again.
        step3State = 'completed';
        step3Badge = translate('payouts', 'status_returned');
        step3Icon = <XCircle size={16} />;
        step3Desc = translate('payouts', 'notice_returned_title');
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
        time: status === 'returned' ? w.returned_at : w.paid_at,
        desc: step3Desc,
    };

    const steps = [step1, step2, step3];

    return (
        <section
            className="panel withdrawal-progress-stepper tps-panel"
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
                    border-bottom: 1px solid var(--ds-surface-3, #f1f5f9);
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
                    color: var(--ds-text, #011627);
                    letter-spacing: -0.02em;
                }
                .withdrawal-progress-stepper .wd-live-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 6px;
                    background: var(--ds-ok-bg, #ecfdf5);
                    color: var(--ds-brand, #006837);
                    border: 1px solid var(--ds-line, #a7f3d0);
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
                    background: var(--ds-brand, #006837);
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
                    background: var(--ds-surface-2, #f8fafc);
                    border: 1px solid var(--ds-line, #e2e8f0);
                    transition: all 0.2s ease;
                }
                .withdrawal-progress-stepper .wd-step-card.state-completed {
                    background: var(--ds-ok-bg, #f0fdf4);
                    border-color: var(--ds-line, #bbf7d0);
                }
                .withdrawal-progress-stepper .wd-step-card.state-active {
                    background: var(--ds-surface, #ffffff);
                    border-color: var(--ds-brand, #006837);
                    box-shadow: 0 0 0 1px var(--ds-brand, #006837), 0 4px 12px rgba(0, 104, 55, 0.12);
                }
                .withdrawal-progress-stepper .wd-step-card.state-failed {
                    background: var(--ds-danger-bg, #fef2f2);
                    border-color: var(--ds-line, #fecaca);
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
                    background: var(--ds-brand, #006837);
                    color: var(--ds-surface, #ffffff);
                    box-shadow: 0 2px 6px rgba(0, 104, 55, 0.25);
                }
                .withdrawal-progress-stepper .wd-step-indicator.state-active {
                    background: var(--ds-text, #011627);
                    color: var(--ds-surface, #ffffff);
                    border: 2px solid var(--ds-brand, #006837);
                    animation: wdStepperPulse 2s infinite;
                }
                .withdrawal-progress-stepper .wd-step-indicator.state-failed {
                    background: var(--ds-danger-fg, #dc2626);
                    color: var(--ds-surface, #ffffff);
                }
                .withdrawal-progress-stepper .wd-step-indicator.state-upcoming {
                    background: var(--ds-line, #e2e8f0);
                    color: var(--ds-text-muted, #64748b);
                }
                .withdrawal-progress-stepper .wd-step-badge {
                    font-size: 10px;
                    font-weight: 700;
                    padding: 2px 7px;
                    border-radius: 4px;
                    letter-spacing: 0.02em;
                }
                .withdrawal-progress-stepper .wd-step-badge.state-completed {
                    background: var(--ds-ok-bg, #dcfce7);
                    color: var(--ds-ok-fg, #166534);
                }
                .withdrawal-progress-stepper .wd-step-badge.state-active {
                    background: var(--ds-ok-bg, #ecfdf5);
                    color: var(--ds-brand, #006837);
                    border: 1px solid var(--ds-line, #a7f3d0);
                }
                .withdrawal-progress-stepper .wd-step-badge.state-failed {
                    background: var(--ds-danger-bg, #fee2e2);
                    color: var(--ds-danger-fg, #991b1b);
                }
                .withdrawal-progress-stepper .wd-step-badge.state-upcoming {
                    background: var(--ds-surface-3, #f1f5f9);
                    color: var(--ds-text-muted, #64748b);
                }
                .withdrawal-progress-stepper .wd-step-name {
                    font-size: 13px;
                    font-weight: 700;
                    color: var(--ds-text, #011627);
                    margin: 2px 0 0;
                }
                .withdrawal-progress-stepper .wd-step-desc {
                    font-size: 11px;
                    color: var(--ds-text-2, #475569);
                    margin: 0;
                    line-height: 1.4;
                    word-break: break-word;
                }
                .withdrawal-progress-stepper .wd-step-time {
                    font-size: 10px;
                    color: var(--ds-text-muted, #64748b);
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
                        <span className={`hub-pill tps-end-pill ${status === 'paid' ? 'tone-ok' : 'tone-danger'}`}>
                            {status === 'paid' ? t('stage_settled', 'Settled') : status === 'returned' ? translate('payouts', 'status_returned') : t('stage_rejected', 'Rejected')}
                        </span>
                    )}
                </div>
                <div className="ops-caption">
                    <span>{t('current_status', 'Status')}: </span>
                    <strong className="hub-strong hub-capital">
                        {label(status, translate)}
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

export default function WithdrawalDetail({ withdrawal: w, events = [], payoutReceipt = null, facts = null }: { withdrawal: Row; events: Row[]; payoutReceipt?: Row | null; facts?: WithdrawalFactsData | null }) {
    const translate = useT();
    const { auth, financeActive, staffActions, portalBase: base, paymentAccounts = [] } = usePage<any>().props;
    const isPartnerDesk = ['dealer', 'sub_dealer'].includes(auth?.user?.role) && auth?.user?.workspace_mode !== 'staff';
    const finance = financeActive && auth.capabilities.includes('finance') && (staffActions?.finance?.approve ?? true);
    const form = useForm<{ payment_account_id: string; receipt: File | null; receipt_later: boolean; payment_reference: string; paid_at: string; note: string; method: PayoutMethod; bank_name: string; account_number: string; wallet_number: string; received_by: string }>({
        payment_account_id: '', receipt: null, receipt_later: false, payment_reference: '', paid_at: '', note: '',
        // The number the partner asked to be paid to; the server refuses any other (F-verify-01).
        method: methodForChannel(String(w.channel ?? '')), bank_name: '', account_number: String(w.destination_for_payment ?? ''), wallet_number: String(w.destination_for_payment ?? ''), received_by: '',
    });
    const isCash = form.data.method === 'cash';
    const paymentNote = useReasonField('money.withdrawal_settle');
    // Bank-file and money-came-back steps are named in the payouts words; the rest as before.
    const eventLabel = (event: string): string => {
        const key = `event_label_${event}`;
        const named = translate('payouts', key);
        return named !== key ? named : label(event, translate);
    };

    const inFlight = ['requested', 'processing', 'approved'].includes(w.status);
    const { stop } = usePoll(5000, { only: ['withdrawal', 'payoutReceipt'], headers: backgroundRefreshHeaders }, { autoStart: inFlight });
    React.useEffect(() => {
        if (!inFlight) {
            stop();
        }
    }, [inFlight, stop]);

    return (
        <Shell section={isPartnerDesk ? 'my-money' : 'payouts-hub'}>
            <Head title={translate('portal', 'wd_head_title')} />
            <Link className="text-link" href={base + (isPartnerDesk ? '/my-money' : '/payouts-hub')}>{translate('portal', 'wd_back')}</Link>
            <div className="order-title">
                <div>
                    <h2>{money(w.amount)}</h2>
                    <p>{translate('portal', 'wd_requested')} {date(w.created_at)} · {channelName(w.channel, translate)}</p>
                </div>
                {w.status === 'returned' ? <span className="badge rejected">{translate('payouts', 'status_returned')}</span> : <Badge status={w.status} />}
            </div>
            <AmountWords amount={w.amount} />
            {w.on_hold && <div className="notice" role="status"><p>{translate('payouts', isPartnerDesk ? 'partner_on_hold' : 'on_hold_badge')}</p></div>}
            {w.status === 'approved' && w.pay_by && <p className="muted">{isPartnerDesk ? translate('payouts', 'partner_pay_by', { date: date(w.pay_by) }) : translate('payouts', 'pay_by', { date: date(w.pay_by) })}{!isPartnerDesk && ` · ${w.payer_name ? translate('payouts', 'payer_named', { name: w.payer_name }) : translate('payouts', 'payer_none')}`}</p>}
            {isPartnerDesk && w.can_cancel && (
                <button type="button" className="button small" onClick={() => {
                    if (window.confirm(translate('payouts', 'cancel_confirm'))) {
                        router.post(`/commands/withdrawals/${w.id}/cancel`, {}, { preserveScroll: true });
                    }
                }}>{translate('payouts', 'cancel_request')}</button>
            )}
            <WithdrawalProgressStepper withdrawal={w} events={events} />
            {finance && facts && <section className="panel narrow-panel"><WithdrawalFacts facts={facts} channel={String(w.channel)} amount={w.amount} /></section>}
            {finance && w.status === 'requested' && (
                <section className="panel narrow-panel">
                    <h2>{translate('portal', 'wd_review_title')}</h2>
                    {w.waits_for_owner && <p className="notice" role="status">{translate('self_approval', 'owner_approves_withdrawal')}</p>}
                    <ActionForm
                        action={`/commands/withdrawals/${w.id}/review`}
                        fields={[
                            {
                                name: 'decision',
                                label: translate('portal', 'wd_decision'),
                                required: true,
                                options: [
                                    ...(facts?.blocker || facts?.over_channel_limit || w.waits_for_owner ? [] : [{ value: 'approved', label: translate('portal', 'wd_approve') }]),
                                    { value: 'rejected', label: translate('portal', 'wd_reject') },
                                ],
                            },
                            ...((facts?.payers.length ?? 0) > 0 ? [{ name: 'payer_id', label: translate('payouts', 'payer_label'), options: (facts?.payers ?? []).map((payer) => ({ value: String(payer.id), label: payer.name })) }] : []),
                            { name: 'reason', label: translate('portal', 'wd_review_reason'), type: 'textarea', required: true, place: 'money.withdrawal_review' },
                        ]}
                        submit={translate('portal', 'wd_record_review')}
                    />
                </section>
            )}
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
                {payoutReceipt && <p className="ops-mt-3">
                    {translate('portal', 'prc_issued_line', { number: String(payoutReceipt.number), method: translate('portal', `payout_method_${payoutReceipt.method}`) })}{' '}
                    <a className="button small" href={`${base}/payment-receipts/${payoutReceipt.id}/print`} target="_blank" rel="noopener"><Printer size={15} />{translate('portal', 'prc_view_print')}</a>
                </p>}
            </section>
            <section className="panel">
                <h2>{translate('portal', 'wd_history_title')}</h2>
                {events.map((e, i) => (
                    <div key={i} className="timeline-item">
                        <i /><div><b>{eventLabel(String(e.event))} · {e.name}</b>{e.note && <p>{e.note}</p>}<small>{date(e.created_at)}</small></div>
                    </div>
                ))}
            </section>
            {/* Paid money that came back: one person records it, a second confirms or takes it back. */}
            {finance && w.status === 'paid' && w.return_state && (
                <section className="panel narrow-panel">
                    <h2>{translate('payouts', 'return_title')}</h2>
                    {w.return_note && <p className="muted">{translate('payouts', 'return_recorded_line', { note: String(w.return_note) })}</p>}
                    {w.return_state === 'record' && <>
                        <p className="muted">{translate('payouts', 'return_body')}</p>
                        <ActionForm action={`/commands/withdrawals/${w.id}/returned`} fields={[{ name: 'note', label: translate('payouts', 'return_note'), type: 'textarea', required: true }]} submit={translate('payouts', 'return_save')} />
                    </>}
                    {w.return_state === 'confirm' && <>
                        <p className="notice" role="status">{translate('payouts', 'return_confirm_body', { amount: money(w.amount) })}</p>
                        <ActionForm action={`/commands/withdrawals/${w.id}/returned/confirm`} fields={[{ name: 'note', label: translate('payouts', 'return_confirm_note'), type: 'textarea', required: true }]} submit={translate('payouts', 'return_confirm_save')} />
                    </>}
                    {w.return_state === 'wait_second' && <p className="notice" role="status">{translate('payouts', 'return_wait_second')}</p>}
                    {w.return_state === 'wait_owner' && <p className="notice" role="status">{translate('payouts', 'return_wait_owner')}</p>}
                    {(w.return_state === 'confirm' || w.return_state === 'wait_second') && (
                        <ActionForm action={`/commands/withdrawals/${w.id}/returned/cancel`} fields={[{ name: 'note', label: translate('payouts', 'return_cancel_note'), type: 'textarea', required: true }]} submit={translate('payouts', 'return_cancel_button')} />
                    )}
                </section>
            )}
            {/* In a file sent to the bank the money may already be on its way: no rejecting until the bank answers. */}
            {finance && w.status === 'approved' && w.exported_at && (
                <section className="panel narrow-panel">
                    <h2>{translate('payouts', 'not_sent_title')}</h2>
                    <p className="notice" role="status">{translate('payouts', 'in_bank_file_badge', { batch: String(w.payout_batch_id ?? '').slice(0, 8).toUpperCase() })}</p>
                    <p className="muted">{translate('payouts', 'not_sent_body')}</p>
                    <ActionForm
                        action={`/commands/withdrawals/${w.id}/not-sent`}
                        fields={[{ name: 'note', label: translate('payouts', 'not_sent_note'), type: 'textarea', required: true }]}
                        submit={translate('payouts', 'not_sent_save')}
                    />
                </section>
            )}
            {finance && w.status === 'approved' && !w.exported_at && (
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
                            { name: 'reason', label: translate('portal', 'wd_review_reason'), type: 'textarea', required: true, place: 'money.withdrawal_review' },
                        ]}
                        submit={translate('portal', 'wd_record_review')}
                    />
                </section>
            )}
            {finance && w.status === 'approved' && w.payer_state === 'second_person' && (
                <section className="panel narrow-panel">
                    <h2>{translate('portal', 'wd_record_title')}</h2>
                    <p className="muted" role="status">{translate('self_approval', 'wait_second_person')}</p>
                </section>
            )}
            {finance && w.status === 'approved' && w.payer_state !== 'second_person' && (
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
                        <label>{translate('portal', 'pay_fld_method')}
                            <select required value={form.data.method} onChange={e => form.setData('method', e.target.value as PayoutMethod)}>
                                {payoutMethods.map(method => <option key={method} value={method}>{translate('portal', `payout_method_${method}`)}</option>)}
                            </select>
                        </label>
                        {!isCash && <label>{translate('portal', 'wd_payout_account')}
                            <select required={paymentAccounts.some((a: Row) => a.is_active && a.pays_out)} value={form.data.payment_account_id} onChange={e => form.setData('payment_account_id', e.target.value)}>
                                <option value="">{translate('portal', 'wd_choose_account')}</option>
                                {paymentAccounts.filter((a: Row) => a.is_active && a.pays_out).map((a: Row) => (
                                    <option key={a.id} value={a.id}>{a.label} · {a.channel}</option>
                                ))}
                            </select>
                        </label>}
                        {(form.data.method === 'bank' || form.data.method === 'other') && <>
                            <label>{translate('portal', form.data.method === 'bank' ? 'pay_fld_bank_name' : 'pay_fld_provider')}<input required={form.data.method === 'bank'} maxLength={100} value={form.data.bank_name} onChange={e => form.setData('bank_name', e.target.value)} /></label>
                            <label>{translate('portal', 'pay_fld_account_number')}<input required={form.data.method === 'bank'} maxLength={60} value={form.data.account_number} onChange={e => form.setData('account_number', e.target.value)} /></label>
                        </>}
                        {(form.data.method === 'bkash' || form.data.method === 'nagad') && <label>{translate('portal', 'pay_fld_wallet_number')}<input required type="tel" inputMode="numeric" maxLength={20} placeholder="01XXXXXXXXX" value={form.data.wallet_number} onChange={e => form.setData('wallet_number', e.target.value)} /></label>}
                        {isCash && <label>{translate('portal', 'pay_fld_received_by')}<input required maxLength={100} placeholder={translate('portal', 'pay_ph_received_by')} value={form.data.received_by} onChange={e => form.setData('received_by', e.target.value)} /></label>}
                        <label>{translate('portal', isCash ? 'pay_fld_reference_cash' : 'wd_payment_ref')}<input required={!isCash} maxLength={100} value={form.data.payment_reference} onChange={e => form.setData('payment_reference', e.target.value)} /></label>
                        <label>{translate('portal', 'wd_paid_at')}<input required type="datetime-local" value={form.data.paid_at} onChange={e => form.setData('paid_at', e.target.value)} /></label>
                        <ReceiptPicker id="wd-receipt" label={translate('portal', isCash ? 'pay_fld_receipt_cash' : 'wd_upload_receipt')} required={!isCash && !form.data.receipt_later} file={form.data.receipt} onChange={file => form.setData('receipt', file)} />
                        {!isCash && !form.data.receipt && <label className="hub-choice"><input type="checkbox" checked={form.data.receipt_later} onChange={e => form.setData('receipt_later', e.target.checked)} />{translate('payouts', 'receipt_later')}</label>}
                        {form.data.receipt_later && <small className="hub-hint">{translate('payouts', 'receipt_later_hint')}</small>}
                        {paymentNote.shown && <label>{paymentNote.label(translate('portal', 'wd_payment_note'))}<textarea required={paymentNote.required} maxLength={1000} value={form.data.note} onChange={e => form.setData('note', e.target.value)} /></label>}
                        {form.progress && <progress max={100} value={form.progress.percentage} aria-label={translate('portal', 'wd_upload_progress')} />}
                        <FormErrors errors={form.errors}/>
                        {facts?.blocker && <p className="notice" role="alert"><b>{translate('payouts', 'blocked_title')}</b> {facts.blocker}</p>}
                        <button disabled={form.processing || Boolean(facts?.blocker)} className="button dark">
                            <Upload size={17} />{form.processing ? translate('portal', 'wd_recording') : translate('portal', 'wd_record_payment')}
                        </button>
                    </form>
                </section>
            )}
        </Shell>
    );
}
