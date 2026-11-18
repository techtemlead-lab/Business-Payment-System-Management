import React from 'react';
import {createRoot} from 'react-dom/client';
import {createInertiaApp} from '@inertiajs/react';
import { applyColorScheme, resolveColorScheme } from './shared/theme';
import { installBanglaDigitShaping } from './shared/banglaDigits';
import { installBanglaAutofill } from './shared/banglaFill/autofill';
import { installNavigationFeedback } from './shared/navigationFeedback';
import { installPortalMotion } from './components/motion/portalMotion';
import { initialTranslationsUrl, loadTranslations } from './shared/i18n/bundle';

applyColorScheme(resolveColorScheme());
installBanglaDigitShaping();
// English-first forms: an empty Bangla box is filled from its English twin (CR-06); the model loads only when used.
installBanglaAutofill();
installNavigationFeedback();
installPortalMotion();

const pages: Record<string, () => Promise<{default: React.ComponentType<any>}>> = {
    Portal: () => import('./pages/Portal'),
    'portals/dealer/Dashboard': () => import('./pages/portals/dealer/Dashboard'),
    'portals/subdealer/Dashboard': () => import('./pages/portals/subdealer/Dashboard'),
    'portals/manage/Dashboard': () => import('./pages/portals/manage/Dashboard'),
    'portals/sadmin/Dashboard': () => import('./pages/portals/sadmin/Dashboard'),
    OrderDetail: () => import('./pages/OrderDetail'),
    PatientRecord: () => import('./pages/PatientRecord'),
    WithdrawalDetail: () => import('./pages/WithdrawalDetail'),
    ProductEditor: () => import('./pages/ProductEditor'),
    AccountCreate: () => import('./pages/AccountCreate'),
    Operations: () => import('./pages/Operations'),
    DataImports: () => import('./pages/DataImports'),
    ServiceIntegrations: () => import('./pages/ServiceIntegrations'),
    Accounting: () => import('./pages/Accounting'),
    TeamOrders: () => import('./pages/TeamOrders'),
    PartnerProfile: () => import('./pages/PartnerProfile'),
    CourierAreas: () => import('./pages/CourierAreas'),
    TreasuryFunds: () => import('./pages/TreasuryFunds'),
    TreasuryFund: () => import('./pages/TreasuryFund'),
    TreasuryOrders: () => import('./pages/TreasuryOrders'),
    TreasuryOrder: () => import('./pages/TreasuryOrder'),
    TreasuryEvents: () => import('./pages/TreasuryEvents'),
    TreasuryPartners: () => import('./pages/TreasuryPartners'),
    TreasuryPartner: () => import('./pages/TreasuryPartner'),
    TreasuryWork: () => import('./pages/TreasuryWork'),
    TreasuryProfit: () => import('./pages/TreasuryProfit'),
    MyMoney: () => import('./pages/MyMoney'),
    MyIncome: () => import('./pages/MyIncome'),
};

// Company name for tab titles; the server sets it from the website editor (default "Madina").
const panelTabName = (typeof document === 'undefined' ? null : document.querySelector<HTMLMetaElement>('meta[name="application-name"]'))?.content || 'Madina';

createInertiaApp({
    title: (title) => `${title} · ${panelTabName}`,
    // The words are fetched together with the first screen (the page asks the browser to start that download early), so nothing is drawn with raw keys.
    resolve: async (name) => {
        const url = initialTranslationsUrl();
        const [page] = await Promise.all([pages[name](), url ? loadTranslations(url).catch(() => undefined) : undefined]);

        return page.default;
    },
    setup({el, App, props}) {
        createRoot(el).render(<App {...props} />);
        document.getElementById('boot-skeleton')?.remove();
    },
    progress: {color: '#377b5a'},
});
