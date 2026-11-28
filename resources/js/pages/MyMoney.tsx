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
import WithdrawalHistory from '../components/mymoney/WithdrawalHistory';
import WithdrawalRequests from '../components/mymoney/WithdrawalRequests';
import WithdrawSheet from '../components/mymoney/WithdrawSheet';
import PayoutAccounts from '../components/PayoutAccounts';
import type { CompanyBenefits, Certificates, HeldCommission, IncomeCard, MoneySummary, NotEarnedArea, PendingMoney, ReserveReceipt, SalaryNote, StatementTable, WithdrawalHistoryData, WithdrawalRequest, WithdrawInfo } from '../components/mymoney/types';
import { useT } from '../shared/i18n/t';
import { useIsPhone } from '../shared/useIsPhone';
import { money } from '../components/UI';

/** All of a partner's money on one page: what they can use, where it came from, every movement. */
export default function MyMoney({ summary, cards, table, withdrawal_requests: withdrawalRequests, withdrawal_history: withdrawalHistory, not_earned_area: notEarnedArea, held_commission: heldCommission, pending, salary_notes: salaryNotes, withdraw, certificates, reserve_receipts: reserveReceipts, benefits }: {
    summary: MoneySummary;
    cards: IncomeCard[];
    table: StatementTable;
    withdrawal_requests: WithdrawalRequest[];
    withdrawal_history?: WithdrawalHistoryData | null;
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
    const isPhone = useIsPhone();
    const { portalBase: base, financeActive } = usePage<any>().props;
    // ?withdraw=1 (dashboard button, menu search) opens the withdrawal sheet straight away.
    const [sheetOpen, setSheetOpen] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('withdraw') === '1');
    const selected = table.state.filters.head ?? '';
    const chooseType = useChooseIncomeType(selected);
    const orderHref = financeActive && Number(summary.available) > 0
        ? `${base}/purchase?pay=account&budget=${summary.available}`
        : `${base}/purchase`;

    const waitingTotal = Number(pending?.total ?? 0);
    // Everything that is not in the balance yet sits in one folded block, so the page opens on the balance and the book.
    const hasWaiting = waitingTotal > 0 || (pending?.lines ?? []).length > 0 || (pending?.paid ?? []).length > 0 || (pending?.rewards ?? []).length > 0 || (pending?.month_end ?? []).length > 0
        || Number(heldCommission?.total ?? 0) > 0 || notEarnedArea.length > 0 || salaryNotes.length > 0 || (benefits?.lines ?? []).length > 0;
    const waitingLists = (
        <>
            <PendingMoneyList pending={pending} />
            <CompanyBenefitsList benefits={benefits} />
            <HeldCommissionList held={heldCommission} />
            <NotEarnedAreaList rows={notEarnedArea} />
            <SalaryNotes notes={salaryNotes} />
        </>
    );

    return (
        <Shell section="my-money">
            <Head title={translate('my_money', 'title')} />
            <div className="mm-page">
                <MoneyHero
                    summary={summary}
                    pending={pending}
                    incomeHref={`${base}/my-income`}
                    actions={(
                        <>
                            <button type="button" className="button dark" onClick={() => setSheetOpen(true)}>{translate('my_money', 'withdraw')}</button>
                            <Link className="button" href={orderHref}>{translate('my_money', 'order')}</Link>
                            <Link className="button" href={`${base}/my-income`}>{translate('cr21_income', 'income_button')}</Link>
                            <Downloads certificates={certificates} receipts={reserveReceipts} view={table.state} />
                        </>
                    )}
                />
                {withdrawalHistory ? <WithdrawalHistory data={withdrawalHistory} allowCancel /> : <WithdrawalRequests requests={withdrawalRequests} />}
                <section className="mm-accounts-card" data-testid="my-money-payout-accounts">
                    <PayoutAccounts accounts={withdraw.saved_accounts ?? []} />
                </section>
                {hasWaiting && (
                    <details key={isPhone ? 'phone' : 'wide'} className="mm-fold" open={!isPhone} data-testid="my-money-waiting">
                        <summary>
                            <span>{translate('my_money', 'waiting_group_title')}</span>
                            {waitingTotal > 0 && <strong>{money(pending?.total)}</strong>}
                        </summary>
                        <div className="mm-fold-body">{waitingLists}</div>
                    </details>
                )}
                <IncomeCards cards={cards} selected={selected} onChoose={chooseType} />
                <Lifetime lifetime={summary.lifetime} />
                <MoneyBook table={table} />
            </div>
            {sheetOpen && <WithdrawSheet available={summary.available} withdraw={withdraw} onClose={() => setSheetOpen(false)} />}
        </Shell>
    );
}
