import type { ReactNode } from 'react';
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import OfficeHubTabs, { type OfficeHub } from '../OfficeHubTabs';

/**
 * The one shape of every office page: a title with at most one main button, the tab strip,
 * then the page's own row of cards and its one table. It sits inside Shell and hides Shell's
 * heading, so a fund or an order can be the title. A subtitle states a fact, never how the page works.
 */
export default function OfficePage({ title, subtitle, back, action, hub, children }: {
    title: string;
    subtitle?: string;
    back?: { href: string; label: string };
    action?: ReactNode;
    hub?: OfficeHub | null;
    children: ReactNode;
}) {
    return (
        <div className="office-page">
            {back && (
                <Link className="office-back" href={back.href}>
                    <ArrowLeft size={16} aria-hidden="true" /> {back.label}
                </Link>
            )}
            <header className="office-head">
                <div className="office-head-text">
                    <h1>{title}</h1>
                    {subtitle && <p>{subtitle}</p>}
                </div>
                {action && <div className="office-head-action">{action}</div>}
            </header>
            {hub && <OfficeHubTabs hub={hub} />}
            {children}
        </div>
    );
}
