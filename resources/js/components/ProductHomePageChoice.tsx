import { localizeDigits } from '../business-date';
import { toLatin } from '../shared/countUp';
import { useT } from '../shared/i18n/t';

/**
 * The plain question "Show on home page?" with Yes or No, and, when yes, the product's place
 * among the home page products. The full numbered list is one link away.
 */
export default function ProductHomePageChoice({ shown, place, listedCount, canEdit, onShownChange, onPlaceChange }: {
    /** '1' for yes, '0' for no, as the product form keeps it. */
    shown: string;
    /** The place typed, or blank to keep the saved place (a product new to the list goes last). */
    place: string;
    listedCount: number;
    canEdit: boolean;
    onShownChange: (shown: string) => void;
    onPlaceChange: (place: string) => void;
}) {
    const translate = useT();
    const question = translate('portal', 'pe_homepage');

    return (
        <>
            <label>
                {question}
                <select aria-label={question} disabled={!canEdit} value={shown} onChange={event => onShownChange(event.target.value)}>
                    <option value="1">{translate('portal', 'pe_featured')}</option>
                    <option value="0">{translate('portal', 'pe_standard')}</option>
                </select>
            </label>
            {shown === '1' && (
                <label>
                    {translate('portal', 'pe_home_place')}
                    <input
                        type="text"
                        inputMode="numeric"
                        disabled={!canEdit}
                        value={localizeDigits(place)}
                        maxLength={4}
                        onChange={event => onPlaceChange(toLatin(event.target.value).replace(/\D/g, ''))}
                    />
                    <small className="muted">{translate('portal', 'pe_home_place_hint', { count: localizeDigits(String(listedCount)) })}</small>
                    {/* A new tab, so a half-filled product form is not lost. */}
                    <a href="/catalog?tab=featured" target="_blank" rel="noreferrer" className="text-link">{translate('portal', 'pe_home_place_all')}</a>
                </label>
            )}
        </>
    );
}
