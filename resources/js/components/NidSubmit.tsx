import { ActionForm } from './UI';
import { useT } from '../shared/i18n/t';

export type NidRequest = { status: 'none' | 'pending' | 'rejected' | 'verified'; note: string | null };

/**
 * R5: a partner who joined with a birth registration sends his own NID number and card photo.
 * Staff verify it; the first withdrawal opens once they have. Used on the profile and in the withdrawal dialog.
 */
export default function NidSubmit({ request }: { request: NidRequest | null | undefined }) {
    const translate = useT();
    const t = (key: string, replace: Record<string, string> = {}) => translate('leftovers_partner', key, replace);
    if (!request || request.status === 'verified') {
        return null;
    }
    if (request.status === 'pending') {
        return <p className="notice" role="status" data-testid="nid-waiting">{t('nid_waiting')}</p>;
    }

    return (
        <section data-testid="nid-submit">
            <h3>{t('nid_title')}</h3>
            <p className="muted">{t('nid_hint')}</p>
            {request.status === 'rejected' && (
                <p className="field-error" role="alert" data-testid="nid-sent-back">{t('nid_sent_back', { reason: request.note ?? '' })}</p>
            )}
            <ActionForm
                action="/commands/profile/nid"
                submit={t('nid_send')}
                fields={[
                    { name: 'nid', label: t('nid_number_label'), required: true },
                    { name: 'photo', type: 'file', accept: 'image/jpeg,image/png,image/webp,application/pdf', label: t('nid_photo_label'), required: true },
                ]}
            />
        </section>
    );
}
