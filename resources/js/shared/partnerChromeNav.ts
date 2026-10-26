type DashboardControls = Record<string, unknown>;

function flagEnabled(controls: DashboardControls, key: string): boolean {
    return controls[key] !== false;
}

export type PartnerChromeItem = 'catalog' | 'orders' | 'network' | 'salary' | 'earnings';

/** Bottom/header chrome links that mirror Shell partnerAvailable toggles. */
export function partnerChromeEnabled(
    role: string,
    controls: DashboardControls | null | undefined,
    item: PartnerChromeItem,
): boolean {
    const c = controls || {};
    if (role === 'dealer') {
        return matchDealer(c, item);
    }
    if (role === 'sub_dealer') {
        return matchSubDealer(c, item);
    }

    return item === 'earnings';
}

function matchDealer(controls: DashboardControls, item: PartnerChromeItem): boolean {
    switch (item) {
        case 'catalog':
            return flagEnabled(controls, 'dealer_catalog_enabled');
        case 'orders':
            return flagEnabled(controls, 'dealer_orders_enabled');
        case 'network':
            return flagEnabled(controls, 'dealer_network_tree_enabled');
        case 'salary':
            return false;
        case 'earnings':
            return true;
        default:
            return true;
    }
}

function matchSubDealer(controls: DashboardControls, item: PartnerChromeItem): boolean {
    switch (item) {
        case 'catalog':
            return flagEnabled(controls, 'subdealer_catalog_enabled');
        case 'orders':
            return flagEnabled(controls, 'subdealer_orders_enabled');
        case 'network':
            return false;
        case 'salary':
            return flagEnabled(controls, 'subdealer_salary_enabled');
        case 'earnings':
            return true;
        default:
            return true;
    }
}
