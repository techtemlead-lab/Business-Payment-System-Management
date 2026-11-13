import {useForm} from '@inertiajs/react';
import {FormErrors, Row, money} from '../../../UI';
import {useT} from '../../../../shared/i18n/t';
import {useReasonField} from '../../../../shared/reasonFields';
/** The withdrawal size that gets a "large payout" mark in the activity log. Only the owner changes it. */
export default function ApprovalLimitPanel({limit}:{limit:Row}){
    const translate=useT();
    const t=(key:string,replace:Record<string,string>={})=>translate('accounting',key,replace);
    const form=useForm({amount:limit.amount,version:limit.version,reason:''});
    const changeReason=useReasonField('money.approval_limit');
    return <section className="panel" data-tour="approval-limit">
        <h2>{t('limit_title')}</h2>
        <p className="muted">{t('limit_hint')}</p>
        <div className="metric"><span>{t('limit_current')}</span><strong>{money(limit.amount)}</strong><small>{t('limit_default',{amount:money(limit.default)})}</small></div>
        {limit.can_edit?<form className="action-form" onSubmit={event=>{event.preventDefault();form.post('/commands/operations/accounting/approval-limit',{preserveScroll:true,onSuccess:()=>form.setData('reason','')});}}>
            <label>{t('limit_new_amount')}<input required inputMode="decimal" value={form.data.amount} onChange={event=>form.setData('amount',event.target.value)}/></label>
            {changeReason.shown&&<label>{changeReason.label(t('limit_reason'))}<textarea required={changeReason.required} minLength={changeReason.required?5:undefined} maxLength={1000} rows={2} value={form.data.reason} onChange={event=>form.setData('reason',event.target.value)}/></label>}
            <FormErrors errors={form.errors}/>
            <button className="button dark" disabled={form.processing}>{t('limit_save')}</button>
        </form>:<p className="notice">{t('limit_owner_only')}</p>}
    </section>;
}
