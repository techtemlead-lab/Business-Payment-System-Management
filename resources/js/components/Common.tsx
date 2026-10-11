import { useState, type ReactNode } from "react";
import { Link } from "@inertiajs/react";
import * as Dialog from "@radix-ui/react-dialog";
import {
    X,
    ArrowUpRight,
    ChevronLeft,
    ChevronRight,
    Inbox,
    CheckCircle2,
    AlertCircle,
} from "lucide-react";
import { Button } from "./ui/button";
import type { Page } from "@/lib/types";
export { Button };
export function Modal({
    open,
    onOpenChange,
    title,
    description,
    children,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    children: ReactNode;
}) {
    return (
        <Dialog.Root open={open} onOpenChange={onOpenChange}>
            <Dialog.Portal>
                <Dialog.Overlay className="modal-overlay" />
                <Dialog.Content className="modal-content">
                    <div className="modal-top">
                        <Dialog.Title>{title}</Dialog.Title>
                        <Dialog.Close
                            className="icon-button"
                            aria-label="Close dialog"
                        >
                            <X size={20} />
                        </Dialog.Close>
                    </div>
                    <Dialog.Description className="modal-description">
                        {description}
                    </Dialog.Description>
                    {children}
                </Dialog.Content>
            </Dialog.Portal>
        </Dialog.Root>
    );
}
export function Field({
    label,
    error,
    children,
    hint,
}: {
    label: string;
    error?: string;
    children: ReactNode;
    hint?: string;
}) {
    return (
        <label className="field">
            <span>{label}</span>
            {children}
            {error ? (
                <span className="field-error">{error}</span>
            ) : hint ? (
                <span className="field-hint">{hint}</span>
            ) : null}
        </label>
    );
}
export function Badge({
    status,
    children,
}: {
    status?: string;
    children?: ReactNode;
}) {
    return (
        <span className={`badge badge-${status ?? "neutral"}`}>
            <span className="badge-dot" />
            {children ?? status?.replaceAll("_", " ")}
        </span>
    );
}
export function Empty({
    icon,
    title,
    description,
    action,
}: {
    icon?: ReactNode;
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <div className="empty">
            <div className="empty-icon">{icon ?? <Inbox size={25} />}</div>
            <h3>{title}</h3>
            <p>{description}</p>
            {action}
        </div>
    );
}
export function Notice({
    children,
    tone = "info",
}: {
    children: ReactNode;
    tone?: "info" | "warning" | "success";
}) {
    return (
        <div className={`notice notice-${tone}`}>
            {tone === "success" ? (
                <CheckCircle2 size={18} />
            ) : (
                <AlertCircle size={18} />
            )}
            <div>{children}</div>
        </div>
    );
}
export function PageHeading({
    eyebrow,
    title,
    description,
    action,
}: {
    eyebrow?: string;
    title: string;
    description: string;
    action?: ReactNode;
}) {
    return (
        <div className="page-heading">
            <div>
                {eyebrow && <span className="eyebrow">{eyebrow}</span>}
                <h1>{title}</h1>
                <p>{description}</p>
            </div>
            {action}
        </div>
    );
}
export function Card({
    children,
    className = "",
}: {
    children: ReactNode;
    className?: string;
}) {
    return <section className={`card ${className}`}>{children}</section>;
}
export function CardHeading({
    title,
    subtitle,
    action,
}: {
    title: string;
    subtitle?: string;
    action?: ReactNode;
}) {
    return (
        <div className="card-heading">
            <div>
                <h2>{title}</h2>
                {subtitle && <p>{subtitle}</p>}
            </div>
            {action}
        </div>
    );
}
export function ArrowLink({
    href,
    children,
}: {
    href: string;
    children: ReactNode;
}) {
    return (
        <Link href={href} className="text-link">
            {children}
            <ArrowUpRight size={15} />
        </Link>
    );
}
export function Pagination({ page }: { page: Page<unknown> }) {
    return (
        <div className="pagination">
            <span>
                {page.total
                    ? `${page.from}–${page.to} of ${page.total} records`
                    : "0 records"}
            </span>
            <div>
                <Button asChild variant="outline" size="sm">
                    <Link
                        href={page.current_page > 1 ? page.links[0].url! : "#"}
                        aria-disabled={page.current_page === 1}
                        onClick={(e) => {
                            if (page.current_page === 1) e.preventDefault();
                        }}
                    >
                        <ChevronLeft size={15} /> Previous
                    </Link>
                </Button>
                <span>
                    {page.current_page} / {page.last_page}
                </span>
                <Button asChild variant="outline" size="sm">
                    <Link
                        href={
                            page.current_page < page.last_page
                                ? page.links.at(-1)!.url!
                                : "#"
                        }
                        aria-disabled={page.current_page === page.last_page}
                        onClick={(e) => {
                            if (page.current_page === page.last_page)
                                e.preventDefault();
                        }}
                    >
                        Next <ChevronRight size={15} />
                    </Link>
                </Button>
            </div>
        </div>
    );
}
export function ConfirmAction({
    title,
    description,
    label,
    onConfirm,
    disabled = false,
}: {
    title: string;
    description: string;
    label: string;
    onConfirm: (reason: string, password: string) => void;
    disabled?: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [reason, setReason] = useState("");
    const [password, setPassword] = useState("");
    return (
        <>
            <Button
                variant="outline"
                disabled={disabled}
                onClick={() => setOpen(true)}
            >
                {label}
            </Button>
            <Modal
                open={open}
                onOpenChange={setOpen}
                title={title}
                description={description}
            >
                <Field label="Reason">
                    <textarea
                        value={reason}
                        minLength={5}
                        onChange={(e) => setReason(e.target.value)}
                        placeholder="Explain the reason for this change"
                    />
                </Field>
                <Field
                    label="Current password"
                    hint="Required outside the local demo workspace."
                >
                    <input
                        type="password"
                        autoComplete="current-password"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                    />
                </Field>
                <div className="modal-actions">
                    <Button variant="outline" onClick={() => setOpen(false)}>
                        Cancel
                    </Button>
                    <Button
                        disabled={reason.trim().length < 5}
                        onClick={() => {
                            onConfirm(reason, password);
                            setOpen(false);
                        }}
                    >
                        Confirm change
                    </Button>
                </div>
            </Modal>
        </>
    );
}
