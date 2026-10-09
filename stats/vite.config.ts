import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

const apiProxy = process.env.VITE_API_PROXY || "http://127.0.0.1:8080";

export default defineConfig({
  base: "/stats/",
  plugins: [react()],
  server: {
    port: 5174,
    proxy: { "/api": { target: apiProxy, changeOrigin: true } },
  },
  build: { outDir: "dist", emptyOutDir: true, sourcemap: true },
});
