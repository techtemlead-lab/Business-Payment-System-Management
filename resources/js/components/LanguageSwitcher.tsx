import { usePage } from '@inertiajs/react';
import { useT } from '../shared/i18n/t';
import { submitLocaleChange } from '../shared/localeSwitch';

export default function LanguageSwitcher({ compact = false }: { compact?: boolean }) {
    const { locale, locales = ['en', 'bn'] } = usePage<any>().props;
    const translate = useT();
    const switchTo = (next: string) => {
        if (next === locale) {
            return;
        }

        // Full-page POST with instant overlay — avoids frozen Inertia wait then sudden swap.
        submitLocaleChange(next, typeof window !== 'undefined' ? window.location.href : undefined);
    };

    return (
        <div className={`language-switcher ${compact ? 'compact' : ''}`} role="group" aria-label={translate('portal', 'language')}>
            {(locales as string[]).map((code) => (
                <button
                    key={code}
                    type="button"
                    className={code === locale ? 'active' : ''}
                    aria-pressed={code === locale}
                    aria-label={code === 'bn' ? translate('portal', 'bangla') : translate('portal', 'english')}
                    onClick={() => switchTo(code)}
                >
                    <span className="language-full">{code === 'bn' ? translate('portal', 'bangla') : translate('portal', 'english')}</span>
                    <span className="language-short" aria-hidden="true">{code === 'bn' ? 'বাং' : 'EN'}</span>
                </button>
            ))}
        </div>
    );
}
