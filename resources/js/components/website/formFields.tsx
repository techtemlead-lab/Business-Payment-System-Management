import React, { useState } from 'react';
import { useT } from '../../shared/i18n/t';
import { notify } from '../../shared/notify';
import type { CustomizationData } from './types';

/** Sends a picture to the server and returns its web address. */
function usePhotoUpload(onUploaded: (url: string) => void) {
    const translate = useT();
    const [uploading, setUploading] = useState(false);

    const upload = async (file: File | null) => {
        if (!file) {
            return;
        }
        setUploading(true);
        try {
            const body = new FormData();
            body.append('image', file);
            body.append('purpose', 'site_photo');
            const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
                || (document.querySelector('input[name="_token"]') as HTMLInputElement | null)?.value
                || '';
            if (token) {
                body.append('_token', token);
            }
            const response = await fetch('/commands/customization/category-icon', {
                method: 'POST',
                body,
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
            });
            const payload = await response.json().catch(() => null) as { ok?: boolean; url?: string; message?: string; errors?: { image?: string[] } } | null;
            if (!response.ok || !payload?.ok || !payload.url) {
                throw new Error(payload?.errors?.image?.[0] || payload?.message || translate('portal', 'sce_md_photo_upload_failed'));
            }
            onUploaded(payload.url);
        } catch (error) {
            notify(error instanceof Error ? error.message : translate('portal', 'sce_md_photo_upload_failed'), 'error');
        } finally {
            setUploading(false);
        }
    };

    return { uploading, upload };
}

/** A button that picks a picture from the computer and hands back its web address. */
export function ImageUploadButton({ onUploaded }: { onUploaded: (url: string) => void }) {
    const translate = useT();
    const { uploading, upload } = usePhotoUpload(onUploaded);
    return (
        <label className={`button small light ed-mt-xs${uploading ? ' ed-busy' : ''}`}>
            {uploading ? translate('portal', 'sce_uploading_category_image') : translate('portal', 'sce_md_photo_upload')}
            <input
                type="file"
                accept="image/png,image/jpeg,image/webp"
                disabled={uploading}
                className="ed-hidden"
                onChange={e => {
                    const file = e.target.files?.[0] ?? null;
                    e.target.value = '';
                    void upload(file);
                }}
            />
        </label>
    );
}

/**
 * Photo box: a picture link the owner can type, or a picture uploaded from the
 * computer, with a small preview. The words default to the Managing Director's photo.
 */
export function MdPhotoField({
    value,
    placeholder,
    onChange,
    label,
    clearLabel,
    hint,
    fieldName,
    darkPreview = false,
}: {
    value?: string;
    placeholder?: string;
    onChange: (val: string) => void;
    label?: string;
    clearLabel?: string;
    hint?: string;
    fieldName?: string;
    /** A white logo cannot be seen on a white card, so its preview sits on a dark one. */
    darkPreview?: boolean;
}) {
    const translate = useT();
    const { uploading, upload } = usePhotoUpload(onChange);
    const preview = (value || '').trim() || placeholder || '';

    return (
        <div className="ed-block hub-span-all" data-field={fieldName}>
            <span className="ed-label">{label ?? translate('portal', 'sce_fld_md_message_image')}</span>
            <div className="ed-row is-top is-loose">
                {preview ? (
                    <img src={preview} alt="" width={72} height={90} className={`ed-md-photo-preview${darkPreview ? ' ws-dark-preview' : ''}`} />
                ) : null}
                <div className="ed-grow">
                    <input
                        type="text"
                        value={value || ''}
                        placeholder={placeholder}
                        onChange={e => onChange(e.target.value)}
                        className="ed-input"
                    />
                    <div className="ed-row is-wrap ed-mt-sm">
                        <label className={`button small light${uploading ? ' ed-busy' : ''}`}>
                            {uploading ? translate('portal', 'sce_uploading_category_image') : translate('portal', 'sce_md_photo_upload')}
                            <input
                                type="file"
                                accept="image/png,image/jpeg,image/webp"
                                disabled={uploading}
                                className="ed-hidden"
                                onChange={e => {
                                    const file = e.target.files?.[0] ?? null;
                                    e.target.value = '';
                                    void upload(file);
                                }}
                            />
                        </label>
                        {value ? (
                            <button type="button" className="button small" onClick={() => onChange('')}>
                                {clearLabel ?? translate('portal', 'sce_md_photo_use_default')}
                            </button>
                        ) : null}
                    </div>
                    <small className="ed-hint ed-mt-xs">{hint ?? translate('portal', 'sce_md_photo_hint')}</small>
                </div>
            </div>
        </div>
    );
}

function FieldBox({
    label,
    fieldKey,
    value,
    placeholder,
    onChange,
    multiline = false,
    rows = 3,
    span2 = false
}: {
    label: string;
    fieldKey: keyof CustomizationData;
    value?: string;
    placeholder?: string;
    onChange: (key: keyof CustomizationData, val: string) => void;
    multiline?: boolean;
    rows?: number;
    span2?: boolean;
}) {
    const translate = useT();
    const fieldLabelKey = `sce_fld_${String(fieldKey)}`;
    const localizedLabel = translate('portal', fieldLabelKey);
    const displayLabel = localizedLabel === fieldLabelKey ? label : localizedLabel;

    return (
        <label className={span2 ? 'ed-block hub-span-all' : 'ed-block'} data-field={String(fieldKey)}>
            <span className="ed-label">{displayLabel}</span>
            {multiline ? (
                <textarea
                    rows={rows}
                    value={value || ''}
                    placeholder={placeholder}
                    onChange={e => onChange(fieldKey, e.target.value)}
                    className="ed-input"
                />
            ) : (
                <input
                    type="text"
                    value={value || ''}
                    placeholder={placeholder}
                    onChange={e => onChange(fieldKey, e.target.value)}
                    className="ed-input"
                />
            )}
            {placeholder ? (
                <small className="ed-hint ed-mt-xs">
                    {translate('portal', 'sce_default_placeholder', { value: placeholder.length > 120 ? `${placeholder.slice(0, 120)}…` : placeholder })}
                </small>
            ) : null}
            {multiline && placeholder && !(value || '').trim() ? (
                <button type="button" className="button small ed-mt-xs" onClick={() => onChange(fieldKey, placeholder)}>
                    {translate('website_admin', 'start_from_default')}
                </button>
            ) : null}
        </label>
    );
}

/**
 * Splits a translated sentence on its :placeholders and shows each given value in bold.
 */
export function withBoldValues(sentence: string, values: Record<string, string>): React.ReactNode[] {
    return sentence.split(/(:[a-z_]+)/).map((part, index) => {
        const name = part.startsWith(':') ? part.slice(1) : '';
        return name && name in values ? <strong key={index}>{values[name]}</strong> : part;
    });
}

/** Only redraws when its own value changes, because the page now keeps every box mounted. */
export const Field = React.memo(FieldBox);
