/**
 * Product units and categories the owner typed in English ("jar", "Skin Care") are shown in Bangla
 * on Bangla screens when partner_ux has a word for them (keys unit_jar, category_skin_care).
 * Anything without a word stays as the owner wrote it.
 */
export type Translate = (group: string, key: string, replace?: Record<string, string | number>) => string;

const slug = (value: string): string => value.trim().toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '');

export function catalogWord(translate: Translate, kind: 'unit' | 'category', value: string | null | undefined): string {
    const text = String(value ?? '');
    const key = slug(text);
    if (key === '') {
        return text;
    }
    const word = translate('partner_ux', `${kind}_${key}`);

    return word === `${kind}_${key}` ? text : word;
}
