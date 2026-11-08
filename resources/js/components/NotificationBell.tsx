import { useCallback, useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { Link, router, usePage } from '@inertiajs/react';
import { Bell, CheckCheck, X } from 'lucide-react';
import { date, number } from './UI';
import { useT } from '../shared/i18n/t';

/** Phones get a sheet over the whole screen; wider screens get a panel under the bell. */
const PHONE_QUERY = '(max-width:767px)';

type Notice = {
    id: number;
    title: string;
    body: string;
    read_at: string | null;
    created_at: string | null;
};

type LoadState = 'idle' | 'loading' | 'ready' | 'failed';

function csrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    if (meta) {
        return meta;
    }
    const xsrf = document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='));

    return xsrf ? decodeURIComponent(xsrf.split('=').slice(1).join('=')) : '';
}

async function postJson(url: string): Promise<{ unread: number }> {
    const token = csrfToken();
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': token,
            'X-XSRF-TOKEN': token,
        },
    });
    if (!response.ok) {
        throw new Error(String(response.status));
    }
    const body = await response.json();

    return { unread: Number(body?.data?.unread ?? 0) };
}

/** Keep the bell badge and the sidebar count in step without reloading the page. */
function shareUnreadCount(unread: number): void {
    router.replace({
        preserveScroll: true,
        preserveState: true,
        props: (props: Record<string, any>) => ({
            ...props,
            unreadNotices: unread,
            navAttention: { ...(props.navAttention ?? {}), notifications: unread },
        }),
    });
}

/**
 * The top-bar bell: a small panel with the newest notices, loaded only when it opens.
 */
export default function NotificationBell({ base, unreadCount }: { base: string; unreadCount: number }) {
    const translate = useT();
    const page = usePage();
    const panelId = useId();
    const titleId = useId();
    const root = useRef<HTMLDivElement>(null);
    const bell = useRef<HTMLButtonElement>(null);
    const panel = useRef<HTMLDivElement>(null);
    const [open, setOpen] = useState(false);
    const [state, setState] = useState<LoadState>('idle');
    const [notices, setNotices] = useState<Notice[]>([]);
    const [expandedId, setExpandedId] = useState<number | null>(null);
    const [markingAll, setMarkingAll] = useState(false);
    const [attempt, setAttempt] = useState(0);
    const onNoticesPage = String(page.url || '').split('?')[0].endsWith('/notifications');
    const [phone, setPhone] = useState(() => typeof window !== 'undefined' && matchMedia(PHONE_QUERY).matches);

    useEffect(() => {
        const media = matchMedia(PHONE_QUERY);
        const change = () => setPhone(media.matches);
        change();
        media.addEventListener('change', change);

        return () => media.removeEventListener('change', change);
    }, []);

    const inside = (target: EventTarget | null): boolean => target instanceof Node
        && (Boolean(root.current?.contains(target)) || Boolean(panel.current?.contains(target)));

    const close = useCallback((returnFocus: boolean) => {
        setOpen(false);
        if (returnFocus) {
            bell.current?.focus();
        }
    }, []);

    useEffect(() => {
        if (!open) {
            return;
        }
        const controller = new AbortController();
        setState((current) => (current === 'ready' ? 'ready' : 'loading'));
        fetch('/commands/notices/latest', {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then((response) => {
                if (!response.ok) {
                    throw new Error(String(response.status));
                }

                return response.json();
            })
            .then((body: { data: { notices: Notice[]; unread: number } }) => {
                setNotices(body.data.notices ?? []);
                setState('ready');
                const unread = Number(body.data.unread ?? 0);
                if (unread !== unreadCount) {
                    shareUnreadCount(unread);
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setState('failed');
                }
            });

        return () => controller.abort();
        // Load once per opening; the badge count is read when the reply arrives.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, attempt]);

    useEffect(() => {
        if (!open) {
            return;
        }
        panel.current?.focus();
        const onPointer = (event: MouseEvent) => {
            if (!inside(event.target)) {
                close(false);
            }
        };
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                close(true);
            }
        };
        const onFocus = (event: FocusEvent) => {
            if (!inside(event.target)) {
                close(false);
            }
        };
        document.addEventListener('mousedown', onPointer);
        document.addEventListener('keydown', onKey);
        document.addEventListener('focusin', onFocus);
        // Close when the person moves to another page; a reload of this page keeps the panel open.
        const stopListening = router.on('start', (event) => {
            if (event.detail.visit.url.pathname !== window.location.pathname) {
                close(false);
            }
        });

        return () => {
            document.removeEventListener('mousedown', onPointer);
            document.removeEventListener('keydown', onKey);
            document.removeEventListener('focusin', onFocus);
            stopListening();
        };
        // `inside` only reads refs, so it does not need to restart the listeners.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, close]);

    useEffect(() => {
        if (!open || !phone) {
            return;
        }
        const previousOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';

        return () => {
            document.body.style.overflow = previousOverflow;
        };
    }, [open, phone]);

    const afterMarking = (unread: number) => {
        shareUnreadCount(unread);
        if (onNoticesPage) {
            router.reload();
        }
    };

    const openNotice = (notice: Notice) => {
        setExpandedId((current) => (current === notice.id ? null : notice.id));
        if (notice.read_at) {
            return;
        }
        const readAt = new Date().toISOString();
        setNotices((list) => list.map((item) => (item.id === notice.id ? { ...item, read_at: readAt } : item)));
        postJson(`/commands/notices/${notice.id}/read`)
            .then(({ unread }) => afterMarking(unread))
            .catch(() => {
                setNotices((list) => list.map((item) => (item.id === notice.id ? { ...item, read_at: null } : item)));
            });
    };

    const markAllRead = () => {
        if (markingAll) {
            return;
        }
        setMarkingAll(true);
        const before = notices;
        const readAt = new Date().toISOString();
        setNotices((list) => list.map((item) => (item.read_at ? item : { ...item, read_at: readAt })));
        postJson('/commands/notices/read-all')
            .then(({ unread }) => afterMarking(unread))
            .catch(() => setNotices(before))
            .finally(() => setMarkingAll(false));
    };

    const relativeTime = (value: string | null): string => {
        if (!value) {
            return '';
        }
        const at = new Date(value);
        if (Number.isNaN(at.valueOf())) {
            return '';
        }
        const minutes = Math.floor((Date.now() - at.valueOf()) / 60000);
        if (minutes < 1) {
            return translate('portal', 'notice_time_just_now');
        }
        if (minutes < 60) {
            return translate('portal', 'notice_time_minutes', { count: minutes });
        }
        const hours = Math.floor(minutes / 60);
        if (hours < 24) {
            return translate('portal', 'notice_time_hours', { count: hours });
        }
        const days = Math.floor(hours / 24);
        if (days === 1) {
            return translate('portal', 'notice_time_yesterday');
        }
        if (days < 7) {
            return translate('portal', 'notice_time_days', { count: days });
        }

        return date(value);
    };

    // On phones the sheet sits on the page body so the bottom bar and header cannot cover it.
    const placeSheet = (node: ReactNode): ReactNode => (phone && typeof document !== 'undefined'
        ? createPortal(<div className="notice-sheet-layer">{node}</div>, document.body)
        : node);

    const hasUnread = notices.some((notice) => !notice.read_at) || (state !== 'ready' && unreadCount > 0);
    const bellLabel = unreadCount > 0
        ? translate('portal', 'notice_panel_bell_unread', { count: unreadCount })
        : translate('portal', 'notifications');

    return (
        <div className="notice-bell-wrap" ref={root}>
            <button
                ref={bell}
                type="button"
                className="icon-button notice-bell"
                aria-label={bellLabel}
                title={translate('portal', 'notifications')}
                data-tooltip={translate('portal', 'notifications')}
                aria-haspopup="dialog"
                aria-expanded={open}
                aria-controls={open ? panelId : undefined}
                onClick={() => (open ? close(false) : setOpen(true))}
            >
                <Bell size={19} />
                {unreadCount > 0 && (
                    <span className="notice-bell-count" aria-hidden="true">
                        {unreadCount > 99 ? `${number(99)}+` : number(unreadCount)}
                    </span>
                )}
            </button>
            {open && placeSheet(
                <>
                    <div className="notice-panel-backdrop" aria-hidden="true" onClick={() => close(false)} />
                    <div
                        ref={panel}
                        id={panelId}
                        className="notice-panel"
                        role="dialog"
                        aria-modal={phone}
                        aria-labelledby={titleId}
                        tabIndex={-1}
                    >
                        <div className="notice-panel-head">
                            <h2 id={titleId}>{translate('portal', 'notifications')}</h2>
                            <div className="notice-panel-head-actions">
                                {hasUnread && state === 'ready' && (
                                    <button type="button" className="notice-panel-mark-all" onClick={markAllRead} disabled={markingAll}>
                                        <CheckCheck size={15} aria-hidden="true" />
                                        {translate('portal', 'notice_panel_mark_all')}
                                    </button>
                                )}
                                <button
                                    type="button"
                                    className="notice-panel-close"
                                    aria-label={translate('portal', 'notice_panel_close')}
                                    onClick={() => close(true)}
                                >
                                    <X size={17} aria-hidden="true" />
                                </button>
                            </div>
                        </div>
                        <div className="notice-panel-body" aria-busy={state === 'loading'}>
                            {state === 'loading' && (
                                <ul className="notice-panel-list" aria-label={translate('portal', 'notice_panel_loading')}>
                                    {[0, 1, 2, 3].map((row) => (
                                        <li key={row} className="notice-panel-skeleton">
                                            <span className="skeleton notice-panel-skeleton-icon" />
                                            <span className="notice-panel-skeleton-lines">
                                                <span className="skeleton" />
                                                <span className="skeleton" />
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            {state === 'failed' && (
                                <div className="notice-panel-empty" role="alert">
                                    <p>{translate('portal', 'notice_panel_failed')}</p>
                                    <button type="button" className="button small" onClick={() => setAttempt((value) => value + 1)}>
                                        {translate('portal', 'notice_panel_retry')}
                                    </button>
                                </div>
                            )}
                            {state === 'ready' && notices.length === 0 && (
                                <div className="notice-panel-empty">
                                    <span className="notice-panel-empty-icon" aria-hidden="true"><Bell size={22} /></span>
                                    <b>{translate('portal', 'all_caught_up')}</b>
                                    <p>{translate('portal', 'notice_panel_empty_body')}</p>
                                </div>
                            )}
                            {state === 'ready' && notices.length > 0 && (
                                <ul className="notice-panel-list">
                                    {notices.map((notice) => {
                                        const unread = !notice.read_at;
                                        const expanded = expandedId === notice.id;

                                        return (
                                            <li key={notice.id}>
                                                <button
                                                    type="button"
                                                    className={`notice-panel-item ${unread ? 'is-unread' : ''} ${expanded ? 'is-expanded' : ''}`}
                                                    aria-expanded={expanded}
                                                    onClick={() => openNotice(notice)}
                                                >
                                                    <span className="notice-panel-item-icon" aria-hidden="true"><Bell size={16} /></span>
                                                    <span className="notice-panel-item-text">
                                                        <b>{notice.title}</b>
                                                        <span className="notice-panel-item-body">{notice.body}</span>
                                                        <small>
                                                            {unread && <span className="sr-only">{translate('portal', 'notice_panel_unread')} · </span>}
                                                            {relativeTime(notice.created_at)}
                                                        </small>
                                                    </span>
                                                    {unread && <span className="notice-panel-dot" aria-hidden="true" />}
                                                </button>
                                            </li>
                                        );
                                    })}
                                </ul>
                            )}
                        </div>
                        <div className="notice-panel-foot">
                            <Link href={base + '/notifications'} onClick={() => close(false)}>
                                {translate('portal', 'notice_panel_see_all')}
                            </Link>
                        </div>
                    </div>
                </>
            )}
        </div>
    );
}
