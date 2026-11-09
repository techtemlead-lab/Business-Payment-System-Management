import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import Shell from '../components/Shell';
import IncomeCards, { useChooseIncomeType } from '../components/mymoney/IncomeCards';
import Lifetime from '../components/mymoney/Lifetime';
import MoneyBook from '../components/mymoney/MoneyBook';
import MoneyHero from '../components/mymoney/MoneyHero';
import type { IncomeCard, MoneySummary, StatementTable } from '../components/mymoney/types';
import { label } from '../components/UI';
import { useT } from '../shared/i18n/t';

type Partner = { id: number; name: string; code: string | null; role: string; area: string | null };

/** The office's read-only view of one partner's money: the same body as My Money, without the buttons. */
export default function TreasuryPartner({ partner, summary, cards, table }: {
    partner: Partner;
    summary: MoneySummary;
    cards: IncomeCard[];
    table: StatementTable;
}) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const selected = table.state.filters.type ?? '';
    const chooseType = useChooseIncomeType(selected);
    const who = [partner.code, label(partner.role, translate), partner.area].filter(Boolean).join(' · ');

    return (
        <Shell section="treasury">
            <Head title={partner.name} />
            <div className="mm-page">
                <header className="mm-partner-head">
                    <Link className="text-link" href={`${base}/treasury/partners`}><ArrowLeft size={16} aria-hidden="true" /> {translate('my_money', 'back_to_partners')}</Link>
                    <h1>{partner.name}</h1>
                    {who && <p>{who}</p>}
                </header>
                <MoneyHero summary={summary} />
                <IncomeCards cards={cards} selected={selected} onChoose={chooseType} />
                <Lifetime lifetime={summary.lifetime} />
                <MoneyBook table={table} orderHref={(orderId) => `${base}/treasury/orders/${orderId}`} />
            </div>
        </Shell>
    );
}
