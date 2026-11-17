import React, { useEffect, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { FormErrors } from './UI';
import { useT } from '../shared/i18n/t';

type Translate = ReturnType<typeof useT>;

export type LetterheadSettingsData = {
    values: Record<string, string>;
    defaults: Record<string, string>;
    logo_url: string | null;
    has_own_logo: boolean;
    company_logo_url: string | null;
};

const LOGO_MAX_BYTES = 2 * 1024 * 1024;

/** One block of fields for a language, in the order they print on the paper. */
const HEADER_FIELDS = ['doctor_name', 'degrees', 'specialities', 'registration', 'chamber'] as const;
const FOOTER_FIELDS = ['footer_address', 'footer_phone', 'visiting_hours', 'footer_note'] as const;
const LONG_FIELDS = new Set(['chamber', 'footer_address', 'footer_note']);

/** The prescriber's own paper: header, logo and footer, with a preview that follows the typing. */
export function LetterheadSettings({ settings, translate }: { settings: LetterheadSettingsData; translate: Translate }) {
    const form = useForm<Record<string, string | File | null | boolean>>({ ...settings.values, logo: null, remove_logo: false });
    const [logoError, setLogoError] = useState<string | null>(null);
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);
    const chosen = form.data.logo instanceof File ? form.data.logo : null;

    useEffect(() => {
        if (chosen === null) {
            setPreviewUrl(null);
            return undefined;
        }
        const url = URL.createObjectURL(chosen);
        setPreviewUrl(url);
        return () => URL.revokeObjectURL(url);
    }, [chosen]);

    const text = (key: string): string => String(form.data[key] ?? '');
    const shown = (key: string): string => text(key).trim() !== '' ? text(key) : (settings.defaults[key] ?? '');
    const placeholder = (key: string): string => settings.defaults[key] ?? '';
    const logoSrc = previewUrl ?? (form.data.remove_logo ? settings.company_logo_url : settings.logo_url);

    const pickLogo = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0] ?? null;
        setLogoError(null);
        if (file !== null && file.size > LOGO_MAX_BYTES) {
            setLogoError(translate('prescription_letterhead', 'logo_intro'));
            event.target.value = '';
            return;
        }
        form.setData((data) => ({ ...data, logo: file, remove_logo: false }));
    };

    const submit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.post('/commands/prescriptions/letterhead', { forceFormData: true, preserveScroll: true, onSuccess: () => form.setData((data) => ({ ...data, logo: null, remove_logo: false })) });
    };

    const lines = (value: string) => value.split('\n').filter((line) => line.trim() !== '');
    const block = (lang: 'bn' | 'en') => (
        <div className="lh-block" lang={lang}>
            {shown(`doctor_name_${lang}`) !== '' && <b className="lh-name">{shown(`doctor_name_${lang}`)}</b>}
            {(['degrees', 'specialities', 'chamber'] as const).map((field) => lines(shown(`${field}_${lang}`)).map((line, index) => <span key={`${field}${index}`}>{line}</span>))}
            {shown(`registration_${lang}`) !== '' && <b>{shown(`registration_${lang}`)}</b>}
        </div>
    );
    const footerSide = (lang: 'bn' | 'en') => (
        <div lang={lang}>
            {(['footer_address', 'footer_phone', 'visiting_hours'] as const).map((field) => lines(shown(`${field}_${lang}`)).map((line, index) => <span key={`${field}${index}`}>{line}</span>))}
        </div>
    );

    const section = (lang: 'bn' | 'en') => (
        <fieldset className="lh-fieldset">
            <legend>{translate('prescription_letterhead', lang === 'bn' ? 'section_bn' : 'section_en')}</legend>
            {HEADER_FIELDS.map((field) => (
                <label key={`${field}_${lang}`}>{translate('prescription_letterhead', `field_${field}`)}
                    {LONG_FIELDS.has(field)
                        ? <textarea rows={2} maxLength={500} value={text(`${field}_${lang}`)} placeholder={placeholder(`${field}_${lang}`)} onChange={(e) => form.setData(`${field}_${lang}`, e.target.value)} />
                        : <input maxLength={field === 'doctor_name' ? 120 : field === 'registration' ? 160 : 255} value={text(`${field}_${lang}`)} placeholder={placeholder(`${field}_${lang}`)} onChange={(e) => form.setData(`${field}_${lang}`, e.target.value)} />}
                </label>
            ))}
            <p className="muted lh-hint">{translate('prescription_letterhead', 'field_chamber_hint')}</p>
        </fieldset>
    );

    return (
        <section className="panel lh-panel">
            <div className="panel-heading"><div><h2>{translate('prescription_letterhead', 'tab_title')}</h2><p className="muted">{translate('prescription_letterhead', 'intro')}</p></div></div>
            <div className="lh-layout">
                <form className="action-form lh-form" onSubmit={submit}>
                    <div className="lh-two">{section('bn')}{section('en')}</div>
                    <fieldset className="lh-fieldset">
                        <legend>{translate('prescription_letterhead', 'section_footer')}</legend>
                        <div className="lh-two">
                            {(['bn', 'en'] as const).map((lang) => (
                                <div key={lang} className="lh-footer-col">
                                    <span className="muted">{lang === 'bn' ? 'বাংলা' : 'English'}</span>
                                    {FOOTER_FIELDS.map((field) => (
                                        <label key={`${field}_${lang}`}>{translate('prescription_letterhead', `field_${field}`)}
                                            {LONG_FIELDS.has(field)
                                                ? <textarea rows={2} maxLength={500} value={text(`${field}_${lang}`)} placeholder={placeholder(`${field}_${lang}`)} onChange={(e) => form.setData(`${field}_${lang}`, e.target.value)} />
                                                : <input maxLength={255} value={text(`${field}_${lang}`)} placeholder={placeholder(`${field}_${lang}`)} onChange={(e) => form.setData(`${field}_${lang}`, e.target.value)} />}
                                        </label>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </fieldset>
                    <fieldset className="lh-fieldset">
                        <legend>{translate('prescription_letterhead', 'logo_title')}</legend>
                        <p className="muted">{translate('prescription_letterhead', 'logo_intro')}</p>
                        <p>{translate('prescription_letterhead', settings.has_own_logo && !form.data.remove_logo ? 'logo_own' : 'logo_company')}</p>
                        <input type="file" accept="image/png,image/jpeg,image/webp" aria-label={translate('prescription_letterhead', 'logo_choose')} onChange={pickLogo} />
                        {settings.has_own_logo && (
                            <label className="check-row">
                                <input type="checkbox" checked={Boolean(form.data.remove_logo)} onChange={(e) => form.setData((data) => ({ ...data, remove_logo: e.target.checked, logo: null }))} />
                                {translate('prescription_letterhead', 'logo_remove')}
                            </label>
                        )}
                        {logoError && <span className="field-error" role="alert">{logoError}</span>}
                    </fieldset>
                    <FormErrors errors={form.errors} />
                    <button className="button dark" disabled={form.processing}>{translate('prescription_letterhead', form.processing ? 'saving' : 'save')}</button>
                </form>
                <aside className="lh-preview-wrap" aria-label={translate('prescription_letterhead', 'preview_title')}>
                    <h3>{translate('prescription_letterhead', 'preview_title')}</h3>
                    <div className="lh-paper">
                        <div className="lh-head">
                            {block('bn')}
                            <div className="lh-logo">{logoSrc && <img src={logoSrc} alt="" />}</div>
                            {block('en')}
                        </div>
                        <div className="lh-bar"><span>{translate('prescription_letterhead', 'preview_patient')}</span><span>{translate('prescription_letterhead', 'print_id')}</span><span>{translate('prescription', 'print_date')}</span></div>
                        <div className="lh-body">
                            <div className="lh-left"><b>{translate('prescription_letterhead', 'complaint')}</b><b>{translate('prescription', 'rx_diagnosis')}</b><b>{translate('prescription_letterhead', 'investigation')}</b></div>
                            <div className="lh-right"><b>Rx</b><span>{translate('prescription_letterhead', 'preview_medicine')}</span><small>{translate('prescription_letterhead', 'preview_dose')}</small></div>
                        </div>
                        <div className="lh-foot">
                            <div className="lh-foot-cols">{footerSide('bn')}{footerSide('en')}</div>
                            {(shown('footer_note_bn') !== '' || shown('footer_note_en') !== '') && <b className="lh-note">{shown('footer_note_bn')}<br />{shown('footer_note_en')}</b>}
                        </div>
                    </div>
                    <p className="muted">{translate('prescription_letterhead', 'preview_hint')}</p>
                </aside>
            </div>
        </section>
    );
}
