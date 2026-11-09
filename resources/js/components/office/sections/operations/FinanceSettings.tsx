import {useForm, usePage} from '@inertiajs/react';
import {actionAllowed} from '../../../action-permissions';
import {FormErrors, Row, money} from '../../../UI';
import {useT} from '../../../../shared/i18n/t';
import {OperationsFrame,useOperationsPage,field,root,type Action} from '../../operationsShared';
/**
 * Auto-approval for payouts. On by default while the company is small: salary,
 * rank royalty and rank rewards are paid as soon as the rules are met. When the
 * owner turns it off, each item can stay automatic or wait for approval.
 * Car and bike rewards always wait for a person.
 */
function AutoApprovalPanel({setting,root}:{setting:Row,root:string}){
    const translate=useT();
    const {portal,staffActions}=usePage<any>().props;
    const action=root+'/settings/auto-approval';
    const items:string[]=['salary','royalty','rank_incentive'];
    const form=useForm<Row>({enabled:Boolean(setting.enabled),items:{...(setting.items||{})},version:setting.version??0,reason:''});
    const canEdit=!((portal==='manage'||portal==='sadmin')&&!actionAllowed(action,staffActions,'post'));
    const setItem=(item:string,value:boolean)=>form.setData('items',{...form.data.items,[item]:value});
    return <section className="panel auto-approval-panel">
        <div className="panel-heading"><div><h2>{translate('portal','aa_title')}</h2><p>{translate('portal','aa_body')}</p></div></div>
        <form onSubmit={e=>{e.preventDefault();form.post(action,{preserveScroll:true,onSuccess:()=>form.setData('reason','')});}}>
            <label className="aa-switch">
                <input type="checkbox" role="switch" checked={form.data.enabled} disabled={!canEdit} onChange={e=>form.setData('enabled',e.target.checked)}/>
                <span><b>{translate('portal','aa_master')}</b><small>{translate('portal',form.data.enabled?'aa_master_on':'aa_master_off')}</small></span>
            </label>
            <fieldset className="aa-items" disabled={form.data.enabled||!canEdit}>
                <legend>{translate('portal','aa_items_legend')}</legend>
                {items.map(item=><label key={item} className="aa-item">
                    <input type="checkbox" checked={form.data.enabled||Boolean(form.data.items?.[item])} onChange={e=>setItem(item,e.target.checked)}/>
                    <span>{translate('portal',`aa_item_${item}`)}</span>
                    <small>{form.data.enabled||form.data.items?.[item]?translate('portal','aa_state_auto'):translate('portal','aa_state_manual')}</small>
                </label>)}
                <p className="aa-note">{translate('portal','aa_car_bike_note')}</p>
            </fieldset>
            {canEdit&&<>
                <label className="aa-reason">{translate('portal','change_reason_label')}<textarea rows={2} required value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)}/></label>
                {Object.values(form.errors).map(error=><span key={String(error)} className="field-error" role="alert">{String(error)}</span>)}
                <button className="button dark" disabled={form.processing}>{form.processing?translate('portal','saving'):translate('portal','save_changes')}</button>
            </>}
        </form>
    </section>;
}

/**
 * Owner switch: approved ID papers (NID/KYC) needed before a partner's first
 * withdrawal. Off by default. Only owners can change it; others just see it.
 */
function KycFirstWithdrawalPanel({setting,root}:{setting:Row,root:string}){
    const translate=useT();
    const action=root+'/settings/kyc-before-first-withdrawal';
    const form=useForm<Row>({enabled:Boolean(setting.enabled),version:setting.version??0,reason:''});
    const canEdit=Boolean(setting.can_edit);
    return <section className="panel auto-approval-panel">
        <div className="panel-heading"><div><h2>{translate('portal','kyc_wd_title')}</h2><p>{translate('portal','kyc_wd_body')}</p></div></div>
        <form onSubmit={e=>{e.preventDefault();form.post(action,{preserveScroll:true,onSuccess:()=>form.setData('reason','')});}}>
            <label className="aa-switch">
                <input type="checkbox" role="switch" checked={form.data.enabled} disabled={!canEdit} onChange={e=>form.setData('enabled',e.target.checked)}/>
                <span><b>{translate('portal','kyc_wd_switch')}</b><small>{translate('portal',form.data.enabled?'kyc_wd_on':'kyc_wd_off')}</small></span>
            </label>
            {!canEdit&&<p className="aa-note">{translate('portal','kyc_wd_owner_only')}</p>}
            {canEdit&&<>
                <label className="aa-reason">{translate('portal','change_reason_label')}<textarea rows={2} required value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)}/></label>
                {Object.values(form.errors).map(error=><span key={String(error)} className="field-error" role="alert">{String(error)}</span>)}
                <button className="button dark" disabled={form.processing}>{form.processing?translate('portal','saving'):translate('portal','save_changes')}</button>
            </>}
        </form>
    </section>;
}

/**
 * Owner switch: "Full payment required when ordering". On by default. While on,
 * partners and staff can only send an order together with the whole payment and
 * its screenshot. Only owners can change it; others just see it.
 */
/** The withdrawal size that gets a "large payout" mark in the activity log. Only the owner changes it. */
function ApprovalLimitPanel({limit}:{limit:Row}){
    const translate=useT();
    const t=(key:string,replace:Record<string,string>={})=>translate('accounting',key,replace);
    const form=useForm({amount:limit.amount,version:limit.version,reason:''});
    return <section className="panel" data-tour="approval-limit">
        <h2>{t('limit_title')}</h2>
        <p className="muted">{t('limit_hint')}</p>
        <div className="metric"><span>{t('limit_current')}</span><strong>{money(limit.amount)}</strong><small>{t('limit_default',{amount:money(limit.default)})}</small></div>
        {limit.can_edit?<form className="action-form" onSubmit={event=>{event.preventDefault();form.post('/commands/operations/accounting/approval-limit',{preserveScroll:true,onSuccess:()=>form.setData('reason','')});}}>
            <label>{t('limit_new_amount')}<input required inputMode="decimal" value={form.data.amount} onChange={event=>form.setData('amount',event.target.value)}/></label>
            <label>{t('limit_reason')}<textarea required minLength={5} maxLength={1000} rows={2} value={form.data.reason} onChange={event=>form.setData('reason',event.target.value)}/></label>
            <FormErrors errors={form.errors}/>
            <button className="button dark" disabled={form.processing}>{t('limit_save')}</button>
        </form>:<p className="notice">{t('limit_owner_only')}</p>}
    </section>;
}
function FullPaymentOrderingPanel({setting,root}:{setting:Row,root:string}){
    const translate=useT();
    const action=root+'/settings/full-payment-when-ordering';
    const form=useForm<Row>({enabled:Boolean(setting.enabled),version:setting.version??0,reason:''});
    const canEdit=Boolean(setting.can_edit);
    return <section className="panel auto-approval-panel" data-tour="full-payment-when-ordering">
        <div className="panel-heading"><div><h2>{translate('portal','fpo_title')}</h2><p>{translate('portal','fpo_body')}</p></div></div>
        <form onSubmit={e=>{e.preventDefault();form.post(action,{preserveScroll:true,onSuccess:()=>form.setData('reason','')});}}>
            <label className="aa-switch">
                <input type="checkbox" role="switch" checked={form.data.enabled} disabled={!canEdit} onChange={e=>form.setData('enabled',e.target.checked)}/>
                <span><b>{translate('portal','fpo_switch')}</b><small>{translate('portal',form.data.enabled?'fpo_on':'fpo_off')}</small></span>
            </label>
            {!canEdit&&<p className="aa-note">{translate('portal','fpo_owner_only')}</p>}
            {canEdit&&<>
                <label className="aa-reason">{translate('portal','change_reason_label')}<textarea rows={2} required value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)}/></label>
                {Object.values(form.errors).map(error=><span key={String(error)} className="field-error" role="alert">{String(error)}</span>)}
                <button className="button dark" disabled={form.processing}>{form.processing?translate('portal','saving'):translate('portal','save_changes')}</button>
            </>}
        </form>
    </section>;
}
/** Payout switches and limits the owner controls. */
export default function FinanceSettings(props:Row){
    const page=useOperationsPage(props);
    const {section,withdrawalSetting,autoApproval,kycBeforeFirstWithdrawal,fullPaymentWhenOrdering,approvalLimit,translate,reason}=page;
    const forms:Action[]=[];
    if(section==='finance-settings'&&withdrawalSetting)forms.push({title:translate('portal','ops_save_withdrawal_minimum'),action:root+'/settings/withdrawal-minimum',fields:[field('amount',translate('portal','ops_fld_min_withdrawal'),'number'),reason],defaults:{amount:withdrawalSetting.amount,version:withdrawalSetting.version}});
    return <OperationsFrame page={page} forms={forms} top={<>
        {section==='finance-settings'&&autoApproval&&<AutoApprovalPanel setting={autoApproval} root={root}/>}{section==='finance-settings'&&approvalLimit&&<ApprovalLimitPanel limit={approvalLimit}/>}{section==='finance-settings'&&fullPaymentWhenOrdering&&<FullPaymentOrderingPanel setting={fullPaymentWhenOrdering} root={root}/>}{section==='finance-settings'&&kycBeforeFirstWithdrawal&&<KycFirstWithdrawalPanel setting={kycBeforeFirstWithdrawal} root={root}/>}{section==='finance-settings'&&withdrawalSetting&&<section className="panel"><h2>{translate('portal','ops_withdrawal_policy')}</h2><p>{translate('portal','ops_withdrawal_policy_body',{amount:money(withdrawalSetting.amount)})}</p></section>}
    </>}/>;
}
