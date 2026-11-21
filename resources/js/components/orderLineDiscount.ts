type DiscountLine = { is_free?: boolean | null; mrp?: unknown; unit_price?: unknown; quantity?: unknown; total?: unknown };

/**
 * Discount of one order line against the list price (MRP) saved on the line when the order was
 * placed, never today's product price. Free scheme goods and lines with no saved MRP have no
 * figure (null); a line sold at or above MRP is 0.
 */
export function orderLineDiscountPercent(line: DiscountLine): number | null {
    const mrp = Number(line.mrp);
    const price = Number(line.unit_price);
    if (line.is_free || !Number.isFinite(mrp) || !Number.isFinite(price) || mrp <= 0) {
        return null;
    }
    const percent = ((mrp - price) / mrp) * 100;
    return percent > 0 ? Math.round(percent * 100) / 100 : 0;
}

/** List-price value of an order: saved MRP times quantity, free scheme goods left out. */
export function orderMrpTotal(lines: DiscountLine[]): number {
    return lines.reduce((sum, line) => sum + (line.is_free ? 0 : Number(line.mrp || 0) * Number(line.quantity || 0)), 0);
}

/** Discount of a whole order against its saved list prices (null when there is no MRP value). */
export function orderDiscountPercent(lines: DiscountLine[]): number | null {
    const mrpTotal = orderMrpTotal(lines);
    if (mrpTotal <= 0) {
        return null;
    }
    const paidTotal = lines.reduce((sum, line) => sum + (line.is_free ? 0 : Number(line.total || 0)), 0);
    const percent = ((mrpTotal - paidTotal) / mrpTotal) * 100;
    return percent > 0 ? Math.round(percent * 100) / 100 : 0;
}
