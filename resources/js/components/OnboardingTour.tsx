import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useT } from '../shared/i18n/t';

type Step = { target: string; title: string; body: string; tip?: string };
type Tour = { key: string; title: string; audience?: string; steps: Step[] };

function audienceLabel(audience: string | undefined, translate: (ns: string, key: string) => string): string {
    if (audience === 'owner') {
        return translate('portal', 'tour_audience_owner');
    }
    if (audience === 'staff') {
        return translate('portal', 'tour_audience_staff');
    }
    if (audience === 'sub_dealer') {
        return translate('portal', 'tour_audience_sub_dealer');
    }
    if (audience === 'dealer') {
        return translate('portal', 'tour_audience_dealer');
    }

    return translate('portal', 'tour_live_guide');
}

export default function OnboardingTour({ tour }: { tour?: { pending?: Tour[] } | null }) {
    const translate = useT();
    const pending = tour?.pending?.[0];
    const [index, setIndex] = useState(0);
    const [rect, setRect] = useState<DOMRect | null>(null);
    const tourKey = pending?.key;
    const stepCount = pending?.steps?.length ?? 0;

    const finish = () => {
        if (!tourKey) {
            return;
        }
        router.post(`/commands/tours/${tourKey}/complete`, {}, { preserveScroll: true, preserveState: true });
    };

    useEffect(() => {
        setIndex(0);
        setRect(null);
    }, [tourKey]);

    useEffect(() => {
        if (!pending || stepCount === 0) {
            return;
        }
        const step = pending.steps[index];
        if (!step) {
            return;
        }
        const el = document.querySelector(step.target) as HTMLElement | null;
        if (!el) {
            setRect(null);
            if (index < stepCount - 1) {
                const timer = window.setTimeout(() => setIndex((value) => value + 1), 250);
                return () => window.clearTimeout(timer);
            }
            return;
        }
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        const measure = () => setRect(el.getBoundingClientRect());
        measure();
        window.addEventListener('resize', measure);
        window.addEventListener('scroll', measure, true);
        return () => {
            window.removeEventListener('resize', measure);
            window.removeEventListener('scroll', measure, true);
        };
    }, [pending, index, stepCount]);

    useEffect(() => {
        if (!tourKey || stepCount === 0) {
            return;
        }
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                finish();
                return;
            }
            if (event.key === 'ArrowRight' || event.key === 'Enter') {
                event.preventDefault();
                if (index < stepCount - 1) {
                    setIndex((value) => value + 1);
                } else {
                    finish();
                }
                return;
            }
            if (event.key === 'ArrowLeft' && index > 0) {
                event.preventDefault();
                setIndex((value) => value - 1);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    });

    if (!pending?.steps?.length) {
        return null;
    }
    const step = pending.steps[index];
    if (!step) {
        return null;
    }

    const progress = ((index + 1) / pending.steps.length) * 100;
    const cardTop = (() => {
        const preferred = (rect?.bottom || 120) + window.scrollY + 14;
        const maxTop = window.scrollY + window.innerHeight - 280;
        if (preferred > maxTop && rect) {
            return Math.max(window.scrollY + 16, rect.top + window.scrollY - 250);
        }
        return Math.min(preferred, maxTop);
    })();
    const cardLeft = Math.max(16, Math.min((rect?.left || 24) + window.scrollX, window.innerWidth - 380));

    return (
        <div className="onboarding-tour" role="dialog" aria-modal="true" aria-label={pending.title}>
            <div className="onboarding-tour-backdrop" />
            {rect && (
                <div
                    className="onboarding-tour-spotlight"
                    style={{
                        top: rect.top + window.scrollY - 10,
                        left: rect.left + window.scrollX - 10,
                        width: rect.width + 20,
                        height: rect.height + 20,
                    }}
                />
            )}
            <div className="onboarding-tour-card" style={{ top: cardTop, left: cardLeft }}>
                <div className="onboarding-tour-meta">
                    <span className="onboarding-tour-audience">{audienceLabel(pending.audience, translate)}</span>
                    <span className="onboarding-tour-progress-label">
                        {translate('portal', 'tour_step_of', { current: String(index + 1), total: String(pending.steps.length) })}
                    </span>
                </div>
                <div className="onboarding-tour-progress" aria-hidden="true">
                    <i style={{ width: `${progress}%` }} />
                </div>
                <p className="onboarding-tour-page">{pending.title}</p>
                <h3>{step.title}</h3>
                <p>{step.body}</p>
                {step.tip ? (
                    <div className="onboarding-tour-tip">
                        <strong>{translate('portal', 'tour_tip_label')}</strong>
                        <span>{step.tip}</span>
                    </div>
                ) : null}
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
