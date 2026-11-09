import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Shell from '../components/Shell';
import Downloads from '../components/mymoney/Downloads';
import IncomeCards, { useChooseIncomeType } from '../components/mymoney/IncomeCards';
import Lifetime from '../components/mymoney/Lifetime';
import MoneyBook from '../components/mymoney/MoneyBook';
import MoneyHero from '../components/mymoney/MoneyHero';
import WithdrawalRequests from '../components/mymoney/WithdrawalRequests';
import WithdrawSheet from '../components/mymoney/WithdrawSheet';
import type { Certificates, IncomeCard, MoneySummary, StatementTable, WithdrawalRequest, WithdrawInfo } from '../components/mymoney/types';
import { useT } from '../shared/i18n/t';

/** All of a partner's money on one page: what they can use, where it came from, every movement. */
export default function MyMoney({ summary, cards, table, withdrawal_requests: withdrawalRequests, withdraw, certificates }: {
    summary: MoneySummary;
    cards: IncomeCard[];
    table: StatementTable;
    withdrawal_requests: WithdrawalRequest[];
    withdraw: WithdrawInfo;
    certificates: Certificates;
}) {
    const translate = useT();
    const { portalBase: base, financeActive } = usePage<any>().props;
    const [sheetOpen, setSheetOpen] = useState(false);
    const selected = table.state.filters.type ?? '';
    const chooseType = useChooseIncomeType(selected);
    const orderHref = financeActive && Number(summary.available) > 0
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
                <WithdrawalRequests requests={withdrawalRequests} />
                <IncomeCards cards={cards} selected={selected} onChoose={chooseType} />
                <Lifetime lifetime={summary.lifetime} />
                <MoneyBook table={table} actions={<Downloads certificates={certificates} view={table.state} />} />
            </div>
            {sheetOpen && <WithdrawSheet available={summary.available} withdraw={withdraw} onClose={() => setSheetOpen(false)} />}
        </Shell>
    );
}
