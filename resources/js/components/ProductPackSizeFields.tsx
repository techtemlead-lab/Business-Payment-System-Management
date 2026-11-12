import { useT } from '../shared/i18n/t';
import type { Row } from './UI';

/**
 * Everything about a product's pack size in one place on the edit page: the size the office sees,
 * and the words the website shows for it. It can be changed at any time after the product is made.
 */
export default function ProductPackSizeFields({ data, setField, canEdit }: {
    data: Row;
    setField: (name: string, value: string) => void;
    canEdit: boolean;
}) {
    const translate = useT();
    const packSize = translate('portal', 'pe_strength');

    return (
        <fieldset id="pack-size" className="pe-fact-field pe-pack-size" disabled={!canEdit} aria-label={packSize}>
            <label>
                {packSize}
                <input value={data.strength ?? ''} placeholder={translate('portal', 'pe_strength_ph')} maxLength={100} onChange={event => setField('strength', event.target.value)} />
                <small className="muted">{translate('portal', 'pe_strength_hint')}</small>
            </label>
            <div className="form-grid">
                <label>
                    {translate('size_groups', 'pack_size_site_bn')}
                    <input value={data.pack_size_bn ?? ''} maxLength={120} onChange={event => setField('pack_size_bn', event.target.value)} />
                </label>
                <label>
                    {translate('size_groups', 'pack_size_site_en')}
                    <input value={data.pack_size_en ?? ''} maxLength={120} onChange={event => setField('pack_size_en', event.target.value)} />
                </label>
            </div>
            <small className="muted">{translate('size_groups', 'pack_size_site_hint')}</small>
        </fieldset>
    );
}
