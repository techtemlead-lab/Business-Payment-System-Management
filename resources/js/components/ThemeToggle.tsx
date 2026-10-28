import { Moon, Sun } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useT } from '../shared/i18n/t';
import {
    applyColorScheme,
    persistColorScheme,
    resolveColorScheme,
    type ColorScheme,
} from '../shared/theme';

export default function ThemeToggle({ compact = false }: { compact?: boolean }) {
    const translate = useT();
    const [scheme, setScheme] = useState<ColorScheme>('light');

    useEffect(() => {
        const current = resolveColorScheme();
        applyColorScheme(current);
        setScheme(current);
    }, []);

    const isDark = scheme === 'dark';
    const label = isDark ? translate('portal', 'day_mode') : translate('portal', 'night_mode');

    return (
        <button
            type="button"
            className={`theme-toggle${compact ? ' compact' : ''}`}
            aria-label={label}
            title={label}
            aria-pressed={isDark}
            data-theme-state={scheme}
            onClick={() => {
                const next: ColorScheme = scheme === 'dark' ? 'light' : 'dark';
                persistColorScheme(next);
                setScheme(next);
            }}
        >
            <span className="theme-icon-day" aria-hidden="true"><Sun size={16} /></span>
            <span className="theme-icon-night" aria-hidden="true"><Moon size={16} /></span>
            {!compact && <span className="theme-toggle-label">{label}</span>}
        </button>
    );
}
