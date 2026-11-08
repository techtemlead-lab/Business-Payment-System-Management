type Translate = (group: string, key: string, replace?: Record<string, string | number>) => string;

/**
 * The arrival line on a parcel. It only talks about when a parcel will come while it is
 * still on the way; a delivered parcel shows its delivery date and a returned one shows nothing.
 */
export function shipmentArrivalText(
    shipment: { status?: string | null; eta?: string | null; delivered_at?: string | null },
    translate: Translate,
    formatDate: (value: string) => string,
): string {
    if (shipment.status === 'delivered') {
        return shipment.delivered_at ? translate('order_payment', 'delivered_on', { date: formatDate(shipment.delivered_at) }) : '';
    }
    if (shipment.status === 'returned') {
        return '';
    }

    return shipment.eta ? `${translate('portal', 'expected')} ${formatDate(shipment.eta)}` : translate('portal', 'eta_to_be_confirmed');
}
