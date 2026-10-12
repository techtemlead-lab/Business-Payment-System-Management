import { usePage } from "@inertiajs/react";
import type { Shared } from "@/lib/types";
import { PublicWebsite } from "@/components/PublicWebsite";
export default function Welcome() {
    return <PublicWebsite document={usePage<Shared>().props.website} />;
}
