import { useT } from '../shared/i18n/t';
import { largestPhotoBytes, longestPhotoSide, type PhotoFitStatus } from '../shared/productPhotoFit';

/** One plain line under a product photo box: the photo is being made smaller, was made smaller, or is too large to use. */
export default function ProductPhotoFitNote({ status }: { status: PhotoFitStatus }) {
    const translate = useT();

    if (status === 'making_smaller') {
        return (
            <div className="ppc-row ppc-fit" role="status" aria-live="polite">
                <span className="ppc-spinner" aria-hidden="true" />
                <span className="ppc-note">{translate('portal', 'ppc_photo_making_smaller')}</span>
            </div>
        );
    }
    if (status === 'made_smaller') {
        return <p className="ppc-note ppc-fit" role="status">{translate('portal', 'ppc_photo_made_smaller')}</p>;
    }
    if (status === 'too_large') {
        return (
            <p className="ppc-note ppc-fit is-error" role="alert">
                {translate('portal', 'ppc_photo_too_large', { megabytes: largestPhotoBytes / 1024 / 1024, pixels: longestPhotoSide })}
            </p>
        );
    }

    return null;
}
