/**
 * Discount of one order line against the list price (MRP) saved on the line when the order was
 * placed, never today's product price. Free scheme goods and lines with no saved MRP have no
 * figure (null); a line sold at or above MRP is 0.
 */
export function orderLineDiscountPercent(line: { is_free?: boolean | null; mrp?: unknown; unit_price?: unknown }): number | null {
    const mrp = Number(line.mrp);
    const price = Number(line.unit_price);
    if (line.is_free || !Number.isFinite(mrp) || !Number.isFinite(price) || mrp <= 0) {
        return null;
    }
    const percent = ((mrp - price) / mrp) * 100;
    return percent > 0 ? Math.round(percent * 100) / 100 : 0;
}
