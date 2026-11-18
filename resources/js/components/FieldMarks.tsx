import { useT } from '../shared/i18n/t';

/**
 * Marks on form labels (CR-08 follow-up): the client believed every product box was compulsory.
 * A red star marks a box the form will not send without; "(optional)" marks one that may stay
 * empty. The input's own `required` attribute is what screen readers announce, so the star is
 * decorative for them.
 */
export function RequiredMark() {
    const translate = useT();

    return <span className="field-required-mark" aria-hidden="true" title={translate('stock_screens', 'mark_required')}
        style={{ color: '#b42318', fontWeight: 700, marginLeft: 4 }}>*</span>;
}

export function OptionalTag() {
    const translate = useT();

    return <small className="field-optional-tag" style={{ fontWeight: 400, marginLeft: 6, color: 'var(--muted, #64748b)' }}>({translate('stock_screens', 'mark_optional')})</small>;
}

/** One line above a form saying what the marks mean. */
export function FieldMarksLegend() {
    const translate = useT();

    return <p className="muted field-marks-legend"><span aria-hidden="true" style={{ color: '#b42318', fontWeight: 700 }}>*</span> {translate('stock_screens', 'mark_legend')}</p>;
}
