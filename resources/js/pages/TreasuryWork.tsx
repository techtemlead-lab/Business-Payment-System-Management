import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { number } from '../components/UI';
import type { MoneyTableData } from '../components/MoneyTable';
import TreasuryPage from '../components/treasury/TreasuryPage';
import SpendApprovals, { type SpendApproval } from '../components/treasury/SpendApprovals';
import { BudgetsTable, HeadsTable, type HeadBudget } from '../components/treasury/HeadsAndBudgets';
import type { ExpenseHead } from '../components/treasury/FundSpendForm';
import { useT } from '../shared/i18n/t';

type WorkTile = { key: string; label: string; href: string; count: number };
type WorkTab = 'waiting' | 'heads' | 'budgets';

const SWITCHES: { key: WorkTab; href: string; word: string }[] = [
    { key: 'waiting', href: '/treasury/work', word: 'work_waiting' },
    { key: 'heads', href: '/treasury/work?tab=heads', word: 'work_heads' },
    { key: 'budgets', href: '/treasury/work?tab=budgets', word: 'work_budgets' },
];

/** Everything waiting for a person, in one list; and the spending heads and budgets. */
export default function TreasuryWork({ tab, tiles, approvals_table: approvals, heads_table: headsTable, budgets_table: budgetsTable, heads, budget_month: budgetMonth }: {
    tab: WorkTab;
    tiles: WorkTile[];
    approvals_table: MoneyTableData<SpendApproval> | null;
    heads_table: MoneyTableData<ExpenseHead> | null;
    budgets_table: MoneyTableData<HeadBudget> | null;
    heads: ExpenseHead[];
    budget_month: string;
}) {
    const translate = useT();

    return (
        <TreasuryPage title={translate('treasury', 'title')} showTabs>
            <div className="tsy-switch" role="group" aria-label={translate('treasury', 'tab_work')}>
                {SWITCHES.map((item) => (
                    <Link key={item.key} href={item.href} preserveScroll className={tab === item.key ? 'is-active' : undefined} aria-current={tab === item.key ? 'true' : undefined}>{translate('treasury', item.word)}</Link>
                ))}
            </div>
            {tab === 'heads' && headsTable && <HeadsTable table={headsTable} />}
            {tab === 'budgets' && budgetsTable && <BudgetsTable table={budgetsTable} heads={heads} month={budgetMonth} />}
            {tab === 'waiting' && approvals && (
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
                    <SpendApprovals table={approvals} />
                </>
            )}
        </TreasuryPage>
    );
}
