import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

/**
 * Dev proxy: Vite serves the PWA; `/api` is forwarded to efaCloud.
 * Override with VITE_API_PROXY (e.g. http://127.0.0.1:8080).
 */
const apiProxy = process.env.VITE_API_PROXY || "http://127.0.0.1:8080";

export default defineConfig({
  base: "/portal/",
  plugins: [react()],
  server: {
    port: 5173,
    proxy: {
      "/api": {
        target: apiProxy,
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: "dist",
    emptyOutDir: true,
    sourcemap: true,
  },
});
