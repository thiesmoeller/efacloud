# efaCloud dockside PWA (`/portal/`)

German phone-first React/TypeScript app for boat selection and individual trips.

## Develop

```bash
cd portal
npm install
npm run dev
```

Vite proxies `/api` → `http://127.0.0.1:8080` (override with `VITE_API_PROXY`).
The app is served under base `/portal/`.

## Build / test

```bash
npm run test
npm run build
```

Output: `portal/dist` (copied into the Docker image at `/var/www/html/portal`).

## Out of scope (v1)

Reservation creation, repair admin, bulk ops. Writes are online-only.
