import { defineConfig, loadEnv } from "vite";
import laravel from "laravel-vite-plugin";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { fileURLToPath, URL } from "node:url";
const developmentOrigin = new URL(
    process.env.NEXORA_VITE_ORIGIN || "http://localhost:5173",
);
export default defineConfig(({ mode }) => {
    const appOrigin =
        process.env.APP_URL || loadEnv(mode, process.cwd(), "APP_URL").APP_URL;
    return {
        server: {
            host: "0.0.0.0",
            port: 5173,
            strictPort: true,
            origin: developmentOrigin.origin,
            cors: {
                origin: [
                    /^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/,
                    ...(appOrigin ? [appOrigin] : []),
                ],
            },
            hmr: {
                host: developmentOrigin.hostname,
                protocol:
                    developmentOrigin.protocol === "https:" ? "wss" : "ws",
                clientPort: Number(
                    developmentOrigin.port ||
                        (developmentOrigin.protocol === "https:" ? 443 : 80),
                ),
            },
        },
        plugins: [
            laravel({ input: ["resources/js/app.tsx"], refresh: true }),
            react(),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                "@": fileURLToPath(new URL("./resources/js", import.meta.url)),
            },
        },
    };
});
