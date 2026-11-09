import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { number } from '../components/UI';
import TreasuryPage from '../components/treasury/TreasuryPage';
import SpendApprovals, { type SpendApproval } from '../components/treasury/SpendApprovals';
import HeadsAndBudgets, { type HeadBudget } from '../components/treasury/HeadsAndBudgets';
import type { ExpenseHead } from '../components/treasury/FundSpendForm';
import { useT } from '../shared/i18n/t';

type WorkTile = { key: string; label: string; href: string; count: number };

/** Everything waiting for a person, in one list; and the spending heads and budgets. */
export default function TreasuryWork({ tiles, approvals, heads, budgets, budget_month: budgetMonth }: { tiles: WorkTile[]; approvals: SpendApproval[]; heads: ExpenseHead[]; budgets: HeadBudget[]; budget_month: string }) {
    const translate = useT();
    const { url } = usePage();
    const showsHeads = new URLSearchParams(url.split('?')[1] ?? '').get('tab') === 'heads';

    return (
        <TreasuryPage title={translate('treasury', 'title')} showTabs>
            <div className="office-switch" role="group" aria-label={translate('treasury', 'tab_work')}>
                <Link href="/treasury/work" preserveState preserveScroll className={showsHeads ? undefined : 'is-active'} aria-current={showsHeads ? undefined : 'true'}>{translate('treasury', 'work_waiting')}</Link>
                <Link href="/treasury/work?tab=heads" preserveState preserveScroll className={showsHeads ? 'is-active' : undefined} aria-current={showsHeads ? 'true' : undefined}>{translate('treasury', 'work_heads')}</Link>
            </div>
            {showsHeads ? <HeadsAndBudgets heads={heads} budgets={budgets} month={budgetMonth} /> : (
                <>
                    <nav className="tsy-work" aria-label={translate('treasury', 'work_waiting')}>
                        {tiles.map((tile) => (
                            <Link key={tile.key} href={tile.href} className="tsy-work-row">
                                <span>{tile.label}</span>
                                {tile.count > 0 && <b>{number(tile.count)}</b>}
                                <ChevronRight size={16} aria-hidden="true" />
                            </Link>
                        ))}
                    </nav>
                    <SpendApprovals approvals={approvals} />
                </>
            )}
        </TreasuryPage>
    );
}
