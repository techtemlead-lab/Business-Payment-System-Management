import { useMemo, useRef, useState } from 'react';
import { router, useForm } from '@inertiajs/react';
import OfficePage from '../OfficePage';
import type { OfficeHub } from '../../OfficeHubTabs';
import { FormErrors } from '../../UI';
import { useT } from '../../../shared/i18n/t';

type Locale = 'bn' | 'en';
type Channel = 'sms' | 'email';
type Placeholder = { name: string; label: string; sample_bn: string; sample_en: string };
type Version = {
    default_subject: string | null;
    default_body: string;
    subject: string | null;
    body: string;
    customised: boolean;
    last_changed: { by: string; at: string | null } | null;
};
type Item = {
    key: string;
    channel: Channel;
    group: string;
    recipient: string;
    title: string;
    when: string;
    required: string[];
    placeholders: Placeholder[];
    locales: Partial<Record<Locale, Version>>;
};
type Data = { items: Item[]; groups: { key: string; label: string }[] };

/**
 * How many SMS parts a text takes: Bangla (Unicode) holds 70 characters in one part and 67 in each
 * part of a longer text; plain English letters hold 160 and 153. It mirrors App\Domain\Notifications\SmsLength.
 */
export function smsLength(text: string): { characters: number; parts: number } {
    const unicode = !/^[\x20-\x7E\n\r]*$/.test(text);
    const characters = text.length;
    const single = unicode ? 70 : 160;
    const perPart = unicode ? 67 : 153;
    return { characters, parts: characters === 0 ? 0 : characters <= single ? 1 : Math.ceil(characters / perPart) };
}

/** Fills {marks} with sample values, so the owner sees roughly what people will read. */
export function fillSamples(text: string, placeholders: Placeholder[], locale: Locale): string {
    return text.replace(/\{([a-z][a-z0-9_]*)\}/g, (whole, name: string) => {
        const found = placeholders.find((placeholder) => placeholder.name === name);
        return found ? (locale === 'en' ? found.sample_en : found.sample_bn) : whole;
    });
}

/**
 * The owner's editor for every SMS and email text: a list grouped by event, and for the one that is
 * open, the words, the marks to insert, a live preview, and Save or Go back to the original.
 */
export default function MessageTemplates({ data, hub }: { data: Data; hub?: OfficeHub | null }) {
    const translate = useT();
    const [channel, setChannel] = useState<Channel>('sms');
    const [openKey, setOpenKey] = useState<string | null>(null);
    const visible = data.items.filter((item) => item.channel === channel);

    return (
        <OfficePage title={translate('message_templates', 'title')} subtitle={translate('message_templates', 'subtitle')} hub={hub}>
            <div className="mt-page">
                <div className="mt-tabs" role="tablist" aria-label={translate('message_templates', 'title')}>
                    {(['sms', 'email'] as Channel[]).map((value) => (
                        <button
                            key={value}
                            type="button"
                            role="tab"
                            id={`mt-tab-${value}`}
                            aria-selected={channel === value}
                            aria-controls="mt-panel"
                            data-active={channel === value || undefined}
                            onClick={() => { setChannel(value); setOpenKey(null); }}
                        >
                            {translate('message_templates', `tab_${value}`)}
                        </button>
                    ))}
                </div>
                {channel === 'sms' && <p className="mt-note">{translate('message_templates', 'sms_bangla_note')}</p>}

                <div id="mt-panel" role="tabpanel" aria-labelledby={`mt-tab-${channel}`}>
                    {data.groups.map((group) => {
                        const items = visible.filter((item) => item.group === group.key);
                        if (items.length === 0) return null;
                        return (
                            <section className="mt-group" key={group.key} data-group={group.key}>
                                <h2>{group.label}</h2>
                                <ul className="mt-list">
                                    {items.map((item) => (
                                        <li key={item.key} className="mt-row" data-open={openKey === item.key || undefined}>
                                            <div className="mt-row-head">
                                                <div className="mt-row-text">
                                                    <b>{item.title}</b>
                                                    <small>{item.when}</small>
                                                    <small className="mt-to">{translate('message_templates', 'sent_to')}: {translate('message_templates', `to_${item.recipient}`)}</small>
                                                </div>
                                                <span className="mt-badge" data-changed={Object.values(item.locales).some((version) => version?.customised) || undefined}>
                                                    {Object.values(item.locales).some((version) => version?.customised) ? translate('message_templates', 'badge_customised') : translate('message_templates', 'badge_default')}
                                                </span>
                                                <button
                                                    type="button"
                                                    className="button"
                                                    aria-expanded={openKey === item.key}
                                                    onClick={() => setOpenKey(openKey === item.key ? null : item.key)}
                                                >
                                                    {openKey === item.key ? translate('message_templates', 'close') : translate('message_templates', 'open_edit')}
                                                </button>
                                            </div>
                                            {openKey === item.key && <Editor item={item} />}
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        );
                    })}
                    {visible.length === 0 && <p className="mt-note">{translate('message_templates', 'no_items')}</p>}
                </div>
            </div>
        </OfficePage>
    );
}

function Editor({ item }: { item: Item }) {
    const locales = Object.keys(item.locales) as Locale[];
    const [locale, setLocale] = useState<Locale>(locales[0] ?? 'bn');
    const translate = useT();

    return (
        <div className="mt-editor">
            {locales.length > 1 && (
                <div className="mt-langs" role="group" aria-label={translate('message_templates', 'language_label')}>
                    {locales.map((value) => (
                        <button key={value} type="button" className="mt-lang" aria-pressed={locale === value} onClick={() => setLocale(value)}>
                            {translate('message_templates', `locale_${value}`)}
                        </button>
                    ))}
                </div>
            )}
            {/* One form per language, restarted when the language changes or a save brings a new text back. */}
            <LocaleForm key={`${item.key}|${locale}|${item.locales[locale]?.body}|${item.locales[locale]?.subject}`} item={item} locale={locale} />
        </div>
    );
}

function LocaleForm({ item, locale }: { item: Item; locale: Locale }) {
    const translate = useT();
    const version = item.locales[locale] as Version;
    const isSms = item.channel === 'sms';
    const form = useForm({ key: item.key, channel: item.channel, locale, subject: version.subject ?? '', body: version.body });
    const bodyRef = useRef<HTMLTextAreaElement>(null);
    const bodyId = `mt-body-${item.key}-${locale}`;
    const subjectId = `mt-subject-${item.key}-${locale}`;

    const missing = useMemo(
        () => item.required.filter((name) => !form.data.body.includes(`{${name}}`)),
        [item.required, form.data.body],
    );
    const preview = fillSamples(form.data.body, item.placeholders, locale);
    const previewSubject = fillSamples(form.data.subject, item.placeholders, locale);
    const length = smsLength(preview);
    const changed = form.data.body !== version.body || form.data.subject !== (version.subject ?? '');
    const hasNotice = item.placeholders.some((placeholder) => placeholder.name === 'notice_text');

    const insert = (name: string) => {
        const area = bodyRef.current;
        const mark = `{${name}}`;
        const start = area?.selectionStart ?? form.data.body.length;
        const end = area?.selectionEnd ?? start;
        form.setData('body', form.data.body.slice(0, start) + mark + form.data.body.slice(end));
        requestAnimationFrame(() => {
            area?.focus();
            area?.setSelectionRange(start + mark.length, start + mark.length);
        });
    };

    return (
        <form
            className="mt-form"
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/commands/message-templates', { preserveScroll: true });
            }}
        >
            {!isSms && (
                <label htmlFor={subjectId}>
                    {translate('message_templates', 'field_subject')}
                    <input id={subjectId} type="text" lang={locale} maxLength={150} value={form.data.subject} onChange={(e) => form.setData('subject', e.target.value)} />
                </label>
            )}
            <label htmlFor={bodyId}>
                {translate('message_templates', 'field_body')}
                <textarea id={bodyId} ref={bodyRef} lang={locale} rows={isSms ? 5 : 8} value={form.data.body} onChange={(e) => form.setData('body', e.target.value)} />
            </label>

            <div className="mt-marks" aria-label={translate('message_templates', 'placeholders_title')}>
                <b>{translate('message_templates', 'placeholders_title')}</b>
                <p>{translate('message_templates', 'placeholders_hint')}</p>
                <div className="mt-chips">
                    {item.placeholders.map((placeholder) => (
                        <button key={placeholder.name} type="button" className="mt-chip" onClick={() => insert(placeholder.name)} data-required={item.required.includes(placeholder.name) || undefined}>
                            <code>{`{${placeholder.name}}`}</code> <span>{placeholder.label}</span>
                            {item.required.includes(placeholder.name) && <em>{translate('message_templates', 'must_keep')}</em>}
                        </button>
                    ))}
                </div>
                {!isSms && hasNotice && <p>{translate('message_templates', 'notice_hint')}</p>}
                {missing.length > 0 && (
                    <p className="mt-warn" role="alert">
                        {missing.map((name) => translate('message_templates', 'err_missing_placeholder', { name: `{${name}}` })).join(' ')}
                    </p>
                )}
            </div>

            <section className="mt-preview" aria-label={translate('message_templates', 'preview_title')}>
                <b>{translate('message_templates', 'preview_title')}</b>
                <p className="mt-hint">{translate('message_templates', 'preview_hint')}</p>
                {!isSms && <p className="mt-preview-subject" lang={locale}>{previewSubject}</p>}
                <p className="mt-preview-body" lang={locale}>{preview}</p>
                {isSms && (
                    <p className="mt-count" role="status">
                        {translate('message_templates', 'length_line', { chars: length.characters, parts: length.parts })}
                        <small>{translate('message_templates', 'length_note')}</small>
                    </p>
                )}
            </section>

            <FormErrors errors={form.errors} />
            <p className="mt-last">
                {version.last_changed
                    ? translate('message_templates', 'last_changed', { name: version.last_changed.by, when: formatWhen(version.last_changed.at) })
                    : translate('message_templates', 'never_changed')}
            </p>
            <div className="mt-actions">
                <button type="submit" className="button dark" disabled={form.processing || !changed || missing.length > 0}>
                    {form.processing ? translate('message_templates', 'saving') : translate('message_templates', 'save')}
                </button>
                {version.customised && (
                    <button
                        type="button"
                        className="button"
                        disabled={form.processing}
                        onClick={() => {
                            if (window.confirm(translate('message_templates', 'reset_confirm'))) {
                                router.post('/commands/message-templates/reset', { key: item.key, channel: item.channel, locale }, { preserveScroll: true });
                            }
                        }}
                    >
                        {translate('message_templates', 'reset')}
                    </button>
                )}
            </div>
        </form>
    );
}

function formatWhen(iso: string | null): string {
    if (!iso) return '';
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? '' : date.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
