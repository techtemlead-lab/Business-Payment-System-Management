import { useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { useT } from './i18n/t';

type FormField = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

const isFormField = (target: EventTarget | null): target is FormField =>
    target instanceof HTMLInputElement || target instanceof HTMLSelectElement || target instanceof HTMLTextAreaElement;

/**
 * The browser writes its "Please fill in this field" bubbles in the phone's language, not the
 * page's. On Bangla pages this swaps them for Bangla ones on every form, without changing any rule.
 */
export function useNativeFieldMessages(): void {
    const { locale } = usePage<any>().props;
    const translate = useT();

    useEffect(() => {
        if (locale !== 'bn') {
            return;
        }

        const reasonFor = (field: FormField): string => {
            const { validity } = field;
            const input = field as HTMLInputElement;
            const say = (key: string, replace: Record<string, string | number> = {}): string => translate('portal', key, replace);

            if (validity.valueMissing) {
                if (input.type === 'checkbox') {
                    return say('field_need_tick');
                }
                if (input.type === 'file') {
                    return say('field_need_file');
                }
                return field instanceof HTMLSelectElement || input.type === 'radio' ? say('field_need_choice') : say('field_need_fill');
            }
            if (validity.typeMismatch) {
                return say(input.type === 'url' ? 'field_need_link' : 'field_need_email');
            }
            if (validity.tooShort) {
                return say('field_too_short', { min: input.minLength });
            }
            if (validity.tooLong) {
                return say('field_too_long', { max: input.maxLength });
            }
            if (validity.rangeUnderflow) {
                return say('field_too_small', { min: input.min });
            }
            if (validity.rangeOverflow) {
                return say('field_too_big', { max: input.max });
            }
            if (validity.badInput || validity.stepMismatch) {
                return say('field_bad_number');
            }
            if (validity.patternMismatch) {
                return say('field_bad_format');
            }

            return '';
        };

        const onInvalid = (event: Event): void => {
            if (isFormField(event.target)) {
                event.target.setCustomValidity(reasonFor(event.target));
            }
        };

        const onEdit = (event: Event): void => {
            if (isFormField(event.target)) {
                event.target.setCustomValidity('');
            }
        };

        /** Runs before the browser checks the form, so a field fixed by a button is not held back. */
        const onSubmitClick = (event: MouseEvent): void => {
            const button = (event.target as Element | null)?.closest?.('button, input[type="submit"]') as HTMLButtonElement | null;
            if (button?.form && button.type === 'submit') {
                Array.from(button.form.elements).forEach((element) => {
                    if (isFormField(element)) {
                        element.setCustomValidity('');
                    }
                });
            }
        };

        document.addEventListener('invalid', onInvalid, true);
        document.addEventListener('input', onEdit, true);
        document.addEventListener('change', onEdit, true);
        document.addEventListener('click', onSubmitClick, true);

        return () => {
            document.removeEventListener('invalid', onInvalid, true);
            document.removeEventListener('input', onEdit, true);
            document.removeEventListener('change', onEdit, true);
            document.removeEventListener('click', onSubmitClick, true);
        };
    }, [locale, translate]);
}
