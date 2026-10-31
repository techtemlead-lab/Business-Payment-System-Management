import { router, usePage } from '@inertiajs/react';
import { Camera } from 'lucide-react';
import { useT } from '../shared/i18n/t';

export function ProfilePhoto({
    src,
    initial,
    variant = 'home',
    upload = false,
}: {
    src?: string | null;
    initial: string;
    variant?: 'home' | 'cover' | 'account';
    upload?: boolean;
}) {
    const translate = useT();
    const { portalBase: base = '' } = usePage<any>().props;
    const picture = typeof src === 'string' && src !== '' ? src : null;
    const className = variant === 'home'
        ? 'partner-home-avatar'
        : variant === 'account'
            ? 'account-avatar'
            : 'avatar large';

    const choosePhoto = (file: File | null) => {
        if (!file) {
            return;
        }
        router.post(`${base}/commands/profile/photo`, { photo: file }, {
            forceFormData: true,
            preserveScroll: true,
        });
    };

    return (
        <div className={`${className}${upload ? ' has-photo-upload' : ''}`}>
            {picture ? <img src={picture} alt="" /> : <span>{initial || '?'}</span>}
            {upload && (
                <label className="partner-home-avatar-upload">
                    <input
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        aria-label={translate('portal', 'profile_photo_upload')}
                        title={translate('portal', 'profile_photo_upload')}
                        onChange={(event) => {
                            choosePhoto(event.target.files?.[0] ?? null);
                            event.target.value = '';
                        }}
                    />
                    <span className="partner-home-avatar-camera" aria-hidden="true"><Camera size={12} /></span>
                </label>
            )}
        </div>
    );
}
