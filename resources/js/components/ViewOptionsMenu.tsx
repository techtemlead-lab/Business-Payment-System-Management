import { useId } from 'react';
import { Compass, SlidersHorizontal } from 'lucide-react';
import { useT } from '../shared/i18n/t';
import { usePopover } from './ActionMenu';
import LanguageSwitcher from './LanguageSwitcher';
import TextSizeToggle from './TextSizeToggle';
import ThemeToggle from './ThemeToggle';

/**
 * One top-bar button for the display choices of the office panels (language, writing size, night mode and the
 * guided tour), so the bar keeps one tidy row on a computer. Phones keep the same choices in the account menu.
 */
export default function ViewOptionsMenu({ onOpenGuide }: { onOpenGuide: () => void }) {
    const translate = useT();
    const { open, setOpen, box } = usePopover();
    const panelId = useId();
    const title = translate('office_shell', 'view_options');

    return (
        <div className="view-options" ref={box}>
            <button
                type="button"
                className="icon-button view-options-trigger"
                aria-haspopup="true"
                aria-expanded={open}
                aria-controls={open ? panelId : undefined}
                aria-label={title}
                title={title}
                data-tooltip={title}
                onClick={() => setOpen((value) => !value)}
            >
                <SlidersHorizontal size={19} />
            </button>
            {open && (
                <div id={panelId} className="view-options-panel" role="group" aria-label={title}>
                    <div className="view-options-row">
                        <span className="view-options-name">{translate('portal', 'language')}</span>
                        <LanguageSwitcher compact />
                    </div>
                    <div className="view-options-row">
                        <span className="view-options-name">{translate('office_shell', 'view_options_text')}</span>
                        <TextSizeToggle />
                    </div>
                    <div className="view-options-row is-single">
                        <ThemeToggle />
                    </div>
                    <button type="button" className="view-options-guide" onClick={() => { setOpen(false); onOpenGuide(); }}>
                        <Compass size={16} aria-hidden="true" />
                        <span>{translate('portal', 'tour_guide_launcher')}</span>
                    </button>
                </div>
            )}
        </div>
    );
}
