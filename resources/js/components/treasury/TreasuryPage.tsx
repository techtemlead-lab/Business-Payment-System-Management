import type { ReactNode } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Shell from '../Shell';
import TreasuryTabs from './TreasuryTabs';

/**
 * The one shape of every Treasury page: a title, the tab strip on the five main places,
 * then the page's own row of cards and its one table.
 */
export default function TreasuryPage({ title, subtitle, back, action, showTabs = false, children }: {
    title: string;
    subtitle?: string;
    back?: { href: string; label: string };
    action?: ReactNode;
    showTabs?: boolean;
    children: ReactNode;
}) {
    return (
        <Shell section="treasury">
            <Head title={title} />
            <div className="tsy-page">
                {back && (
                    <Link className="tsy-back" href={back.href}>
                        <ArrowLeft size={16} aria-hidden="true" /> {back.label}
                    </Link>
                )}
                <header className="tsy-head">
                    <div className="tsy-head-text">
                        <h1>{title}</h1>
                        {subtitle && <p>{subtitle}</p>}
                    </div>
                    {action && <div className="tsy-head-action">{action}</div>}
                </header>
                {showTabs && <TreasuryTabs />}
                {children}
            </div>
        </Shell>
    );
}
