import React, { useCallback, useEffect, useId, useRef, useState } from 'react';
import { X } from 'lucide-react';
import { FormErrors, money } from './UI';
import { useT } from '../shared/i18n/t';
import { takaWords } from '../shared/takaText';
import { useKeyboardSafeSheet } from '../shared/useKeyboardSafeSheet';

/** One line of the facts list: "Who gets it: Rahim (MNHC-101)". */
export type ConfirmFact = { label: string; value: React.ReactNode };

/** An optional box for the reason; the caller keeps its value so the same field name is posted as before. */
export type ConfirmReason = {
    label: string;
    value: string;
    onChange: (value: string) => void;
    required?: boolean;
    minLength?: number;
    maxLength?: number;
    hint?: string;
};

export type ConfirmSheetProps = {
    title: string;
    /** One plain sentence: what happens when the button is pressed. */
    sentence?: React.ReactNode;
    /** The money this step moves, shown large in Taka with the amount in words. */
    amount?: unknown;
    amountLabel?: string;
    facts?: ConfirmFact[];
    reason?: ConfirmReason;
    /** `danger` for reject, cancel or write-off; `primary` for approve or confirm. */
    tone?: 'primary' | 'danger';
    confirmLabel: string;
    cancelLabel?: string;
    /** Adds the line "This cannot be undone". */
    irreversible?: boolean;
    /** True while the request is on its way: both buttons wait and a second press does nothing. */
    pending?: boolean;
    errors?: Record<string, unknown>;
    /** Extra fields (inside the same form, so `required` boxes are checked before onConfirm). */
    children?: React.ReactNode;
    onConfirm: () => void;
    onCancel: () => void;
};

const PHONE_QUERY = '(max-width: 767px)';

/**
 * The one confirmation step for money and other serious actions. A bottom sheet on a phone (CSS in
 * screens-confirm-sheet.css) and a centred dialog on a computer. It repeats the amount and who gets it,
 * so a tap on the wrong row is caught before anything is sent.
 *
 * Built on the browser's modal <dialog>: the rest of the page cannot be reached while it is open, Esc closes it.
 * A tap outside closes it only when there is nothing typed in it to lose (client, 2026-11-20).
 */
export default function ConfirmSheet({
    title, sentence, amount, amountLabel, facts = [], reason, tone = 'primary', confirmLabel, cancelLabel,
    irreversible = false, pending = false, errors, children, onConfirm, onCancel,
}: ConfirmSheetProps) {
    const translate = useT();
    const ref = useRef<HTMLDialogElement>(null);
    const cancelRef = useRef<HTMLButtonElement>(null);
    const fieldsRef = useRef<HTMLDivElement>(null);
    const titleId = useId();
    const reasonId = useId();
    // One press counts once, even before the caller's `pending` arrives; a finished request (pending back to false) allows another try.
    const [fired, setFired] = useState(false);
    const busy = pending || fired;
    const onCancelRef = useRef(onCancel);
    onCancelRef.current = onCancel;
    useKeyboardSafeSheet(ref);

    useEffect(() => {
        if (!pending) {
            setFired(false);
        }
    }, [pending]);

    useEffect(() => {
        const dialog = ref.current;
        const previous = document.activeElement as HTMLElement | null;
        dialog?.showModal();
        // On a phone the keyboard would cover the sheet at once, so start on "Cancel"; on a computer start in the first box.
        const firstField = fieldsRef.current?.querySelector<HTMLElement>('input, select, textarea');
        const onPhone = window.matchMedia?.(PHONE_QUERY).matches ?? false;
        (firstField && !onPhone ? firstField : cancelRef.current)?.focus();

        return () => {
            dialog?.close();
            previous?.focus?.();
        };
    }, []);

    const cancel = useCallback(() => {
        if (!pending) {
            onCancelRef.current();
        }
    }, [pending]);

    const hasTyping = Boolean(reason) || Boolean(children);
    const words = amount !== undefined && amount !== null && amount !== '' ? takaWords(amount, undefined, true) : null;

    return (
        <dialog
            ref={ref}
            className={`portal-modal confirm-sheet is-${tone}`}
            aria-labelledby={titleId}
            onCancel={(event) => {
                event.preventDefault();
                cancel();
            }}
            onClick={(event) => {
                // The backdrop belongs to the <dialog> itself: a tap there closes a sheet with nothing typed in it.
                if (event.target === ref.current && !hasTyping) {
                    cancel();
                }
            }}
        >
            <div className="modal-heading">
                <h2 id={titleId}>{title}</h2>
                <button type="button" className="icon-button confirm-sheet-close" aria-label={translate('portal', 'close_dialog')} title={translate('portal', 'close_dialog')} disabled={pending} onClick={cancel}><X /></button>
            </div>
            <form
                className="confirm-sheet-form"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (busy) {
                        return;
                    }
                    setFired(true);
                    onConfirm();
                }}
            >
                <div className="modal-body">
                    {sentence && <p className="confirm-sheet-sentence">{sentence}</p>}
                    {amount !== undefined && amount !== null && amount !== '' && (
                        <div className="confirm-sheet-amount">
                            <span>{amountLabel ?? translate('payouts', 'cs_amount')}</span>
                            <strong>{money(amount)}</strong>
                            {words && <small>{translate('payouts', 'amount_words', { words })}</small>}
                        </div>
                    )}
                    {facts.length > 0 && (
                        <dl className="confirm-sheet-facts">
                            {facts.map((fact) => (
                                <div key={fact.label}><dt>{fact.label}</dt><dd>{fact.value}</dd></div>
                            ))}
                        </dl>
                    )}
                    <div ref={fieldsRef} className="confirm-sheet-fields">
                        {children}
                        {reason && (
                            <label htmlFor={reasonId}>
                                {reason.label}
                                <textarea
                                    id={reasonId}
                                    rows={3}
                                    required={reason.required}
                                    minLength={reason.minLength}
                                    maxLength={reason.maxLength ?? 1000}
                                    value={reason.value}
                                    onChange={(event) => reason.onChange(event.target.value)}
                                />
                                {reason.hint && <small className="field-hint">{reason.hint}</small>}
                            </label>
                        )}
                    </div>
                    {irreversible && <p className="confirm-sheet-warn" role="note">{translate('payouts', 'cs_irreversible')}</p>}
                    <FormErrors errors={errors} />
                </div>
                <div className="modal-footer confirm-sheet-actions">
                    <button ref={cancelRef} type="button" className="button" disabled={pending} onClick={cancel}>{cancelLabel ?? translate('payouts', 'cs_cancel')}</button>
                    <button type="submit" className={`button ${tone === 'danger' ? 'danger' : 'dark'}`} disabled={busy} aria-busy={busy}>
                        {busy ? translate('payouts', 'cs_working') : confirmLabel}
                    </button>
                </div>
            </form>
        </dialog>
    );
}

/** What `confirm()` from useConfirm shows. `reason` asks for a reason box; its text comes back in the answer. */
export type ConfirmOptions = Omit<ConfirmSheetProps, 'reason' | 'pending' | 'errors' | 'children' | 'onConfirm' | 'onCancel'> & {
    reason?: Omit<ConfirmReason, 'value' | 'onChange'>;
};

/**
 * Drop-in for `window.confirm`: `const { confirm, sheet } = useConfirm();` then
 * `if (!(await confirm({...}))) return;` and render `{sheet}` once in the component.
 * The answer is null when the person cancels, or `{ reason }` when they confirm.
 */
export function useConfirm(): { confirm: (options: ConfirmOptions) => Promise<{ reason: string } | null>; sheet: React.ReactNode } {
    const [asking, setAsking] = useState<null | { options: ConfirmOptions; resolve: (answer: { reason: string } | null) => void }>(null);
    const [reasonText, setReasonText] = useState('');

    const confirm = useCallback((options: ConfirmOptions) => new Promise<{ reason: string } | null>((resolve) => {
        setReasonText('');
        setAsking({ options, resolve });
    }), []);

    const sheet = asking ? (
        <ConfirmSheet
            {...asking.options}
            reason={asking.options.reason ? { ...asking.options.reason, value: reasonText, onChange: setReasonText } : undefined}
            onConfirm={() => {
                asking.resolve({ reason: reasonText });
                setAsking(null);
            }}
            onCancel={() => {
                asking.resolve(null);
                setAsking(null);
            }}
        />
    ) : null;

    return { confirm, sheet };
}
