import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useT } from '../shared/i18n/t';

type Step = { target: string; title: string; body: string };
type Tour = { key: string; title: string; steps: Step[] };

export default function OnboardingTour({ tour }: { tour?: { pending?: Tour[] } | null }) {
    const translate = useT();
    const pending = tour?.pending?.[0];
    const [index, setIndex] = useState(0);
    const [rect, setRect] = useState<DOMRect | null>(null);

    useEffect(() => {
        if (!pending) {
            return;
        }
        const step = pending.steps[index];
        if (!step) {
            return;
        }
        const el = document.querySelector(step.target) as HTMLElement | null;
        if (!el) {
            setRect(null);
            // Skip missing targets so a partial page still allows the tour to finish.
            if (index < pending.steps.length - 1) {
                const timer = window.setTimeout(() => setIndex((value) => value + 1), 250);
                return () => window.clearTimeout(timer);
            }
            return;
        }
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        setRect(el.getBoundingClientRect());
    }, [pending, index]);

    if (!pending?.steps?.length) {
        return null;
    }
    const step = pending.steps[index];
    if (!step) {
        return null;
    }

    const finish = () => {
        router.post(`/commands/tours/${pending.key}/complete`, {}, { preserveScroll: true, preserveState: true });
    };

    return (
        <div className="onboarding-tour" role="dialog" aria-modal="true" aria-label={pending.title}>
            <div className="onboarding-tour-backdrop" />
            {rect && (
                <div
                    className="onboarding-tour-spotlight"
                    style={{
                        top: rect.top + window.scrollY - 8,
                        left: rect.left + window.scrollX - 8,
                        width: rect.width + 16,
                        height: rect.height + 16,
                    }}
                />
            )}
            <div
                className="onboarding-tour-card"
                style={{
                    top: Math.min((rect?.bottom || 120) + window.scrollY + 12, window.scrollY + window.innerHeight - 220),
                    left: Math.max(16, Math.min((rect?.left || 24) + window.scrollX, window.innerWidth - 340)),
                }}
            >
                <small>{pending.title} · {index + 1}/{pending.steps.length}</small>
                <h3>{step.title}</h3>
                <p>{step.body}</p>
                <div className="onboarding-tour-actions">
                    <button type="button" className="button small" onClick={finish}>{translate('portal', 'tour_skip')}</button>
                    {index > 0 && (
                        <button type="button" className="button small" onClick={() => setIndex((value) => value - 1)}>
                            {translate('portal', 'tour_back')}
                        </button>
                    )}
                    {index < pending.steps.length - 1 ? (
                        <button type="button" className="button small dark" onClick={() => setIndex((value) => value + 1)}>
                            {translate('portal', 'tour_next')}
                        </button>
                    ) : (
                        <button type="button" className="button small dark" onClick={finish}>
                            {translate('portal', 'tour_done')}
                        </button>
                    )}
                </div>
            </div>
        </div>
    );
}
