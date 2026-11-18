/**
 * An order number that is easy to read aloud: the date part small and the unique last part big
 * ("MN-270226-" + "FJSHOQRS"). The stored number never changes; the full number stays in the
 * text, so copying, searching and printing still give all of it.
 */
export default function OrderNumber({ number }: { number: string }) {
    const cut = number.lastIndexOf('-');
    if (cut <= 0 || cut === number.length - 1) {
        return <span>{number}</span>;
    }

    return <span title={number} aria-label={number} className="order-number">
        <small className="order-number-prefix" style={{ fontSize: '0.55em', fontWeight: 400, color: 'var(--muted)' }}>{number.slice(0, cut + 1)}</small>
        <span className="order-number-key" style={{ letterSpacing: '0.06em' }}>{number.slice(cut + 1)}</span>
    </span>;
}
