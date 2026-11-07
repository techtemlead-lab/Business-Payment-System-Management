import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, MapPin, MessageSquareText, Pencil, Plus, Send, Trash2, Video, XCircle } from 'lucide-react';
import { DateInput, Empty, FormErrors, Modal } from './UI';
import { useT } from '../shared/i18n/t';
import { isBanglaDocument } from '../business-date';

export type PartnerEventRow = {
    id: number;
    title_bn: string;
    title_en: string;
    /** Dhaka date, "YYYY-MM-DD". */
    date: string;
    /** Dhaka clock times, "HH:mm". */
    start_time: string;
    end_time: string;
    starts_at: string;
    ends_at: string;
    place?: string | null;
    online_url?: string | null;
    notes?: string | null;
    status: 'draft' | 'published' | 'cancelled';
    is_ongoing: boolean;
    is_past: boolean;
    audience?: 'all' | 'divisions';
    divisions?: string[];
    /** Division place code ids the event is for (names above are only for reading). */
    division_ids?: number[];
    send_sms?: boolean;
    published_at?: string | null;
    cancelled_at?: string | null;
    cancel_reason?: string | null;
};

type PartnerData = {
    mode: 'partner';
    today_date: string;
    timezone: string;
    today: PartnerEventRow[];
    this_week: PartnerEventRow[];
    months: { month: string; days: { date: string; events: PartnerEventRow[] }[] }[];
    past: PartnerEventRow[];
};

type StaffData = {
    mode: 'staff';
    can_edit: boolean;
    can_create: boolean;
    events: PartnerEventRow[];
    /** Coded divisions to pick from: the value is the place code id. */
    divisions: { id: number; name: string }[];
    today_date: string;
    timezone: string;
};

export type EventsCalendarData = PartnerData | StaffData;

type Translate = ReturnType<typeof useT>;

const intlLocale = () => (isBanglaDocument() ? 'bn-BD' : 'en-GB');

/** "2026-11-10" → "Tuesday, 10 November" (dates are already Dhaka dates, so format in UTC). */
function dayLabel(iso: string): string {
    const [y, m, d] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(intlLocale(), { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' })
        .format(new Date(Date.UTC(y, m - 1, d)));
}

/** "2026-11" → "November 2026". */
function monthLabel(month: string): string {
    const [y, m] = month.split('-').map(Number);
    return new Intl.DateTimeFormat(intlLocale(), { month: 'long', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(y, m - 1, 1)));
}

/** "2026-11-10" → "10 Nov 2026". */
function shortDate(iso: string): string {
    const [y, m, d] = iso.split('-').map(Number);
    return new Intl.DateTimeFormat(intlLocale(), { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' })
        .format(new Date(Date.UTC(y, m - 1, d)));
}

/** "14:30" → "2:30 pm" (Dhaka wall-clock time from the server). */
function clock(time: string): string {
    const [h, m] = time.split(':').map(Number);
    return new Intl.DateTimeFormat(intlLocale(), { hour: 'numeric', minute: '2-digit', hour12: true, timeZone: 'UTC' })
        .format(new Date(Date.UTC(2000, 0, 1, h, m)));
}

function useEventTitle(): (event: PartnerEventRow) => string {
    const { locale } = usePage<any>().props;
    return (event) => (locale === 'bn' ? event.title_bn : event.title_en) || event.title_en;
}

function EventCard({ event, translate, showDate = false, children }: { event: PartnerEventRow; translate: Translate; showDate?: boolean; children?: React.ReactNode }) {
    const titleOf = useEventTitle();
    const cancelled = event.status === 'cancelled';
    return (
        <article className={`event-card${cancelled ? ' is-cancelled' : ''}${event.is_ongoing ? ' is-ongoing' : ''}`}>
            <div className="event-time">
                <Clock size={15} aria-hidden="true" />
                <span>
                    {showDate && <>{shortDate(event.date)} · </>}
                    {clock(event.start_time)} – {clock(event.end_time)}
                </span>
                {event.is_ongoing && <span className="event-badge is-live">{translate('portal', 'events_ongoing')}</span>}
                {cancelled && <span className="event-badge is-cancelled">{translate('portal', 'events_status_cancelled')}</span>}
                {event.status === 'draft' && <span className="event-badge is-draft">{translate('portal', 'events_status_draft')}</span>}
            </div>
            <h4>{titleOf(event)}</h4>
            {event.place && (
                <p className="event-line"><MapPin size={15} aria-hidden="true" /><span>{event.place}</span></p>
            )}
            {event.online_url && !cancelled && (
                <p className="event-line">
                    <Video size={15} aria-hidden="true" />
                    <a className="text-link" href={event.online_url} target="_blank" rel="noopener noreferrer">{translate('portal', 'events_join_online')}</a>
                </p>
            )}
            {event.notes && <p className="event-notes">{event.notes}</p>}
            {cancelled && event.cancel_reason && <p className="event-notes">{translate('portal', 'events_cancel_reason_label', { reason: event.cancel_reason })}</p>}
            {children}
        </article>
    );
}

function PartnerCalendar({ data }: { data: PartnerData }) {
    const translate = useT();
    const hasUpcoming = data.months.length > 0;
    const highlight = data.today.length > 0 || data.this_week.length > 0;
    return (
        <div className="events-hub" data-tour="events-calendar">
            <section className="panel training-intro">
                <h2>{translate('portal', 'events')}</h2>
                <p className="muted">{translate('portal', 'events_partner_intro')}</p>
            </section>

            {highlight && (
                <section className="events-highlight" aria-label={translate('portal', 'events_this_week')}>
                    {data.today.length > 0 && (
                        <div>
                            <h3>{translate('portal', 'events_today')}</h3>
                            <div className="events-list">{data.today.map((event) => <EventCard key={event.id} event={event} translate={translate} />)}</div>
                        </div>
                    )}
                    {data.this_week.length > 0 && (
                        <div>
                            <h3>{translate('portal', 'events_this_week')}</h3>
                            <div className="events-list">{data.this_week.map((event) => <EventCard key={event.id} event={event} translate={translate} showDate />)}</div>
                        </div>
                    )}
                </section>
            )}

            {!hasUpcoming && (
                <section className="panel"><Empty title={translate('portal', 'events_empty_title')} body={translate('portal', 'events_empty_body')} /></section>
            )}

            {hasUpcoming && (
                <section className="events-upcoming" aria-labelledby="events-upcoming-title">
                    <h2 id="events-upcoming-title" className="sr-only">{translate('portal', 'events_upcoming')}</h2>
                    {data.months.map((month) => (
                        <div key={month.month} className="events-month">
                            <h3 className="events-month-title">{monthLabel(month.month)}</h3>
                            {month.days.map((day) => (
                                <div key={day.date} className={`events-day${day.date === data.today_date ? ' is-today' : ''}`}>
                                    <p className="events-day-title">
                                        <CalendarDays size={15} aria-hidden="true" />{dayLabel(day.date)}
                                        {day.date === data.today_date && <span className="event-badge is-today">{translate('portal', 'events_today')}</span>}
                                    </p>
                                    <div className="events-list">{day.events.map((event) => <EventCard key={event.id} event={event} translate={translate} />)}</div>
                                </div>
                            ))}
                        </div>
                    ))}
                </section>
            )}

            {data.past.length > 0 && (
                <details className="panel events-past">
                    <summary>{translate('portal', 'events_past', { count: String(data.past.length) })}</summary>
                    <div className="events-list">{data.past.map((event) => <EventCard key={event.id} event={event} translate={translate} showDate />)}</div>
                </details>
            )}
        </div>
    );
}

type FormShape = {
    title_bn: string;
    title_en: string;
    date: string;
    start_time: string;
    end_time: string;
    place: string;
    online_url: string;
    audience: 'all' | 'divisions';
    divisions: number[];
    notes: string;
    send_sms: boolean;
    publish: boolean;
};

function EventForm({ data, event, onDone }: { data: StaffData; event: PartnerEventRow | null; onDone: () => void }) {
    const translate = useT();
    const form = useForm<FormShape>({
        title_bn: event?.title_bn ?? '',
        title_en: event?.title_en ?? '',
        date: event?.date ?? data.today_date,
        start_time: event?.start_time ?? '10:00',
        end_time: event?.end_time ?? '12:00',
        place: event?.place ?? '',
        online_url: event?.online_url ?? '',
        audience: event?.audience ?? 'all',
        divisions: event?.division_ids ?? [],
        notes: event?.notes ?? '',
        send_sms: event?.send_sms ?? false,
        publish: false,
    });
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => onDone() };
        if (event) {
            form.patch(`/commands/events/${event.id}`, options);
        } else {
            form.post('/commands/events', options);
        }
    };
    const toggleDivision = (id: number) => {
        form.setData('divisions', form.data.divisions.includes(id)
            ? form.data.divisions.filter((value) => value !== id)
            : [...form.data.divisions, id]);
    };
    const error = (name: string) => {
        const message = (form.errors as Record<string, string | undefined>)[name];
        return message ? <span className="field-error" role="alert">{message}</span> : null;
    };
    return (
        <form className="action-form events-form" onSubmit={submit}>
            <FormErrors errors={form.errors} />
            <label>{translate('portal', 'events_field_title_bn')}
                <input type="text" lang="bn" required maxLength={200} value={form.data.title_bn} onChange={(e) => form.setData('title_bn', e.target.value)} />{error('title_bn')}
            </label>
            <label>{translate('portal', 'events_field_title_en')}
                <input type="text" lang="en" required maxLength={200} value={form.data.title_en} onChange={(e) => form.setData('title_en', e.target.value)} />{error('title_en')}
            </label>
            <div className="training-form-row">
                <label>{translate('portal', 'events_field_date')}
                    <DateInput value={form.data.date} required min={event ? undefined : data.today_date} onChange={(e) => form.setData('date', e.target.value)} />{error('date')}
                </label>
                <label>{translate('portal', 'events_field_start')}
                    <input type="time" required value={form.data.start_time} onChange={(e) => form.setData('start_time', e.target.value)} />{error('start_time')}
                </label>
                <label>{translate('portal', 'events_field_end')}
                    <input type="time" required value={form.data.end_time} onChange={(e) => form.setData('end_time', e.target.value)} />{error('end_time')}
                </label>
            </div>
            <label>{translate('portal', 'events_field_place')}
                <input type="text" maxLength={300} value={form.data.place} onChange={(e) => form.setData('place', e.target.value)} />{error('place')}
            </label>
            <label>{translate('portal', 'events_field_online_url')}
                <input type="url" inputMode="url" pattern="https://.*" maxLength={500} placeholder="https://" value={form.data.online_url} onChange={(e) => form.setData('online_url', e.target.value)} />
                <small className="field-hint">{translate('portal', 'events_field_place_hint')}</small>{error('online_url')}
            </label>
            <label>{translate('portal', 'events_field_audience')}
                <select value={form.data.audience} onChange={(e) => form.setData('audience', e.target.value as FormShape['audience'])}>
                    <option value="all">{translate('portal', 'events_audience_all')}</option>
                    <option value="divisions">{translate('portal', 'events_audience_divisions')}</option>
                </select>
            </label>
            {form.data.audience === 'divisions' && (
                <fieldset className="events-divisions">
                    <legend>{translate('portal', 'events_field_divisions')}</legend>
                    {data.divisions.length === 0 && <p className="muted">{translate('portal', 'events_no_divisions')}</p>}
                    {data.divisions.map((division) => (
                        <label key={division.id} className="events-check">
                            <input type="checkbox" checked={form.data.divisions.includes(division.id)} onChange={() => toggleDivision(division.id)} />{division.name}
                        </label>
                    ))}
                    {error('divisions')}
                </fieldset>
            )}
            <label>{translate('portal', 'events_field_notes')}
                <textarea rows={3} maxLength={5000} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />{error('notes')}
            </label>
            <label className="events-check">
                <input type="checkbox" checked={form.data.send_sms} onChange={(e) => form.setData('send_sms', e.target.checked)} />
                <span>{translate('portal', 'events_field_send_sms')}<small className="field-hint">{translate('portal', 'events_field_send_sms_hint')}</small></span>
            </label>
            {!event && (
                <label className="events-check">
                    <input type="checkbox" checked={form.data.publish} onChange={(e) => form.setData('publish', e.target.checked)} />
                    <span>{translate('portal', 'events_field_publish_now')}</span>
                </label>
            )}
            <button type="submit" className="button dark" disabled={form.processing}>
                {form.processing ? translate('portal', 'saving') : translate('portal', 'events_save')}
            </button>
        </form>
    );
}

function CancelForm({ event, onDone }: { event: PartnerEventRow; onDone: () => void }) {
    const translate = useT();
    const form = useForm({ reason: '' });
    return (
        <form className="action-form" onSubmit={(e) => { e.preventDefault(); form.post(`/commands/events/${event.id}/cancel`, { preserveScroll: true, onSuccess: () => onDone() }); }}>
            <FormErrors errors={form.errors} />
            <label>{translate('portal', 'events_field_cancel_reason')}
                <textarea rows={2} maxLength={300} value={form.data.reason} onChange={(e) => form.setData('reason', e.target.value)} />
            </label>
            <button type="submit" className="button dark" disabled={form.processing}>
                <XCircle size={16} aria-hidden="true" />{translate('portal', 'events_cancel_submit')}
            </button>
        </form>
    );
}

function StaffCalendar({ data }: { data: StaffData }) {
    const translate = useT();
    const titleOf = useEventTitle();
    const [editing, setEditing] = useState<PartnerEventRow | 'new' | null>(null);
    const [cancelling, setCancelling] = useState<PartnerEventRow | null>(null);

    const publish = (event: PartnerEventRow) => {
        if (window.confirm(translate('portal', 'events_publish_confirm', { title: titleOf(event) }))) {
            router.post(`/commands/events/${event.id}/publish`, {}, { preserveScroll: true });
        }
    };
    const remove = (event: PartnerEventRow) => {
        if (window.confirm(translate('portal', 'events_delete_confirm', { title: titleOf(event) }))) {
            router.delete(`/commands/events/${event.id}`, { preserveScroll: true });
        }
    };

    return (
        <div className="events-hub" data-tour="events-editor">
            <section className="panel training-intro">
                <div className="panel-heading">
                    <div>
                        <h2>{translate('portal', 'events')}</h2>
                        <p className="muted">{translate('portal', 'events_staff_intro')}</p>
                    </div>
                    {data.can_create && (
                        <button type="button" className="button dark" onClick={() => setEditing('new')}>
                            <Plus size={17} aria-hidden="true" />{translate('portal', 'events_add')}
                        </button>
                    )}
                </div>
            </section>
            {data.events.length === 0 && (
                <section className="panel"><Empty title={translate('portal', 'events_empty_title')} body={translate('portal', 'events_staff_empty_body')} /></section>
            )}
            <div className="events-list is-grid">
                {data.events.map((event) => (
                    <EventCard key={event.id} event={event} translate={translate} showDate>
                        <div className="training-meta">
                            {event.status === 'published' && <span className="badge approved">{translate('portal', 'events_status_published')}</span>}
                            <span className="training-chip">
                                {event.audience === 'divisions'
                                    ? translate('portal', 'events_divisions_label', { list: (event.divisions ?? []).join(', ') })
                                    : translate('portal', 'events_audience_all')}
                            </span>
                            {event.send_sms && <span className="training-chip"><MessageSquareText size={13} aria-hidden="true" /> {translate('portal', 'events_sms_on')}</span>}
                        </div>
                        {data.can_edit && event.status !== 'cancelled' && (
                            <div className="training-actions">
                                {event.status === 'draft' && (
                                    <button type="button" className="button small dark" onClick={() => publish(event)}>
                                        <Send size={15} aria-hidden="true" />{translate('portal', 'events_publish')}
                                    </button>
                                )}
                                <button type="button" className="button small" onClick={() => setEditing(event)}>
                                    <Pencil size={15} aria-hidden="true" />{translate('portal', 'events_edit')}
                                </button>
                                <button type="button" className="button small" onClick={() => setCancelling(event)}>
                                    <XCircle size={15} aria-hidden="true" />{translate('portal', 'events_cancel')}
                                </button>
                                {event.status === 'draft' && (
                                    <button type="button" className="icon-button training-delete" onClick={() => remove(event)} aria-label={translate('portal', 'events_delete')} title={translate('portal', 'events_delete')}>
                                        <Trash2 size={16} />
                                    </button>
                                )}
                            </div>
                        )}
                    </EventCard>
                ))}
            </div>
            {editing && (
                <Modal title={translate('portal', editing === 'new' ? 'events_modal_add' : 'events_modal_edit')} onClose={() => setEditing(null)}>
                    <EventForm data={data} event={editing === 'new' ? null : editing} onDone={() => setEditing(null)} />
                </Modal>
            )}
            {cancelling && (
                <Modal title={translate('portal', 'events_modal_cancel')} onClose={() => setCancelling(null)}>
                    <p className="muted">{titleOf(cancelling)} · {shortDate(cancelling.date)} · {clock(cancelling.start_time)}</p>
                    <CancelForm event={cancelling} onDone={() => setCancelling(null)} />
                </Modal>
            )}
        </div>
    );
}

/** Meetings and training calendar: editor for office staff, month list for partners. */
export default function EventsCalendarHub({ data }: { data?: EventsCalendarData | null }) {
    if (!data) {
        return null;
    }
    return data.mode === 'staff' ? <StaffCalendar data={data} /> : <PartnerCalendar data={data} />;
}
