import { useCallback } from 'react';
import { usePage } from '@inertiajs/react';
import { useT } from './i18n/t';

/**
 * The owners' switches for the reason and note boxes (App\Support\ReasonFields on the server).
 * Each place is compulsory, optional or off. The server sends only the places that are not
 * optional, so a place it leaves out is optional.
 *
 * A form with a list of fields marks its reason box with `place: '<place>'`; a form written
 * by hand asks `useReasonField('<place>')` and hides or requires its box from the answer.
 *
 * One box can answer for two places (a review reason that is also the reason for approving
 * alone): pass both, and the box follows the stricter one.
 */
export type ReasonMode = 'required' | 'optional' | 'off';

export type ReasonPlace = string | string[];

export type ReasonField = {
    mode: ReasonMode;
    /** False when the owner switched the box off: do not draw it and send nothing. */
    shown: boolean;
    /** True when the box must be filled. */
    required: boolean;
    /** The box's name, with "(optional)" after it when it may be left blank. */
    label: (text: string) => string;
};

type Modes = Record<string, ReasonMode>;

function useModes(): Modes {
    return (usePage<any>().props.reasonFields ?? {}) as Modes;
}

/** Compulsory wins over optional, and optional over off. */
function strictest(modes: Modes, place: ReasonPlace): ReasonMode {
    const each = (Array.isArray(place) ? place : [place]).map((key) => modes[key] ?? 'optional');
    if (each.includes('required')) return 'required';
    return each.includes('optional') ? 'optional' : 'off';
}

/** Ask about several places in one component: `const reasonOf = useReasonFields()`. */
export function useReasonFields(): (place: ReasonPlace) => ReasonField {
    const modes = useModes();
    const translate = useT();

    return useCallback((place: ReasonPlace): ReasonField => {
        const mode = strictest(modes, place);
        return {
            mode,
            shown: mode !== 'off',
            required: mode === 'required',
            label: (text: string) => (mode === 'optional' ? `${text} ${translate('reason_fields', 'optional_mark')}` : text),
        };
    }, [modes, translate]);
}

/** The owner's choice for one place. */
export function useReasonField(place: ReasonPlace): ReasonField {
    return useReasonFields()(place);
}

/**
 * For forms built from a list of fields: a field with a `place` is left out when the owner
 * switched it off, and is required or not by the owner's choice. Other fields pass unchanged.
 */
export function usePlacedFields<F extends { place?: ReasonPlace; required?: boolean; label: string }>(fields: F[]): F[] {
    const reasonOf = useReasonFields();

    return fields.flatMap((field) => {
        if (!field.place) return [field];
        const reason = reasonOf(field.place);
        return reason.shown ? [{ ...field, required: reason.required, label: reason.label(field.label) }] : [];
    });
}
