# GameShelf — Design Doc (v0.1)

## 1. Problem & Vision

Gamers own libraries fragmented across Steam, Epic, GOG, and other storefronts, with
no unified way to see what they own, track wishlists, or decide what to play next.
GameShelf aggregates library and wishlist data across platforms into one dashboard,
with filtering, backlog insights, and discovery tools.

**v1 wedge:** unified library view + backlog stats, for Steam and GOG only.
Wishlist/price tracking, Epic support, and social features come after the core
loop is validated.

## 2. Goals / Non-Goals

**Goals (v1)**
- Connect Steam + GOG accounts, pull full owned-games library
- Normalize games to a canonical title (dedupe cross-platform)
- Tagging, filtering, and saved smart collections
- Backlog stats (unplayed count, estimated burn-down time)

**Non-goals (v1)**
- Epic/Xbox/PlayStation sync (no reliable public API — revisit later)
- Social features (friends, sharing) — needs user density first
- Price tracking / deal alerts — separate milestone after core loop works
- Native mobile app — web only, responsive

## 3. Users & Core Loop

Primary user: PC gamer with a large backlog across 2+ storefronts.

Core loop: connect accounts → see unified library → tag/filter → see backlog
stats → come back periodically to update play status and re-triage backlog.

## 4. System Architecture

```
[Nuxt web app] --HTTPS/JSON--> [Laravel API] --> [MySQL/Postgres]
[iOS app, stretch] --^                |
                                       +--> [Queue workers (Laravel Queue)] --> Steam Web API
                                       |                                    --> GOG API
                                       +--> [IGDB API] (canonical game metadata)
                                       +--> [Cache: Redis] (IGDB lookups, sync status)
```

- **Backend:** Laravel API (v11+), token auth via Sanctum (stateless — needed
  since both Nuxt and, later, a native iOS client hit the same API)
- **Frontend:** Nuxt 3 (Vue), consumes the Laravel API over JSON, SSR or SPA
  mode either works fine here since most views are behind auth
- **DB:** MySQL or Postgres (either is fine with Laravel/Eloquent — Postgres
  if you want array/jsonb columns for tags natively, MySQL if that's already
  your team's default)
- **Queue/sync workers:** Laravel Queue (database or Redis driver) with
  scheduled jobs (Laravel Scheduler) for daily per-user syncs, plus an
  on-demand "sync now" job dispatched from the API
- **Cache:** Redis for IGDB lookup caching and sync-status/rate-limit tracking
- **Metadata source:** IGDB (or SteamGridDB for box art) to map
  platform-specific game IDs to one canonical `game` record
- **Stretch — native iOS client:** since the API is already a separate
  Laravel service consumed over JSON by Nuxt, a Swift/SwiftUI iOS app can hit
  the same endpoints with no backend changes needed. Worth designing the API
  as platform-agnostic from day one (no server-rendered HTML responses, no
  Nuxt-specific assumptions baked into payloads) so the iOS client is additive
  later rather than a rewrite.

## 5. Data Model (initial)

```
users
  id, email, created_at

platform_connections
  id, user_id, platform (enum: steam|gog), external_account_id,
  auth_token (encrypted), last_synced_at, status

games            -- canonical, one row per real-world game
  id, igdb_id, title, cover_url, genres[], release_date

owned_games      -- join table, one row per (user, platform, game)
  id, user_id, platform_connection_id, game_id,
  platform_game_id, playtime_minutes, last_played_at,
  install_status, added_at

user_game_meta   -- user's own tags/status, decoupled from platform data
  id, user_id, game_id, status (enum: unplayed|playing|finished|abandoned),
  tags[], notes, rating
```

Key design point: `owned_games` can have multiple rows per `game_id` per user
(same game owned on both Steam and GOG) — dedupe at query time or with a
materialized view, don't collapse at ingestion (you lose "which platform to
launch from" info).

## 6. Platform Integration Notes

**Steam**
- Steam Web API (`GetOwnedGames`) — needs user's Steam ID + API key, or
  OAuth via Steam OpenID (no scoped API keys, it's identity-only — library
  read still uses the public Web API against the resolved Steam ID)
- Playtime and last-played data available; wishlist via separate endpoint
- Rate limits are generous but not officially documented — poll conservatively
  (e.g. once per day per user, plus manual refresh button)

**GOG**
- OAuth2 flow, then GOG's (undocumented but stable) embedded API for owned
  games — expect to reverse-engineer against community docs (e.g. gogapidocs)
- No official support commitment from GOG — flag this as a maintenance risk

**Epic (deferred)**
- No public library API. Options if pursued later: browser-extension based
  scraping, or manual CSV/JSON import as a stopgap. Don't block v1 on this.

**Canonical metadata**
- On each new `owned_games` row, resolve to a `games` record by matching
  against IGDB search (title + platform hint), cache the mapping so repeat
  syncs don't re-query IGDB

## 7. Core Features — v1 Spec

### 7.1 Account connection
- OAuth/connect flow per platform, stored per user
- Manual "sync now" button + automatic daily background sync
- Sync status indicator (last synced, in progress, error state)

### 7.2 Unified library view
- Single grid/list of all owned games, deduped, showing which platform(s)
  each is owned on
- Sort: alphabetical, playtime, last played, date added
- Filter: platform, genre, status, tags, playtime range

### 7.3 Smart collections
- Saved filter presets, system-provided defaults:
  - "Unplayed" (playtime = 0)
  - "Abandoned" (played but not touched in 6+ months, not marked finished)
  - "Quick wins" (est. completion time < 5 hrs, if metadata available)
- User can save their own filter combos as named collections

### 7.4 Backlog stats
- Total unplayed count, total estimated hours to clear backlog
- Simple burn-down projection based on user's recent play pace
  (e.g. avg hours/week over last N weeks → "X years to clear backlog")
- This is a good shareable/viral stat — worth a dedicated shareable card view

## 8. Deferred (v2+) Features
- Wishlist sync + price tracking/alerts (differentiate from IsThereAnyDeal by
  cross-referencing against owned library — "already own this on GOG")
- Discovery: "what to play next" recommendations from owned-but-unplayed pool,
  filtered by mood/time available
- Social: friends' backlogs, shared wishlists, co-op ownership overlap
- Epic/Xbox/PlayStation integration

## 9. Open Questions
- Auth: build our own accounts (email/password + Sanctum tokens), or
  "sign in with Steam" only for v1? Sanctum supports either; OAuth-only
  is simpler to start but locks out anyone without a Steam account
- Hosting/infra choice (not specified yet — e.g. Laravel Forge/Vapor for the
  API, Vercel or a Node server for Nuxt SSR, managed MySQL/Postgres + Redis)
- Nuxt rendering mode: SPA is simpler given everything's behind auth anyway;
  SSR only matters if there's a public marketing/landing page to optimize
- Rate-limit and caching strategy for IGDB calls at scale

## 10. Suggested Build Order for v1
1. Laravel API skeleton + Sanctum auth + user accounts
2. Nuxt app skeleton, auth flow wired to Sanctum
3. Steam connect + queued sync job + raw library ingestion
4. Canonical game matching against IGDB (cached in Redis)
5. Unified library UI in Nuxt (grid, filters, sort)
6. GOG connect + sync (validates the multi-platform dedupe logic)
7. Tags/status/smart collections
8. Backlog stats view
9. (Stretch) iOS client against the existing Sanctum API
