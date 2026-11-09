import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import OfficePage from './OfficePage';
import { Empty } from '../UI';
import { useT } from '../../shared/i18n/t';
import '../../../css/office-settings-home.css';

export type SettingsGroup = {
    key: string;
    label: string;
    links: Array<{ key: string; label: string; href: string }>;
};

/** The Settings page: links only, grouped, and only to pages this person can open. */
export default function SettingsHome({ groups }: { groups: SettingsGroup[] }) {
    const translate = useT();
    const base = String(usePage<any>().props.portalBase ?? '');

    return (
        <OfficePage title={translate('portal', 'nav_module_settings')}>
            {groups.length === 0 ? <Empty line={translate('office_menu', 'settings_empty')} /> : (
                <div className="office-settings">
                    {groups.map((group) => (
                        <section key={group.key} className="office-settings-group" aria-labelledby={`settings-group-${group.key}`}>
                            <h2 id={`settings-group-${group.key}`}>{group.label}</h2>
                            <ul>
                                {group.links.map((link) => (
                                    <li key={link.key}>
                                        <Link href={`${base}${link.href}`}>
                                            <span>{link.label}</span>
                                            <ChevronRight size={16} aria-hidden="true" />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    ))}
                </div>
            )}
        </OfficePage>
    );
}
