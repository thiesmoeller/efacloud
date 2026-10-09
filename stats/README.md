# efaStats (`/stats/`)

Authenticated, German-first club statistics over the efaCloud logbook. The browser receives aggregate data only; personal statistics are derived server-side from the signed-in portal account's `PersonId`.

## Develop

```bash
cd stats
npm install
npm run dev
```

Vite proxies `/api` to `http://127.0.0.1:8080` by default. Override with `VITE_API_PROXY`. For local synthetic data, run efaCloud with `EFACLOUD_PORTAL_FIXTURES=1` and sign in with a portal fixture account.

## Build and test

```bash
npm run build
npm test
cd ..
./tests/stats/run.sh
```

The production build is copied to `/var/www/html/stats` by the root Dockerfile. The read-only API lives at `/api/stats/v1/` and shares the `EFA_PORTAL` session.
