import { useState } from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Shell from '../components/Shell';
import HeldCommissionList from '../components/mymoney/HeldCommission';
import Downloads from '../components/mymoney/Downloads';
import IncomeCards, { useChooseIncomeType } from '../components/mymoney/IncomeCards';
import Lifetime from '../components/mymoney/Lifetime';
import MoneyBook from '../components/mymoney/MoneyBook';
import MoneyHero from '../components/mymoney/MoneyHero';
import NotEarnedAreaList from '../components/mymoney/NotEarnedArea';
import PendingMoneyList from '../components/mymoney/PendingMoney';
import CompanyBenefitsList from '../components/mymoney/CompanyBenefits';
import SalaryNotes from '../components/mymoney/SalaryNotes';
import WithdrawalRequests from '../components/mymoney/WithdrawalRequests';
import WithdrawSheet from '../components/mymoney/WithdrawSheet';
import type { CompanyBenefits, Certificates, HeldCommission, IncomeCard, MoneySummary, NotEarnedArea, PendingMoney, ReserveReceipt, SalaryNote, StatementTable, WithdrawalRequest, WithdrawInfo } from '../components/mymoney/types';
import { useT } from '../shared/i18n/t';

/** All of a partner's money on one page: what they can use, where it came from, every movement. */
export default function MyMoney({ summary, cards, table, withdrawal_requests: withdrawalRequests, not_earned_area: notEarnedArea, held_commission: heldCommission, pending, salary_notes: salaryNotes, withdraw, certificates, reserve_receipts: reserveReceipts, benefits }: {
    summary: MoneySummary;
    cards: IncomeCard[];
    table: StatementTable;
    withdrawal_requests: WithdrawalRequest[];
    not_earned_area: NotEarnedArea[];
    held_commission: HeldCommission;
    pending: PendingMoney;
    salary_notes: SalaryNote[];
    withdraw: WithdrawInfo;
    certificates: Certificates;
    reserve_receipts: ReserveReceipt[];
    benefits?: CompanyBenefits | null;
}) {
    const translate = useT();
    const { portalBase: base, financeActive } = usePage<any>().props;
    // ?withdraw=1 (dashboard button, menu search) opens the withdrawal sheet straight away.
    const [sheetOpen, setSheetOpen] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('withdraw') === '1');
    const selected = table.state.filters.head ?? '';
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
                    pending={pending}
                    actions={(
                        <>
                            <button type="button" className="button dark" onClick={() => setSheetOpen(true)}>{translate('my_money', 'withdraw')}</button>
                            <Link className="button" href={orderHref}>{translate('my_money', 'order')}</Link>
                            <Link className="button" href={`${base}/my-income`}>{translate('cr21_income', 'income_button')}</Link>
                            <Downloads certificates={certificates} receipts={reserveReceipts} view={table.state} />
                        </>
                    )}
                />
                <WithdrawalRequests requests={withdrawalRequests} />
                <PendingMoneyList pending={pending} />
                <CompanyBenefitsList benefits={benefits} />
                <HeldCommissionList held={heldCommission} />
                <NotEarnedAreaList rows={notEarnedArea} />
                <SalaryNotes notes={salaryNotes} />
                <IncomeCards cards={cards} selected={selected} onChoose={chooseType} />
                <Lifetime lifetime={summary.lifetime} />
                <MoneyBook table={table} />
            </div>
            {sheetOpen && <WithdrawSheet available={summary.available} withdraw={withdraw} onClose={() => setSheetOpen(false)} />}
        </Shell>
    );
}
