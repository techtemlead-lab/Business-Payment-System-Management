import React, { useState } from 'react';
import { ActionForm, Modal } from './UI';
import { useT } from '../shared/i18n/t';

/** A button that asks for a reason and a confirmation before the owner finishes a step alone. */
export default function SelfApprovalForm({ action, submit, noteField = 'reason' }: { action: string; submit: string; noteField?: string }) {
    const translate = useT();
    const [open, setOpen] = useState(false);

    return (
        <>
            <button type="button" className="button small" onClick={() => setOpen(true)}>{submit}</button>
            {open && (
                <Modal title={submit} onClose={() => setOpen(false)}>
                    <ActionForm
                        action={action}
                        submit={submit}
                        onDone={() => setOpen(false)}
                        fields={[
                            { name: noteField, label: translate('self_approval', 'reason_label'), type: 'textarea', required: true },
                            { name: 'self_approve', label: translate('self_approval', 'confirm_label'), required: true, options: [{ value: '1', label: translate('self_approval', 'confirm_yes') }] },
                        ]}
                    />
                </Modal>
            )}
        </>
    );
}

/** Approve or reject a stock count the owner took part in, when nobody else could decide it. */
export function DecideCountAlone({ sessionId }: { sessionId: number }) {
    const translate = useT();

    return (
        <div className="ops-row is-wrap">
            <SelfApprovalForm action={`/commands/stock-counts/${sessionId}/approve`} submit={translate('self_approval', 'approve_alone')} noteField="note" />
            <SelfApprovalForm action={`/commands/stock-counts/${sessionId}/reject`} submit={translate('self_approval', 'reject_alone')} noteField="note" />
        </div>
    );
}

/** Closes a submitted stock count so the branch can count again. */
export function CancelSubmittedCount({ sessionId }: { sessionId: number }) {
    const translate = useT();

    return (
        <ActionForm
            action={`/commands/stock-counts/${sessionId}/cancel`}
            submit={translate('self_approval', 'count_cancel_submitted')}
            fields={[{ name: 'reason', label: translate('self_approval', 'count_cancel_reason'), type: 'textarea', required: true }]}
        />
    );
}
