import type { ReactNode } from 'react';
import { Head } from '@inertiajs/react';
import Shell from '../Shell';
import OfficePage from '../office/OfficePage';
import { useTreasuryHub } from './TreasuryTabs';

/** A Treasury page: the shared office page shape, with the five Treasury places as its tab strip. */
export default function TreasuryPage({ title, subtitle, back, action, showTabs = false, children }: {
    title: string;
    subtitle?: string;
    back?: { href: string; label: string };
    action?: ReactNode;
    showTabs?: boolean;
    children: ReactNode;
}) {
    const hub = useTreasuryHub();

    return (
        <Shell section="treasury">
            <Head title={title} />
            <OfficePage title={title} subtitle={subtitle} back={back} action={action} hub={showTabs ? hub : null}>
                {children}
            </OfficePage>
        </Shell>
    );
}
