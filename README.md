# GameBower

Unified game library dashboard. Connects Steam, GOG, and Xbox accounts, dedupes owned games across platforms into one canonical entry per game (via IGDB), and gives you tagging/filtering/smart collections plus backlog stats.

Two apps in this repo:

- `api/` — Laravel 13 API (Sanctum token auth, MySQL, Redis queue/cache)
- `web/` — Nuxt 4 SPA (`ssr: false`)

This doc covers running your own instance. `SPEC.md` at the repo root documents the actual behavior/invariants in detail if you need to know exactly how something works.

## Requirements

- PHP 8.3+ and Composer
- Node 20+
- MySQL (or compatible)
- Redis
- A queue worker + cron (or equivalent) able to run scheduled commands — the app polls connected accounts daily and needs both running continuously, not just during a request

## External accounts you'll need

| Service | Used for | Where to get it |
|---|---|---|
| Steam Web API key | Steam library sync, Deck compat, resolve | https://steamcommunity.com/dev/apikey |
| Twitch app (IGDB) | Game metadata/matching — IGDB auth rides Twitch's client-credentials OAuth | https://dev.twitch.tv/console/apps |
| GOG API app | GOG library sync | Community-documented (`gogapidocs`), no official developer portal — unofficial/undocumented, treat as a maintenance-risk dependency |
| Azure AD app (Xbox) | Xbox library sync | https://portal.azure.com — register an app, add `https://<your-web-domain>/connections/xbox/callback` as a redirect URI, request the `XboxLive.signin` + `offline_access` scopes. Xbox is optional — skip it and the Xbox connect option just shows "unavailable" |

Steam and GOG are the only platforms with no missing-key failure mode beyond "that connect button won't work" — omit Xbox entirely if you don't want to deal with Azure AD.

## Backend setup (`api/`)

```bash
cd api
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

- `DB_CONNECTION=mysql` + `DB_HOST`/`DB_PORT`/`DB_DATABASE`/`DB_USERNAME`/`DB_PASSWORD` (the example defaults to `sqlite`, fine for a quick local try but not what the app is built against — SPEC §C calls out MySQL/Eloquent specifically)
- `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis` (the example defaults both to `database` — that works, but Redis is what IGDB rate-limit tracking and sync-status caching are designed around)
- `REDIS_HOST`/`REDIS_PORT`/`REDIS_PASSWORD`
- `STEAM_API_KEY`, `TWITCH_CLIENT_ID`, `TWITCH_CLIENT_SECRET`, `GOG_CLIENT_ID`, `GOG_CLIENT_SECRET`
- `XBOX_CLIENT_ID`, `XBOX_CLIENT_SECRET` (optional — leave blank to skip Xbox)
- `APP_URL` to your API's real URL

```bash
php artisan migrate
```

Run the API (a real webserver + PHP-FPM in production; `php artisan serve` is fine for local dev).

### Background workers (required, not optional)

Two long-running processes, separate from serving HTTP requests:

```bash
php artisan queue:work        # processes sync jobs, IGDB matching, etc. — run under supervisor/systemd in prod
php artisan schedule:work     # dev only — runs the daily connection-sync cron job
```

In production, don't use `schedule:work`; instead add a single cron entry and let Laravel's scheduler dispatch from there:

```
* * * * * cd /path/to/api && php artisan schedule:run >> /dev/null 2>&1
```

Without the queue worker running, nothing ever syncs — connects/sync-now requests just sit queued forever (V8: sync always happens off the request cycle, never inline).

### CORS

Laravel's CORS config here is framework defaults (`Access-Control-Allow-Origin: *`) — no `cors.php` has been published. Fine if the API is only ever called by your own frontend and you're not worried about that, but if you want to lock it down, publish the CORS config and restrict it to your web app's origin.

## Frontend setup (`web/`)

```bash
cd web
npm install
cp .env.example .env
```

Edit `.env`:

- `NUXT_PUBLIC_API_BASE` — your API's URL (must be reachable from the browser, not just server-side)
- `NUXT_PUBLIC_GOG_CLIENT_ID` — same value as the API's `GOG_CLIENT_ID` (this one's public, not secret — needed client-side to build the GOG login URL)
- `NUXT_PUBLIC_XBOX_CLIENT_ID` — same value as the API's `XBOX_CLIENT_ID`, if you set one up

Local dev:

```bash
npm run dev
```

Production — this is a static SPA (`ssr: false`), so build it and serve the output as static files:

```bash
npm run build
```

Serve the contents of `.output/public/` from any static file host (nginx, Caddy, S3+CDN, etc.), with a fallback to `index.html` for client-side routing. There's no Node server to run — it's not `ssr: true`.

## Verifying it's working

```bash
cd api && php artisan test   # backend test suite
cd web && npm run test       # frontend test suite
```

Then hit `/welcome` in the browser, register an account, and try connecting a platform from `/profile`. If a connect button says "unavailable (missing client id)" you skipped that platform's env vars on purpose or by accident — check the table above.

## Known rough edges

Worth knowing before you rely on this for anything serious — from `SPEC.md`'s own constraints:

- GOG and Xbox both integrate via undocumented/community-reverse-engineered APIs (no official third-party developer program for either). They can break if the provider changes something, with no advance notice.
- Steam Deck compatibility and some appdetails lookups hit an undocumented, unauthenticated Steam store endpoint that rate-limits aggressively at scale.
- PlayStation and Humble Bundle are deliberately not supported — both would require storing a password-equivalent session token to read a user's library, which this project treats as an unacceptable trust model (see `SPEC.md` V60).
