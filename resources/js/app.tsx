import "../css/app.css";
import "../css/experience.css";
import { createInertiaApp } from "@inertiajs/react";
import { createRoot } from "react-dom/client";
const pages = import.meta.glob<{ default: React.ComponentType<any> }>(
    "./Pages/*.tsx",
);
createInertiaApp({
    title: (title) => `${title} · Nexora`,
    resolve: async (name) => {
        const load = pages[`./Pages/${name}.tsx`];
        if (!load) throw new Error(`Unknown page ${name}`);
        return (await load()).default;
    },
    setup({ el, App, props }) {
        createRoot(el).render(<App {...props} />);
    },
    progress: { color: "#174d3b" },
});
