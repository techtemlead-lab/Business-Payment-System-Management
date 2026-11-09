import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { number } from './UI';
import {
    Sparkles, BookOpen, Compass, ChevronRight, ChevronLeft, X, Search,
    CheckCircle2, HelpCircle, Lightbulb, ArrowUpRight, ShoppingBag, Coins,
    ShieldCheck, Layers, FileText, ArrowRight, RotateCcw, MapPin, Zap, Info, AlertTriangle
} from 'lucide-react';
import { useT } from '../shared/i18n/t';

export type TourStep = {
    target: string;
    title: string;
    body: string;
    tip?: string;
    category?: string;
    location?: string;
    action?: string;
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
    badge?: string;
    title: string;
    summary: string;
    purpose?: string;
    why?: string;
    steps: string[];
    outcomes?: string[];
    rules?: string[];
    tips: string[];
    warnings?: string[];
    faqs?: Array<{ q: string; a: string }>;
    keywords?: string[];
    /** The portal pages this chapter explains; the compass opens the guide at it from those pages. */
    pages?: string[];
};

export type TourPayload = {
    completed?: string[];
    pending?: TourDefinition[];
    blocked_by?: Array<{ key: string; title: string; href: string }>;
    is_first_login?: boolean;
    all_tours?: TourDefinition[];
    chapters?: ManualChapter[];
    labels?: { this_page?: string; show_me?: string };
};

/**
 * The manual quotes live rates and targets as ":name" placeholders; the values arrive as the
 * deferred `manualRuleValues` prop (already in Bangla digits on Bangla pages). Until they
 * arrive, a placeholder shows as "…" rather than as code.
 */
function fillManualRates(chapter: ManualChapter, values: Record<string, string> | undefined): ManualChapter {
    const fill = (text: string) => text.replace(/:([a-z][a-z0-9]*(?:_[a-z0-9]+)+)/g, (match, name: string) =>
        values ? (values[name] ?? match) : '…');
    const list = (items?: string[]) => items?.map(fill);

    return {
        ...chapter,
        title: fill(chapter.title),
        summary: fill(chapter.summary),
        purpose: chapter.purpose ? fill(chapter.purpose) : chapter.purpose,
        why: chapter.why ? fill(chapter.why) : chapter.why,
        steps: chapter.steps.map(fill),
        outcomes: list(chapter.outcomes),
        rules: list(chapter.rules),
        tips: chapter.tips.map(fill),
        warnings: list(chapter.warnings),
        faqs: chapter.faqs?.map((faq) => ({ q: fill(faq.q), a: fill(faq.a) })),
    };
}

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

const autostartKey = 'madina_tour_autostart';
const dismissedKey = (userId: unknown) => `madina_tour_dismissed_${userId || 'guest'}`;

function readSession(key: string): string | null {
    try {
        return window.sessionStorage.getItem(key);
    } catch {
        return null;
    }
}

function writeSession(key: string, value: string | null): void {
    try {
        if (value === null) {
            window.sessionStorage.removeItem(key);
        } else {
            window.sessionStorage.setItem(key, value);
        }
    } catch {
        // Private mode or blocked storage: the tour still works, it just is not remembered.
    }
}

/** The tour walks through the dashboard, so it always runs on the portal home page. */
function onDashboard(portalBase: string | undefined): boolean {
    return window.location.pathname.replace(/\/+$/, '') === (portalBase || '').replace(/\/+$/, '');
}

/**
 * A step target may list several selectors ("desktop place, phone place"). Use the first one
 * that is really on screen: the side menu, for example, is pushed off-screen on phones.
 */
function findVisibleTarget(selector: string): HTMLElement | null {
    let candidates: HTMLElement[] = [];
    try {
        candidates = Array.from(document.querySelectorAll<HTMLElement>(selector));
    } catch {
        return null;
    }

    return candidates.find((el) => {
        const box = el.getBoundingClientRect();
        const style = window.getComputedStyle(el);

        return box.width > 0 && box.height > 0 && box.right > 0 && box.left < window.innerWidth
            && style.visibility !== 'hidden' && style.display !== 'none';
    }) ?? null;
}

/**
 * Start the dashboard tour again from anywhere (Help page, user guide): forget earlier
 * skips, reset the tour, then open the dashboard and start it there.
 */
export function restartOnboardingTour(userId: unknown, portalBase: string | undefined): void {
    writeSession(dismissedKey(userId), null);
    writeSession(autostartKey, '1');
    router.post('/commands/tours/restart', { page: 'overview' }, {
        preserveScroll: true,
        onSuccess: () => {
            if (!onDashboard(portalBase)) {
                router.visit(portalBase || '/');
            }
        },
    });
}

type CardPosition = 'bottom' | 'top' | 'left' | 'right' | 'center';

const cardGap = 16;
const cardMargin = 16;

function cardWidthFor(viewportWidth: number): number {
    return Math.min(420, viewportWidth - cardMargin * 2);
}

/** Keep the guide card safely inside the viewport and off the thing it is talking about. */
function calculateCardLayout(rect: DOMRect | null, measuredHeight: number): { top: number; left: number; position: CardPosition } {
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const cardWidth = cardWidthFor(viewportWidth);
    const cardHeight = Math.min(measuredHeight || 290, viewportHeight - cardMargin * 2);

    if (!rect) {
        return {
            top: Math.max(cardMargin, Math.round((viewportHeight - cardHeight) / 2)),
            left: Math.max(cardMargin, Math.round((viewportWidth - cardWidth) / 2)),
            position: 'center',
        };
    }

    const centredLeft = Math.max(cardMargin, Math.min(rect.left + rect.width / 2 - cardWidth / 2, viewportWidth - cardWidth - cardMargin));
    const besideTop = Math.max(cardMargin, Math.min(rect.top, viewportHeight - cardHeight - cardMargin));

    if (rect.bottom + cardGap + cardHeight <= viewportHeight - cardMargin) {
        return { top: Math.round(rect.bottom + cardGap), left: Math.round(centredLeft), position: 'bottom' };
    }
    if (rect.top - cardGap - cardHeight >= cardMargin) {
        return { top: Math.round(rect.top - cardGap - cardHeight), left: Math.round(centredLeft), position: 'top' };
    }
    if (viewportWidth - rect.right >= cardWidth + cardGap + cardMargin) {
        return { top: Math.round(besideTop), left: Math.round(rect.right + cardGap), position: 'right' };
    }
    if (rect.left >= cardWidth + cardGap + cardMargin) {
        return { top: Math.round(besideTop), left: Math.round(rect.left - cardGap - cardWidth), position: 'left' };
    }

    return { top: Math.round(Math.max(cardMargin, viewportHeight - cardHeight - cardMargin)), left: Math.round(centredLeft), position: 'bottom' };
}

/**
 * Brings a step's target on screen and leaves room for the card. When the card can only go
 * under the target (a phone), the target goes to the top of the screen, just below the top bar.
 */
function scrollTargetIntoView(el: HTMLElement, cardHeight: number): void {
    const box = el.getBoundingClientRect();
    const cardWidth = cardWidthFor(window.innerWidth);
    const fitsBeside = window.innerWidth - box.right >= cardWidth + cardGap + cardMargin || box.left >= cardWidth + cardGap + cardMargin;
    const roomWhenCentred = (window.innerHeight - box.height) / 2;
    const block: ScrollLogicalPosition = !fitsBeside && roomWhenCentred < cardHeight + cardGap + cardMargin ? 'start' : 'center';
    if (block === 'start') {
        const topBar = document.querySelector<HTMLElement>('.workspace-header');
        el.style.scrollMarginTop = `${(topBar?.getBoundingClientRect().bottom ?? 0) + 24}px`;
    }
    try {
        el.scrollIntoView({ behavior: 'smooth', block, inline: 'nearest' });
    } catch {
        el.scrollIntoView();
    }
}

export default function OnboardingTour({
    tour,
    chapters: manualChaptersProp,
    forceOpenManual,
    onCloseManual,
}: OnboardingTourProps) {
    const translate = useT();
    const page = usePage<any>();
    const { auth, manualRuleValues, portalBase } = page.props;
    const user = auth?.user;
    const capabilities: string[] = auth?.capabilities ?? [];
    const section = String(page.props.section ?? '');
    const onInnerPage = section !== '' && section !== 'overview';

    const chapters = useMemo<ManualChapter[]>(() => {
        const source = manualChaptersProp && manualChaptersProp.length > 0
            ? manualChaptersProp
            : (tour?.chapters && tour.chapters.length > 0 ? tour.chapters : []);

        return source.map((chapter) => fillManualRates(chapter, manualRuleValues));
    }, [manualChaptersProp, tour?.chapters, manualRuleValues]);

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
    /** The steps whose target is on this screen, fixed when the tour starts. */
    const [liveSteps, setLiveSteps] = useState<TourStep[] | null>(null);
    /** A tour of the page on screen, started from the guide; the dashboard tour is not affected. */
    const [pageTour, setPageTour] = useState<TourDefinition | null>(null);
    const [cardHeight, setCardHeight] = useState(0);
    const [beaconWidth, setBeaconWidth] = useState(0);
    const cardRef = useRef<HTMLDivElement | null>(null);
    const beaconRef = useRef<HTMLDivElement | null>(null);

    const pageChapter = useMemo(
        () => (onInnerPage ? chapters.find((chapter) => chapter.pages?.includes(section)) : undefined),
        [chapters, onInnerPage, section],
    );

    /** The page's own explanation: the pages no longer print it, so the guide says it instead. */
    const pageExplanation = useMemo(() => {
        if (!onInnerPage) {
            return '';
        }
        if (section === 'catalog' && capabilities.length > 0) {
            return translate('portal', 'catalog_staff_hint');
        }
        const pageKey = (section === 'inventory' ? 'stock' : section).replaceAll('-', '_');
        const key = `${capabilities.length > 0 ? 'section_hint_' : 'section_hint_partner_'}${pageKey}`;
        const text = translate('portal', key);

        return text === key ? '' : text;
    }, [onInnerPage, section, capabilities.length, translate]);

    /** Steps of this page's own tour that really are on the screen right now. */
    const pageTourSteps = useMemo<TourStep[]>(() => {
        if (!manualOpen || !onInnerPage) {
            return [];
        }
        const definition = tour?.all_tours?.find((candidate) => candidate.key === section);

        return definition ? definition.steps.filter((step) => findVisibleTarget(step.target) !== null) : [];
    }, [manualOpen, onInnerPage, section, tour?.all_tours]);

    // Sync external forceOpenManual: the guide opens at the chapter for the page on screen.
    useEffect(() => {
        if (forceOpenManual) {
            if (pageChapter) {
                setSelectedChapterKey(pageChapter.key);
            }
            setManualOpen(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [forceOpenManual]);

    // Handle first-time login trigger; a restart from Help starts the tour straight away instead.
    useEffect(() => {
        if (!activeTour) {
            return;
        }
        if (readSession(autostartKey)) {
            if (!onDashboard(portalBase)) {
                // On the way to the dashboard; the tour starts there.
                return;
            }
            writeSession(autostartKey, null);
            const timer = window.setTimeout(() => startTour(), 250);

            return () => window.clearTimeout(timer);
        }
        if (isFirstTime && !readSession(dismissedKey(user?.id))) {
            setWelcomeOpen(true);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isFirstTime, user?.id, activeTour?.key, page.url]);

    // Set default selected chapter
    useEffect(() => {
        if (chapters.length > 0 && !selectedChapterKey) {
            setSelectedChapterKey(chapters[0].key);
        }
    }, [chapters, selectedChapterKey]);

    const tourKey = activeTour?.key;
    const steps = liveSteps ?? activeTour?.steps ?? [];
    const stepCount = steps.length;
    const currentStep = steps[stepIndex] || null;

    // Reset tour position when active tour changes
    useEffect(() => {
        setStepIndex(0);
        setRect(null);
        setElementMissing(false);
        setLiveSteps(null);
    }, [tourKey]);

    // Measure the real card so it is placed where it fits (the card grows with long Bangla text).
    useLayoutEffect(() => {
        if (tourActive && cardRef.current) {
            setCardHeight(cardRef.current.offsetHeight);
        }
        if (tourActive && beaconRef.current) {
            setBeaconWidth(beaconRef.current.offsetWidth);
        }
    }, [tourActive, stepIndex, rect]);

    // Locate target element and compute bounding rect with smooth scroll
    useEffect(() => {
        if (!tourActive || !currentStep) {
            setRect(null);
            return;
        }

        let isMounted = true;
        const targetSelector = currentStep.target;
        const el = findVisibleTarget(targetSelector);

        if (!el) {
            setRect(null);
            setElementMissing(true);
            return;
        }

        setElementMissing(false);

        scrollTargetIntoView(el, cardRef.current?.offsetHeight ?? 290);

        const updateRect = () => {
            if (!isMounted) return;
            const updatedEl = el.isConnected ? el : findVisibleTarget(targetSelector);
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
        const finishedKey = pageTour?.key ?? tourKey;
        if (pageTour) {
            setPageTour(null);
            setLiveSteps(null);
        }
        if (finishedKey) {
            // async: a click on a link right after "Done" must not cancel saving it.
            router.post(`/commands/tours/${finishedKey}/complete`, {}, {
                preserveScroll: true,
                preserveState: true,
                async: true,
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
        // The steps point at the dashboard: open it first when the tour is started elsewhere.
        if (!onDashboard(portalBase)) {
            writeSession(autostartKey, '1');
            router.visit(portalBase || '/');
            return;
        }
        const all = activeTour?.steps ?? [];
        const present = all.filter((step) => findVisibleTarget(step.target) !== null);
        setLiveSteps(present.length > 0 ? present : all);
        setStepIndex(0);
        setTourActive(true);
    };

    const startPageTour = () => {
        const definition = tour?.all_tours?.find((candidate) => candidate.key === section);
        if (!definition || pageTourSteps.length === 0) {
            return;
        }
        closeManual();
        setPageTour(definition);
        setLiveSteps(pageTourSteps);
        setStepIndex(0);
        setTourActive(true);
    };

    const dismissWelcome = () => {
        setWelcomeOpen(false);
        writeSession(dismissedKey(user?.id), 'true');
        if (tourKey) {
            router.post(`/commands/tours/${tourKey}/complete`, {}, {
                preserveScroll: true,
                preserveState: true,
                async: true,
            });
        }
    };

    const openManualToChapter = (chapterKey?: string) => {
        if (chapterKey) {
            // The dashboard tour has no chapter of its own; "Getting started" covers it.
            setSelectedChapterKey(chapterKey === 'overview' ? 'getting_started' : chapterKey);
        }
        setWelcomeOpen(false);
        setTourActive(false);
        if (pageTour) {
            setPageTour(null);
            setLiveSteps(null);
        }
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

    const { top: cardTop, left: cardLeft, position: cardPos } = calculateCardLayout(rect, cardHeight);
    const progressPercent = stepCount > 0 ? Math.round(((stepIndex + 1) / stepCount) * 100) : 0;

    const roleWelcome = useMemo(() => {
        const aud = activeTour?.audience || tour?.pending?.[0]?.audience || 'dealer';
        switch (aud) {
            case 'owner':
                return {
                    badge: translate('portal', 'tour_welcome_owner_badge'),
                    title: translate('portal', 'tour_welcome_owner_title'),
                    subtitle: translate('portal', 'tour_welcome_owner_sub'),
                    pillars: [
                        {
                            icon: <ShieldCheck size={22} />,
                            iconClass: 'ops',
                            title: translate('portal', 'tour_pillar_owner_gov_title'),
                            desc: translate('portal', 'tour_pillar_owner_gov_desc'),
                        },
                        {
                            icon: <Coins size={22} />,
                            iconClass: 'fin',
                            title: translate('portal', 'tour_pillar_owner_treasury_title'),
                            desc: translate('portal', 'tour_pillar_owner_treasury_desc'),
                        },
                        {
                            icon: <Layers size={22} />,
                            iconClass: 'help',
                            title: translate('portal', 'tour_pillar_owner_branches_title'),
                            desc: translate('portal', 'tour_pillar_owner_branches_desc'),
                        },
                    ],
                };
            case 'sub_dealer':
                return {
                    badge: translate('portal', 'tour_welcome_subdealer_badge'),
                    title: translate('portal', 'tour_welcome_subdealer_title'),
                    subtitle: translate('portal', 'tour_welcome_subdealer_sub'),
                    pillars: [
                        {
                            icon: <ShoppingBag size={22} />,
                            iconClass: 'ops',
                            title: translate('portal', 'tour_pillar_subdealer_retail_title'),
                            desc: translate('portal', 'tour_pillar_subdealer_retail_desc'),
                        },
                        {
                            icon: <HelpCircle size={22} />,
                            iconClass: 'help',
                            title: translate('portal', 'tour_pillar_subdealer_sponsor_title'),
                            desc: translate('portal', 'tour_pillar_subdealer_sponsor_desc'),
                        },
                        {
                            icon: <Coins size={22} />,
                            iconClass: 'fin',
                            title: translate('portal', 'tour_pillar_subdealer_salary_title'),
                            desc: translate('portal', 'tour_pillar_subdealer_salary_desc'),
                        },
                    ],
                };
            case 'staff':
                return {
                    badge: translate('portal', 'tour_welcome_staff_badge'),
                    title: translate('portal', 'tour_welcome_staff_title'),
                    subtitle: translate('portal', 'tour_welcome_staff_sub'),
                    pillars: [
                        {
                            needs: ['orders'],
                            icon: <ShoppingBag size={22} />,
                            iconClass: 'ops',
                            title: translate('portal', 'tour_pillar_staff_dispatch_title'),
                            desc: translate('portal', 'tour_pillar_staff_dispatch_desc'),
                        },
                        {
                            needs: ['order_review', 'finance'],
                            icon: <CheckCircle2 size={22} />,
                            iconClass: 'fin',
                            title: translate('portal', 'tour_pillar_staff_claims_title'),
                            desc: translate('portal', 'tour_pillar_staff_claims_desc'),
                        },
                        {
                            needs: ['inventory'],
                            icon: <Layers size={22} />,
                            iconClass: 'help',
                            title: translate('portal', 'tour_pillar_staff_inventory_title'),
                            desc: translate('portal', 'tour_pillar_staff_inventory_desc'),
                        },
                    ].filter((pillar) => pillar.needs.some((capability) => capabilities.includes(capability))),
                };
            case 'dealer':
            default:
                return {
                    badge: translate('portal', 'tour_welcome_dealer_badge'),
                    title: translate('portal', 'tour_welcome_dealer_title'),
                    subtitle: translate('portal', 'tour_welcome_dealer_sub'),
                    pillars: [
                        {
                            icon: <ShoppingBag size={22} />,
                            iconClass: 'ops',
                            title: translate('portal', 'tour_pillar_dealer_wholesale_title'),
                            desc: translate('portal', 'tour_pillar_dealer_wholesale_desc'),
                        },
                        {
                            icon: <Layers size={22} />,
                            iconClass: 'help',
                            title: translate('portal', 'tour_pillar_dealer_network_title'),
                            desc: translate('portal', 'tour_pillar_dealer_network_desc'),
                        },
                        {
                            icon: <Coins size={22} />,
                            iconClass: 'fin',
                            title: translate('portal', 'tour_pillar_dealer_wallet_title'),
                            desc: translate('portal', 'tour_pillar_dealer_wallet_desc'),
                        },
                    ],
                };
        }
    }, [activeTour?.audience, tour?.pending, translate, capabilities]);

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
                                <span>{roleWelcome.badge}</span>
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
                                {roleWelcome.title}
                            </h2>
                            <p className="onboarding-welcome-subtitle">
                                {roleWelcome.subtitle}
                            </p>

                            <div className="onboarding-user-pill">
                                <strong>{user?.name}</strong>
                                <span>·</span>
                                <span>{user?.dealer_code || user?.email}</span>
                                <span>·</span>
                                <span className="onboarding-role-tag">{audienceLabel(tour?.pending?.[0]?.audience, translate)}</span>
                            </div>

                            <div className="onboarding-pillars-grid">
                                {roleWelcome.pillars.map((pillar, pIdx) => (
                                    <div key={pIdx} className="onboarding-pillar-card">
                                        <div className={`pillar-icon ${pillar.iconClass}`}>
                                            {pillar.icon}
                                        </div>
                                        <h4>{pillar.title}</h4>
                                        <p>{pillar.desc}</p>
                                    </div>
                                ))}
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
                    aria-label={pageTour?.title || activeTour?.title || translate('portal', 'tour_live_guide')}
                >
                    {/* Dark spotlight mask */}
                    <div className="onboarding-spotlight-backdrop" onClick={completeTour} />

                    {/* Target highlight ring with pulsing glow */}
                    {rect && (
                        <>
                            <div
                                className="onboarding-spotlight-ring"
                                style={{
                                    top: Math.max(6, rect.top - 8),
                                    left: Math.max(6, rect.left - 8),
                                    width: Math.min(rect.width + 16, window.innerWidth - 12),
                                    height: Math.min(rect.height + 16, window.innerHeight - 12),
                                }}
                            />

                            {/* Floating Target Beacon & Indicator Badge */}
                            <div
                                ref={beaconRef}
                                className="onboarding-target-beacon"
                                style={{
                                    top: Math.max(10, rect.top - 18),
                                    left: Math.max(12, Math.min(rect.left + 14, window.innerWidth - beaconWidth - 12)),
                                }}
                            >
                                <span className="beacon-ping" />
                                <span className="beacon-dot" />
                                <span className="beacon-label">
                                    {currentStep.location ? `📍 ${currentStep.location}` : `👉 ${currentStep.title}`}
                                </span>
                            </div>
                        </>
                    )}

                    {/* Floating Walkthrough Card */}
                    <div
                        ref={cardRef}
                        className={`onboarding-tour-card floating-step-card position-${cardPos}`}
                        style={{ top: cardTop, left: cardLeft, maxHeight: 'calc(100vh - 32px)', overflowY: 'auto' }}
                    >
                        {/* Directional pointer arrow connecting card to target element */}
                        {rect && <div className={`onboarding-card-pointer pointer-${cardPos}`} />}

                        <div className="onboarding-card-header">
                            <div className="onboarding-card-tags">
                                <span className="onboarding-tag audience">
                                    {audienceLabel(activeTour?.audience, translate)}
                                </span>
                                {currentStep.category && (
                                    <span className="onboarding-tag category">
                                        {currentStep.category}
                                    </span>
                                )}
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
                            {/* Explicit Location Breadcrumb */}
                            {currentStep.location && (
                                <div className="onboarding-location-breadcrumb">
                                    <MapPin size={13} className="location-icon" />
                                    <span>
                                        {translate('portal', 'tour_location_label')}: <strong>{currentStep.location}</strong>
                                    </span>
                                </div>
                            )}

                            <h3 className="onboarding-step-title">{currentStep.title}</h3>
                            <p className="onboarding-step-body">{currentStep.body}</p>

                            {/* Action Guide Box */}
                            {currentStep.action && (
                                <div className="onboarding-step-action">
                                    <Zap size={14} className="action-icon" />
                                    <div>
                                        <strong>{translate('portal', 'tour_action_label')}:</strong>{' '}
                                        <span>{currentStep.action}</span>
                                    </div>
                                </div>
                            )}

                            {/* Pro Tip Box */}
                            {currentStep.tip && (
                                <div className="onboarding-step-tip">
                                    <Lightbulb size={16} className="tip-icon" />
                                    <div>
                                        <strong>{translate('portal', 'tour_tip_label')}</strong>
                                        <p>{currentStep.tip}</p>
                                    </div>
                                </div>
                            )}

                            {/* Interactive Step Mini-Map (Clickable Dots) */}
                            {stepCount > 1 && (
                                <div className="onboarding-step-dots" role="tablist" aria-label={translate('portal', 'aria_tour_steps')}>
                                    {steps.map((st, idx) => {
                                        const isCurrent = idx === stepIndex;
                                        const isCompleted = idx < stepIndex;
                                        return (
                                            <button
                                                key={idx}
                                                type="button"
                                                role="tab"
                                                aria-selected={isCurrent}
                                                className={`onboarding-step-dot ${isCurrent ? 'active' : ''} ${isCompleted ? 'completed' : ''}`}
                                                title={translate('portal', 'tour_jump_to_step', { step: String(idx + 1), title: st.title })}
                                                onClick={() => setStepIndex(idx)}
                                            >
                                                <span className="dot-circle" />
                                            </button>
                                        );
                                    })}
                                </div>
                            )}
                        </div>

                        <div className="onboarding-card-footer">
                            <div className="onboarding-footer-left">
                                <button
                                    type="button"
                                    className="text-link small tour-manual-link"
                                    onClick={() => openManualToChapter(pageTour ? pageChapter?.key : tourKey)}
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

                        {window.innerWidth >= 768 && (
                            <div className="onboarding-keyboard-hint">
                                <small>{translate('portal', 'tour_keyboard_shortcuts')}</small>
                            </div>
                        )}
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
                            <nav className="onboarding-manual-toc" aria-label={translate('portal', 'aria_manual_chapters')}>
                                <div className="toc-header">
                                    <span>{translate('portal', 'tour_manual_filter_all')}</span>
                                    <span className="chapter-count-badge">
                                        {number(filteredChapters.length)} / {number(chapters.length)}
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
                                {(pageExplanation || pageTourSteps.length > 0) && (
                                    <div className="help-manual-callout help-manual-purpose otr-callout" data-testid="guide-this-page">
                                        <MapPin size={18} className="callout-icon-col" />
                                        <div>
                                            <strong className="ops-block ops-mb-1">{tour?.labels?.this_page}</strong>
                                            {pageExplanation && <p className="ops-m-0 ops-fs-sm">{pageExplanation}</p>}
                                            {pageTourSteps.length > 0 && (
                                                <button type="button" className="button small dark ops-mt-2" onClick={startPageTour}>
                                                    <Sparkles size={15} />
                                                    {tour?.labels?.show_me}
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                )}

                                {activeChapter ? (
                                    <>
                                        <div className="reader-header">
                                            <div>
                                                <div className="ops-row ops-mb-1">
                                                    <span className="reader-eyebrow">
                                                        {translate('portal', 'help_manual_title')}
                                                    </span>
                                                    {activeChapter.badge && (
                                                        <span className="badge info small">{activeChapter.badge}</span>
                                                    )}
                                                </div>
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

                                        {activeChapter.purpose && (
                                            <div className="help-manual-callout help-manual-purpose otr-callout">
                                                <Info size={18} className="callout-icon-col" />
                                                <div>
                                                    <strong className="ops-block ops-mb-1">{translate('portal', 'help_manual_purpose')}</strong>
                                                    <p className="ops-m-0 ops-fs-sm">{activeChapter.purpose}</p>
                                                </div>
                                            </div>
                                        )}

                                        {activeChapter.why && (
                                            <div className="help-manual-callout help-manual-why otr-callout">
                                                <Compass size={18} className="callout-icon-col" />
                                                <div>
                                                    <strong className="ops-block ops-mb-1">{translate('portal', 'help_manual_why')}</strong>
                                                    <p className="ops-m-0 ops-fs-sm">{activeChapter.why}</p>
                                                </div>
                                            </div>
                                        )}

                                        <div className="reader-steps-list">
                                            <h4>{translate('portal', 'help_manual_steps')}</h4>
                                            <div className="step-cards-grid">
                                                {activeChapter.steps.map((stepText, sIdx) => (
                                                    <div className="manual-step-card" key={sIdx}>
                                                        <span className="step-badge">{sIdx + 1}</span>
                                                        <p className="step-text">{stepText}</p>
                                                    </div>
                                                ))}
                                            </div>
                                        </div>

                                        {activeChapter.outcomes && activeChapter.outcomes.length > 0 && (
                                            <div className="help-manual-callout help-manual-outcomes otr-callout">
                                                <CheckCircle2 size={18} className="callout-icon-col" />
                                                <div>
                                                    <strong className="ops-block ops-mb-1">{translate('portal', 'help_manual_outcomes')}</strong>
                                                    <ul className="ops-list">
                                                        {activeChapter.outcomes.map((out, oIdx) => (
                                                            <li key={oIdx}>{out}</li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            </div>
                                        )}

                                        {activeChapter.rules && activeChapter.rules.length > 0 && (
                                            <div className="help-manual-callout help-manual-rules otr-callout">
                                                <FileText size={18} className="callout-icon-col" />
                                                <div>
                                                    <strong className="ops-block ops-mb-1">{translate('portal', 'help_manual_rules')}</strong>
                                                    <ul className="ops-list">
                                                        {activeChapter.rules.map((rule, rIdx) => (
                                                            <li key={rIdx}>{rule}</li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            </div>
                                        )}

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

                                        {activeChapter.warnings && activeChapter.warnings.length > 0 && (
                                            <div className="help-manual-callout help-manual-warnings otr-callout">
                                                <AlertTriangle size={18} className="callout-icon-col" />
                                                <div>
                                                    <strong className="ops-block ops-mb-1">{translate('portal', 'help_manual_warnings')}</strong>
                                                    <ul className="ops-list">
                                                        {activeChapter.warnings.map((warn, wIdx) => (
                                                            <li key={wIdx}>{warn}</li>
                                                        ))}
                                                    </ul>
                                                </div>
                                            </div>
                                        )}

                                        {activeChapter.faqs && activeChapter.faqs.length > 0 && (
                                            <div className="ops-mt-4">
                                                <h4 className="ops-m-0 ops-mb-3 ops-fs-base">{translate('portal', 'help_manual_faqs')}</h4>
                                                <div className="ops-stack is-snug">
                                                    {activeChapter.faqs.map((faq, fIdx) => (
                                                        <div key={fIdx} className="ops-card is-soft is-tight">
                                                            <strong className="ops-block ops-fs-sm ops-mb-1 ops-text">{faq.q}</strong>
                                                            <p className="ops-m-0 ops-fs-xs ops-text-2">{faq.a}</p>
                                                        </div>
                                                    ))}
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
                                    closeManual();
                                    restartOnboardingTour(user?.id, portalBase);
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