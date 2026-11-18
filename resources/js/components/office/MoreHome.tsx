import OfficePage from './OfficePage';
import FeatureBoxes, { type FeatureGroup } from './FeatureBoxes';
import { useT } from '../../shared/i18n/t';

/** The sidebar's "More tools" page: the pages that are set once or opened rarely, as boxes. */
export default function MoreHome({ groups }: { groups: FeatureGroup[] }) {
    const translate = useT();

    return (
        <OfficePage title={translate('portal', 'nav_module_more')}>
            <FeatureBoxes groups={groups} columns={4} emptyLine={translate('office_menu', 'more_empty')} />
        </OfficePage>
    );
}
