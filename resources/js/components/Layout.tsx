import * as Dialog from "@radix-ui/react-dialog";
import { useEffect, useState, type ReactNode } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    type LucideIcon,
    LayoutDashboard,
    Users,
    Network,
    SlidersHorizontal,
    CircleDollarSign,
    Gift,
    Wallet,
    ArrowLeftRight,
    BarChart3,
    ScrollText,
    Settings2,
    Search,
    Bell,
    ChevronDown,
    ArrowUpRight,
    ShieldCheck,
    LogOut,
    Menu,
    X,
    Command,
    LifeBuoy,
    Building2,
    Plus,
    CheckCircle2,
} from "lucide-react";
import { type Shared } from "@/lib/types";
import { Modal, Button } from "./Common";
type NavGroup = { group: string; items: [string, string, LucideIcon][] };
const adminNav: NavGroup[] = [
    {
        group: "WORKSPACE",
        items: [
            ["/dashboard", "Overview", LayoutDashboard],
            ["/dealers", "Dealer management", Users],
            ["/hierarchy", "Network & territories", Network],
        ],
    },
    {
        group: "FINANCE",
        items: [
            ["/rules", "Commission & rewards", CircleDollarSign],
            ["/operating-mode", "Operating mode", SlidersHorizontal],
            ["/ledger", "Financial ledger", ArrowLeftRight],
            ["/payouts", "Payouts", Wallet],
            ["/reports", "Reports", BarChart3],
        ],
    },
    {
        group: "ADMINISTRATION",
        items: [
            ["/audit", "Audit trail", ScrollText],
            ["/settings", "Settings", Settings2],
        ],
    },
];
const dealerNav: NavGroup[] = [
    {
        group: "MY WORKSPACE",
        items: [
            ["/dashboard", "Overview", LayoutDashboard],
            ["/ledger", "My earnings", CircleDollarSign],
            ["/payouts", "My payouts", Wallet],
            ["/security", "Account security", ShieldCheck],
        ],
    },
];
export function Logo({ light = false }: { light?: boolean }) {
    return (
        <span className={`brand ${light ? "brand-light" : ""}`}>
            <span className="brand-mark">
                <svg viewBox="0 0 32 32" fill="none">
                    <path
                        d="M7 24V8h4l10 11V8h4v16h-4L11 13v11z"
                        fill="currentColor"
                    />
                </svg>
            </span>
            <span>
                nexora<span className="brand-period">.</span>
            </span>
        </span>
    );
}
export function Layout({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    const { props, url } = usePage<Shared>();
    const user = props.auth.user!;
    const isAdmin = user.role === "admin";
    const nav = isAdmin ? adminNav : dealerNav;
    const [mobile, setMobile] = useState(false);
    const [searchOpen, setSearchOpen] = useState(false);
    const [search, setSearch] = useState("");
    const [notifications, setNotifications] = useState(false);
    const [help, setHelp] = useState(false);
    const [userMenu, setUserMenu] = useState(false);
    useEffect(() => {
        const key = (e: KeyboardEvent) => {
            if ((e.ctrlKey || e.metaKey) && e.key === "k") {
                e.preventDefault();
                setSearchOpen((v) => !v);
            }
        };
        document.addEventListener("keydown", key);
        return () => document.removeEventListener("keydown", key);
    }, []);
    useEffect(() => setMobile(false), [url]);
    const sidebar = (
        <>
            <Link
                href="/dashboard"
                aria-label="Nexora overview"
                className="sidebar-brand"
            >
                <Logo />
                <span className="brand-subtitle">DEALER OPERATIONS</span>
            </Link>
            <div className="workspace-switch">
                <span className="workspace-icon">
                    <Building2 size={18} />
                </span>
                <div>
                    <strong>{props.company.company_name}</strong>
                    <span>
                        {isAdmin ? "Company workspace" : "Dealer workspace"}
                    </span>
                </div>
                <ChevronDown size={15} />
            </div>
            <nav>
                {nav.map((group) => (
                    <div className="nav-group" key={group.group}>
                        <p>{group.group}</p>
                        {group.items.map(([href, label, Icon]) => (
                            <Link
                                key={href}
                                href={href}
                                className={`nav-item ${url.split("?")[0] === href ? "nav-active" : ""}`}
                            >
                                <Icon size={18} />
                                <span>{label}</span>
                                {url.split("?")[0] === href && (
                                    <span className="active-marker" />
                                )}
                            </Link>
                        ))}
                    </div>
                ))}
            </nav>
            <div className="sidebar-bottom">
                <div className="help-card">
                    <span className="help-symbol">
                        <LifeBuoy size={19} />
                    </span>
                    <strong>A little guidance?</strong>
                    <p>Keep your network moving forward.</p>
                    <button onClick={() => setHelp(true)}>
                        Workspace guide <ArrowUpRight size={15} />
                    </button>
                </div>
                <div className="sidebar-health">
                    <span />
                    <span>Workspace connected</span>
                    <ShieldCheck size={14} />
                </div>
            </div>
        </>
    );
    return (
        <>
            <Head title={title} />
            <div className="app-shell">
                <aside className="sidebar">{sidebar}</aside>
                <Dialog.Root open={mobile} onOpenChange={setMobile}>
                    <Dialog.Portal>
                        <Dialog.Content
                            className="mobile-sidebar"
                            onCloseAutoFocus={(event) => {
                                event.preventDefault();
                                document
                                    .querySelector<HTMLButtonElement>(
                                        ".mobile-toggle",
                                    )
                                    ?.focus();
                            }}
                        >
                            <Dialog.Title className="sr-only">
                                Workspace navigation
                            </Dialog.Title>
                            <Dialog.Description className="sr-only">
                                Navigate to your workspace pages.
                            </Dialog.Description>
                            <Dialog.Close
                                className="mobile-dismiss"
                                aria-label="Close navigation"
                            >
                                <X />
                            </Dialog.Close>
                            <aside>{sidebar}</aside>
                            <Dialog.Close
                                className="mobile-shade"
                                aria-label="Dismiss navigation"
                            />
                        </Dialog.Content>
                    </Dialog.Portal>
                </Dialog.Root>
                <div className="main-shell">
                    <header className="topbar">
                        <div className="topbar-left">
                            <button
                                className="icon-button mobile-toggle"
                                aria-label="Open navigation"
                                onClick={() => setMobile(true)}
                            >
                                <Menu size={22} />
                            </button>
                            <span className="topbar-workspace">Workspace</span>
                            <span className="breadcrumb-slash">/</span>
                            <strong>{title}</strong>
                        </div>
                        <div className="topbar-right">
                            <button
                                className="topbar-search"
                                onClick={() => setSearchOpen(true)}
                            >
                                <Search size={17} />
                                <span>Search workspace...</span>
                                <kbd>⌘ K</kbd>
                            </button>
                            {props.demo && (
                                <span className="demo-label">
                                    Demo workspace
                                </span>
                            )}
                            <div className="popover-parent">
                                <button
                                    className="icon-button notification-button"
                                    aria-label="Notifications"
                                    onClick={() => {
                                        setNotifications((v) => !v);
                                        setUserMenu(false);
                                    }}
                                >
                                    <Bell size={19} />
                                    <span />
                                </button>
                                {notifications && (
                                    <div className="header-popover">
                                        <h3>Workspace updates</h3>
                                        <p>
                                            {isAdmin
                                                ? "Commission distribution and reward eligibility need company approval."
                                                : "Your private workspace shows only your account and authorized financial records."}
                                        </p>
                                        <Link
                                            href={
                                                isAdmin ? "/rules" : "/ledger"
                                            }
                                            onClick={() =>
                                                setNotifications(false)
                                            }
                                        >
                                            {isAdmin
                                                ? "Review configuration"
                                                : "View my ledger"}{" "}
                                            <ArrowUpRight size={14} />
                                        </Link>
                                    </div>
                                )}
                            </div>
                            <span className="topbar-divider" />
                            <div className="popover-parent">
                                <button
                                    className="user-button"
                                    onClick={() => {
                                        setUserMenu((v) => !v);
                                        setNotifications(false);
                                    }}
                                >
                                    <span className="avatar avatar-user">
                                        {user.name
                                            .split(" ")
                                            .map((x) => x[0])
                                            .slice(0, 2)
                                            .join("")}
                                    </span>
                                    <div>
                                        <strong>{user.name}</strong>
                                        <span>
                                            {isAdmin
                                                ? "Company admin"
                                                : "Authorized dealer"}
                                        </span>
                                    </div>
                                    <ChevronDown size={14} />
                                </button>
                                {userMenu && (
                                    <div className="header-popover user-popover">
                                        <Link href="/security">
                                            <ShieldCheck size={16} /> Account
                                            security
                                        </Link>
                                        <button
                                            onClick={() =>
                                                router.post("/logout")
                                            }
                                        >
                                            <LogOut size={16} /> Sign out
                                        </button>
                                    </div>
                                )}
                            </div>
                        </div>
                    </header>
                    <main className="content">
                        {props.flash.success && (
                            <div className="flash" role="status">
                                <CheckCircle2 size={18} />
                                {props.flash.success}
                            </div>
                        )}
                        {children}
                    </main>
                    <footer className="app-footer">
                        <span>
                            © {new Date().getFullYear()}{" "}
                            {props.company.company_name}. Built for better
                            partnerships.
                        </span>
                        <span>
                            <ShieldCheck size={13} /> Secure workspace{" "}
                            <span className="footer-dot">·</span> BDT{" "}
                            <span className="footer-dot">·</span> Dhaka time
                        </span>
                    </footer>
                </div>
            </div>
            <Modal
                open={searchOpen}
                onOpenChange={setSearchOpen}
                title="Find your next step"
                description="Jump to a workspace section or search your dealer network."
            >
                <div className="search-field">
                    <Search size={19} />
                    <input
                        autoFocus
                        value={search}
                        placeholder="Search pages or dealers..."
                        onChange={(e) => setSearch(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === "Enter" && isAdmin) {
                                router.get("/dealers", { q: search });
                                setSearchOpen(false);
                            }
                        }}
                    />
                </div>
                <div className="command-items">
                    {nav
                        .flatMap((g) => g.items)
                        .filter(([, label]) =>
                            label.toLowerCase().includes(search.toLowerCase()),
                        )
                        .map(([href, label, Icon]) => (
                            <Link
                                href={href}
                                key={href}
                                onClick={() => setSearchOpen(false)}
                            >
                                <Icon size={18} />
                                {label}
                                <ArrowUpRight size={15} />
                            </Link>
                        ))}
                    {isAdmin && search && (
                        <button
                            onClick={() => {
                                router.get("/dealers", { q: search });
                                setSearchOpen(false);
                            }}
                        >
                            <Users size={18} /> Search dealers for “{search}”{" "}
                            <ArrowUpRight size={15} />
                        </button>
                    )}
                </div>
            </Modal>
            <Modal
                open={help}
                onOpenChange={setHelp}
                title="Your workspace, at a glance"
                description="Everything you need to manage your dealer relationships."
            >
                <div className="guide-list">
                    <div>
                        <Users />
                        <h3>Build your network</h3>
                        <p>
                            Create dealer accounts, review their coverage, then
                            activate approved partners.
                        </p>
                    </div>
                    <div>
                        <CircleDollarSign />
                        <h3>Understand your allocations</h3>
                        <p>
                            Preview the confirmed MRP model. All unapproved
                            rules remain inactive.
                        </p>
                    </div>
                    <div>
                        <ShieldCheck />
                        <h3>Stay in control</h3>
                        <p>
                            Review audit history and turn on two-factor
                            authentication for your account.
                        </p>
                    </div>
                </div>
            </Modal>
        </>
    );
}
export function CreateDealerButton({ onClick }: { onClick: () => void }) {
    return (
        <Button onClick={onClick}>
            <Plus size={17} /> Create dealer
        </Button>
    );
}
