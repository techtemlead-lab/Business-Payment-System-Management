import OfficePage from './OfficePage';
import FeatureBoxes, { type FeatureGroup } from './FeatureBoxes';
import { useT } from '../../shared/i18n/t';

export type SettingsGroup = FeatureGroup;

/** The Settings page: boxes grouped by kind, each with a name and one line; only pages this person can open. */
export default function SettingsHome({ groups }: { groups: SettingsGroup[] }) {
    const translate = useT();

    return (
        <OfficePage title={translate('portal', 'nav_module_settings')}>
            <FeatureBoxes groups={groups} columns={3} emptyLine={translate('office_menu', 'settings_empty')} />
        </OfficePage>
    );
}
