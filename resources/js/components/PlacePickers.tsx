import React, { useEffect, useState } from 'react';
import SearchSelect from './SearchSelect';
import { useT } from '../shared/i18n/t';

export type PlaceOption = { id: number; code: string; name: string; level: string; parent_id: number | null };
export type PlaceLevel = 'division' | 'district' | 'upazila' | 'union';
export type ChildLevel = Exclude<PlaceLevel, 'division'>;
export type PlaceIds = Record<PlaceLevel, string>;

/** From the top of the country down; a dealer of a level picks only down to that level. */
export const placeLevels: PlaceLevel[] = ['division', 'district', 'upazila', 'union'];
const noPlaces: PlaceIds = { division: '', district: '', upazila: '', union: '' };

/** One level of the office place list (only places that have a code), from the server. */
export async function loadPlaceList(level: PlaceLevel, parentId: string, failedMessage: string): Promise<PlaceOption[]> {
    const params = new URLSearchParams({ level });
    if (parentId) {
        params.set('parent_id', parentId);
    }
    const response = await fetch(`/commands/place-codes/children?${params}`, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!response.ok) {
        throw new Error(failedMessage);
    }
    const json = await response.json();

    return json.data ?? [];
}

/**
 * One Division > District > Upazila > Union chain of lists. Each list is filled from the server
 * once the place above it is chosen; it is emptied first and "loading" shows until the answer
 * arrives, and an answer for a place that is no longer chosen is dropped, so a slow reply can
 * never fill the list with another upazila's unions. The add-partner form uses one chain for the
 * business area and another for the permanent address; the account editor uses one for the
 * permanent address.
 */
export function usePlaceChain(divisions: PlaceOption[], load: (level: ChildLevel, parentId: string) => Promise<PlaceOption[]>) {
    const [ids, setIds] = useState<PlaceIds>(noPlaces);
    const [lists, setLists] = useState<Record<ChildLevel, PlaceOption[]>>({ district: [], upazila: [], union: [] });
    const [loading, setLoading] = useState<Record<ChildLevel, boolean>>({ district: false, upazila: false, union: false });

    const fill = (level: ChildLevel, parentId: string): (() => void) | undefined => {
        setLists((prev) => ({ ...prev, [level]: [] }));
        if (!parentId) {
            setLoading((prev) => ({ ...prev, [level]: false }));
            return undefined;
        }
        let current = true;
        setLoading((prev) => ({ ...prev, [level]: true }));
        load(level, parentId)
            .then((list) => { if (current) setLists((prev) => ({ ...prev, [level]: list })); })
            .catch(() => { if (current) setLists((prev) => ({ ...prev, [level]: [] })); })
            .finally(() => { if (current) setLoading((prev) => ({ ...prev, [level]: false })); });
        return () => { current = false; };
    };

    useEffect(() => fill('district', ids.division), [ids.division]);
    useEffect(() => fill('upazila', ids.district), [ids.district]);
    useEffect(() => fill('union', ids.upazila), [ids.upazila]);

    /** Chooses a place on one level; every level below it is emptied. Gives back the new choice. */
    const choose = (level: PlaceLevel, value: string): PlaceIds => {
        const depth = placeLevels.indexOf(level);
        const next = { ...ids };
        placeLevels.forEach((each, index) => {
            if (index === depth) {
                next[each] = value;
            } else if (index > depth) {
                next[each] = '';
            }
        });
        setIds(next);
        return next;
    };
    const find = (level: PlaceLevel, id: string): PlaceOption | undefined => (level === 'division' ? divisions : lists[level]).find((item) => String(item.id) === id);

    return { divisions, ids, lists, loading, choose, find, clear: () => setIds(noPlaces) };
}

export type PlaceChain = ReturnType<typeof usePlaceChain>;

/** How a page wraps one list with its label, error and hint. */
export type PlaceField = (props: { text: string; error?: string; hint?: string; children: React.ReactNode }) => React.ReactElement;

/** The searchable lists of one chain, only down to the deepest level asked for. */
export default function PlacePickers({ chain, levels, onPick, Field, errors, hints }: {
    chain: PlaceChain;
    levels: PlaceLevel[];
    onPick: (level: PlaceLevel, value: string) => void;
    Field: PlaceField;
    errors?: Partial<Record<PlaceLevel, string | undefined>>;
    hints?: Partial<Record<PlaceLevel, string>>;
}) {
    const translate = useT();
    const optionsOf = (list: PlaceOption[]) => list.map((item) => ({ value: String(item.id), label: `${item.code} — ${item.name}` }));
    const loadingLabel = translate('leftovers_office', 'place_loading');
    const shown = (level: PlaceLevel) => levels.includes(level);

    return (
        <>
            {shown('division') && (
                <Field text={translate('portal', 'acct_division')} error={errors?.division} hint={hints?.division}>
                    <SearchSelect
                        value={chain.ids.division}
                        onChange={(value) => onPick('division', value)}
                        emptyLabel={translate('portal', 'acct_select_division')}
                        options={optionsOf(chain.divisions)}
                    />
                </Field>
            )}
            {shown('district') && (
                <Field text={translate('portal', 'acct_district')} error={errors?.district} hint={hints?.district}>
                    <SearchSelect
                        value={chain.ids.district}
                        onChange={(value) => onPick('district', value)}
                        disabled={!chain.ids.division}
                        loading={chain.loading.district}
                        loadingLabel={loadingLabel}
                        emptyLabel={translate('portal', 'acct_select_district')}
                        options={optionsOf(chain.lists.district)}
                    />
                </Field>
            )}
            {shown('upazila') && (
                <Field text={translate('portal', 'acct_upazila')} error={errors?.upazila} hint={hints?.upazila}>
                    <SearchSelect
                        value={chain.ids.upazila}
                        onChange={(value) => onPick('upazila', value)}
                        disabled={!chain.ids.district}
                        loading={chain.loading.upazila}
                        loadingLabel={loadingLabel}
                        emptyLabel={translate('portal', 'acct_select_upazila')}
                        options={optionsOf(chain.lists.upazila)}
                    />
                </Field>
            )}
            {shown('union') && (
                <Field text={translate('portal', 'acct_union')} error={errors?.union} hint={hints?.union}>
                    <SearchSelect
                        value={chain.ids.union}
                        onChange={(value) => onPick('union', value)}
                        disabled={!chain.ids.upazila}
                        loading={chain.loading.union}
                        loadingLabel={loadingLabel}
                        emptyLabel={translate('portal', 'acct_select_union')}
                        options={optionsOf(chain.lists.union)}
                    />
                </Field>
            )}
        </>
    );
}
