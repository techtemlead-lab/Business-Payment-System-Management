import React, { useState } from 'react';
import { router, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, Clock, MapPin, MessageSquareText, Pencil, Plus, Send, Trash2, Video, XCircle } from 'lucide-react';
import { Badge, DateInput, Empty, FormErrors, Modal, Table } from './UI';
import OfficePage from './office/OfficePage';
import { useT } from '../shared/i18n/t';
import { isBanglaDocument } from '../business-date';
import { banglaSmsSegments, hasBanglaLetters } from '../shared/smsRules';

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
    /** Bangla texts for the SMS box; always Bangla, whatever language the page is in. */
    sms_compose: { template: string; online_place: string; rules_note: string; error: string; counter: string; counter_hint: string };
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

/** The event SMS as it will roughly read, so the office can see its length before sending. */
function eventSmsPreview(compose: StaffData['sms_compose'], form: FormShape): string {
    const [y, m, d] = form.date.split('-').map(Number);
    const date = y && m && d
        ? new Intl.DateTimeFormat('bn-BD', { day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(y, m - 1, d)))
        : '';
    const [hour, minute] = form.start_time.split(':').map(Number);
    const time = Number.isNaN(hour) || Number.isNaN(minute)
        ? ''
        : `${hour % 12 || 12}:${String(minute).padStart(2, '0')} ${hour < 12 ? 'AM' : 'PM'}`;
    const place = form.place.trim() || (form.online_url.trim() ? compose.online_place : '');
    return compose.template
        .replace(':title', () => form.title_bn.trim())
        .replace(':date', () => date)
        .replace(':time', () => time)
        .replace(':place', () => place);
}

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
    const smsTitleNotBangla = form.data.send_sms && form.data.title_bn.trim() !== '' && !hasBanglaLetters(form.data.title_bn);
    const smsPreview = form.data.send_sms ? eventSmsPreview(data.sms_compose, form.data) : '';
    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        if (smsTitleNotBangla) {
            return;
        }
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
            <FormErrors errors={form.errors} besideFields={['title_bn', 'title_en', 'date', 'start_time', 'end_time', 'place', 'online_url', 'divisions', 'notes']} />
            <label>{translate('portal', 'events_field_title_bn')}
                <input type="text" lang="bn" required maxLength={200} value={form.data.title_bn} onChange={(e) => form.setData('title_bn', e.target.value)} />{error('title_bn')}
                {smsTitleNotBangla && !form.errors.title_bn && <span className="field-error" role="alert" lang="bn">{data.sms_compose.error}</span>}
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
            {form.data.send_sms && (
                <div className="notice" lang="bn">
                    <p>{data.sms_compose.rules_note}</p>
                    <p aria-live="polite"><strong>{data.sms_compose.counter.replace(':chars', String(smsPreview.length)).replace(':segments', String(banglaSmsSegments(smsPreview)))}</strong></p>
                    <small className="field-hint">{data.sms_compose.counter_hint}</small>
                </div>
            )}
            {!event && (
                <label className="events-check">
                    <input type="checkbox" checked={form.data.publish} onChange={(e) => form.setData('publish', e.target.checked)} />
                    <span>{translate('portal', 'events_field_publish_now')}</span>
                </label>
            )}
            <button type="submit" className="button dark" disabled={form.processing || smsTitleNotBangla}>
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
    const hub = usePage<any>().props.data?.hub ?? null;
    const [editing, setEditing] = useState<PartnerEventRow | 'new' | null>(null);
    const [cancelling, setCancelling] = useState<PartnerEventRow | null>(null);
    const [openedId, setOpenedId] = useState<number | null>(null);
    const opened = data.events.find((event) => event.id === openedId) ?? null;

    const publish = (event: PartnerEventRow) => {
        if (window.confirm(translate('portal', 'events_publish_confirm', { title: titleOf(event) }))) {
            router.post(`/commands/events/${event.id}/publish`, {}, { preserveScroll: true });
        }
    };
    const remove = (event: PartnerEventRow) => {
        if (window.confirm(translate('portal', 'events_delete_confirm', { title: titleOf(event) }))) {
            router.delete(`/commands/events/${event.id}`, { preserveScroll: true, onSuccess: () => setOpenedId(null) });
        }
    };
    const audience = (event: PartnerEventRow) => (event.audience === 'divisions'
        ? translate('portal', 'events_divisions_label', { list: (event.divisions ?? []).join(', ') })
        : translate('portal', 'events_audience_all'));

    return (
        <OfficePage
            title={translate('office_website', 'partners_title')}
            hub={hub}
            action={data.can_create ? <button type="button" className="button dark" onClick={() => setEditing('new')}><Plus size={17} aria-hidden="true" />{translate('portal', 'events_add')}</button> : undefined}
        >
            <div className="events-hub" data-tour="events-editor">
                <Table
                    rows={data.events}
                    onRowClick={(row: PartnerEventRow) => setOpenedId(row.id)}
                    empty={translate('portal', 'events_empty_title')}
                    emptyBody=""
                    defaultSortKey="date"
                    defaultSortDir="desc"
                    columns={[
                        { key: 'date', title: translate('portal', 'events_field_date'), sortable: true, render: (row: PartnerEventRow) => <>{shortDate(row.date)}<small className="cell-sub">{clock(row.start_time)} – {clock(row.end_time)}</small></> },
                        { key: 'title', title: translate('office_website', 'col_event'), render: (row: PartnerEventRow) => <><button type="button" className="text-link" onClick={() => setOpenedId(row.id)}>{titleOf(row)}</button>{row.place && <small className="cell-sub">{row.place}</small>}</> },
                        { key: 'audience', title: translate('portal', 'events_field_audience'), render: (row: PartnerEventRow) => audience(row) },
                        { key: 'send_sms', title: translate('office_website', 'col_sms'), render: (row: PartnerEventRow) => (row.send_sms ? translate('portal', 'events_sms_on') : '—') },
                        { key: 'status', title: translate('portal', 'enquiry_status'), render: (row: PartnerEventRow) => <Badge status={row.status} /> },
                    ]}
                />
            </div>
            {opened && (
                <Modal variant="drawer" title={titleOf(opened)} onClose={() => setOpenedId(null)} footer={data.can_edit && opened.status !== 'cancelled' ? <>
                            {opened.status === 'draft' && (
                                <button type="button" className="button small dark" onClick={() => publish(opened)}>
                                    <Send size={15} aria-hidden="true" />{translate('portal', 'events_publish')}
                                </button>
                            )}
                            <button type="button" className="button small" onClick={() => { setOpenedId(null); setEditing(opened); }}>
                                <Pencil size={15} aria-hidden="true" />{translate('office_website', 'edit')}
                            </button>
                            <button type="button" className="button small" onClick={() => { setOpenedId(null); setCancelling(opened); }}>
                                <XCircle size={15} aria-hidden="true" />{translate('portal', 'events_cancel')}
                            </button>
                            {opened.status === 'draft' && (
                                <button type="button" className="button small danger" onClick={() => remove(opened)}>
                                    <Trash2 size={15} aria-hidden="true" />{translate('portal', 'events_delete')}
                                </button>
                            )}
                    </> : undefined}>
                    <EventCard event={opened} translate={translate} showDate>
                        <div className="training-meta">
                            {opened.status === 'published' && <span className="badge approved">{translate('portal', 'events_status_published')}</span>}
                            <span className="training-chip">{audience(opened)}</span>
                            {opened.send_sms && <span className="training-chip"><MessageSquareText size={13} aria-hidden="true" /> {translate('portal', 'events_sms_on')}</span>}
                        </div>
                    </EventCard>
                </Modal>
            )}
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
        </OfficePage>
    );
}

/** Meetings and training calendar: editor for office staff, month list for partners. */
export default function EventsCalendarHub({ data }: { data?: EventsCalendarData | null }) {
    if (!data) {
        return null;
    }
    return data.mode === 'staff' ? <StaffCalendar data={data} /> : <PartnerCalendar data={data} />;
}
