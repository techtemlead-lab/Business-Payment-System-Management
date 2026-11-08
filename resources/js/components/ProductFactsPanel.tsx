import {useState} from 'react';
import {router, usePage} from '@inertiajs/react';
import {FormErrors, type Row} from './UI';
import {useT} from '../shared/i18n/t';

export type ProductFactField = {name:string;max_length:number;multiline:boolean};
export type ProductConcernOption = {id:number;name_bn:string;name_en:string};

/** Saved values for the form, blank for a new product. */
export function productFactFormValues(fields:ProductFactField[],product:Row|null):Row{
 const values:Row={registration_number:product?.registration_number??'',concern_ids:(product?.concern_ids??[]).map(String),sync_concerns:'1'};
 for(const field of fields){
  values[`${field.name}_bn`]=product?.[`${field.name}_bn`]??'';
  values[`${field.name}_en`]=product?.[`${field.name}_en`]??'';
 }
 return values;
}

/** Form keys the product editor sends for these facts. */
export function productFactFormKeys(fields:ProductFactField[]):string[]{
 return ['registration_number','concern_ids','sync_concerns',...fields.flatMap(field=>[`${field.name}_bn`,`${field.name}_en`])];
}

const FIELD_HINTS:Record<string,string>={product_system:'hint_product_system',quality_points:'hint_quality_points'};

function ConcernTagManager({concerns,canManage}:{concerns:ProductConcernOption[];canManage:boolean}){
 const translate=useT();
 const {errors}=usePage<any>().props;
 const [editingId,setEditingId]=useState<number|null>(null);
 const [nameBn,setNameBn]=useState('');
 const [nameEn,setNameEn]=useState('');
 const [processing,setProcessing]=useState(false);
 if(!canManage){return null;}
 const finish={preserveState:true,preserveScroll:true,onFinish:()=>setProcessing(false)};
 const clear=()=>{setEditingId(null);setNameBn('');setNameEn('');};
 const save=()=>{
  setProcessing(true);
  const onSuccess=clear;
  if(editingId){router.patch(`/commands/product-concerns/${editingId}`,{name_bn:nameBn,name_en:nameEn},{...finish,onSuccess});return;}
  router.post('/commands/product-concerns',{name_bn:nameBn,name_en:nameEn},{...finish,onSuccess});
 };
 const edit=(concern:ProductConcernOption)=>{setEditingId(concern.id);setNameBn(concern.name_bn);setNameEn(concern.name_en);};
 const remove=(concern:ProductConcernOption)=>{
  if(window.confirm(translate('product_facts','concern_delete_confirm',{name:concern.name_en}))){
   router.delete(`/commands/product-concerns/${concern.id}`,finish);
   if(editingId===concern.id){clear();}
  }
 };
 const concernErrors={name_bn:errors?.name_bn,name_en:errors?.name_en};
 return <div>
  {concerns.length>0&&<ul className="pe-concern-list">{concerns.map(concern=><li key={concern.id}><span>{concern.name_bn} · {concern.name_en}</span><span className="ed-row is-tight"><button type="button" className="text-link" onClick={()=>edit(concern)}>{translate('product_facts','concern_edit')}</button><button type="button" className="text-link" onClick={()=>remove(concern)}>{translate('product_facts','concern_delete')}</button></span></li>)}</ul>}
  <div className="form-grid">
   <label>{translate('product_facts','concern_name_bn')}<input maxLength={80} value={nameBn} onChange={e=>setNameBn(e.target.value)}/></label>
   <label>{translate('product_facts','concern_name_en')}<input maxLength={80} value={nameEn} onChange={e=>setNameEn(e.target.value)}/></label>
  </div>
  <FormErrors errors={concernErrors}/>
  <div className="ed-row"><button type="button" className="button small" disabled={processing||!nameBn.trim()||!nameEn.trim()} onClick={save}>{editingId?translate('product_facts','concern_save'):translate('product_facts','concern_add')}</button>{editingId&&<button type="button" className="text-link" onClick={clear}>{translate('product_facts','concern_cancel')}</button>}</div>
 </div>;
}

/** Facts and concern tags the owner fills in for the public product page. */
export default function ProductFactsPanel({fields,concerns,data,setField,canEdit}:{
 fields:ProductFactField[];
 concerns:ProductConcernOption[];
 data:Row;
 setField:(name:string,value:unknown)=>void;
 canEdit:boolean;
}){
 const translate=useT();
 const chosen:string[]=data.concern_ids??[];
 const toggleConcern=(id:string,checked:boolean)=>setField('concern_ids',checked?[...chosen,id]:chosen.filter(value=>value!==id));
 return <section className="panel">
  <h2>{translate('product_facts','panel_title')}</h2>
  <p className="muted">{translate('product_facts','panel_hint')}</p>
  <label>{translate('product_facts','field_registration_number')}<input disabled={!canEdit} maxLength={120} value={data.registration_number??''} onChange={e=>setField('registration_number',e.target.value)}/><small className="muted">{translate('product_facts','hint_registration_number')}</small></label>
  {fields.map(field=><fieldset key={field.name} className="pe-fact-field" disabled={!canEdit}>
   <legend>{translate('product_facts',`field_${field.name}`)}</legend>
   {FIELD_HINTS[field.name]&&<small className="muted">{translate('product_facts',FIELD_HINTS[field.name])}</small>}
   <div className="form-grid">
    {(['bn','en'] as const).map(language=><label key={language}>{translate('product_facts',`column_${language}`)}{field.multiline
     ?<textarea rows={3} maxLength={field.max_length} value={data[`${field.name}_${language}`]??''} onChange={e=>setField(`${field.name}_${language}`,e.target.value)}/>
     :<input maxLength={field.max_length} value={data[`${field.name}_${language}`]??''} onChange={e=>setField(`${field.name}_${language}`,e.target.value)}/>}</label>)}
   </div>
  </fieldset>)}
  <h3>{translate('product_facts','concerns_title')}</h3>
  <p className="muted">{translate('product_facts','concerns_hint')}</p>
  {concerns.length===0&&<p className="muted">{translate('product_facts','concerns_empty')}</p>}
  {concerns.map(concern=><label key={concern.id} className="registry-checkbox"><input type="checkbox" disabled={!canEdit} checked={chosen.includes(String(concern.id))} onChange={e=>toggleConcern(String(concern.id),e.target.checked)}/>{concern.name_bn} · {concern.name_en}</label>)}
  <ConcernTagManager concerns={concerns} canManage={canEdit}/>
 </section>;
}
