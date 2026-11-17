import { router, usePage } from '@inertiajs/react';
import { useT } from '../../shared/i18n/t';
import type { Field, Row } from '../UI';

/** What the owner is asked for on a sensitive change: nothing, the password, or a code sent to their phone. */
export type OwnerConfirmationControl = { mode: string; version: number; method: 'none' | 'password' | 'code'; sms_works: boolean; has_phone: boolean; code_fell_back: boolean };

/** The confirmation box for a form: the password, the code, or nothing, following the owner's chosen mode. */
export function ownerConfirmFields(control: OwnerConfirmationControl | null | undefined, translate: (group: string, key: string) => string): Field[] {
    const method = control?.method ?? 'password';
    if (method === 'none') {
        return [];
    }
    if (method === 'code') {
        return [{ name: 'confirm_code', label: translate('owner_security', 'code_label'), required: true }];
    }

    return [{ name: 'current_password', label: translate('portal', 'owner_contact_password'), type: 'password', required: true }];
}

/** Says in plain words what will be asked, and offers the code button when a code is needed. */
export function OwnerConfirmationNote({ control }: { control: OwnerConfirmationControl | null | undefined }) {
    const translate = useT();
    const { flash, errors } = usePage<any>().props;
    const method = control?.method ?? 'password';
    if (method === 'none') {
        return <p className="set-line">{translate('owner_security', 'note_none')}</p>;
    }
    if (method === 'password') {
        return control?.code_fell_back ? <p className="field-error" role="alert">{translate('owner_security', 'note_code_fell_back')}</p> : null;
    }

    return (
        <div className="set-line">
            <p>{translate('owner_security', 'note_code')}</p>
            <button type="button" className="button small" onClick={() => router.post('/commands/owner/confirmation-code', {}, { preserveScroll: true })}>{translate('owner_security', 'send_code')}</button>
            {(errors as Row)?.confirm_code && <span className="field-error" role="alert"> {String((errors as Row).confirm_code)}</span>}
            {flash?.owner_code_on_screen && <p className="muted">{translate('owner_security', 'code_on_screen')} <b>{String(flash.owner_code_on_screen)}</b></p>}
        </div>
    );
}
