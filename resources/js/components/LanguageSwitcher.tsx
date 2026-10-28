import { router, usePage } from '@inertiajs/react';
import { useT } from '../shared/i18n/t';

export default function LanguageSwitcher({ compact = false }: { compact?: boolean }) {
    const { locale, locales = ['en', 'bn'] } = usePage<any>().props;
    const translate = useT();
    const switchTo = (next: string) => {
        if (next === locale) return;
        router.post('/locale', {
            locale: next,
            return_url: typeof window !== 'undefined' ? window.location.href : undefined,
        }, { preserveScroll: true, preserveState: false });
    };

    return (
        <div className={`language-switcher ${compact ? 'compact' : ''}`} role="group" aria-label={translate('portal', 'language')}>
            {(locales as string[]).map((code) => (
                <button
                    key={code}
                    type="button"
                    className={code === locale ? 'active' : ''}
                    aria-pressed={code === locale}
                    onClick={() => switchTo(code)}
                >
                    {code === 'bn' ? translate('portal', 'bangla') : translate('portal', 'english')}
                </button>
            ))}
        </div>
    );
}
