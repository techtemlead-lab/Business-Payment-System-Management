import {useForm} from '@inertiajs/react';
import {FormErrors, Row, money} from '../../../UI';
import {useT} from '../../../../shared/i18n/t';
import {useReasonField} from '../../../../shared/reasonFields';
import {AmountWords} from '../../../payouts/WithdrawalFacts';

type Limit = {amount: string; version: number; default: string};
const kinds = ['withdrawal', 'refund', 'fund_spend'] as const;

/**
 * CR-16: the money sizes at or above which a withdrawal, a refund or a fund spend waits for the
 * owner. Below them a staff member with the permission finishes the work. Only the owner changes them.
 */
export default function ApprovalLimitPanel({limit}:{limit:Row}){
    const translate=useT();
    const t=(key:string,replace:Record<string,string>={})=>translate('accounting',key,replace);
    const limits:Record<string,Limit>=limit.limits??{withdrawal:{amount:limit.amount,version:limit.version,default:limit.default}};
    return <section className="panel" data-tour="approval-limit">
        <h2>{t('owner_limits_title')}</h2>
        <p className="muted">{t('owner_limits_hint')}</p>
        {kinds.filter(kind=>limits[kind]).map(kind=><OwnerLimit key={kind} kind={kind} limit={limits[kind]} canEdit={Boolean(limit.can_edit)}/>)}
        {!limit.can_edit&&<p className="notice">{t('limit_owner_only')}</p>}
    </section>;
}

function OwnerLimit({kind,limit,canEdit}:{kind:string;limit:Limit;canEdit:boolean}){
    const translate=useT();
    const t=(key:string,replace:Record<string,string>={})=>translate('accounting',key,replace);
    const form=useForm({kind,amount:limit.amount,version:limit.version,reason:''});
    const changeReason=useReasonField('money.approval_limit');
    return <div className="action-form" data-testid={`owner-limit-${kind}`}>
        <h3>{t(`owner_limit_${kind}_title`)}</h3>
        <p className="muted">{t(`owner_limit_${kind}_hint`)}</p>
        <div className="metric"><span>{t('limit_current')}</span><strong>{money(limit.amount)}</strong><AmountWords amount={limit.amount}/><small>{t('limit_default',{amount:money(limit.default)})}</small></div>
        {canEdit&&<form onSubmit={event=>{event.preventDefault();form.post('/commands/operations/accounting/approval-limit',{preserveScroll:true,onSuccess:()=>form.setData('reason','')});}}>
            <label>{t('limit_new_amount')}<input required inputMode="decimal" value={form.data.amount} onChange={event=>form.setData('amount',event.target.value)}/></label>
            <AmountWords amount={form.data.amount}/>
            {changeReason.shown&&<label>{changeReason.label(t('limit_reason'))}<textarea required={changeReason.required} minLength={changeReason.required?5:undefined} maxLength={1000} rows={2} value={form.data.reason} onChange={event=>form.setData('reason',event.target.value)}/></label>}
            <FormErrors errors={form.errors}/>
            <button className="button dark" disabled={form.processing}>{t('limit_save')}</button>
        </form>}
    </div>;
}
