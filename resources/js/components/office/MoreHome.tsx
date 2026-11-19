import { ChevronRight } from 'lucide-react';
import OfficePage from './OfficePage';
import FeatureBoxes, { type FeatureGroup } from './FeatureBoxes';
import { useT } from '../../shared/i18n/t';
import { OPEN_MANUAL_EVENT } from '../../shared/openManual';

/**
 * The sidebar's "Other modules" page: the pages that are set once or opened rarely, as boxes.
 * The last box opens the user guide and tour, which left the sidebar to save height (CR-15).
 */
export default function MoreHome({ groups }: { groups: FeatureGroup[] }) {
    const translate = useT();
    // Short groups (a partner has two boxes in each) stand side by side, the guide as the last column, so the page fills its width.
    const sideBySide = groups.length > 0 && groups.every((group) => group.links.length <= 3);
    const guide = (
        <section aria-labelledby="feature-group-help">
            <h2 id="feature-group-help">{translate('cr15', 'more_group_help')}</h2>
            <ul>
                <li>
                    <button type="button" className="feature-box" onClick={() => window.dispatchEvent(new Event(OPEN_MANUAL_EVENT))}>
                        <span className="feature-box-title">{translate('cr15', 'more_title_guide')}<ChevronRight size={16} aria-hidden="true" /></span>
                        <span className="feature-box-text">{translate('cr15', 'more_desc_guide')}</span>
                    </button>
                </li>
            </ul>
        </section>
    );

    return (
        <OfficePage title={translate('portal', 'nav_module_more')}>
            <FeatureBoxes groups={groups} columns={4} emptyLine={translate('office_menu', 'more_empty')} sideBySide={sideBySide} extra={sideBySide ? guide : null} />
            {!sideBySide && <div className="feature-boxes feature-boxes-guide">{guide}</div>}
        </OfficePage>
    );
}
