type Matrix=Record<string,Record<string,boolean>>;

export function actionAllowed(url:string,matrix:Matrix|undefined,method='post'):boolean {
    if(!matrix)return true;
    const path=url.split('?')[0];
    const any=(modules:string[],action:string)=>modules.some(module=>matrix[module]?.[action]);
    if(path.startsWith('/security/')||path==='/commands/owner/contact'||path==='/commands/owner/partner-login-otp'||path==='/logout'||/\/notices\/[^/]+\/read$/.test(path))return true;
    if(path.startsWith('/commands/operations/')) {
        const part=path.slice('/commands/operations/'.length);
        if(part.startsWith('staff/roles')||part.startsWith('staff/action-permissions'))return any(['staff_admin'],'edit');
        if(part.startsWith('staff/territories')||part.startsWith('staff/targets'))return any(['staff_admin'],'edit');
        if(part.startsWith('staff/applications'))return any([part.endsWith('/review')?'accounts':'sr_onboarding'],part.endsWith('/review')?'approve':'create');
        if(part==='staff/orders')return any(['sr_orders'],'create');
        if(part==='marketing/homepage-video')return any(['content','catalog'],'edit');
        if(part.startsWith('integrations/'))return any(['finance'],'edit');
        if(part==='payroll/prepare')return any(['payroll'],'create');
        if(part==='payroll/contracts'||part==='settings/withdrawal-minimum')return any(['finance'],'edit');
        if(/\/(review|pay|verify|release|recover|reassign)$/.test(part))return any(['finance'],'approve');
        return any(['finance'],method==='patch'?'edit':'create');
    }
    if(path.startsWith('/commands/accounts'))return any(['accounts'],method==='patch'||/\/(invite|benefit-controls)$/.test(path)?'edit':'create');
    if(path.startsWith('/commands/products'))return any(['catalog','pricing','inventory'],method==='post'&&path==='/commands/products'?'create':'edit');
    if(path.startsWith('/commands/area-dealers')||path.startsWith('/commands/dashboards'))return any(['accounts'],'edit');
    if(path==='/commands/stock')return any(['inventory'],'create');
    if(/^\/commands\/branches\/\d+\/select$/.test(path))return true;
    if(path.startsWith('/commands/branches'))return any(['inventory','accounts'],method==='post'&&path==='/commands/branches'?'create':'edit');
    if(/\/decision$/.test(path))return any(['order_review'],'approve');
    if(/\/dispatch$|\/delivered$/.test(path))return any(['inventory'],'edit');
    if(path.startsWith('/commands/claims')||path.startsWith('/commands/withdrawals'))return any(['finance'],'approve');
    if(path.startsWith('/commands/commissions'))return any(['finance'],path.endsWith('/rules')?'edit':path.endsWith('/simulate')?'view':'approve');
    if(path==='/commands/policies')return any(['finance'],'create');
    if(path.startsWith('/commands/customization')||path==='/commands/content')return any(['content','catalog'],'edit');
    if(path.startsWith('/commands/enquiries'))return any(['content','catalog','accounts'],'edit');
    return false;
}
