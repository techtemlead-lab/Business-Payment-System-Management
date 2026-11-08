import React from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { Field } from '../formFields';
import { WebsiteGroup, WebsiteSection, WebsiteToolLink, type WebsiteSectionProps } from '../WebsiteLayout';
import { useT } from '../../../shared/i18n/t';

export default function TextPoliciesSection({ form, defaults, updateField }: WebsiteSectionProps) {
    const translate = useT();
    return (
        <WebsiteSection id="text" title={translate('website_admin', 'section_text')} hint={translate('website_admin', 'section_text_hint')}>
        <WebsiteToolLink href="/content" title={translate('website_admin', 'tool_content_title')} body={translate('website_admin', 'tool_content_body')} />

        <WebsiteGroup title={translate('portal', 'sce_faq_page_heading')}>
                            <div className="form-grid ed-grid ed-mb">
                                <Field label={translate('portal', 'sce_f_faq_hero_title')} fieldKey="faq_hero_title" value={form.data.customization.faq_hero_title} placeholder={defaults.faq_hero_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_faq_hero_lead')} fieldKey="faq_hero_lead" value={form.data.customization.faq_hero_lead} placeholder={defaults.faq_hero_lead} onChange={updateField} multiline rows={2} />
                            </div>
                            <div className="ed-stack ed-mb-lg">
                                {(form.data.customization.faq_items || []).map((item, idx) => (
                                    <div key={`faq-${idx}`} className="ed-card is-soft">
                                        <div className="ed-head is-sm">
                                            <strong className="ed-strong">{translate('portal', 'sce_question_number', { number: String(idx + 1) })}</strong>
                                            <button
                                                type="button"
                                                className="button small danger"
                                                onClick={() => {
                                                    const next = [...(form.data.customization.faq_items || [])];
                                                    next.splice(idx, 1);
                                                    form.setData('customization', { ...form.data.customization, faq_items: next });
                                                }}
                                            >
                                                <Trash2 size={14} /> {translate('portal', 'sce_remove')}
                                            </button>
                                        </div>
                                        <div className="form-grid ed-grid is-single">
                                            <label className="ed-label is-inline">
                                                {translate('portal', 'sce_question')}
                                                <input
                                                    value={item.question || ''}
                                                    placeholder={defaults.faq_items?.[idx]?.question || translate('portal', 'sce_question')}
                                                    onChange={(e) => {
                                                        const next = [...(form.data.customization.faq_items || [])];
                                                        next[idx] = { ...next[idx], question: e.target.value };
                                                        form.setData('customization', { ...form.data.customization, faq_items: next });
                                                    }}
                                                />
                                            </label>
                                            <label className="ed-label is-inline">
                                                {translate('portal', 'sce_answer')}
                                                <textarea
                                                    rows={3}
                                                    value={item.answer || ''}
                                                    placeholder={defaults.faq_items?.[idx]?.answer || translate('portal', 'sce_answer')}
                                                    onChange={(e) => {
                                                        const next = [...(form.data.customization.faq_items || [])];
                                                        next[idx] = { ...next[idx], answer: e.target.value };
                                                        form.setData('customization', { ...form.data.customization, faq_items: next });
                                                    }}
                                                />
                                            </label>
                                        </div>
                                    </div>
                                ))}
                                <button
                                    type="button"
                                    className="button small dark"
                                    onClick={() => form.setData('customization', {
                                        ...form.data.customization,
                                        faq_items: [...(form.data.customization.faq_items || []), { question: '', answer: '' }],
                                    })}
                                >
                                    <Plus size={14} /> {translate('portal', 'sce_add_faq_item')}
                                </button>
                            </div>
        </WebsiteGroup>

        <WebsiteGroup title={translate('portal', 'sce_privacy_terms_heading')}>
                            <div className="form-grid ed-grid">
                                <Field label={translate('portal', 'sce_f_privacy_title')} fieldKey="privacy_hero_title" value={form.data.customization.privacy_hero_title} placeholder={defaults.privacy_hero_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_privacy_lead')} fieldKey="privacy_hero_lead" value={form.data.customization.privacy_hero_lead} placeholder={defaults.privacy_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label={translate('portal', 'sce_f_privacy_body')} fieldKey="privacy_body" value={form.data.customization.privacy_body} placeholder={defaults.privacy_body} onChange={updateField} multiline rows={6} span2 />
                                <Field label={translate('portal', 'sce_f_terms_title')} fieldKey="terms_hero_title" value={form.data.customization.terms_hero_title} placeholder={defaults.terms_hero_title} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_terms_lead')} fieldKey="terms_hero_lead" value={form.data.customization.terms_hero_lead} placeholder={defaults.terms_hero_lead} onChange={updateField} multiline rows={2} />
                                <Field label={translate('portal', 'sce_f_terms_body')} fieldKey="terms_body" value={form.data.customization.terms_body} placeholder={defaults.terms_body} onChange={updateField} multiline rows={6} span2 />
                                <Field label={translate('portal', 'sce_f_legal_effective')} fieldKey="legal_meta_effective" value={form.data.customization.legal_meta_effective} placeholder={defaults.legal_meta_effective} onChange={updateField} />
                                <Field label={translate('portal', 'sce_f_legal_governance')} fieldKey="legal_meta_governance" value={form.data.customization.legal_meta_governance} placeholder={defaults.legal_meta_governance} onChange={updateField} />
                            </div>
        </WebsiteGroup>
        </WebsiteSection>
    );
}
