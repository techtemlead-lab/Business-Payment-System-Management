import { useEffect, useRef, useState } from 'react';
import { Link, router, usePage, usePoll } from '@inertiajs/react';
import { ImagePlus, Search, Send, X } from 'lucide-react';
import { dateTime, label, Pagination, type Row } from './UI';
import { useT } from '../shared/i18n/t';

type ChatMessage = { id: number; side: 'partner' | 'office'; body: string | null; has_photo: boolean; created_at: string | null; sender_name: string | null };

/** Messages as bubbles: the viewer's own side on the right, the other side on the left. */
function Thread({ messages, mine, officeName }: { messages: ChatMessage[]; mine: 'partner' | 'office'; officeName: string }) {
    const translate = useT();
    const end = useRef<HTMLDivElement>(null);
    useEffect(() => {
        end.current?.scrollIntoView({ block: 'end' });
    }, [messages.length]);
    if (messages.length === 0) {
        return <p className="chat-empty">{translate('portal', mine === 'partner' ? 'chat_empty_partner' : 'chat_empty_office')}</p>;
    }

    return (
        <ol className="chat-thread" aria-live="polite">
            {messages.map((message) => {
                const own = message.side === mine;
                const who = message.side === 'office'
                    ? (mine === 'office' && message.sender_name ? message.sender_name : officeName)
                    : (message.sender_name ?? '');

                return (
                    <li key={message.id} className={`chat-bubble ${own ? 'is-own' : 'is-other'} side-${message.side}`}>
                        {!own && who && <b className="chat-who">{who}</b>}
                        {message.has_photo && (
                            <a href={`/commands/messages/${message.id}/photo`} target="_blank" rel="noopener noreferrer" className="chat-photo">
                                <img src={`/commands/messages/${message.id}/photo`} alt={translate('portal', 'chat_photo_alt')} loading="lazy" />
                            </a>
                        )}
                        {message.body && <p>{message.body}</p>}
                        <small>{message.created_at ? dateTime(message.created_at) : ''}</small>
                    </li>
                );
            })}
            <div ref={end} />
        </ol>
    );
}

/** Text box with an optional photo; Enter sends, Shift+Enter makes a new line. */
function Composer({ action }: { action: string }) {
    const translate = useT();
    const [body, setBody] = useState('');
    const [photo, setPhoto] = useState<File | null>(null);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const fileInput = useRef<HTMLInputElement>(null);
    const submit = () => {
        if (sending || (body.trim() === '' && !photo)) {
            return;
        }
        setSending(true);
        setError(null);
        router.post(action, { body, ...(photo ? { photo } : {}) }, {
            forceFormData: true,
            preserveScroll: true,
            preserveState: true,
            only: ['data', 'navAttention', 'errors'],
            onSuccess: () => {
                setBody('');
                setPhoto(null);
                if (fileInput.current) {
                    fileInput.current.value = '';
                }
            },
            onError: (errors) => setError(String(Object.values(errors)[0] ?? translate('portal', 'chat_err_send'))),
            onFinish: () => setSending(false),
        });
    };

    return (
        <form className="chat-composer" onSubmit={(event) => { event.preventDefault(); submit(); }}>
            {photo && (
                <div className="chat-photo-chip">
                    <span>📷 {photo.name}</span>
                    <button type="button" aria-label={translate('portal', 'chat_remove_photo')} onClick={() => { setPhoto(null); if (fileInput.current) fileInput.current.value = ''; }}><X size={14} /></button>
                </div>
            )}
            {error && <p className="field-error" role="alert">{error}</p>}
            <div className="chat-composer-row">
                <label className="chat-attach" title={translate('portal', 'chat_add_photo')}>
                    <ImagePlus size={20} aria-hidden="true" />
                    <span className="sr-only">{translate('portal', 'chat_add_photo')}</span>
                    <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp" onChange={(event) => setPhoto(event.target.files?.[0] ?? null)} />
                </label>
                <textarea
                    rows={1}
                    maxLength={2000}
                    value={body}
                    placeholder={translate('portal', 'chat_type_here')}
                    aria-label={translate('portal', 'chat_type_here')}
                    onChange={(event) => setBody(event.target.value)}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter' && !event.shiftKey) {
                            event.preventDefault();
                            submit();
                        }
                    }}
                />
                <button type="submit" className="button dark chat-send" disabled={sending || (body.trim() === '' && !photo)}>
                    <Send size={16} aria-hidden="true" />
                    <span>{translate('portal', 'chat_send')}</span>
                </button>
            </div>
        </form>
    );
}

/** Dealer or sub-dealer view: one chat with the company office. */
export function PartnerMessages({ chat }: { chat: Row }) {
    const translate = useT();
    usePoll(10000, { only: ['data', 'navAttention'] });
    const officeName = translate('portal', 'chat_office_name');

    return (
        <section className="panel chat-panel">
            <header className="chat-head">
                <span className="chat-avatar" aria-hidden="true">ম</span>
                <div>
                    <h2>{officeName}</h2>
                    <p>{translate('portal', 'chat_partner_hint')}</p>
                </div>
            </header>
            <Thread messages={chat?.messages ?? []} mine="partner" officeName={officeName} />
            <Composer action="/commands/messages" />
        </section>
    );
}

/** Office view: every partner's chat in one inbox; open one to read and reply. */
export default function OfficeChat({ inbox, thread }: { inbox: Row; thread: Row | null }) {
    const translate = useT();
    const { portalBase: base, filters } = usePage<any>().props;
    usePoll(10000, { only: ['data', 'navAttention'] });
    const [q, setQ] = useState(String(filters?.q ?? ''));
    const rows: Row[] = inbox?.data ?? [];
    const activeId = thread?.partner?.id ?? null;
    const unreadOnly = filters?.status === 'unread';
    const go = (next: Row) => router.get(`${base}/partner-messages`, Object.fromEntries(Object.entries({ q, status: unreadOnly ? 'unread' : '', with: activeId ?? '', ...next }).filter(([, value]) => value)), { preserveState: true, preserveScroll: true });

    return (
        <section className={`panel chat-office${activeId ? ' has-thread' : ''}`}>
            <aside className="chat-inbox" aria-label={translate('portal', 'chat_inbox')}>
                <form className="chat-search" onSubmit={(event) => { event.preventDefault(); go({ q, with: '' }); }}>
                    <div className="search-input"><Search size={16} /><input value={q} onChange={(event) => setQ(event.target.value)} placeholder={translate('portal', 'chat_search')} aria-label={translate('portal', 'chat_search')} /></div>
                    <button type="button" className={`button small${unreadOnly ? ' dark' : ''}`} aria-pressed={unreadOnly} onClick={() => go({ status: unreadOnly ? '' : 'unread' })}>{translate('portal', 'chat_unread_only')}</button>
                </form>
                {rows.length === 0 ? (
                    <p className="chat-empty">{translate('portal', 'chat_inbox_empty')}</p>
                ) : (
                    <ul className="chat-list">
                        {rows.map((row) => (
                            <li key={row.conversation_id}>
                                <Link
                                    href={`${base}/partner-messages?${new URLSearchParams(Object.fromEntries(Object.entries({ q, status: unreadOnly ? 'unread' : '', with: String(row.partner_id) }).filter(([, value]) => value))).toString()}`}
                                    preserveScroll
                                    className={`chat-list-item${row.partner_id === activeId ? ' is-active' : ''}${row.unread > 0 ? ' is-unread' : ''}`}
                                >
                                    <span className="chat-avatar" aria-hidden="true">{String(row.name || '?').slice(0, 1)}</span>
                                    <span className="chat-list-text">
                                        <b>{row.name}</b>
                                        <small>{[row.dealer_code, label(String(row.role), translate), row.place].filter(Boolean).join(' · ')}</small>
                                        <span className="chat-preview">{row.last_from_office ? `${translate('portal', 'chat_you')}: ` : ''}{row.preview}</span>
                                    </span>
                                    <span className="chat-list-meta">
                                        <small>{row.last_message_at ? dateTime(row.last_message_at) : ''}</small>
                                        {row.unread > 0 && <span className="chat-unread" aria-label={translate('portal', 'chat_unread_count', { count: String(row.unread) })}>{row.unread}</span>}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
                <Pagination page={inbox} />
            </aside>
            <div className="chat-main">
                {thread ? (
                    <>
                        <header className="chat-head">
                            <Link href={`${base}/partner-messages`} className="chat-back" aria-label={translate('portal', 'chat_back')}>←</Link>
                            <span className="chat-avatar" aria-hidden="true">{String(thread.partner?.name || '?').slice(0, 1)}</span>
                            <div>
                                <h2>{thread.partner?.name}</h2>
                                <p>{[thread.partner?.dealer_code, label(String(thread.partner?.role), translate), thread.partner?.place, thread.partner?.phone].filter(Boolean).join(' · ')}</p>
                            </div>
                        </header>
                        <Thread messages={thread.messages ?? []} mine="office" officeName={translate('portal', 'chat_office_name')} />
                        <Composer action={`/commands/partner-messages/${thread.partner?.id}`} />
                    </>
                ) : (
                    <p className="chat-empty chat-pick">{translate('portal', 'chat_pick_partner')}</p>
                )}
            </div>
        </section>
    );
}
