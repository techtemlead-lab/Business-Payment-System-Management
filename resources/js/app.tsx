import React from 'react';
import {createRoot} from 'react-dom/client';
import {createInertiaApp} from '@inertiajs/react';
import { applyColorScheme, resolveColorScheme } from './shared/theme';
import { installBanglaDigitShaping } from './shared/banglaDigits';
import { installCountUp } from './shared/countUp';
import { installNavigationFeedback } from './shared/navigationFeedback';
import { installPortalMotion } from './components/motion/portalMotion';

applyColorScheme(resolveColorScheme());
installBanglaDigitShaping();
installCountUp();
installNavigationFeedback();
installPortalMotion();

const pages: Record<string, () => Promise<{default: React.ComponentType<any>}>> = {
    Portal: () => import('./pages/Portal'),
    'portals/dealer/Dashboard': () => import('./pages/portals/dealer/Dashboard'),
    'portals/subdealer/Dashboard': () => import('./pages/portals/subdealer/Dashboard'),
    'portals/manage/Dashboard': () => import('./pages/portals/manage/Dashboard'),
    'portals/sadmin/Dashboard': () => import('./pages/portals/sadmin/Dashboard'),
    OrderDetail: () => import('./pages/OrderDetail'),
    WithdrawalDetail: () => import('./pages/WithdrawalDetail'),
    ProductEditor: () => import('./pages/ProductEditor'),
    AccountCreate: () => import('./pages/AccountCreate'),
    Operations: () => import('./pages/Operations'),
    DataImports: () => import('./pages/DataImports'),
    ServiceIntegrations: () => import('./pages/ServiceIntegrations'),
};

// Company name for tab titles; the server sets it from the website editor (default "Madina").
const panelTabName = (typeof document === 'undefined' ? null : document.querySelector<HTMLMetaElement>('meta[name="application-name"]'))?.content || 'Madina';

createInertiaApp({
    title: (title) => `${title} · ${panelTabName}`,
    resolve: async (name) => (await pages[name]()).default,
    setup({el, App, props}) {
        createRoot(el).render(<App {...props} />);
    },
    progress: {color: '#377b5a'},
});
