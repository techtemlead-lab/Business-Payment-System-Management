import React, { useMemo } from 'react';
import {
    Check,
    Clock,
    AlertCircle,
    XCircle,
    Truck,
    PackageCheck,
    CreditCard,
    FileCheck2,
    ShieldCheck,
    Radio,
} from 'lucide-react';
import { Row, date, money, label } from './UI';
import { useT } from '../shared/i18n/t';

export type OrderStatus =
    | 'submitted'
    | 'held'
    | 'approved'
    | 'partially_dispatched'
    | 'dispatched'
    | 'delivered'
    | 'partly_delivered'
    | 'rejected'
    | 'cancelled'
    | string;

export interface OrderData {
    id?: number | string;
    number?: string;
    status?: OrderStatus;
    created_at?: string;
    total?: string | number;
    paid?: string | number;
    due_credit?: string | number;
    decision_reason?: string;
    note?: string;
    lines?: Row[];
    [key: string]: any;
}

export interface PaymentClaim {
    id?: number | string;
    channel?: string;
    reference?: string;
    amount?: string | number;
    status?: 'pending' | 'verified' | 'rejected' | string;
    reason?: string;
    created_at?: string;
    [key: string]: any;
}

export interface Shipment {
    id?: number | string;
    courier?: string;
    tracking?: string;
    eta?: string;
    status?: 'dispatched' | 'delivered' | string;
    lines?: any;
    delivered_at?: string;
    created_at?: string;
    [key: string]: any;
}

export interface OrderEvent {
    id?: number | string;
    action?: string;
    metadata?: string | Record<string, any>;
    created_at?: string;
    [key: string]: any;
}

export interface TransactionProgressStepperProps {
    order: OrderData | Row;
    currentStatus?: OrderStatus;
    claims?: (PaymentClaim | Row)[];
    shipments?: (Shipment | Row)[];
    events?: (OrderEvent | Row)[];
    className?: string;
}

type StepState = 'completed' | 'active' | 'warning' | 'failed' | 'upcoming';

interface StepDescriptor {
    id: string;
    stepNumber: number;
    title: string;
    statusBadge: string;
    state: StepState;
    timestamp?: string;
    description?: string;
    icon: React.ReactNode;
}

export default function TransactionProgressStepper({
    order,
    currentStatus,
    claims = [],
    shipments = [],
    events = [],
    className = '',
}: TransactionProgressStepperProps) {
    const translate = useT();
    const t = (k: string, fallback: string) => {
        const val = translate('portal', k);
        return val !== k ? val : fallback;
    };
    /** Payment method in the reader's language ("Bank" shows as "ব্যাংক"); unknown methods show as stored. */
    const channelName = (channel: unknown, fallback: string): string => {
        const raw = String(channel ?? '').trim();
        return raw === '' ? fallback : t(`pay_channel_${raw.toLowerCase()}`, raw);
    };

    const status = (currentStatus || order.status || 'submitted').toLowerCase();
    const isPartlyDelivered = status === 'partly_delivered';
    const isTerminal = ['delivered', 'partly_delivered', 'rejected', 'cancelled'].includes(status);
    const isInFlight = ['submitted', 'held', 'approved', 'partially_dispatched', 'dispatched'].includes(status);

    // Helpers to extract timestamps from events if present
    const eventTimes = useMemo(() => {
        const times: Record<string, string> = {};
        for (const ev of events) {
            if (ev.action && ev.created_at) {
                times[ev.action] = ev.created_at;
            }
        }
        return times;
    }, [events]);

    // Financial calculations
    const orderTotal = Number(order.total || 0);
    const orderPaid = Number(order.paid || 0);
    const orderDueCredit = Number(order.due_credit || 0);
    const hasVerifiedClaim = claims.some((c) => c.status === 'verified');
    const hasPendingClaim = claims.some((c) => c.status === 'pending');
    const hasOnlyRejectedClaims = claims.length > 0 && claims.every((c) => c.status === 'rejected');
    const isPaymentSettled = hasVerifiedClaim || (orderTotal > 0 && orderPaid >= orderTotal) || (orderTotal > 0 && (orderPaid + orderDueCredit) >= orderTotal);

    // Shipment details
    const activeShipment = shipments[shipments.length - 1];
    const isDelivered = status === 'delivered' || (shipments.length > 0 && shipments.every((s) => s.status === 'delivered'));
    const isDispatched = status === 'dispatched' || status === 'partially_dispatched' || shipments.some((s) => s.status === 'dispatched');

    // Build the 5 pipeline stages
    const steps: StepDescriptor[] = useMemo(() => {
        // --- Stage 1: Order Submitted ---
        const stage1: StepDescriptor = {
            id: 'submitted',
            stepNumber: 1,
            title: t('stage_submitted', 'Order Submitted'),
            statusBadge: t('stage_badge_received', 'Received'),
            state: 'completed',
            timestamp: eventTimes['order.submitted'] || order.created_at,
            description: order.number ? `${t('col_order', 'Order')} #${order.number}` : undefined,
            icon: <Check size={16} />,
        };

        // --- Stage 2: Staff Review & Decision ---
        let stage2State: StepState = 'upcoming';
        let stage2Badge = t('stage_badge_pending', 'Pending');
        let stage2Desc = t('stage_desc_awaiting_review', 'Awaiting staff review');
        let stage2Icon: React.ReactNode = <Clock size={16} />;

        if (status === 'rejected') {
            stage2State = 'failed';
            stage2Badge = t('stage_badge_rejected', 'Rejected');
            stage2Desc = order.decision_reason || t('stage_desc_order_rejected', 'Order rejected by staff');
            stage2Icon = <XCircle size={16} />;
        } else if (status === 'cancelled') {
            stage2State = 'failed';
            stage2Badge = t('stage_badge_cancelled', 'Cancelled');
            stage2Desc = order.decision_reason || t('stage_desc_order_cancelled', 'Order cancelled');
            stage2Icon = <XCircle size={16} />;
        } else if (status === 'held') {
            stage2State = 'warning';
            stage2Badge = t('stage_badge_held', 'On Hold');
            stage2Desc = order.decision_reason || t('stage_desc_order_held', 'Order placed on review hold');
            stage2Icon = <AlertCircle size={16} />;
        } else if (status === 'submitted') {
            stage2State = 'active';
            stage2Badge = t('stage_badge_in_review', 'In Review');
            stage2Desc = t('stage_desc_processing_review', 'Review in progress');
            stage2Icon = <Radio size={16} className="pulsing-icon" />;
        } else {
            // approved, partially_dispatched, dispatched, delivered
            stage2State = 'completed';
            stage2Badge = t('stage_badge_approved', 'Approved');
            stage2Desc = order.decision_reason ? `${t('stage_approved_label', 'Approved')}: ${order.decision_reason}` : t('stage_desc_review_passed', 'Staff review approved');
            stage2Icon = <ShieldCheck size={16} />;
        }

        const stage2: StepDescriptor = {
            id: 'review',
            stepNumber: 2,
            title: t('stage_review', 'Staff Review'),
            statusBadge: stage2Badge,
            state: stage2State,
            timestamp: eventTimes['order.approved'] || eventTimes['order.held'] || eventTimes['order.rejected'] || eventTimes['order.cancelled'],
            description: stage2Desc,
            icon: stage2Icon,
        };

        // --- Stage 3: Payment Verification ---
        let stage3State: StepState = 'upcoming';
        let stage3Badge = t('stage_badge_pending', 'Pending');
        let stage3Desc = t('stage_desc_payment_awaiting', 'Payment verification required');
        let stage3Icon: React.ReactNode = <CreditCard size={16} />;

        if (status === 'rejected' || status === 'cancelled') {
            stage3State = 'upcoming';
            stage3Badge = t('stage_badge_na', 'N/A');
            stage3Desc = t('stage_desc_not_applicable', 'Workflow halted');
        } else if (isPaymentSettled) {
            stage3State = 'completed';
            stage3Badge = t('stage_badge_verified', 'Verified');
            const verifiedClaim = claims.find((c) => c.status === 'verified');
            if (verifiedClaim) {
                stage3Desc = `${channelName(verifiedClaim.channel, t('stage_payment_fallback', 'Payment'))} · ${money(verifiedClaim.amount)}`;
            } else if (orderPaid > 0) {
                stage3Desc = `${t('stage_settled_paid', 'Verified')} ${money(orderPaid)}`;
            } else {
                stage3Desc = t('stage_desc_payment_cleared', 'Payment verified');
            }
            stage3Icon = <FileCheck2 size={16} />;
        } else if (hasPendingClaim) {
            stage3State = 'active';
            stage3Badge = t('stage_badge_inspecting', 'Inspecting');
            const pendingClaim = claims.find((c) => c.status === 'pending');
            stage3Desc = pendingClaim ? `${channelName(pendingClaim.channel, '')} ${money(pendingClaim.amount)} (${pendingClaim.reference})` : t('stage_desc_claim_verifying', 'Claim awaiting verification');
            stage3Icon = <Radio size={16} className="pulsing-icon" />;
        } else if (hasOnlyRejectedClaims) {
            stage3State = 'warning';
            stage3Badge = t('stage_badge_claim_rejected', 'Claim Rejected');
            stage3Desc = t('stage_desc_submit_valid_claim', 'Please submit valid payment slip');
            stage3Icon = <AlertCircle size={16} />;
        } else if (['approved', 'partially_dispatched', 'dispatched', 'delivered', 'partly_delivered'].includes(status)) {
            if (orderPaid > 0) {
                stage3State = 'active';
                stage3Badge = t('stage_badge_partial', 'Partial');
                stage3Desc = `${money(orderPaid)} ${t('stage_paid_of', 'of')} ${money(orderTotal)}`;
                stage3Icon = <Clock size={16} />;
            } else {
                stage3State = 'active';
                stage3Badge = t('stage_badge_awaiting_claim', 'Action Needed');
                stage3Desc = t('stage_desc_submit_claim_hint', 'Awaiting payment reference submission');
                stage3Icon = <Clock size={16} />;
            }
        }

        const latestClaim = claims[claims.length - 1];
        const stage3: StepDescriptor = {
            id: 'payment',
            stepNumber: 3,
            title: t('stage_payment', 'Payment Verification'),
            statusBadge: stage3Badge,
            state: stage3State,
            timestamp: latestClaim?.created_at,
            description: stage3Desc,
            icon: stage3Icon,
        };

        // --- Stage 4: Dispatch & Shipment ---
        let stage4State: StepState = 'upcoming';
        let stage4Badge = t('stage_badge_pending', 'Pending');
        let stage4Desc = t('stage_desc_warehouse_prep', 'Awaiting stock dispatch');
        let stage4Icon: React.ReactNode = <Truck size={16} />;

        if (status === 'rejected' || status === 'cancelled') {
            stage4State = 'upcoming';
            stage4Badge = t('stage_badge_na', 'N/A');
            stage4Desc = t('stage_desc_not_applicable', 'Workflow halted');
        } else if (isDelivered || isPartlyDelivered) {
            stage4State = 'completed';
            stage4Badge = t('stage_badge_dispatched', 'Dispatched');
            stage4Desc = activeShipment ? `${activeShipment.courier} · ${activeShipment.tracking}` : t('stage_desc_fulfilled', 'Stock fulfilled');
            stage4Icon = <Check size={16} />;
        } else if (status === 'dispatched') {
            stage4State = 'active';
            stage4Badge = t('stage_badge_in_transit', 'In Transit');
            stage4Desc = activeShipment ? `${activeShipment.courier} · ${activeShipment.tracking}` : t('stage_desc_in_transit', 'Courier in transit');
            stage4Icon = <Radio size={16} className="pulsing-icon" />;
        } else if (status === 'partially_dispatched') {
            stage4State = 'active';
            stage4Badge = t('stage_badge_partial_dispatch', 'Partial Dispatch');
            stage4Desc = activeShipment ? `${activeShipment.courier} · ${activeShipment.tracking}` : t('stage_desc_partial_dispatch', 'Items partially dispatched');
            stage4Icon = <Truck size={16} />;
        } else if (status === 'approved') {
            stage4State = 'upcoming';
            stage4Badge = t('stage_badge_queued', 'Queued');
            stage4Desc = t('stage_desc_ready_dispatch', 'Ready for warehouse dispatch');
        }

        const stage4: StepDescriptor = {
            id: 'shipment',
            stepNumber: 4,
            title: t('stage_dispatch', 'Dispatch & Shipment'),
            statusBadge: stage4Badge,
            state: stage4State,
            timestamp: activeShipment?.created_at || eventTimes['order.dispatched'],
            description: stage4Desc,
            icon: stage4Icon,
        };

        // --- Stage 5: Delivery Completed ---
        let stage5State: StepState = 'upcoming';
        let stage5Badge = t('stage_badge_pending', 'Pending');
        let stage5Desc = t('stage_desc_pending_delivery', 'Awaiting destination delivery');
        let stage5Icon: React.ReactNode = <PackageCheck size={16} />;

        if (status === 'rejected' || status === 'cancelled') {
            stage5State = 'upcoming';
            stage5Badge = t('stage_badge_na', 'N/A');
            stage5Desc = t('stage_desc_not_applicable', 'Workflow halted');
        } else if (isPartlyDelivered) {
            stage5State = 'completed';
            stage5Badge = t('status_partly_delivered', 'Partly delivered');
            stage5Desc = t('stage_desc_partly_delivered', 'Some parcels were delivered and some came back');
            stage5Icon = <Check size={16} />;
        } else if (isDelivered) {
            stage5State = 'completed';
            stage5Badge = t('stage_badge_delivered', 'Delivered');
            stage5Desc = t('stage_desc_delivery_confirmed', 'Goods received & verified');
            stage5Icon = <Check size={16} />;
        } else if (status === 'dispatched' || status === 'partially_dispatched') {
            stage5State = 'upcoming';
            stage5Badge = t('stage_badge_transit', 'En Route');
            stage5Desc = activeShipment?.eta ? `${t('stage_eta', 'ETA')}: ${date(activeShipment.eta)}` : t('stage_desc_out_delivery', 'Out for courier delivery');
        }

        const stage5: StepDescriptor = {
            id: 'delivery',
            stepNumber: 5,
            title: t('stage_delivery', 'Delivery Completed'),
            statusBadge: stage5Badge,
            state: stage5State,
            timestamp: activeShipment?.delivered_at || eventTimes['order.delivered'],
            description: stage5Desc,
            icon: stage5Icon,
        };

        return [stage1, stage2, stage3, stage4, stage5];
    }, [status, order, claims, shipments, eventTimes, isPaymentSettled, isDelivered, isPartlyDelivered, isDispatched, activeShipment]);

    return (
        <section
            className={`panel transaction-progress-stepper tps-panel ${className}`}
            data-tour="order-progress-stepper"
        >
            <style>{`
                .transaction-progress-stepper .stepper-head {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 16px;
                    padding-bottom: 16px;
                    margin-bottom: 20px;
                    border-bottom: 1px solid var(--ds-surface-3, #f1f5f9);
                    flex-wrap: wrap;
                }
                .transaction-progress-stepper .stepper-title-area {
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .transaction-progress-stepper .stepper-title-area h3 {
                    margin: 0;
                    font-size: 17px;
                    font-weight: 700;
                    color: var(--ds-text, #011627);
                    letter-spacing: -0.02em;
                }
                .transaction-progress-stepper .live-pulse-badge {
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
                .transaction-progress-stepper .live-dot-pulse {
                    width: 7px;
                    height: 7px;
                    background: var(--ds-brand, #006837);
                    border-radius: 50%;
                    box-shadow: 0 0 0 0 rgba(0, 104, 55, 0.5);
                    animation: stepperPulse 1.8s infinite;
                }
                @keyframes stepperPulse {
                    0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 104, 55, 0.7); }
                    70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(0, 104, 55, 0); }
                    100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(0, 104, 55, 0); }
                }
                .transaction-progress-stepper .stepper-track {
                    display: grid;
                    grid-template-columns: repeat(5, minmax(0, 1fr));
                    gap: 12px;
                    position: relative;
                }
                @media (max-width: 900px) {
                    .transaction-progress-stepper .stepper-track {
                        grid-template-columns: 1fr;
                        gap: 16px;
                    }
                }
                .transaction-progress-stepper .step-card {
                    display: flex;
                    flex-direction: column;
                    gap: 8px;
                    padding: 12px 14px;
                    border-radius: 9px;
                    background: var(--ds-surface-2, #f8fafc);
                    border: 1px solid var(--ds-line, #e2e8f0);
                    transition: all 0.2s ease;
                    position: relative;
                }
                .transaction-progress-stepper .step-card.state-completed {
                    background: var(--ds-ok-bg, #f0fdf4);
                    border-color: var(--ds-line, #bbf7d0);
                }
                .transaction-progress-stepper .step-card.state-active {
                    background: var(--ds-surface, #ffffff);
                    border-color: var(--ds-brand, #006837);
                    box-shadow: 0 0 0 1px var(--ds-brand, #006837), 0 4px 12px rgba(0, 104, 55, 0.12);
                }
                .transaction-progress-stepper .step-card.state-warning {
                    background: var(--ds-warn-bg, #fffbeb);
                    border-color: var(--ds-line, #fde68a);
                }
                .transaction-progress-stepper .step-card.state-failed {
                    background: var(--ds-danger-bg, #fef2f2);
                    border-color: var(--ds-line, #fecaca);
                }
                .transaction-progress-stepper .step-card.state-upcoming {
                    opacity: 0.72;
                }
                .transaction-progress-stepper .step-top {
                    display: flex;
                    justify-content: space-between;
                    align-items: center;
                    gap: 8px;
                }
                .transaction-progress-stepper .step-indicator {
                    width: 28px;
                    height: 28px;
                    border-radius: 50%;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 12px;
                    font-weight: 700;
                    flex-shrink: 0;
                    transition: all 0.2s ease;
                }
                .transaction-progress-stepper .step-indicator.state-completed {
                    background: var(--ds-brand, #006837);
                    color: var(--ds-surface, #ffffff);
                    box-shadow: 0 2px 6px rgba(0, 104, 55, 0.25);
                }
                .transaction-progress-stepper .step-indicator.state-active {
                    background: var(--ds-text, #011627);
                    color: var(--ds-surface, #ffffff);
                    border: 2px solid var(--ds-brand, #006837);
                    animation: stepperPulse 2s infinite;
                }
                .transaction-progress-stepper .step-indicator.state-warning {
                    background: var(--ds-warn-fg, #d97706);
                    color: var(--ds-surface, #ffffff);
                }
                .transaction-progress-stepper .step-indicator.state-failed {
                    background: var(--ds-danger-fg, #dc2626);
                    color: var(--ds-surface, #ffffff);
                }
                .transaction-progress-stepper .step-indicator.state-upcoming {
                    background: var(--ds-line, #e2e8f0);
                    color: var(--ds-text-muted, #64748b);
                }
                .transaction-progress-stepper .step-badge {
                    /* undo the round 24px onboarding .step-badge, which squeezed this pill to one word per line */
                    width: auto;
                    height: auto;
                    margin-top: 0;
                    white-space: nowrap;
                    line-height: 1.4;
                    font-size: 10px;
                    font-weight: 700;
                    padding: 2px 6px;
                    border-radius: 4px;
                    letter-spacing: 0.02em;
                }
                .transaction-progress-stepper .step-badge.state-completed {
                    background: var(--ds-ok-bg, #dcfce7);
                    color: var(--ds-ok-fg, #166534);
                }
                .transaction-progress-stepper .step-badge.state-active {
                    background: var(--ds-ok-bg, #ecfdf5);
                    color: var(--ds-brand, #006837);
                    border: 1px solid var(--ds-line, #a7f3d0);
                }
                .transaction-progress-stepper .step-badge.state-warning {
                    background: var(--ds-warn-bg, #fef3c7);
                    color: var(--ds-warn-fg, #92400e);
                }
                .transaction-progress-stepper .step-badge.state-failed {
                    background: var(--ds-danger-bg, #fee2e2);
                    color: var(--ds-danger-fg, #991b1b);
                }
                .transaction-progress-stepper .step-badge.state-upcoming {
                    background: var(--ds-surface-3, #f1f5f9);
                    color: var(--ds-text-muted, #64748b);
                }
                .transaction-progress-stepper .step-name {
                    font-size: 13px;
                    font-weight: 700;
                    color: var(--ds-text, #011627);
                    margin: 2px 0 0;
                    line-height: 1.3;
                }
                .transaction-progress-stepper .step-desc {
                    font-size: 11px;
                    color: var(--ds-text-2, #475569);
                    margin: 0;
                    line-height: 1.4;
                    word-break: break-word;
                }
                .transaction-progress-stepper .step-time {
                    font-size: 10px;
                    color: var(--ds-text-muted, #64748b);
                    margin-top: auto;
                    padding-top: 4px;
                    border-top: 1px dashed rgba(226, 232, 240, 0.8);
                }
                /* Dark theme compatibility */
                html[data-theme="dark"] .transaction-progress-stepper {
                    background: #0d1b2a !important;
                    border-color: #1e293b !important;
                    box-shadow: 0 4px 16px rgba(0, 0, 0, 0.4) !important;
                }
                html[data-theme="dark"] .transaction-progress-stepper .stepper-title-area h3 {
                    color: #f8fafc !important;
                }
                html[data-theme="dark"] .transaction-progress-stepper .stepper-head {
                    border-color: #1e293b !important;
                }
                html[data-theme="dark"] .transaction-progress-stepper .step-card {
                    background: #1e293b !important;
                    border-color: #334155 !important;
                }
                html[data-theme="dark"] .transaction-progress-stepper .step-card.state-completed {
                    background: #064e3b22 !important;
                    border-color: #065f46 !important;
                }
                html[data-theme="dark"] .transaction-progress-stepper .step-card.state-active {
                    background: #0d1b2a !important;
                    border-color: #10b981 !important;
                }
                html[data-theme="dark"] .transaction-progress-stepper .step-name {
                    color: #f1f5f9 !important;
                }
                html[data-theme="dark"] .transaction-progress-stepper .step-desc {
                    color: #cbd5e1 !important;
                }
            `}</style>

            <div className="stepper-head">
                <div className="stepper-title-area">
                    <h3>{t('order_progress_lifecycle', 'Transaction Lifecycle & Fulfillment')}</h3>
                    {isInFlight && (
                        <span className="live-pulse-badge">
                            <span className="live-dot-pulse" aria-hidden="true" />
                            {t('live_sync_active', 'Live Updates Active')}
                        </span>
                    )}
                    {isTerminal && (
                        <span className={`hub-pill tps-end-pill ${status === 'delivered' || isPartlyDelivered ? 'tone-ok' : 'tone-danger'}`}>
                            {status === 'delivered' || isPartlyDelivered ? t('stage_completed', 'Completed') : t('stage_closed', 'Closed')}
                        </span>
                    )}
                </div>
                <div className="ops-caption">
                    <span>{t('current_status', 'Status')}: </span>
                    <strong className="ops-text hub-capital">
                        {label(status, translate)}
                    </strong>
                </div>
            </div>

            <div className="stepper-track">
                {steps.map((step) => (
                    <article key={step.id} className={`step-card state-${step.state}`}>
                        <div className="step-top">
                            <div className={`step-indicator state-${step.state}`} aria-label={translate('portal', 'step_aria', { n: String(step.stepNumber), title: step.title })}>
                                {step.icon}
                            </div>
                            <span className={`step-badge state-${step.state}`}>
                                {step.statusBadge}
                            </span>
                        </div>
                        <h4 className="step-name">{step.title}</h4>
                        {step.description && <p className="step-desc">{step.description}</p>}
                        {step.timestamp && (
                            <div className="step-time">
                                <span>{date(step.timestamp)}</span>
                            </div>
                        )}
                    </article>
                ))}
            </div>
        </section>
    );
}
