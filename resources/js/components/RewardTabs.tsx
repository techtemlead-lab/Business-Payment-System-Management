import { Link } from "@inertiajs/react";

export function RewardTabs({
    active,
    admin,
}: {
    active: "rules" | "earnings";
    admin: boolean;
}) {
    return (
        <nav
            aria-label="Commission and rewards sections"
            className="mb-6 flex flex-wrap gap-3"
        >
            {admin && (
                <Link
                    href="/rules"
                    className={`rounded-xl border px-4 py-3 text-sm font-semibold ${active === "rules" ? "border-green-900 bg-green-900 text-white!" : "border-gray-200 bg-white text-green-950"}`}
                    aria-current={active === "rules" ? "page" : undefined}
                >
                    Commission & reward settings
                </Link>
            )}
            <Link
                href="/rewards/earnings"
                className={`rounded-xl border px-4 py-3 text-sm font-semibold ${active === "earnings" ? "border-green-900 bg-green-900 text-white!" : "border-gray-200 bg-white text-green-950"}`}
                aria-current={active === "earnings" ? "page" : undefined}
            >
                Earnings by category
            </Link>
        </nav>
    );
}
