import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Shell from '../components/Shell';
import IncomeByPart, { type IncomeData } from '../components/income/IncomeByPart';
import IncomeCards, { useChooseIncomeType } from '../components/mymoney/IncomeCards';
import Lifetime from '../components/mymoney/Lifetime';
import MoneyBook from '../components/mymoney/MoneyBook';
import MoneyHero from '../components/mymoney/MoneyHero';
import type { IncomeCard, MoneySummary, StatementTable } from '../components/mymoney/types';
import { label } from '../components/UI';
import { useTreasuryViewer } from '../components/treasury/TreasuryTabs';
import { useT } from '../shared/i18n/t';

type Partner = { id: number; name: string; code: string | null; role: string; area: string | null };

/**
 * The office's read-only view of one partner's money: the same body as My Money, without the buttons,
 * or, with the second tab, the same income-by-part view the partner sees.
 */
export default function TreasuryPartner({ partner, summary, cards, table, income, view }: {
    partner: Partner;
    summary: MoneySummary;
    cards: IncomeCard[];
    table: StatementTable | null;
    income: IncomeData | null;
    view: 'money' | 'income';
}) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const selected = table?.state.filters.head ?? '';
    const chooseType = useChooseIncomeType(selected);
    const viewer = useTreasuryViewer();
    const accountsList = `${base}/${partner.role === 'sub_dealer' ? 'sub-dealers' : 'accounts'}?q=${encodeURIComponent(partner.code ?? partner.name)}`;
    const who = [partner.code, label(partner.role, translate), partner.area].filter(Boolean).join(' · ');
    const orderHref = viewer.finance || viewer.reports ? (orderId: string) => `${base}/treasury/orders/${orderId}` : undefined;
    const here = `${base}/treasury/partners/${partner.id}`;

    return (
        <Shell section="treasury">
            <Head title={partner.name} />
            <div className="mm-page">
                <header className="mm-partner-head">
                    <Link className="text-link" href={viewer.finance ? `${base}/treasury/partners` : accountsList}><ArrowLeft size={16} aria-hidden="true" /> {translate('my_money', 'back_to_partners')}</Link>
                    <h2>{partner.name}</h2>
                    {who && <p>{who}</p>}
                </header>
                <MoneyHero summary={summary} />
                <nav className="office-switch" aria-label={partner.name}>
                    <Link href={here} aria-current={view === 'money' ? 'page' : undefined}>{translate('income', 'tab_money')}</Link>
                    <Link href={`${here}?view=income`} aria-current={view === 'income' ? 'page' : undefined}>{translate('income', 'admin_heading')}</Link>
                </nav>
                {view === 'income' && income ? (
                    <IncomeByPart income={income} statementBase={`${here}/income`} orderHref={orderHref} />
                ) : (
                    <>
                        <IncomeCards cards={cards} selected={selected} onChoose={chooseType} />
                        <Lifetime lifetime={summary.lifetime} />
                        {table && <MoneyBook table={table} orderHref={orderHref} />}
                    </>
                )}
            </div>
        </Shell>
    );
}
