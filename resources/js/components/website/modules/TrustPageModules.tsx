import React from 'react';
import { Field } from '../formFields';
import { WebsiteGroup, WebsiteToolLink, type WebsiteSectionProps } from '../WebsiteLayout';
import type { CustomizationData } from '../types';
import { useT } from '../../../shared/i18n/t';
import { WebsiteNote } from './SharedFields';

const fieldLabelKey = (field: string): string => {
    if (field.endsWith('_contact_note')) {
        return 'admin_field_contact_note';
    }
    return `admin_field_${field.split('_').pop()}`;
};

const isLongField = (field: string): boolean => /_(body|warning|disclaimer)$/.test(field);

type TrustProps = Pick<WebsiteSectionProps, 'form' | 'updateField' | 'trustEditor'>;

/** One piece of text with its English box and its Bangla box. Empty keeps the built-in wording. */
function BilingualFields({ field, form, updateField, trustEditor }: TrustProps & { field: string }) {
    if (!trustEditor) {
        return null;
    }
    const label = trustEditor.labels[fieldLabelKey(field)] ?? field;
    const long = isLongField(field);
    const rows = field.endsWith('_body') ? 12 : 3;
    const value = (key: string): string | undefined => (form.data.customization as Record<string, unknown>)[key] as string | undefined;

    return (
        <>
            {(['', '_bn'] as const).map((suffix) => {
                const key = `trust_${field}${suffix}`;
                const caption = trustEditor.labels[suffix === '' ? 'admin_english' : 'admin_bangla'] ?? '';
                return (
                    <Field
                        key={key}
                        label={`${label} (${caption})`}
                        fieldKey={key as keyof CustomizationData}
                        value={value(key)}
                        placeholder={trustEditor.drafts[suffix === '' ? 'en' : 'bn'][field]}
                        onChange={updateField}
                        multiline={long}
                        rows={rows}
                        span2={long}
                    />
                );
            })}
        </>
    );
}

function MissingAccessNote() {
    const translate = useT();
    return <WebsiteNote>{translate('website_admin', 'trust_needs_content_access')}</WebsiteNote>;
}

function TrustPageText({ fields, form, updateField, trustEditor }: TrustProps & { fields: string[] }) {
    const translate = useT();
    if (!trustEditor) {
        return <MissingAccessNote />;
    }
    return (
        <WebsiteGroup title={translate('website_admin', 'group_policy_words')} defaultOpen>
            <div className="form-grid ed-grid">
                {fields.map((field) => <BilingualFields key={field} field={field} form={form} updateField={updateField} trustEditor={trustEditor} />)}
            </div>
        </WebsiteGroup>
    );
}

export function RefundModule(props: WebsiteSectionProps) {
    return <TrustPageText {...props} fields={['refund_title', 'refund_lead', 'refund_body']} />;
}

export function DeliveryModule(props: WebsiteSectionProps) {
    return <TrustPageText {...props} fields={['delivery_title', 'delivery_lead', 'delivery_body']} />;
}

export function ConductModule(props: WebsiteSectionProps) {
    return <TrustPageText {...props} fields={['conduct_title', 'conduct_lead', 'conduct_body']} />;
}

export function PartnerExitModule(props: WebsiteSectionProps) {
    const { trustEditor } = props;
    return (
        <>
            {trustEditor && <WebsiteNote>{trustEditor.labels.admin_exit_hint}</WebsiteNote>}
            <TrustPageText {...props} fields={['exit_title', 'exit_lead', 'exit_body']} />
        </>
    );
}

export function VerifyModule(props: WebsiteSectionProps) {
    return <TrustPageText {...props} fields={['verify_title', 'verify_lead', 'verify_warning']} />;
}

export function GrievanceModule(props: WebsiteSectionProps) {
    const { form, updateField, trustEditor } = props;
    const translate = useT();
    const value = (key: string): string | undefined => (form.data.customization as Record<string, unknown>)[key] as string | undefined;
    return (
        <>
            <TrustPageText {...props} fields={['grievance_title', 'grievance_lead', 'grievance_contact_note']} />
            {trustEditor && (
                <WebsiteGroup title={translate('website_admin', 'group_grievance_person')} defaultOpen>
                    <p className="ed-desc is-sm">{trustEditor.labels.admin_contact_hint}</p>
                    <div className="form-grid ed-grid">
                        {trustEditor.contact_keys.map((key) => (
                            <Field
                                key={key}
                                label={trustEditor.labels[`admin_contact_${key.replace('grievance_contact_', '')}`] ?? key}
                                fieldKey={`trust_${key}` as keyof CustomizationData}
                                value={value(`trust_${key}`)}
                                onChange={updateField}
                            />
                        ))}
                    </div>
                </WebsiteGroup>
            )}
        </>
    );
}

export function QualityDocumentsModule(props: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
            <WebsiteToolLink href="/public-trust" title={translate('website_admin', 'tool_documents_title')} body={translate('website_admin', 'tool_documents_body')} />
            <TrustPageText {...props} fields={['quality_documents_title', 'quality_documents_lead']} />
        </>
    );
}

export function ArticlesModule(props: WebsiteSectionProps) {
    const translate = useT();
    return (
        <>
            <WebsiteToolLink href="/public-trust" title={translate('website_admin', 'tool_articles_title')} body={translate('website_admin', 'tool_articles_body')} />
            <TrustPageText {...props} fields={['articles_title', 'articles_lead', 'articles_disclaimer']} />
        </>
    );
}
