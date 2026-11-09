import type { ReactNode } from 'react';
import type { Row } from '../../UI';
import type { OfficeHub } from '../../OfficeHubTabs';
import { useT } from '../../../shared/i18n/t';
import OfficePage from '../OfficePage';

/**
 * The page frame of the Dealers and Sub-dealers module: one title and the tab strip.
 * The dealer list, sub-dealer list, referral tree, area dealers and ranks all sit inside it.
 */
export default function PartnerFrame({ data, action, children }: { data: Row; action?: ReactNode; children: ReactNode }) {
    const translate = useT();
    const hub = data.hub as OfficeHub | undefined;

    return (
        <OfficePage title={hub?.label ?? translate('office_partners', 'title')} hub={hub} action={action}>
            {children}
        </OfficePage>
    );
}
