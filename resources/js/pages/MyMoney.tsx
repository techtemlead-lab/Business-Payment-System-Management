import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Shell from '../components/Shell';
import Downloads from '../components/mymoney/Downloads';
import IncomeCards, { useChooseIncomeType } from '../components/mymoney/IncomeCards';
import Lifetime from '../components/mymoney/Lifetime';
import MoneyBook from '../components/mymoney/MoneyBook';
import MoneyHero from '../components/mymoney/MoneyHero';
import WithdrawSheet from '../components/mymoney/WithdrawSheet';
import type { Certificates, IncomeCard, MoneySummary, StatementTable, WithdrawInfo } from '../components/mymoney/types';
import { useT } from '../shared/i18n/t';

/** The statement files take a date range; a period chip or date choice on the table carries over. */
function statementQuery(table: StatementTable): string {
    const { from, to } = table.state;
    if (!from || !to) {
        return '';
    }

    return new URLSearchParams({ from, to }).toString();
}

/** All of a partner's money on one page: what they can use, where it came from, every movement. */
export default function MyMoney({ summary, cards, table, withdraw, certificates }: {
    summary: MoneySummary;
    cards: IncomeCard[];
    table: StatementTable;
    withdraw: WithdrawInfo;
    certificates: Certificates;
}) {
    const translate = useT();
    const { portalBase: base } = usePage<any>().props;
    const [sheetOpen, setSheetOpen] = useState(false);
    const selected = table.state.filters.type ?? '';
    const chooseType = useChooseIncomeType(selected);
    const orderHref = Number(summary.available) > 0
        ? `${base}/purchase?pay=account&budget=${summary.available}`
        : `${base}/purchase`;

    return (
        <Shell section="my-money">
            <Head title={translate('my_money', 'title')} />
            <div className="mm-page">
                <MoneyHero
                    summary={summary}
                    actions={(
                        <>
                            <button type="button" className="button dark" onClick={() => setSheetOpen(true)}>{translate('my_money', 'withdraw')}</button>
                            <Link className="button" href={orderHref}>{translate('my_money', 'order')}</Link>
                        </>
                    )}
                />
                <IncomeCards cards={cards} selected={selected} onChoose={chooseType} />
                <Lifetime lifetime={summary.lifetime} />
                <MoneyBook table={table} actions={<Downloads certificates={certificates} statementQuery={statementQuery(table)} />} />
            </div>
            {sheetOpen && <WithdrawSheet available={summary.available} withdraw={withdraw} onClose={() => setSheetOpen(false)} />}
        </Shell>
    );
}
