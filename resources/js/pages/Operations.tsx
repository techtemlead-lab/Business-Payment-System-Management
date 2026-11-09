import React from 'react';
import {Head} from '@inertiajs/react';
import Shell from '../components/Shell';
import {Row} from '../components/UI';
import {useT} from '../shared/i18n/t';
import {OperationsFrame,useOperationsPage} from '../components/office/operationsShared';
import VideoMarketing from '../components/office/sections/operations/VideoMarketing';
import AccessManagement from '../components/office/sections/operations/AccessManagement';
import FinanceSettings from '../components/office/sections/operations/FinanceSettings';
import PolicySchedules from '../components/office/sections/operations/PolicySchedules';
import Payroll from '../components/office/sections/operations/Payroll';
import ReservePayouts from '../components/office/sections/operations/ReservePayouts';
import FieldSales from '../components/office/sections/operations/FieldSales';
import StaffOperations from '../components/office/sections/operations/StaffOperations';
import FinancialReadiness from '../components/office/sections/operations/FinancialReadiness';
import FinancialReports from '../components/office/sections/operations/FinancialReports';
import PaymentAccounts from '../components/office/sections/operations/PaymentAccounts';

const sectionTitleKeys:Record<string,string> = {
    'field-sales':'field_sales',
    'staff-operations':'staff_operations',
    payroll:'payroll',
    'my-payroll':'my_payroll',
    'payment-accounts':'payment_accounts',
    'policy-schedules':'policy_schedules',
    'reserve-payouts':'reserve_payouts',
    'commission-holds':'commission_holds',
    'financial-readiness':'financial_readiness',
    'financial-reports':'financial_reports',
    'finance-settings':'finance_settings',
    'access-management':'access_management',
    'video-marketing':'video_marketing',
};
/** Sections with forms or panels of their own. Any other section is just its tables. */
const sectionComponents:Record<string,React.ComponentType<Row>>={
    'video-marketing':VideoMarketing,
    'access-management':AccessManagement,
    'finance-settings':FinanceSettings,
    'policy-schedules':PolicySchedules,
    'payroll':Payroll,
    'reserve-payouts':ReservePayouts,
    'field-sales':FieldSales,
    'staff-operations':StaffOperations,
    'financial-readiness':FinancialReadiness,
    'financial-reports':FinancialReports,
    'payment-accounts':PaymentAccounts,
};
function TablesOnly(props:Row){return <OperationsFrame page={useOperationsPage(props)}/>;}

export default function Operations({section,tables,hub=null,reportTypes=[],reportGroups=[],paymentAccounts=[],partners=[],partnerSearch='',staff=[],secondApprovers=[],summary={},policy,readiness,deploymentStatus,startupMode,regions=[],products=[],accountHistory=[],withdrawalSetting,autoApproval,kycBeforeFirstWithdrawal,fullPaymentWhenOrdering,approvalLimit,permissionModules=[],staffAccess=[],customRoles=[],rolePresets=[],videoManagedOnWebsite=false,videoSetting={},videoProducts=[]}:Row) {
    const translate=useT();
    const sectionTitle=translate('portal', sectionTitleKeys[section] ?? section);
    const Section=sectionComponents[section]??TablesOnly;
    return <Shell section={hub?.home??section}><Head title={sectionTitle}/><Section section={section} tables={tables} hub={hub} reportTypes={reportTypes} reportGroups={reportGroups} paymentAccounts={paymentAccounts} partners={partners} partnerSearch={partnerSearch} staff={staff} secondApprovers={secondApprovers} summary={summary} policy={policy} readiness={readiness} deploymentStatus={deploymentStatus} startupMode={startupMode} regions={regions} products={products} accountHistory={accountHistory} withdrawalSetting={withdrawalSetting} autoApproval={autoApproval} kycBeforeFirstWithdrawal={kycBeforeFirstWithdrawal} fullPaymentWhenOrdering={fullPaymentWhenOrdering} approvalLimit={approvalLimit} permissionModules={permissionModules} staffAccess={staffAccess} customRoles={customRoles} rolePresets={rolePresets} videoManagedOnWebsite={videoManagedOnWebsite} videoSetting={videoSetting} videoProducts={videoProducts}/></Shell>;
}
