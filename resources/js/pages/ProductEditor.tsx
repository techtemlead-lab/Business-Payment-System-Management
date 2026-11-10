import {useState, useEffect, useMemo} from 'react';
import {Head,Link,router,useForm,usePage} from '@inertiajs/react';
import Shell from '../components/Shell';
import {money, number, FormErrors, type Row} from '../components/UI';
import {useT} from '../shared/i18n/t';
import {isPastBusinessDate} from '../business-date';
import {skuPrefix, suggestSku} from '../shared/sku';
import ProductPhotoCutout from '../components/ProductPhotoCutout';
import ProductSizeGroupPanel from '../components/ProductSizeGroupPanel';
import {ProductRemovePanel, type ProductRemoval} from '../components/ProductRemoveDialog';
import ProductFactsPanel, {productFactFormKeys, productFactFormValues, type ProductConcernOption, type ProductFactField} from '../components/ProductFactsPanel';

type CatalogSubcategory = {id:number;name:string};
type CatalogCategory = {id:number;name:string;subcategories:CatalogSubcategory[]};
type CatalogUnit = {id:number;name:string;code:string};

export default function ProductEditor({
 product:p,
 catalog_categories=[],
 catalog_units=[],
 supports_product_expiry=true,
 product_fact_fields=[],
 product_concerns=[],
 stock_stages=null,
 removal=null,
}:{
 product:Row|null;
 removal?:ProductRemoval|null;
 stock_stages?:{branches:Row[];total:Row}|null;
 catalog_categories?:CatalogCategory[];
 catalog_units?:CatalogUnit[];
 categories?:string[];
 sub_categories?:string[];
 supports_product_expiry?:boolean;
 product_fact_fields?:ProductFactField[];
 product_concerns?:ProductConcernOption[];
}){
 const translate=useT();
 const {auth,staffActions,publicBase}=usePage<any>().props;const caps:string[]=auth.capabilities;const catalog=caps.includes('catalog')&&(staffActions?.catalog?.[p?'edit':'create']??true),pricing=caps.includes('pricing')&&(staffActions?.pricing?.[p?'edit':'create']??true),inventory=caps.includes('inventory')&&(staffActions?.inventory?.[p?'edit':'create']??true),cost=caps.includes('cost_read');const editable=catalog||inventory||pricing;
 const isOwner = Boolean(auth?.user?.role && ['super_admin', 'md', 'chairman'].includes(auth.user.role));
 const initialMrp = parseFloat(p?.mrp ?? '0') || 0;
 const initialDealer = parseFloat(p?.dealer_price ?? '0') || 0;
 const initialPct = initialMrp > 0 && initialDealer > 0 && initialDealer <= initialMrp ? String(Math.round(((initialMrp - initialDealer) / initialMrp) * 100)) : '';
 const [discountPct, setDiscountPct] = useState<string>(initialPct);

 const initialSubDealer = parseFloat(p?.sub_dealer_price ?? '0') || 0;
 const initialSubDealerPct = initialMrp > 0 && initialSubDealer > 0 && initialSubDealer <= initialMrp ? String(Math.round(((initialMrp - initialSubDealer) / initialMrp) * 100)) : '';
 const [subDealerDiscountPct, setSubDealerDiscountPct] = useState<string>(initialSubDealerPct);

 const form=useForm<Row>({
  name:p?.name??'',
  sku:p?.sku??'',
  category:p?.category??'',
  sub_category:p?.sub_category??'',
  product_category_id:p?.product_category_id?String(p.product_category_id):'',
  product_subcategory_id:p?.product_subcategory_id?String(p.product_subcategory_id):'',
  strength:p?.strength??'',
  description:p?.description??'',
  unit:p?.unit??'',
  product_unit_id:p?.product_unit_id?String(p.product_unit_id):'',
  expires_on:p?.expires_on??'',
  batch_number:p?.batch_number??'',
  mrp:p?.mrp??'',
  dealer_price:p?.dealer_price??'',
  dealer_discount_pct:initialPct,
  sub_dealer_price:p?.sub_dealer_price??'',
  sub_dealer_discount_pct:initialSubDealerPct,
  cost_price:p?.cost_price??'',
  low_stock_at:p?.low_stock_at??10,
  active:p?.active?'1':'0',
  featured:p?.featured?'1':'0',
  image:null as File|null,
  image_original:null as File|null,
  restore_original_image:false,
  remove_image:false,
  video_source:p?.video_source??'none',
  youtube_url:p?.youtube_url??'',
  vimeo_url:p?.vimeo_url??'',
  external_video_url:p?.external_video_url??'',
  video:null as File|null,
  reason:'',
  version:p?.version??'',
  _method:p?'patch':'post',
  ...productFactFormValues(product_fact_fields,p),
 });
 const [chosenPhoto,setChosenPhoto]=useState<File|null>(null);
 const defaultPhotoReason=translate('portal','pe_photo_default_reason');
 const [photoReason,setPhotoReason]=useState(defaultPhotoReason);
 const [photoOnlySave,setPhotoOnlySave]=useState(false);
 const [photoInputKey,setPhotoInputKey]=useState(0);
 const [photoPreview,setPhotoPreview]=useState<string|null>(null);
 const photoChanging=Boolean(form.data.image||form.data.remove_image||form.data.restore_original_image);
 useEffect(()=>{const file=form.data.image as File|null;if(!file){setPhotoPreview(null);return undefined;}const url=URL.createObjectURL(file);setPhotoPreview(url);return ()=>URL.revokeObjectURL(url);},[form.data.image]);
 useEffect(()=>{form.setData('version',p?.version??'');},[p?.version]);

 const selectedCategory = useMemo(
  () => catalog_categories.find(c => String(c.id) === String(form.data.product_category_id)),
  [catalog_categories, form.data.product_category_id],
 );
 const subcategoryOptions = selectedCategory?.subcategories ?? [];
 const selectedCategoryName = selectedCategory?.name ?? form.data.category ?? '';
 const selectedSubcategoryName = subcategoryOptions.find(s => String(s.id) === String(form.data.product_subcategory_id))?.name
  ?? form.data.sub_category
  ?? '';
 const selectedUnitName = catalog_units.find(u => String(u.id) === String(form.data.product_unit_id))?.name
  ?? form.data.unit
  ?? '';

 const setCategorySelection = (categoryId: string) => {
  const next = catalog_categories.find(c => String(c.id) === categoryId);
  form.setData((data: Row) => ({
   ...data,
   product_category_id: categoryId,
   product_subcategory_id: '',
   category: next?.name ?? '',
   sub_category: '',
  }));
 };

 const setSubcategorySelection = (subcategoryId: string) => {
  const next = subcategoryOptions.find(s => String(s.id) === subcategoryId);
  form.setData((data: Row) => ({
   ...data,
   product_subcategory_id: subcategoryId,
   sub_category: next?.name ?? '',
   category: selectedCategoryName || data.category,
  }));
 };

 const setUnitSelection = (unitId: string) => {
  const next = catalog_units.find(u => String(u.id) === unitId);
  form.setData((data: Row) => ({
   ...data,
   product_unit_id: unitId,
   unit: next?.name ?? '',
  }));
 };

 const handleMrpChange = (val: string) => {
  form.setData('mrp', val);
  const m = parseFloat(val);
  const d = parseFloat(discountPct);
  if (!isNaN(m) && m > 0 && !isNaN(d) && d >= 0 && d <= 100) {
   form.setData('dealer_price', (m * (1 - (d / 100))).toFixed(2));
  }
  const sd = parseFloat(subDealerDiscountPct);
  if (!isNaN(m) && m > 0 && !isNaN(sd) && sd >= 0 && sd <= 100) {
   form.setData('sub_dealer_price', (m * (1 - (sd / 100))).toFixed(2));
  }
 };

 const handleDiscountChange = (val: string) => {
  setDiscountPct(val);
  form.setData('dealer_discount_pct', val);
  const m = parseFloat(form.data.mrp);
  const d = parseFloat(val);
  if (!isNaN(m) && m > 0 && !isNaN(d) && d >= 0 && d <= 100) {
   form.setData('dealer_price', (m * (1 - (d / 100))).toFixed(2));
  }
 };

 const handleDealerPriceChange = (val: string) => {
  form.setData('dealer_price', val);
  const m = parseFloat(form.data.mrp);
  const dp = parseFloat(val);
  if (!isNaN(m) && m > 0 && !isNaN(dp) && dp >= 0 && dp <= m) {
   const calculated = String(Math.round(((m - dp) / m) * 100));
   setDiscountPct(calculated);
   form.setData('dealer_discount_pct', calculated);
  }
 };

 const handleSubDealerDiscountChange = (val: string) => {
  setSubDealerDiscountPct(val);
  form.setData('sub_dealer_discount_pct', val);
  const m = parseFloat(form.data.mrp);
  const sd = parseFloat(val);
  if (!isNaN(m) && m > 0 && !isNaN(sd) && sd >= 0 && sd <= 100) {
   form.setData('sub_dealer_price', (m * (1 - (sd / 100))).toFixed(2));
  }
 };

 const handleSubDealerPriceChange = (val: string) => {
  form.setData('sub_dealer_price', val);
  const m = parseFloat(form.data.mrp);
  const sdp = parseFloat(val);
  if (!isNaN(m) && m > 0 && !isNaN(sdp) && sdp >= 0 && sdp <= m) {
   const calculated = String(Math.round(((m - sdp) / m) * 100));
   setSubDealerDiscountPct(calculated);
   form.setData('sub_dealer_discount_pct', calculated);
  }
 };

 const mrpNum = parseFloat(form.data.mrp) || 0;
 const dealerPriceNum = parseFloat(form.data.dealer_price) || 0;
 const savingsNum = mrpNum > dealerPriceNum && dealerPriceNum > 0 ? mrpNum - dealerPriceNum : 0;
 const computedPct = mrpNum > 0 && dealerPriceNum > 0 && dealerPriceNum <= mrpNum ? Math.round(((mrpNum - dealerPriceNum) / mrpNum) * 100) : (parseFloat(discountPct) || 0);

 const subDealerPriceNum = parseFloat(form.data.sub_dealer_price) || 0;
 const subSavingsNum = mrpNum > subDealerPriceNum && subDealerPriceNum > 0 ? mrpNum - subDealerPriceNum : 0;
 const subComputedPct = mrpNum > 0 && subDealerPriceNum > 0 && subDealerPriceNum <= mrpNum ? Math.round(((mrpNum - subDealerPriceNum) / mrpNum) * 100) : (parseFloat(subDealerDiscountPct) || 0);

 // Older products saved a category or unit as plain text. Show what is saved and let the form save without picking again.
 const keepsOldCategory = Boolean(p && !form.data.product_category_id && form.data.category);
 const keepsOldSubcategory = Boolean(p && !form.data.product_subcategory_id && form.data.sub_category);
 const keepsOldUnit = Boolean(p && !form.data.product_unit_id && form.data.unit);

 const generateSku = () => {
  const prefix = `${skuPrefix(selectedCategoryName, 'PRD', 3)}${skuPrefix(form.data.name, '', 3)}`;
  form.setData('sku', suggestSku(prefix));
 };

 function forgetChosenPhoto(){setChosenPhoto(null);setPhotoInputKey(key=>key+1);}
 /** Saves only the photo, so an unfinished or old field elsewhere on the page cannot hold it up. */
 function savePhoto(){if(!p)return;setPhotoOnlySave(true);form.transform(data=>({reason:photoReason,version:data.version,_method:'patch',image:data.image,image_original:data.image_original,restore_original_image:data.restore_original_image,remove_image:data.remove_image}));form.post(`/commands/products/${p.id}`,{forceFormData:true,preserveScroll:true,onSuccess:()=>{form.reset('image','image_original','restore_original_image','remove_image');forgetChosenPhoto();setPhotoReason(defaultPhotoReason);}});}
 function save(event:React.FormEvent){event.preventDefault();setPhotoOnlySave(false);form.transform(data=>{const payload:Row={reason:data.reason,version:data.version,_method:p?'patch':'post'};if(catalog){for(const key of ['name','sku','category','sub_category','strength','description','unit','active','featured','image','image_original','restore_original_image','remove_image','video_source','youtube_url','vimeo_url','external_video_url','video','product_category_id','product_subcategory_id','product_unit_id'])payload[key]=data[key];if(supports_product_expiry)payload.expires_on=data.expires_on||null;payload.batch_number=data.batch_number||null;payload.category=selectedCategoryName||data.category;payload.sub_category=selectedSubcategoryName||data.sub_category;payload.unit=selectedUnitName||data.unit;for(const key of productFactFormKeys(product_fact_fields))payload[key]=data[key];}if(pricing)for(const key of ['mrp','dealer_price','dealer_discount_pct','sub_dealer_price','sub_dealer_discount_pct'])payload[key]=data[key];if(pricing&&cost)payload.cost_price=data.cost_price;if(inventory)payload.low_stock_at=data.low_stock_at;return payload;});form.post(p?`/commands/products/${p.id}`:'/commands/products',{forceFormData:true,onSuccess:()=>{form.reset('reason','image','image_original','restore_original_image','video','remove_image');forgetChosenPhoto();}});}
 const stockLabel = p ? (typeof p.stock === 'number' ? translate('portal','pe_units_in_stock',{count:String(p.stock)}) : (p.stock_status === 'out_of_stock' ? translate('portal','pe_awaiting_stock') : (p.stock_status === 'low_stock' ? translate('portal','pe_low_stock') : translate('portal','pe_in_stock')))) : '';
 const inStock = p ? (typeof p.stock === 'number' ? p.stock > 0 : p.stock_status !== 'out_of_stock') : false;
 return <Shell section="catalog"><Head title={p?translate('portal','pe_title_details'):translate('portal','pe_title_add')}/><Link href="/catalog" className="text-link">{translate('portal','pe_registry')}</Link><div className="registry-heading"><div><h2>{p?.name??translate('portal','pe_heading_add')}</h2><p>{p?`${p.sku} · ${stockLabel}`:translate('portal','pe_create_hint')}</p></div><div className="ed-row">{p&&inventory&&<button type="button" className={`button small ${inStock?'danger':'light'}`} onClick={()=>router.post(`/commands/products/${p.id}/toggle-stock`,{},{preserveScroll:true})}>{inStock?translate('portal','pe_toggle_out'):translate('portal','pe_toggle_in')}</button>}{p?.active&&<a href={`${publicBase||''}/products/${p.id}`} className="button small" target="_blank" rel="noreferrer">{translate('portal','pe_view_public')}</a>}</div></div>
 <form onSubmit={save} className="registry-layout"><div><section id="photo" className="panel pe-photo-panel"><h2>{translate('portal','pe_photo_title')}</h2>{(photoPreview||p?.image_url)&&<img className="registry-photo" src={photoPreview??p?.image_url} alt={p?.name??''}/>}<label>{p?.image_url?translate('office_stock','change_photo'):translate('portal','pe_upload_photo')}<input key={photoInputKey} type="file" disabled={!catalog} accept="image/jpeg,image/png,image/webp" onChange={e=>{const file=e.target.files?.[0]??null;setChosenPhoto(file);form.setData((data:Row)=>({...data,image:file,image_original:null}));}}/></label><p className="muted">{translate('portal','pe_photo_hint')}</p>{catalog&&<ProductPhotoCutout photo={chosenPhoto} savedPhotoUrl={p?.image_url&&!p?.has_original_image?p.image_url:null} disabled={form.data.remove_image||form.data.restore_original_image} onUse={(cutout,original)=>form.setData((data:Row)=>({...data,image:cutout,image_original:original}))} onUndo={()=>form.setData((data:Row)=>({...data,image:chosenPhoto,image_original:null}))}/>}{p?.image_url&&catalog&&<label className="registry-checkbox"><input type="checkbox" checked={form.data.remove_image} onChange={e=>form.setData('remove_image',e.target.checked)}/>{translate('portal','pe_remove_photo')}</label>}{p?.has_original_image&&catalog&&<label className="registry-checkbox"><input type="checkbox" disabled={form.data.remove_image||Boolean(form.data.image)} checked={form.data.restore_original_image} onChange={e=>form.setData('restore_original_image',e.target.checked)}/>{translate('portal','pe_restore_original')}</label>}{p&&catalog&&photoChanging&&<div className="pe-photo-save"><label>{translate('portal','pe_change_reason')}<input value={photoReason} maxLength={1000} onChange={e=>setPhotoReason(e.target.value)} onKeyDown={e=>{if(e.key==='Enter'){e.preventDefault();savePhoto();}}}/></label>{photoOnlySave&&<FormErrors errors={form.errors}/>}<button type="button" className="button dark" disabled={form.processing} onClick={savePhoto}>{form.processing?translate('portal','pe_saving'):translate('portal','pe_save_photo')}</button></div>}{photoOnlySave&&form.recentlySuccessful&&<p className="muted" role="status">{translate('portal','pe_photo_saved')}</p>}</section>
 <section className="panel"><h2>{translate('portal','pe_info_title')}</h2><div className="form-grid"><label>{translate('portal','pe_name')}<input required disabled={!catalog} value={form.data.name} maxLength={150} onChange={e=>form.setData('name',e.target.value)}/></label><label><div className="ed-head is-flush pe-label-row"><span>{translate('portal','pe_sku')}</span>{!form.data.sku&&catalog&&<button type="button" onClick={generateSku} className="text-link pe-label-action">⚡ {translate('portal','pe_auto_sku')}</button>}</div><input required disabled={!catalog} value={form.data.sku} maxLength={60} onChange={e=>form.setData('sku',e.target.value)}/></label><label><div className="ed-head is-flush pe-label-row"><span>{translate('portal','pe_category')}</span><a href="/catalog?tab=taxonomy" target="_blank" rel="noreferrer" className="text-link pe-label-action">{translate('portal','pe_manage_categories')}</a></div><select required={!keepsOldCategory} disabled={!catalog} value={form.data.product_category_id} onChange={e=>setCategorySelection(e.target.value)}><option value="">{keepsOldCategory?String(form.data.category):translate('portal','pe_category_select')}</option>{catalog_categories.map(c=><option key={c.id} value={String(c.id)}>{c.name}</option>)}</select>{catalog_categories.length===0&&<small className="muted">{translate('portal','pe_cms_categories_empty')}</small>}</label><label><div className="ed-head is-flush pe-label-row"><span>{translate('portal','pe_sub_category')}</span></div><select required={!keepsOldSubcategory} disabled={!catalog||!form.data.product_category_id} value={form.data.product_subcategory_id} onChange={e=>setSubcategorySelection(e.target.value)}><option value="">{keepsOldSubcategory?String(form.data.sub_category):(form.data.product_category_id?translate('portal','pe_sub_category_select'):translate('portal','b2b_select_category_first'))}</option>{subcategoryOptions.map(sc=><option key={sc.id} value={String(sc.id)}>{sc.name}</option>)}</select></label><label>{translate('portal','pe_strength')}<input disabled={!catalog} value={form.data.strength} placeholder={translate('portal','pe_strength_ph')} maxLength={100} onChange={e=>form.setData('strength',e.target.value)}/><small className="muted">{translate('portal','pe_strength_hint')}</small></label><label>{translate('portal','pe_unit')}<select required={!keepsOldUnit} disabled={!catalog} value={form.data.product_unit_id} onChange={e=>setUnitSelection(e.target.value)}><option value="">{keepsOldUnit?String(form.data.unit):translate('portal','pe_unit_select')}</option>{catalog_units.map(u=><option key={u.id} value={String(u.id)}>{u.name}</option>)}</select>{catalog_units.length===0&&<small className="muted">{translate('portal','pe_cms_units_empty')}</small>}</label><label>{translate('portal','pe_batch_number')}<input disabled={!catalog} maxLength={60} value={form.data.batch_number||''} onChange={e=>form.setData('batch_number',e.target.value)}/><small className="muted">{translate('portal','pe_batch_number_hint')}</small></label>{supports_product_expiry&&<label>{translate('portal','pe_expires_on')}<input type="date" disabled={!catalog} value={form.data.expires_on||''} onChange={e=>form.setData('expires_on',e.target.value)}/>{isPastBusinessDate(form.data.expires_on)&&<span className="field-error" role="alert">{translate('portal','err_stock_expiry_past')}</span>}<small className="muted">{translate('portal','pe_expires_on_hint')}</small></label>}</div><label>{translate('portal','pe_description')}<textarea rows={5} disabled={!catalog} maxLength={3000} value={form.data.description} onChange={e=>form.setData('description',e.target.value)}/></label></section>
 <ProductFactsPanel fields={product_fact_fields} concerns={product_concerns} data={form.data} setField={(name,value)=>form.setData(name,value as never)} canEdit={catalog}/>
 <section className="panel"><h2>{translate('portal','pe_video_title')}</h2><fieldset className="registry-video-options" disabled={!catalog}><legend>{translate('portal','pe_video_source')}</legend>{[['none','pe_video_none'],['youtube','pe_video_youtube'],['vimeo','pe_video_vimeo'],['url','pe_video_direct'],['upload','pe_video_upload']].map(([value,name])=><label key={value}><input type="radio" name="video_source" value={value} checked={form.data.video_source===value} onChange={()=>{form.setData('video_source',value);form.setData('video',null);}}/>{translate('portal',name)}</label>)}</fieldset>
 {form.data.video_source==='youtube'&&<label>{translate('portal','pe_youtube_url')}<input type="url" disabled={!catalog} required value={form.data.youtube_url} placeholder="https://www.youtube.com/watch?v=..." onChange={e=>form.setData('youtube_url',e.target.value)}/></label>}
 {form.data.video_source==='vimeo'&&<label>{translate('portal','pe_vimeo_url')}<input type="url" disabled={!catalog} required value={form.data.vimeo_url} placeholder="https://vimeo.com/123456789" onChange={e=>form.setData('vimeo_url',e.target.value)}/></label>}
 {form.data.video_source==='url'&&<label>{translate('portal','pe_direct_url')}<input type="url" disabled={!catalog} required value={form.data.external_video_url} placeholder="https://media.example.com/product.mp4" onChange={e=>form.setData('external_video_url',e.target.value)}/><small>{translate('portal','pe_direct_hint')}</small></label>}
 {form.data.video_source==='upload'&&<><label>{translate('portal','pe_upload_video')}<input type="file" disabled={!catalog} required={!p?.video_url} accept="video/mp4,video/webm" onChange={e=>form.setData('video',e.target.files?.[0]??null)}/></label><p className="muted">{translate('portal','pe_upload_video_hint')}</p></>}
 {p?.video_url&&<video className="registry-video" controls preload="metadata" src={p.video_url} aria-label={translate('portal','pe_current_video')}/>}{p?.video_embed_url&&<a className="text-link" href={p.video_embed_url} target="_blank" rel="noreferrer">{translate('portal','pe_view_youtube')}</a>}</section></div>
 <div>{p&&<ProductSizeGroupPanel productId={p.id} canEdit={catalog}/>}{stock_stages&&<section className="panel"><h2>{translate('stock_lots','stages_title')}</h2><div className="pe-stages">{[...stock_stages.branches.map((row:Row)=>({key:String(row.branch_id),name:String(row.branch_name),row})),{key:'total',name:translate('stock_lots','stages_total_row'),row:stock_stages.total}].map(({key,name,row})=><div key={key} className={`pe-stage-row${key==='total'?' is-total':''}`}><div className="pe-stage-head"><span>{name}</span><b>{number(row.total)}</b></div><div className="pe-stage-parts">{(['in_stock','on_hold','with_courier','expired'] as const).map(stage=><span key={stage}>{translate('stock_lots',`stage_${stage}`)} <b>{number(row[stage])}</b></span>)}</div></div>)}</div></section>}<section className="panel"><h2>{translate('portal','pe_prices_title')}</h2>
  <label>{translate('portal','pe_mrp')}<input required type="number" min="0.01" step="0.01" disabled={!pricing} value={form.data.mrp} onChange={e=>handleMrpChange(e.target.value)}/></label>
  <div className="pe-discount">
   <div className="ed-head is-flush pe-label-row">
    <label className="pe-discount-label">{translate('portal','pe_dealer_discount')} <span className="pe-cap tone-ok">({translate('portal', 'pe_cap_max', { pct: '50' })})</span></label>
    <div className="ed-row is-tight is-wrap">
     {[20,25,30,35,40,50].map(pct=>(
      <button key={pct} type="button" disabled={!pricing} onClick={()=>{handleDiscountChange(String(pct));}} className={`ed-chip${discountPct===String(pct)?' is-active':''}`}>
       {pct}%
      </button>
     ))}
    </div>
   </div>
   <input type="number" min="0" max={isOwner ? 100 : 50} step="0.5" disabled={!pricing} placeholder={translate('portal','pe_discount_ph')} value={discountPct} onChange={e=>handleDiscountChange(e.target.value)}/>
   {!isOwner && parseFloat(discountPct) > 50 && (
    <div className="pe-warning tone-danger" role="alert">
     ⚠️ {translate('portal', 'pe_dealer_cap_warning')}
    </div>
   )}
  </div>
   <label>{translate('portal','pe_dealer_price_cash')}<input type="number" min="0.01" step="0.01" disabled={!pricing} value={form.data.dealer_price} onChange={e=>handleDealerPriceChange(e.target.value)}/></label>
   <div className="pe-split tone-ok">
    <div className="pe-split-head">
     <span>{translate('portal','pe_cash_in_title')}</span>
     <span className="hub-pill">{translate('portal','pe_discount_badge',{pct:String(computedPct)})}</span>
    </div>
    <div className="pe-split-grid">
     <div className="pe-split-cell">
      <div className="ed-meta">{translate('portal','pe_dealer_price')}</div>
      <div className="pe-split-value">{money(dealerPriceNum>0?dealerPriceNum:0)}</div>
      <div className="ed-meta">{translate('portal','pe_collected')}</div>
     </div>
     <div className="pe-split-cell tone-warn">
      <div className="ed-meta">{translate('portal','pe_deducted')}</div>
      <div className="pe-split-value">{money(savingsNum>0?savingsNum:0)}</div>
      <div className="ed-meta">{translate('portal','pe_margin')}</div>
     </div>
    </div>
   </div>
   <div className="pe-discount is-divided">
    <div className="ed-head is-flush pe-label-row">
     <label className="pe-discount-label tone-info">{translate('portal','pe_sub_dealer_discount')} <span className="pe-cap tone-info">({translate('portal', 'pe_cap_max', { pct: '40' })})</span></label>
     <div className="ed-row is-tight is-wrap">
      {[20,25,30,35,40].map(pct=>(
       <button key={pct} type="button" disabled={!pricing} onClick={()=>{handleSubDealerDiscountChange(String(pct));}} className={`ed-chip is-info${subDealerDiscountPct===String(pct)?' is-active':''}`}>
        {pct}%
       </button>
      ))}
     </div>
    </div>
    <input type="number" min="0" max={isOwner ? 100 : 40} step="0.5" disabled={!pricing} placeholder={translate('portal','pe_discount_ph')} value={subDealerDiscountPct} onChange={e=>handleSubDealerDiscountChange(e.target.value)}/>
    {!isOwner && parseFloat(subDealerDiscountPct) > 40 && (
     <div className="pe-warning tone-danger" role="alert">
      ⚠️ {translate('portal', 'pe_sub_dealer_cap_warning')}
     </div>
    )}
   </div>
   <label>{translate('portal','pe_sub_dealer_price_cash')}<input type="number" min="0.01" step="0.01" disabled={!pricing} value={form.data.sub_dealer_price} onChange={e=>handleSubDealerPriceChange(e.target.value)}/><small>{translate('portal','pe_sub_dealer_price_hint')}</small></label>
   <div className="pe-split tone-info">
    <div className="pe-split-head">
     <span>{translate('portal','pe_sub_dealer_box_title')}</span>
     <span className="hub-pill">{translate('portal','pe_sub_dealer_discount_badge',{pct:String(subComputedPct)})}</span>
    </div>
    <div className="pe-split-grid">
     <div className="pe-split-cell">
      <div className="ed-meta">{translate('portal','pe_sub_dealer_price')}</div>
      <div className="pe-split-value">{money(subDealerPriceNum>0?subDealerPriceNum:0)}</div>
      <div className="ed-meta">{translate('portal','pe_collected')}</div>
     </div>
     <div className="pe-split-cell tone-orange">
      <div className="ed-meta">{translate('portal','pe_deducted')}</div>
      <div className="pe-split-value">{money(subSavingsNum>0?subSavingsNum:0)}</div>
      <div className="ed-meta">{translate('portal','pe_sub_dealer_margin')}</div>
     </div>
    </div>
   </div>
  {cost&&<label>{translate('portal','pe_cost')}<input type="number" min="0" step="0.01" disabled={!pricing} value={form.data.cost_price} onChange={e=>form.setData('cost_price',e.target.value)}/></label>}<label>{translate('portal','pe_low_stock_at')}<input type="number" min="0" max="100000" step="1" disabled={!inventory} value={form.data.low_stock_at} onChange={e=>form.setData('low_stock_at',e.target.value)}/></label><label>{translate('portal','pe_publication')}<select aria-label={translate('portal','pe_publication')} disabled={!catalog} value={form.data.active} onChange={e=>form.setData('active',e.target.value)}><option value="0">{translate('portal','pe_draft_hidden')}</option><option value="1">{translate('portal','pe_published')}</option></select></label><label>{translate('portal','pe_homepage')}<select aria-label={translate('portal','pe_homepage')} disabled={!catalog} value={form.data.featured} onChange={e=>form.setData('featured',e.target.value)}><option value="0">{translate('portal','pe_standard')}</option><option value="1">{translate('portal','pe_featured')}</option></select></label><p className="muted">{translate('portal','pe_stock_note')}</p></section>
 {editable&&<section className="panel"><h2>{translate('portal','pe_save_title')}</h2><label>{translate('portal','pe_change_reason')}<textarea required minLength={5} maxLength={1000} rows={3} value={form.data.reason} onChange={e=>form.setData('reason',e.target.value)}/></label>{form.progress&&<progress max={100} value={form.progress.percentage} aria-label={translate('portal','pe_upload_progress')}/>}{!photoOnlySave&&<FormErrors errors={form.errors}/>}<button type="submit" className="button dark" disabled={form.processing}>{form.processing?translate('portal','pe_saving'):p?translate('portal','pe_save_product'):translate('portal','pe_create_product')}</button></section>}
 </div></form>
 {p && catalog && removal && <div className="registry-layout pe-remove-row"><div><ProductRemovePanel product={{id:p.id,name:String(p.name),archived:Boolean(p.archived)}} removal={removal}/></div></div>}
 </Shell>;
}


