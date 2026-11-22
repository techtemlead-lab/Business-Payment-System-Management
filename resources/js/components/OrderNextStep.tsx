import { Link } from '@inertiajs/react';
import { useT } from '../shared/i18n/t';

/** What the person looking at an order should do next: a section of this page to scroll to, or a page to open. */
export type OrderNextStepData = {
    key: 'verify' | 'approve' | 'courier' | 'handover' | 'delivered' | 'pay' | 'received';
    targetId?: string;
    href?: string;
};

/**
 * Phone only (see mobile-office.css): a short "what to do now" card under the order number and one big button
 * pinned above the bottom bar, so the decision is never thousands of pixels down the page.
 * Hidden on wider screens, where the forms are already in view.
 */
export default function OrderNextStep({ step }: { step: OrderNextStepData | null }) {
    const translate = useT();
    if (!step) {
        return null;
    }
    const scrollToTarget = () => {
        const target = step.targetId ? document.getElementById(step.targetId) : null;
        if (!target) {
            return;
        }
        const calm = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({ behavior: calm ? 'auto' : 'smooth', block: 'start' });
    };
    const buttonText = translate('orders_stock', `od_btn_${step.key}`);

    return <>
        <section className="panel od-now" role="status" data-testid="order-next-step">
            <small>{translate('orders_stock', 'od_now_title')}</small>
            <b>{translate('orders_stock', `od_now_${step.key}`)}</b>
        </section>
        <div className="od-now-bar">
            <span>{translate('orders_stock', 'od_now_title')}</span>
            {step.href
                ? <Link className="button dark" href={step.href}>{buttonText}</Link>
                : <button type="button" className="button dark" onClick={scrollToTarget}>{buttonText}</button>}
        </div>
    </>;
}
