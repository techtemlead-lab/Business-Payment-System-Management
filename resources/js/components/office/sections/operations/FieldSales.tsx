import {Link} from '@inertiajs/react';
import {Empty, Row} from '../../../UI';
import {randomUuid} from '../../../../shared/randomUuid';
import {OperationsFrame,useOperationsPage,field,root,type Action,type Field} from '../../operationsShared';
/** Field staff: their areas, orders and new-dealer applications. */
export default function FieldSales(props:Row){
    const page=useOperationsPage(props);
    const {section,partners,staff,regions,products,fullPaymentWhenOrdering,translate,collectionAccounts,capabilities,choice,choose,partnerField,payment}=page;
    const forms:Action[]=[];
    if(section==='field-sales') {
        // "Full payment required when ordering": staff orders carry the whole payment and its screenshot too.
        const orderPayment:Field[]=fullPaymentWhenOrdering?.enabled?[{name:'channel',label:translate('portal','payment_method'),required:true,options:[{value:'Bank',label:translate('portal','pay_channel_bank')},{value:'bKash',label:translate('portal','pay_channel_bkash')},{value:'Nagad',label:translate('portal','pay_channel_nagad')},{value:'Rocket',label:translate('portal','pay_channel_rocket')}]},...(collectionAccounts.length?[{name:'payment_account_id',label:translate('portal','company_collection_account'),required:true,options:collectionAccounts.map((a:Row)=>({value:String(a.id),label:`${a.holder} · ${a.account_number}`,channel:a.channel}))}]:[]),field('reference',translate('portal','transaction_reference')),field('amount',translate('portal','ops_fld_full_order_amount'),'number'),field('proof',translate('portal','payment_proof_label'),'file')]:[];
        if(capabilities.includes('sr_orders'))forms.push({title:translate('portal','ops_submit_partner_order'),action:root+'/staff/orders',fields:[partnerField,choose(products,'items.0.product_id',translate('portal','ops_fld_product')),field('items.0.quantity',translate('portal','ops_fld_quantity'),'number'),...orderPayment,field('note',translate('portal','ops_fld_order_note'),'textarea',false)],defaults:{idempotency_key:randomUuid()}});
        if(capabilities.includes('sr_onboarding'))forms.push({title:translate('portal','ops_submit_dealer_application'),action:root+'/staff/applications',fields:[field('name',translate('portal','ops_fld_full_name')),field('email',translate('portal','ops_fld_email'),'email'),field('phone',translate('portal','ops_fld_phone')),choice('role',translate('portal','ops_fld_account_type'),['dealer','sub_dealer']),field('place_code',translate('portal','ops_fld_place_code')),choose(partners.filter((p:Row)=>p.role==='dealer'),'sponsor_id',translate('portal','ops_fld_sponsor_optional'),false)]});
    }
    return <OperationsFrame page={page} forms={forms} middle={<>
        {section==='field-sales'&&<section className="panel"><h2>{translate('portal','ops_my_territories')}</h2>{regions.length?regions.map((r:Row)=><p key={r.id}>{[r.division,r.district,r.upazila,r.union].filter(Boolean).join(' / ')}</p>):<Empty title={translate('portal','ops_no_territory')} body={translate('portal','ops_no_territory_body')}/>}{capabilities.includes('accounts')&&<p className="muted ops-mt-3">{translate('portal','ops_accounts_partnerships_hint')} <Link className="text-link" href="/partnerships">{translate('portal','partnerships')}</Link>.</p>}</section>}
    </>}/>;
}
