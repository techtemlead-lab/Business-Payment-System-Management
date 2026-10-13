import {createRequire} from 'node:module';
import {mkdir,writeFile} from 'node:fs/promises';
import {dirname,resolve} from 'node:path';
import {fileURLToPath} from 'node:url';
const root=resolve(dirname(fileURLToPath(import.meta.url)),'../..');
const {chromium}=createRequire(import.meta.url)(process.argv[2]||'playwright');
let browser;try{browser=await chromium.launch({headless:true});}catch{browser=await chromium.launch({headless:true,channel:'msedge'});}
const context=await browser.newContext({viewport:{width:1536,height:900},reducedMotion:'reduce'});
const page=await context.newPage();const errors=[];page.on('pageerror',e=>errors.push(e.message));
const result={checks:[],errors};await mkdir(resolve(root,'docs/screenshots'),{recursive:true});
function check(name,value){result.checks.push({name,passed:!!value});if(!value)throw new Error(name);}
try{
const response=await page.goto('http://localhost:8088/');check('Homepage HTTP200',response.status()===200);
await page.locator('.ref-slide.is-active img').waitFor();await page.evaluate(()=>document.fonts.ready);
check('Reference five-slide carousel',await page.locator('.ref-slide').count()===5);
check('Twenty reference product cards',await page.locator('.ref-product-card').count()===20);
check('Desktop does not overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
await page.screenshot({path:resolve(root,'docs/screenshots/reference-home-desktop.png')});
await page.locator('.ref-promotion-dialog').waitFor({state:'visible',timeout:7000});check('Promotional popup opens',await page.locator('.ref-promotion-dialog').isVisible());await page.getByRole('button',{name:'Close promotion',exact:true}).click();
await page.getByRole('button',{name:'Next promotion',exact:true}).click();check('Carousel next advances',await page.locator('.ref-slide').nth(1).getAttribute('aria-hidden')==='false');
await page.getByRole('button',{name:'Show promotion 1',exact:true}).click();
await page.locator('.ref-add-cart').first().click();await page.locator('[data-open-cart]').first().click();check('Cart dialog opens',await page.locator('.ref-cart-dialog').isVisible());check('Cart contains added product',await page.locator('.ref-cart-items>div').count()===1);await page.getByRole('button',{name:'Close cart',exact:true}).click();
await page.locator('.ref-wishlist').first().click();check('Wishlist toggles',await page.locator('.ref-wishlist').first().getAttribute('aria-pressed')==='true');
await page.locator('[data-open-compare]').click();await page.locator('[data-compare-slot="0"]').selectOption('reference-product-01');await page.locator('[data-compare-slot="1"]').selectOption('reference-product-02');check('Product comparison renders two items',await page.locator('.ref-compare-results>article').count()===2);await page.getByRole('button',{name:'Close comparison',exact:true}).click();
await page.getByRole('textbox',{name:'Search products',exact:true}).fill('Herbal');await page.getByRole('button',{name:'Search',exact:true}).click();await page.waitForURL('**/products?q=Herbal');check('Search reaches real product route',new URL(page.url()).pathname==='/products');
await page.goto('http://localhost:8088/');await page.setViewportSize({width:390,height:844});check('Mobile bottom navigation visible',await page.locator('.ref-mobile-nav').isVisible());check('Mobile does not overflow',await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth));
await page.screenshot({path:resolve(root,'docs/screenshots/reference-home-mobile.png')});
await page.evaluate(async()=>{const images=[...document.images];images.forEach(x=>x.loading='eager');await Promise.all(images.map(x=>x.complete?Promise.resolve():new Promise(r=>{x.onload=r;x.onerror=r;})));});
const broken=await page.evaluate(()=>[...document.images].filter(x=>!x.naturalWidth).map(x=>x.getAttribute('src')));check('All homepage images decode',broken.length===0);
await page.screenshot({path:resolve(root,'docs/screenshots/reference-home-mobile-full.png'),fullPage:true});
check('No browser JavaScript errors',errors.length===0);result.passed=true;await writeFile(resolve(root,'docs/qa/homepage-results.json'),JSON.stringify(result,null,2));console.log(JSON.stringify({passed:true,checks:result.checks.length,consoleErrors:errors.length}));
}catch(e){result.passed=false;result.failure=e.message;await writeFile(resolve(root,'docs/qa/homepage-results.json'),JSON.stringify(result,null,2));throw e;}finally{await browser.close();}
