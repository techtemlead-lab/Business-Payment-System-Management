import type { ReactNode } from 'react';

type Props = {
    title: ReactNode;
    /** A short line at the right of the closed card: a count, an amount or a status. */
    hint?: ReactNode;
    /** Starts open. Leave off for background information; set it for the card the reader must act on. */
    open?: boolean;
    id?: string;
    testId?: string;
    /** Extra classes on the card, for example `table-panel` when the body is a full-width table. */
    className?: string;
    children: ReactNode;
};

/**
 * A card that starts closed and opens on a click, so a long page shows its headings first.
 * It is a plain `<details>`: the keyboard, the screen reader and the browser's find-in-page all
 * work without script, and what the reader opened stays open while the page refreshes.
 */
export default function FoldCard({ title, hint, open = false, id, testId, className = '', children }: Props) {
    return <details id={id} data-testid={testId} className={`panel fold-card ${className}`.trim()} open={open || undefined}>
        <summary className="fold-card-head"><h2>{title}</h2>{hint ? <span className="fold-card-hint">{hint}</span> : null}</summary>
        <div className="fold-card-body">{children}</div>
    </details>;
}
