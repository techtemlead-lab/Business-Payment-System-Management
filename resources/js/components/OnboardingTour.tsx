import React, { useEffect, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import {
    Sparkles, BookOpen, Compass, ChevronRight, ChevronLeft, X, Search,
    CheckCircle2, HelpCircle, Lightbulb, ArrowUpRight, ShoppingBag, Coins,
    ShieldCheck, Layers, FileText, ArrowRight, RotateCcw
} from 'lucide-react';
import { useT } from '../shared/i18n/t';

export type TourStep = {
    target: string;
    title: string;
    body: string;
    tip?: string;
    category?: string;
};

export type TourDefinition = {
    key: string;
    title: string;
    audience?: string;
    steps: TourStep[];
    step_count?: number;
    completed?: boolean;
};

export type ManualChapter = {
    key: string;
    title: string;
    summary: string;
    steps: string[];
    tips: string[];
    keywords?: string[];
};

export type TourPayload = {
    completed?: string[];
    pending?: TourDefinition[];
    blocked_by?: Array<{ key: string; title: string; href: string }>;
    is_first_login?: boolean;
    all_tours?: TourDefinition[];
    chapters?: ManualChapter[];
};

interface OnboardingTourProps {
    tour?: TourPayload | null;
    chapters?: ManualChapter[];
    forceOpenManual?: boolean;
    onCloseManual?: () => void;
}

function audienceLabel(audience: string | undefined, translate: (ns: string, key: string) => string): string {
    if (audience === 'owner') return translate('portal', 'tour_audience_owner');
    if (audience === 'staff') return translate('portal', 'tour_audience_staff');
    if (audience === 'sub_dealer') return translate('portal', 'tour_audience_sub_dealer');
    if (audience === 'dealer') return translate('portal', 'tour_audience_dealer');
    return translate('portal', 'tour_live_guide');
}

/** Keep the guide card safely inside the viewport and avoid obscuring the target */
function calculateCardLayout(rect: DOMRect | null): { top: number; left: number; position: 'bottom' | 'top' | 'center' } {
    const cardWidth = Math.min(380, window.innerWidth - 32);
    const cardHeight = 290;
    const gap = 16;
    const margin = 16;

    if (!rect) {
        return {
            top: Math.max(margin, Math.round((window.innerHeight - cardHeight) / 2)),
            left: Math.max(margin, Math.round((window.innerWidth - cardWidth) / 2)),
            position: 'center',
        };
    }

    let top = rect.bottom + gap;
    let position: 'bottom' | 'top' = 'bottom';

    // If bottom overflows, place above the element
    if (top + cardHeight > window.innerHeight - margin) {
        const topCandidate = rect.top - cardHeight - gap;
        if (topCandidate >= margin) {
            top = topCandidate;
            position = 'top';
        } else {
            // If neither fits nicely, stick to bottom margin
            top = Math.max(margin, window.innerHeight - cardHeight - margin);
        }
    }

    // Horizontally center with the element when possible
    let left = rect.left + (rect.width / 2) - (cardWidth / 2);
    if (left + cardWidth > window.innerWidth - margin) {
        left = window.innerWidth - cardWidth - margin;
    }
    left = Math.max(margin, left);

    return { top: Math.round(top), left: Math.round(left), position };
}

export default function OnboardingTour({
    tour,
    chapters: manualChaptersProp,
    forceOpenManual,
    onCloseManual,
}: OnboardingTourProps) {
    const translate = useT();
    const { auth } = usePage<any>().props;
    const user = auth?.user;

    const chapters = useMemo<ManualChapter[]>(() => {
        if (manualChaptersProp && manualChaptersProp.length > 0) return manualChaptersProp;
        if (tour?.chapters && tour.chapters.length > 0) return tour.chapters;
        return [];
    }, [manualChaptersProp, tour?.chapters]);

    const activeTour = tour?.pending?.[0] || null;
    const isFirstTime = Boolean(tour?.is_first_login);

    // Dialog & UI states
    const [welcomeOpen, setWelcomeOpen] = useState(false);
    const [tourActive, setTourActive] = useState(false);
    const [manualOpen, setManualOpen] = useState(false);
    const [manualSearch, setManualSearch] = useState('');
    const [selectedChapterKey, setSelectedChapterKey] = useState<string>('');
    const [stepIndex, setStepIndex] = useState(0);
    const [rect, setRect] = useState<DOMRect | null>(null);
    const [elementMissing, setElementMissing] = useState(false);

    // Sync external forceOpenManual
    useEffect(() => {
        if (forceOpenManual) {
            setManualOpen(true);
        }
    }, [forceOpenManual]);

    // Handle first-time login trigger
    useEffect(() => {
        const dismissedKey = `madina_tour_dismissed_${user?.id || 'guest'}`;
        const hasSessionDismissed = sessionStorage.getItem(dismissedKey);

        if (isFirstTime && !hasSessionDismissed) {
            setWelcomeOpen(true);
        }
    }, [isFirstTime, user?.id]);

    // Set default selected chapter
    useEffect(() => {
        if (chapters.length > 0 && !selectedChapterKey) {
            setSelectedChapterKey(chapters[0].key);
        }
    }, [chapters, selectedChapterKey]);

    const tourKey = activeTour?.key;
    const steps = activeTour?.steps || [];
    const stepCount = steps.length;
    const currentStep = steps[stepIndex] || null;

    // Reset tour position when active tour changes
    useEffect(() => {
        setStepIndex(0);
        setRect(null);
        setElementMissing(false);
    }, [tourKey]);

    // Locate target element and compute bounding rect with smooth scroll
    useEffect(() => {
        if (!tourActive || !currentStep) {
            setRect(null);
            return;
        }

        let isMounted = true;
        const targetSelector = currentStep.target;
        const el = document.querySelector(targetSelector) as HTMLElement | null;

        if (!el) {
            setRect(null);
            setElementMissing(true);
            return;
        }

        setElementMissing(false);

        // Smooth scroll to target
        try {
            el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
        } catch {
            // Fallback for older environments
            el.scrollIntoView();
        }

        const updateRect = () => {
            if (!isMounted) return;
            const updatedEl = document.querySelector(targetSelector) as HTMLElement | null;
            if (updatedEl) {
                setRect(updatedEl.getBoundingClientRect());
            }
        };

        // Measure immediately and after smooth scroll completes
        updateRect();
        const timer1 = window.setTimeout(updateRect, 180);
        const timer2 = window.setTimeout(updateRect, 380);

        window.addEventListener('resize', updateRect, { passive: true });
        window.addEventListener('scroll', updateRect, { passive: true, capture: true });

        return () => {
            isMounted = false;
            window.clearTimeout(timer1);
            window.clearTimeout(timer2);
            window.removeEventListener('resize', updateRect);
            window.removeEventListener('scroll', updateRect, { capture: true });
        };
    }, [tourActive, currentStep, stepIndex]);

    // Keyboard shortcuts for tour navigation
    useEffect(() => {
        if (!tourActive) return;

        const onKeyDown = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                e.preventDefault();
                completeTour();
            } else if (e.key === 'ArrowRight' || e.key === 'Enter') {
                e.preventDefault();
                nextStep();
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault();
                prevStep();
            }
        };

        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    });

    const completeTour = () => {
        setTourActive(false);
        if (tourKey) {
            router.post(`/commands/tours/${tourKey}/complete`, {}, {
                preserveScroll: true,
                preserveState: true,
            });
        }
    };

    const nextStep = () => {
        if (stepIndex < stepCount - 1) {
            setStepIndex((curr) => curr + 1);
        } else {
            completeTour();
        }
    };

    const prevStep = () => {
        if (stepIndex > 0) {
            setStepIndex((curr) => curr - 1);
        }
    };

    const startTour = () => {
        setWelcomeOpen(false);
        setTourActive(true);
        setStepIndex(0);
    };

    const dismissWelcome = () => {
        setWelcomeOpen(false);
        if (user?.id) {
            sessionStorage.setItem(`madina_tour_dismissed_${user.id}`, 'true');
        }
        if (tourKey) {
            router.post(`/commands/tours/${tourKey}/complete`, {}, {
                preserveScroll: true,
                preserveState: true,
            });
        }
    };

    const openManualToChapter = (chapterKey?: string) => {
        if (chapterKey) {
            setSelectedChapterKey(chapterKey);
        }
        setWelcomeOpen(false);
        setTourActive(false);
        setManualOpen(true);
    };

    const closeManual = () => {
        setManualOpen(false);
        if (onCloseManual) onCloseManual();
    };

    // Filter chapters based on search query
    const filteredChapters = useMemo(() => {
        const q = manualSearch.trim().toLowerCase();
        if (!q) return chapters;
        return chapters.filter((ch) => {
            const haystack = [
                ch.key,
                ch.title,
                ch.summary,
                ...(ch.steps || []),
                ...(ch.tips || []),
                ...(ch.keywords || []),
            ].join(' ').toLowerCase();
            return haystack.includes(q);
        });
    }, [chapters, manualSearch]);

    const activeChapter = useMemo(() => {
        return chapters.find((c) => c.key === selectedChapterKey) || filteredChapters[0] || null;
    }, [chapters, selectedChapterKey, filteredChapters]);

    const { top: cardTop, left: cardLeft } = calculateCardLayout(rect);
    const progressPercent = stepCount > 0 ? Math.round(((stepIndex + 1) / stepCount) * 100) : 0;

    return (
        <>
            {/* 1. FIRST-TIME LOGIN WELCOME MODAL */}
            {welcomeOpen && (
                <div
                    className="onboarding-welcome-backdrop"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="onboarding-welcome-heading"
                >
                    <div className="onboarding-welcome-dialog">
                        <div className="onboarding-welcome-header">
                            <div className="onboarding-welcome-badge">
                                <Sparkles size={16} />
                                <span>{translate('portal', 'tour_welcome_badge')}</span>
                            </div>
                            <button
                                type="button"
                                className="onboarding-close-btn"
                                onClick={dismissWelcome}
                                aria-label={translate('portal', 'tour_skip')}
                            >
                                <X size={20} />
                            </button>
                        </div>

                        <div className="onboarding-welcome-body">
                            <h2 id="onboarding-welcome-heading">
                                {translate('portal', 'tour_welcome_title')}
                            </h2>
                            <p className="onboarding-welcome-subtitle">
                                {translate('portal', 'tour_welcome_subtitle')}
                            </p>

                            <div className="onboarding-user-pill">
                                <strong>{user?.name}</strong>
                                <span>·</span>
                                <span>{user?.dealer_code || user?.email}</span>
                                <span>·</span>
                                <span className="onboarding-role-tag">{audienceLabel(tour?.pending?.[0]?.audience, translate)}</span>
                            </div>

                            <div className="onboarding-pillars-grid">
                                <div className="onboarding-pillar-card">
                                    <div className="pillar-icon ops">
                                        <ShoppingBag size={22} />
                                    </div>
                                    <h4>{translate('portal', 'tour_welcome_pillar_ops')}</h4>
                                    <p>{translate('portal', 'tour_welcome_pillar_ops_desc')}</p>
                                </div>
                                <div className="onboarding-pillar-card">
                                    <div className="pillar-icon fin">
                                        <Coins size={22} />
                                    </div>
                                    <h4>{translate('portal', 'tour_welcome_pillar_fin')}</h4>
                                    <p>{translate('portal', 'tour_welcome_pillar_fin_desc')}</p>
                                </div>
                                <div className="onboarding-pillar-card">
                                    <div className="pillar-icon help">
                                        <BookOpen size={22} />
                                    </div>
                                    <h4>{translate('portal', 'tour_welcome_pillar_help')}</h4>
                                    <p>{translate('portal', 'tour_welcome_pillar_help_desc')}</p>
                                </div>
                            </div>
                        </div>

                        <div className="onboarding-welcome-footer">
                            <button
                                type="button"
                                className="button onboarding-skip-btn"
                                onClick={dismissWelcome}
                            >
                                {translate('portal', 'tour_btn_skip_welcome')}
                            </button>

                            <div className="onboarding-primary-actions">
                                <button
                                    type="button"
                                    className="button onboarding-manual-btn"
                                    onClick={() => openManualToChapter('getting_started')}
                                >
                                    <BookOpen size={17} />
                                    {translate('portal', 'tour_btn_open_manual')}
                                </button>
                                <button
                                    type="button"
                                    className="button dark onboarding-start-btn"
                                    onClick={startTour}
                                >
                                    <Sparkles size={17} />
                                    {translate('portal', 'tour_btn_start_tour')}
                                    <ArrowRight size={17} />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            {/* 2. INTERACTIVE LIVE SPOTLIGHT TOUR */}
            {tourActive && currentStep && (
                <div
                    className="onboarding-spotlight-layer"
                    role="dialog"
                    aria-modal="true"
                    aria-label={activeTour?.title || translate('portal', 'tour_live_guide')}
                >
                    {/* Dark spotlight mask */}
                    <div className="onboarding-spotlight-backdrop" onClick={completeTour} />

                    {/* Target highlight ring */}
                    {rect && (
                        <div
                            className="onboarding-spotlight-ring"
                            style={{
                                top: Math.max(6, rect.top - 8),
                                left: Math.max(6, rect.left - 8),
                                width: Math.min(rect.width + 16, window.innerWidth - 12),
                                height: Math.min(rect.height + 16, window.innerHeight - 12),
                            }}
                        />
                    )}

                    {/* Floating Walkthrough Card */}
                    <div
                        className="onboarding-tour-card floating-step-card"
                        style={{ top: cardTop, left: cardLeft }}
                    >
                        <div className="onboarding-card-header">
                            <div className="onboarding-card-tags">
                                <span className="onboarding-tag audience">
                                    {audienceLabel(activeTour?.audience, translate)}
                                </span>
                                {elementMissing && (
                                    <span className="onboarding-tag missing">
                                        {translate('portal', 'tour_feature_overview')}
                                    </span>
                                )}
                            </div>
                            <span className="onboarding-card-step-badge">
                                {translate('portal', 'tour_step_of', {
                                    current: String(stepIndex + 1),
                                    total: String(stepCount),
                                })}
                            </span>
                        </div>

                        {/* Animated Step Progress Bar */}
                        <div className="onboarding-progress-track" aria-hidden="true">
                            <div
                                className="onboarding-progress-fill"
                                style={{ width: `${progressPercent}%` }}
                            />
                        </div>

                        <div className="onboarding-card-content">
                            <h3 className="onboarding-step-title">{currentStep.title}</h3>
                            <p className="onboarding-step-body">{currentStep.body}</p>

                            {currentStep.tip && (
                                <div className="onboarding-step-tip">
                                    <Lightbulb size={16} className="tip-icon" />
                                    <div>
                                        <strong>{translate('portal', 'tour_tip_label')}</strong>
                                        <p>{currentStep.tip}</p>
                                    </div>
                                </div>
                            )}
                        </div>

                        <div className="onboarding-card-footer">
                            <div className="onboarding-footer-left">
                                <button
                                    type="button"
                                    className="text-link small tour-manual-link"
                                    onClick={() => openManualToChapter(tourKey)}
                                >
                                    <BookOpen size={14} />
                                    {translate('portal', 'tour_read_in_manual')}
                                </button>
                            </div>

                            <div className="onboarding-footer-nav">
                                <button
                                    type="button"
                                    className="button small"
                                    onClick={completeTour}
                                >
                                    {translate('portal', 'tour_skip')}
                                </button>

                                {stepIndex > 0 && (
                                    <button
                                        type="button"
                                        className="button small"
                                        onClick={prevStep}
                                        aria-label={translate('portal', 'tour_back')}
                                    >
                                        <ChevronLeft size={16} />
                                        {translate('portal', 'tour_back')}
                                    </button>
                                )}

                                <button
                                    type="button"
                                    className="button small dark"
                                    onClick={nextStep}
                                    aria-label={stepIndex < stepCount - 1 ? translate('portal', 'tour_next') : translate('portal', 'tour_done')}
                                >
                                    {stepIndex < stepCount - 1 ? (
                                        <>
                                            {translate('portal', 'tour_next')}
                                            <ChevronRight size={16} />
                                        </>
                                    ) : (
                                        <>
                                            {translate('portal', 'tour_done')}
                                            <CheckCircle2 size={16} />
                                        </>
                                    )}
                                </button>
                            </div>
                        </div>

                        <div className="onboarding-keyboard-hint">
                            <small>{translate('portal', 'tour_keyboard_shortcuts')}</small>
                        </div>
                    </div>
                </div>
            )}

            {/* 3. COMPREHENSIVE INTERACTIVE USER MANUAL MODAL */}
            {manualOpen && (
                <div
                    className="onboarding-manual-modal-overlay"
                    role="dialog"
                    aria-modal="true"
                    aria-labelledby="manual-modal-title"
                >
                    <div className="onboarding-manual-modal">
                        {/* Header */}
                        <div className="onboarding-manual-header">
                            <div className="manual-header-title">
                                <BookOpen size={24} className="manual-brand-icon" />
                                <div>
                                    <h3 id="manual-modal-title">
                                        {translate('portal', 'tour_manual_modal_title')}
                                    </h3>
                                    <p className="muted">
                                        {translate('portal', 'tour_relaunch_anytime')}
                                    </p>
                                </div>
                            </div>

                            <button
                                type="button"
                                className="onboarding-close-btn"
                                onClick={closeManual}
                                aria-label={translate('portal', 'close')}
                            >
                                <X size={22} />
                            </button>
                        </div>

                        {/* Search Toolbar */}
                        <div className="onboarding-manual-search-bar">
                            <Search size={18} className="search-icon" />
                            <input
                                type="search"
                                value={manualSearch}
                                onChange={(e) => setManualSearch(e.target.value)}
                                placeholder={translate('portal', 'tour_manual_search_placeholder')}
                                aria-label={translate('portal', 'tour_manual_search_placeholder')}
                            />
                            {manualSearch && (
                                <button
                                    type="button"
                                    className="clear-search-btn"
                                    onClick={() => setManualSearch('')}
                                    aria-label={translate('portal', 'clear')}
                                >
                                    <X size={16} />
                                </button>
                            )}
                        </div>

                        {/* Dual Column Layout: Chapter Sidebar + Rich Article Content */}
                        <div className="onboarding-manual-container">
                            {/* Chapter Index Sidebar */}
                            <nav className="onboarding-manual-toc" aria-label="Manual Chapters">
                                <div className="toc-header">
                                    <span>{translate('portal', 'tour_manual_filter_all')}</span>
                                    <span className="chapter-count-badge">
                                        {filteredChapters.length} / {chapters.length}
                                    </span>
                                </div>

                                <div className="toc-list">
                                    {filteredChapters.map((chapter, idx) => {
                                        const isActive = activeChapter?.key === chapter.key;
                                        return (
                                            <button
                                                key={chapter.key}
                                                type="button"
                                                className={`toc-item ${isActive ? 'is-active' : ''}`}
                                                onClick={() => setSelectedChapterKey(chapter.key)}
                                            >
                                                <span className="toc-number">{idx + 1}</span>
                                                <div className="toc-meta">
                                                    <strong className="toc-title">{chapter.title}</strong>
                                                    <span className="toc-summary-snippet">{chapter.summary}</span>
                                                </div>
                                            </button>
                                        );
                                    })}

                                    {filteredChapters.length === 0 && (
                                        <div className="toc-empty">
                                            <HelpCircle size={20} />
                                            <p>{translate('portal', 'help_manual_no_results')}</p>
                                        </div>
                                    )}
                                </div>
                            </nav>

                            {/* Chapter Detailed Reader View */}
                            <article className="onboarding-manual-reader">
                                {activeChapter ? (
                                    <>
                                        <div className="reader-header">
                                            <div>
                                                <span className="reader-eyebrow">
                                                    {translate('portal', 'help_manual_title')} · {activeChapter.key.replaceAll('_', ' ')}
                                                </span>
                                                <h2>{activeChapter.title}</h2>
                                                <p className="reader-summary">{activeChapter.summary}</p>
                                            </div>

                                            {activeTour && (
                                                <button
                                                    type="button"
                                                    className="button small dark launch-spotlight-btn"
                                                    onClick={() => {
                                                        closeManual();
                                                        startTour();
                                                    }}
                                                >
                                                    <Sparkles size={15} />
                                                    {translate('portal', 'tour_manual_launch_feature_tour')}
                                                </button>
                                            )}
                                        </div>

                                        <div className="reader-steps-list">
                                            <h4>{translate('portal', 'manual_ch_getting_started_title')}</h4>
                                            <div className="step-cards-grid">
                                                {activeChapter.steps.map((stepText, sIdx) => (
                                                    <div className="manual-step-card" key={sIdx}>
                                                        <span className="step-badge">{sIdx + 1}</span>
                                                        <p className="step-text">{stepText}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>

                                        {activeChapter.tips && activeChapter.tips.length > 0 && (
                                            <div className="reader-tips-section">
                                                <div className="tips-callout">
                                                    <Lightbulb size={20} className="tip-callout-icon" />
                                                    <div>
                                                        <strong>{translate('portal', 'help_manual_tips')}</strong>
                                                        <ul>
                                                            {activeChapter.tips.map((tipText, tIdx) => (
                                                                <li key={tIdx}>{tipText}</li>
                                                            ))}
                                                        </ul>
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </>
                                ) : (
                                    <div className="reader-empty">
                                        <BookOpen size={36} />
                                        <h3>{translate('portal', 'help_manual_no_results')}</h3>
                                        <p>{translate('portal', 'search_by_name_or_reference')}</p>
                                    </div>
                                )}
                            </article>
                        </div>

                        {/* Modal Footer */}
                        <div className="onboarding-manual-footer">
                            <button
                                type="button"
                                className="button"
                                onClick={() => {
                                    router.post('/commands/tours/restart', {}, {
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            closeManual();
                                            startTour();
                                        },
                                    });
                                }}
                            >
                                <RotateCcw size={15} />
                                {translate('portal', 'tour_btn_start_tour')}
                            </button>

                            <button
                                type="button"
                                className="button dark"
                                onClick={closeManual}
                            >
                                {translate('portal', 'close')}
                            </button>
                        </div>
                    </div>
                </div>
            )}
        </>
    );
}