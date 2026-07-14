# SPEC — GameShelf v1

## §G goal

Unified game library dashboard. Aggregate owned games Steam+GOG → dedupe to canonical titles → tag/filter/smart collections → backlog stats. Web only. Core loop: connect → view library → triage → return.

Source: `design-doc.md` v0.1.

## §C constraints

- Backend: Laravel 11+, Sanctum stateless token auth.
- Frontend: Nuxt 4 (Vue), JSON over HTTPS. Was Nuxt 3 → upgrade T10.
- Styling: Tailwind CSS. Added amend 2026-07-13 → T11.
- Theme: dark only, ∀ views. Slate base + teal accent motif. ⊥ light mode v1.
- Landing: 1 public marketing page (route `/welcome`), guests → `/welcome` ≠ `/login`. Same dark slate/teal theme. SPA `ssr: false` holds → SEO limited, accepted v1. Added amend 2026-07-13 → T12.
- DB: MySQL (Eloquent). tags[]/genres[] → JSON columns | pivot tables.
- Queue: Laravel Queue, Redis driver (Redis already in stack) + Scheduler. Daily per-user sync & on-demand "sync now".
- "sync now" throttled per connection (≥5 min gap) + API rate limiting (Sanctum throttle).
- SPA auth: Sanctum bearer token (stateless per V3). localStorage XSS tradeoff accepted over cookie/CSRF mode.
- Cache: Redis — IGDB lookups, sync status, rate-limit tracking.
- Metadata: IGDB (SteamGridDB ? box art).
- v1 platforms: steam, gog only. Epic/Xbox/PS ⊥ (no public API).
- ⊥ v1: social, price tracking, native mobile.
- API platform-agnostic from day 1 → iOS client additive later.
- GOG API undocumented (community docs: gogapidocs) → maintenance risk.
- GOG playtime unavailable via owned-games API → playtime nullable, null = unknown ≠ 0.
- Burn-down pace: `playtime_snapshots` table, row per sync. Steam `playtime_2weeks` ? seed until history accrues.
- "Quick wins" conditional: IGDB time-to-beat where present; games w/o data excluded from collection.
- Auth: own accounts — email/password + Sanctum tokens. Steam OpenID ⊥ identity (connection only).
- Hosting: Laravel Forge + single VPS (API, queue, Redis, DB). Nuxt SPA static via same box | CDN.
- Nuxt mode: SPA (`ssr: false`). All views behind auth, no SEO need. Holds in Nuxt 4 — upgrade ⊥ drop `ssr: false`.
- Local dev topology: API via Herd → `http://gameshelf.test` (Sites symlink → `api/`), FE `nuxt dev` :3000, `web/.env` NUXT_PUBLIC_API_BASE=http://gameshelf.test. API unreachable → browser reports CORS-shaped error ∴ check server up before touching CORS config. Laravel CORS = framework defaults (ACAO *), no cors.php published.

## §I interfaces

- api: `POST /api/connections` {platform: steam|gog} → OAuth/connect flow
- api: `POST /api/connections/:id/sync` → 202, dispatch sync job
- api: `GET /api/connections` → [{platform, last_synced_at, status}]
- api: `DELETE /api/connections/:id` → 200 soft disconnect: status → disconnected, owned_games kept (V13). Added T5.
- api: `GET /api/library` → deduped games, owned-on platforms per game; sort: alpha|playtime|last_played|added; filter: platform|genre|status|tags|playtime range|collection (system slug | custom id, explicit params win). Entries carry meta (status, status_declared, tags, notes, rating) + time_to_beat_minutes. Extended T7.
- api: `PUT /api/library/:game_id/meta` {status?, tags[]?, notes?, rating? 1-5} → upsert partial, 404 if game ∉ caller library. Added T7.
- api: `GET/POST /api/collections` → GET {system: [{slug,name,description}], custom: [...]}; POST {name, filters} → 201, filter keys ⊂ library vocabulary, ⊥ nested collection. System: unplayed (playtime=0 | declared unplayed, V12), abandoned (played, untouched ≥6 mo, ≠finished), quick_wins (ttb < 300 min ∧ ttb present). Shape set T7.
- api: `PATCH /api/user` {email? | password? + password_confirmation?, current_password !} → updated user JSON. Added T13.
- api: `GET /api/tokens` → [{id, name, last_used_at, created_at}]; `POST /api/tokens` {name} → 201 {token: plaintext, shown once}; `DELETE /api/tokens/:id` → revoke. Sanctum PATs. Added T13.
- api: `GET /api/stats/backlog` → {unplayed_count, est_hours, burndown} (avg hrs/wk last N wks → yrs to clear); shareable card view
- ext: Steam Web API `GetOwnedGames` — Steam ID + API key; OpenID identity-only, library read via public Web API; returns `playtime_2weeks`
- ext: Steam `ResolveVanityURL` — vanity URL → SteamID64 at connect
- ext: GOG OAuth2 → embedded API for owned games; tokens expire ~1 hr → refresh flow
- ext: IGDB search (title + platform hint) → canonical game record; auth = Twitch OAuth client credentials (app access token), ≤ 4 req/sec
- ext: IGDB `game_time_to_beats` → normally-pace seconds → games.time_to_beat_minutes; best-effort, fail ⊥ fail match. Added T7.
- env: `STEAM_API_KEY`, `TWITCH_CLIENT_ID`, `TWITCH_CLIENT_SECRET`, `GOG_CLIENT_ID`, `GOG_CLIENT_SECRET`, `APP_KEY` (token encryption) ! set
- db: `users` — id, email, created_at
- db: `platform_connections` — id, user_id, platform enum(steam|gog), external_account_id, auth_token (encrypted), refresh_token (encrypted, nullable), token_expires_at, last_synced_at, status
- db: `games` — canonical, 1 row per real-world game: id, igdb_id (nullable — provisional when unmatched), title, cover_url, genres[], release_date, time_to_beat_minutes (nullable, T7)
- db: `collections` — id, user_id, name, filters JSON (library filter preset). Added T7.
- db: `owned_games` — 1 row per (user, platform, game): id, user_id, platform_connection_id, game_id, platform_game_id, playtime_minutes (nullable), last_played_at, install_status, added_at
- db: `playtime_snapshots` — id, owned_game_id, playtime_minutes, captured_at. Appended per sync.
- db: `user_game_meta` — id, user_id, game_id, status enum(unplayed|playing|finished|abandoned), tags[], notes, rating

## §V invariants

V1: `owned_games` ? multiple rows per (user, game_id) — 1 per platform. ⊥ collapse at ingestion (loses launch-platform info). Dedupe at query time | materialized view.
V2: `platform_connections.auth_token` ! encrypted at rest.
V3: ∀ API responses → JSON. ⊥ server-rendered HTML, ⊥ Nuxt-specific payload assumptions.
V4: IGDB mapping cached → repeat sync ⊥ re-query IGDB for known platform_game_id.
V5: Steam auto-poll ≤ 1/day/user + manual refresh. Conservative — limits undocumented.
V6: `user_game_meta` decoupled from platform data → re-sync ⊥ touch user tags/status/notes/rating.
V7: `games` canonical — igdb_id unique, 1 row per real-world game.
V8: sync work async via queue. API dispatches job → returns. ⊥ inline sync in request cycle.
V9: ∀ platform_connections → sync status visible (last_synced_at, in-progress, error).
V10: sync upsert keyed (platform_connection_id, platform_game_id) unique index → re-sync ⊥ duplicate owned_games rows.
V11: IGDB match fail → game still visible in library (`games` row, igdb_id null, provisional). Sync ⊥ silently drop games.
V12: playtime_minutes null = unknown ≠ 0. "Unplayed" = playtime 0 | user status unplayed. Null ∉ unplayed auto-count.
V13: disconnect → soft-keep. owned_games rows persist, connection status disconnected, UI badge. Reconnect restores. ⊥ delete on disconnect.
V14: GOG token refresh before expiry via refresh_token. V2 encryption applies to refresh_token.
V15: Steam private profile → distinct connection error state + user messaging. ⊥ silent 0-game sync.
V16: ∀ sync → append `playtime_snapshots` row per owned_game with playtime data. Burn-down reads snapshots.
V17: email | password change ! verify current_password. ⊥ silent account takeover via stolen bearer token.
V18: API token plaintext → response once @ creation only. Stored hashed (Sanctum), ⊥ retrievable later.

## §T tasks

id|status|task|cites
T1|x|Laravel API skeleton + Sanctum auth + email/password accounts|V3
T2|x|Nuxt skeleton + auth flow → Sanctum|V3
T3|x|Steam connect (vanity resolve) + queued sync job + raw ingestion + snapshots|V2,V5,V8,V9,V10,V15,V16,I.steam
T4|x|canonical game matching vs IGDB (Twitch auth), Redis cache, provisional fallback|V4,V7,V11,I.igdb
T5|x|unified library UI: grid, filters, sort, disconnect badges|V1,V12,V13,I.api
T6|x|GOG connect + token refresh + sync — validates multi-platform dedupe|V1,V2,V8,V10,V14,I.gog
T7|x|tags/status/smart collections (quick wins conditional)|V6,V12,I.api
T8|x|backlog stats view + shareable card — burn-down from snapshots|V16,I.api
T9|.|(stretch) iOS client vs existing Sanctum API|V3
T10|x|migrate web/ Nuxt 3 → 4: bump nuxt dep, `app/` srcDir (already set), compat fixes; keep `ssr: false`; auth flow + library UI ⊥ regress|V3,§C.nuxt-mode
T11|x|adopt Tailwind CSS in web/; restyle ∀ views dark slate + teal (login, register, library grid, GameCard, badges)|§C.styling,§C.theme
T12|x|landing marketing page `/welcome`: hero, feature pitch (connect→dedupe→triage backlog), CTA → register/login; public route in auth.global; guests hitting `/` → `/welcome`|§C.landing,§C.theme
T13|x|profile page `/profile`: account section (email/password change vs current_password), connected services (list + connect steam/gog + sync now + disconnect vs existing I.api), API keys (list/create w/ once-shown plaintext/revoke)|V2,V13,V17,V18,I.api,§C.theme

## §B bugs

id|date|cause|fix
B1|2026-07-13|browser "CORS" error local = no server bound to apiBase http://localhost:8000; Herd site `gameshelf` pointed at `web/.output/public` ⊥ `api/public`; Laravel CORS defaults fine (ACAO *)|Herd relink → api/, web/.env apiBase, §C.dev-topology
