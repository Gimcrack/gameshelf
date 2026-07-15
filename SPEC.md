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
- v1 platforms: steam, gog only. Epic/Xbox/PS ⊥ (no public API). + `manual` pseudo-platform (T14): user-added games, synthetic connection, ⊥ sync.
- Discovery: IGDB = catalogue source. ∀ discover calls server-proxied, Redis-cached, ≤ 4 req/s shared budget (V4 throttle). ⊥ FE direct IGDB. Added amend 2026-07-13 → T14,T15.
- Tags: Steam has ⊥ public API for community tags → platform taxonomy = IGDB only (themes/keywords/game_modes, mirrors existing genres). User freeform tags (T7) unaffected. Added amend 2026-07-15 → T22.
- Steam Deck: ⊥ official API for compat rating (Verified/Playable/Unsupported/Unknown) → undocumented store endpoint, same risk class as GOG (maintenance risk, unofficial). Refetched every sync (matches playtime cadence) → 1 extra HTTP call per Steam owned_game per sync; ? revisit w/ TTL cache if this trips Steam's aggressive unauth store rate-limit (same class already flagged on `appName`) at larger library sizes. Steam-only — GOG/manual rows ⊥ rating. Added amend 2026-07-15 → T26.
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
- api: `GET /api/library` → deduped games, owned-on platforms per game (each platform entry carries `deck_status` nullable, Steam-only, T26); sort: alpha|playtime|last_played|added; filter: platform|genre|theme|keyword|game_mode|status|tags|playtime range|collection (system slug | custom id, explicit params win)|`deck_status[]` (multi-select verified|playable|unsupported|unknown, matches ∀ game w/ ≥1 owning platform row in listed set)|esrb (single-select E|E10|T|M|AO|RP)|multiplayer|coop|local_multiplayer|local_coop (bool). Entries carry meta (status, status_declared, tags, notes, rating, hidden) + time_to_beat_minutes + esrb_rating/multiplayer/coop/local_multiplayer/local_coop. ⊥ hidden entries by default → `include_hidden=1` reveals them. Extended T7, T22 (theme/keyword/game_mode filters), T25 (hidden), T26 (deck_status), T27 (esrb/multiplayer/coop/local flags).
- api: `GET /api/library/:game_id` → single entry, same shape as list item | 404 if ∉ caller library. Added T24.
- api: `PUT /api/library/:game_id/meta` {status?, tags[]?, notes?, rating? 1-5, hidden? bool} → upsert partial, 404 if game ∉ caller library. Added T7, extended T25 (hidden).
- api: `GET/POST /api/collections` → GET {system: [{slug,name,description}], custom: [...]}; custom entries carry `type: filter|manual`. POST {name, type: filter|manual, filters? (required if type=filter)} → 201, filter keys ⊂ library vocabulary, ⊥ nested collection. System: unplayed (playtime=0 | declared unplayed, V12), abandoned (played, untouched ≥6 mo, ≠finished), quick_wins (ttb < 300 min ∧ ttb present). ⊥ hidden games counted in system collections (V28). Shape set T7, extended T23 (type).
- api: `POST /api/collections/:id/games` {game_id} → 201 add to manual collection; `DELETE /api/collections/:id/games/:game_id` → 200 remove. 422 if collection type ≠ manual. Added T23.
- api: `PATCH /api/user` {email? | password? + password_confirmation?, current_password !} → updated user JSON. Added T13.
- api: `GET /api/tokens` → [{id, name, last_used_at, created_at}]; `POST /api/tokens` {name} → 201 {token: plaintext, shown once}; `DELETE /api/tokens/:id` → revoke. Sanctum PATs. Added T13.
- api: `POST /api/library` {igdb_id} → 201 manual owned_game (canonical game reused via V7 | created from IGDB record); duplicate manual add → 200 existing. Added T14.
- api: `DELETE /api/library/:game_id/manual` → remove manual entry only; platform-synced rows untouchable. Added T14.
- api: `GET /api/discover/search` {q} → IGDB search proxy, each hit {igdb_id, title, cover_url, genres[], release_date, rating?, in_library}. Added T15.
- api: `GET /api/discover/browse` {genre?, sort: rating|release|popularity, page} → IGDB catalogue proxy, same hit shape. Added T15.
- api: `GET /api/discover/similar` → rails [{seed: owned game, similar: [hit...]}], seeds = top-playtime/rated owned w/ igdb_id. IGDB `similar_games`. Added T16.
- api: `GET/POST/DELETE /api/wishlist` → save discovered games w/o ownership claim; POST {igdb_id}, hit shape + added_at + per-platform presence flags; promote = POST /api/library + row removed. Added T17.
- api: `POST /api/wishlist/sync` → 202, dispatch wishlist sync job (throttled ≥5 min like connection sync). Added T20.
- ext: Steam `IWishlistService/GetWishlist` → appids, ! public profile (V15-style error else); titles via store `appdetails` (cached, unauthenticated). READ ONLY — Steam wishlist writes impossible via public API. T20.
- ext: GOG `embed.gog.com/user/wishlist.json` read; `user/wishlist/add/{productId}` + `remove/{productId}` write w/ stored OAuth token (V14 refresh applies). 2-way. T20.
- ext: IGDB `external_games` → igdb_id ↔ steam appid | gog product id mapping (category 1 = steam, 5 = gog). Cached forever. T20.
- api: `GET /api/discover/franchises` → [{franchise, owned: [...], missing: [hit...]}] via IGDB franchise data. Edition/remaster noise accepted v1. Added T18.
- api: `GET /api/discover/upcoming` → hits w/ release_date ∈ next 6 mo, filtered by caller's top owned genres. Added T19.
- api: `GET /api/stats/backlog` → {unplayed_count, est_hours, burndown} (avg hrs/wk last N wks → yrs to clear); shareable card view. ⊥ hidden games counted (V28). Extended T25.
- api: `GET /api/connections/steam/resolve` {steam_id|vanity_url} → 200 {steam_id,persona_name,avatar_url} | 404 no player | 422 invalid input. Pure lookup, ⊥ creates connection. Added T21.
- ext: Steam Web API `GetOwnedGames` — Steam ID + API key; OpenID identity-only, library read via public Web API; returns `playtime_2weeks`
- ext: Steam `ResolveVanityURL` — vanity URL → SteamID64 at connect
- ext: Steam `saleaction/ajaxgetdeckappcompatibilityreport` {nAppID} → undocumented, unauthenticated; `resolved_category`: 0=unknown|1=unsupported|2=playable|3=verified. Best-effort (V31), fetched per Steam owned_game per sync. Added T26.
- ext: IGDB `age_ratings` → category+rating per game; category=ESRB (? exact enum id unverified, confirm live @ build) → `games.esrb_rating` nullable string (E|E10|T|M|AO|RP). Best-effort, fetched once @ match/create (⊥ refetch — rating doesn't change). Added T27.
- ext: IGDB `multiplayer_modes` → per-game rows (campaigncoop/dropin/lancoop/offlinecoop/offlinemax/onlinecoop/onlinemax/splitscreen/splitscreenonline), OR'd across all returned rows → derives `games.multiplayer/coop/local_multiplayer/local_coop` (V32). Best-effort, fetched once @ match/create. Added T27.
- ext: GOG OAuth2 → embedded API for owned games; tokens expire ~1 hr → refresh flow
- ext: IGDB search (title + platform hint) → canonical game record; auth = Twitch OAuth client credentials (app access token), ≤ 4 req/sec
- ext: IGDB `game_time_to_beats` → normally-pace seconds → games.time_to_beat_minutes; best-effort, fail ⊥ fail match. Added T7.
- env: `STEAM_API_KEY`, `TWITCH_CLIENT_ID`, `TWITCH_CLIENT_SECRET`, `GOG_CLIENT_ID`, `GOG_CLIENT_SECRET`, `APP_KEY` (token encryption) ! set
- db: `users` — id, email, created_at
- db: `platform_connections` — id, user_id, platform enum(steam|gog|manual T14), external_account_id, auth_token (encrypted), refresh_token (encrypted, nullable), token_expires_at, last_synced_at, status
- db: `games` — canonical, 1 row per real-world game: id, igdb_id (nullable — provisional when unmatched), title, cover_url, genres[], release_date, time_to_beat_minutes (nullable, T7), themes[], keywords[], game_modes[] (nullable JSON, T22), esrb_rating (nullable string, T27), multiplayer/coop/local_multiplayer/local_coop (nullable bool — null = not yet fetched ≠ false, T27)
- db: `collections` — id, user_id, name, type enum(filter|manual) default filter, filters JSON nullable (required iff type=filter). Added T7, type col T23.
- db: `collection_games` — id, collection_id, game_id, added_at. unique(collection_id, game_id). Membership for type=manual collections only. Added T23.
- db: `owned_games` — 1 row per (user, platform, game): id, user_id, platform_connection_id, game_id, platform_game_id, playtime_minutes (nullable), last_played_at, install_status, added_at, deck_status (nullable enum: unknown|unsupported|playable|verified — null = not fetched/inapplicable ≠ Valve's own "unknown" category, T26)
- db: `playtime_snapshots` — id, owned_game_id, playtime_minutes, captured_at. Appended per sync.
- db: `user_game_meta` — id, user_id, game_id, status enum(unplayed|playing|finished|abandoned), tags[], notes, rating, hidden boolean default false (T25)
- db: `wishlist_items` — id, user_id, game_id, added_at, origin enum(local|steam|gog), steam_present, gog_present, gog_product_id (nullable), suppressed_at (nullable tombstone), synced_at. unique(user_id, game_id). Added T17, sync cols T20.

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
V19: manual adds → per-user synthetic `manual` platform_connection (no tokens, ⊥ sync jobs). V10 upsert key applies → ⊥ duplicate manual rows per game. Manual entries deletable; synced entries ⊥ manual delete.
V20: ∀ discovery responses → `in_library` computed vs caller's owned igdb_ids. ⊥ stale per-user cache: IGDB payloads cacheable globally, ownership overlay per-request. `in_wishlist` same rule (T17).
V21: wishlist ∩ library = ∅. Add-to-wishlist of owned game → 200 no-op w/ in_library flag. Promote to library → wishlist row removed. Wishlist ⊥ counted in library, stats, backlog.
V22: wishlist sync idempotent + asymmetric. Remote removal propagates: item previously platform-present, now absent from pull → local wish deleted (unless other platform still lists | tombstone pending — then flag clears only). ⊥ re-push after remote removal (thrash). Pull steam+gog → upsert (⊥ dups per unique key); local delete → tombstone (`suppressed_at`) → ⊥ re-import while platform still lists it, gog_present rows also pushed remove to GOG; local adds push to GOG only when external mapping resolves; Steam ⊥ pushed ever (no write API). Remote GOG write ≤ 1 per state change. Sync ⊥ run inline (V8 queue).
V23: Steam sync ⊥ pass `include_played_free_games` → matches Steam default, excludes F2P titles "anyone technically owns" (not real library intent). Manual F2P add (T14) unaffected.
V24: Steam sync reflects current Steam state, ⊥ just accretes — owned_games rows w/ platform_game_id ∉ fresh fetch pruned each sync (covers legacy noise + genuinely-removed games). Snapshot history cascades w/ row (V16 data meaningless off-library). V15 null-response (private) short-circuits before reconciliation → never wrongly wipes on privacy flip.
V25: Steam connect ! resolve+display identity (persona_name/avatar) → user ! confirm before `platform_connections` row created. ⊥ blind-linked accounts.
V26: GameMatcher single-title failure (network/auth/rate-limit) ⊥ abort rest of batch → try/catch around searchGame, ⊥ MISS cache write on exception (retried next sync, ≠ genuine no-match). Mirrors existing timeToBeat tolerance.
V27: GameMatcher retries search once w/ trademark glyphs (®™©) stripped when raw title misses — Steam titles carry glyphs IGDB canonical titles ⊥. Retry only fires when stripping changes query (⊥ extra IGDB call on genuine misses: non-game software, edition-suffix mismatches stay correctly unmatched).
V28: hidden (`user_game_meta.hidden=true`) games ⊥ counted anywhere by default — excluded from `/api/library` (⊥ `include_hidden=1`), system smart collections (unplayed/abandoned/quick_wins), `/api/stats/backlog`. Mirrors V21 (wishlist exclusion) pattern but toggle-based, ⊥ separate table. ∀ new library-aggregating endpoint ! apply same exclusion.
V29: manual collections (type=manual) membership explicit via `collection_games` pivot; filter collections (type=filter) evaluate `filters` JSON at read time (existing T7 behavior). ⊥ mixing — manual collection ⊥ has `filters`, filter collection ⊥ has membership rows. `POST/DELETE .../games` 422 on type mismatch.
V30: platform taxonomy (themes/keywords/game_modes) sourced IGDB-only — Steam has ⊥ public tags API, ⊥ scraped. Stored on canonical `games` row (mirrors genres), populated wherever genres already populated (GameMatcher canonicalize, GameFromIgdb, manual/wishlist add) — 1 IGDB fields list extension, ⊥ new endpoint (V4 cache/throttle unchanged).
V31: Deck compat fetch is best-effort, mirrors V11/timeToBeat tolerance — failure ⊥ fail sync, `deck_status` just stays null (not "unsupported"). Fetched only for platform=steam owned_games rows (gog/manual ⊥ attempted, stay null). null ≠ Valve's "unknown" category (stored as string) — null means "we never successfully checked."
V32: multiplayer/coop/local_multiplayer/local_coop derived from single unified source (IGDB `multiplayer_modes`, OR'd across ∀ returned rows) — ⊥ split across coarser game_modes[] taxonomy (T22), avoids 2 sources disagreeing. `local_coop` = offlinecoop ∨ lancoop specifically (splitscreen alone ⊥ imply coop — could be local competitive). null = not yet fetched/best-effort miss ≠ false (mirrors V31).
V33: ESRB rating sourced IGDB `age_ratings` filtered to ESRB category only (⊥ PEGI/CERO/other orgs mixed in). Nullable — unrated | non-ESRB-market games stay null, ⊥ "Not Rated" placeholder. Best-effort, fetched once (rating doesn't change post-release, ⊥ refetch).

## §T tasks

id|status|task|cites
T1|x|Laravel API skeleton + Sanctum auth + email/password accounts|V3
T2|x|Nuxt skeleton + auth flow → Sanctum|V3
T3|x|Steam connect (vanity resolve) + queued sync job + raw ingestion + snapshots|V2,V5,V8,V9,V10,V15,V16,V23,V24,I.steam
T4|x|canonical game matching vs IGDB (Twitch auth), Redis cache, provisional fallback|V4,V7,V11,V26,V27,I.igdb
T5|x|unified library UI: grid, filters, sort, disconnect badges|V1,V12,V13,I.api
T6|x|GOG connect + token refresh + sync — validates multi-platform dedupe|V1,V2,V8,V10,V14,I.gog
T7|x|tags/status/smart collections (quick wins conditional)|V6,V12,I.api
T8|x|backlog stats view + shareable card — burn-down from snapshots|V16,I.api
T9|.|(stretch) iOS client vs existing Sanctum API|V3
T10|x|migrate web/ Nuxt 3 → 4: bump nuxt dep, `app/` srcDir (already set), compat fixes; keep `ssr: false`; auth flow + library UI ⊥ regress|V3,§C.nuxt-mode
T11|x|adopt Tailwind CSS in web/; restyle ∀ views dark slate + teal (login, register, library grid, GameCard, badges)|§C.styling,§C.theme
T12|x|landing marketing page `/welcome`: hero, feature pitch (connect→dedupe→triage backlog), CTA → register/login; public route in auth.global; guests hitting `/` → `/welcome`|§C.landing,§C.theme
T13|x|profile page `/profile`: account section (email/password change vs current_password), connected services (list + connect steam/gog + sync now + disconnect vs existing I.api), API keys (list/create w/ once-shown plaintext/revoke)|V2,V13,V17,V18,I.api,§C.theme
T14|x|manual add: `manual` platform enum + synthetic connection, POST /api/library {igdb_id}, manual delete, library UI add/remove affordances|V1,V7,V10,V19,I.api
T15|x|discover: /api/discover search+browse (IGDB proxy, Redis cache, in_library overlay), FE /discover page — search bar, browse grid w/ genre/sort, add-to-library button|V4,V19,V20,I.igdb,§C.discovery,§C.theme
T16|x|similar-games rails: seeds from top owned, IGDB similar_games cached, "Because you played X" sections on /discover|V4,V20,§C.discovery
T17|x|wishlist core: wishlist_items table, GET/POST/DELETE /api/wishlist (games created from igdb_id via shared GameFromIgdb service), in_wishlist overlay, wishlist page + promote-to-owned flow|V7,V20,V21,I.api
T18|.|franchise gaps: IGDB franchise lookup for owned games, /api/discover/franchises, "complete the series" rail|V4,V20,§C.discovery
T19|.|upcoming releases: IGDB release_dates ∈ 6 mo window × caller top genres, /api/discover/upcoming, rail on /discover|V4,V20,§C.discovery
T20|x|wishlist platform sync: queued job — pull steam (read-only, appdetails titles) + gog wishlists, tombstone suppression, push local add/remove → GOG via external_games mapping, POST /api/wishlist/sync throttled, sync status in wishlist UI|V8,V14,V15,V21,V22,I.gog,I.steam,I.igdb
T21|x|Steam connect identity confirm: resolve+preview (persona_name/avatar) before creating connection, FE two-step confirm|V25,I.steam
T22|x|advanced filtering: pull IGDB themes/keywords/game_modes onto `games` (extend IgdbGameAttributes + IgdbClient field lists, populate at match/create time), extend GET /api/library filter vocab (theme/keyword/game_mode params), FE filter UI. User freeform tags (T7) unaffected|V4,V30,I.api
T23|x|manual collections: `collections.type` enum(filter\|manual), `collection_games` pivot, POST/DELETE /api/collections/:id/games, FE add-to-collection / remove-from-collection affordance|V29,I.api
T24|x|game detail view: GET /api/library/:game_id show route, FE /games/:game_id page — cover/genres/platforms (+ deck_status badge per platform, T26)/time_to_beat/themes/keywords/game_modes/esrb_rating/multiplayer+coop+local badges (T27) + editable status/tags/rating/notes via existing PUT meta|I.api
T25|.|show/hide: `user_game_meta.hidden` bool, PUT meta `hidden?`, GET /api/library exclude-by-default + `include_hidden=1` toggle, exclude hidden from smart collections + backlog stats, FE hide/unhide button + reveal-hidden toggle|V28,I.api
T26|.|Steam Deck status: `owned_games.deck_status` nullable enum, fetch via undocumented saleaction/ajaxgetdeckappcompatibilityreport per Steam owned_game every sync (best-effort, V31), GET /api/library platforms[] carries deck_status + `deck_status[]` multi-select filter, FE badge on GameCard + detail view (T24) + filter UI|V31,I.steam
T27|.|ESRB + multiplayer flags: IGDB `age_ratings` (ESRB category) → `games.esrb_rating`, IGDB `multiplayer_modes` → `games.multiplayer/coop/local_multiplayer/local_coop` (unified source, V32), both best-effort @ match/create time (mirrors time_to_beat, T7); GET /api/library filter vocab extended (esrb, multiplayer, coop, local_multiplayer, local_coop), FE filter UI + detail view (T24) badges|V32,V33,I.igdb

## §B bugs

id|date|cause|fix
B1|2026-07-13|browser "CORS" error local = no server bound to apiBase http://localhost:8000; Herd site `gameshelf` pointed at `web/.output/public` ⊥ `api/public`; Laravel CORS defaults fine (ACAO *)|Herd relink → api/, web/.env apiBase, §C.dev-topology
B2|2026-07-14|T20 dev: V22 unspecified for remote removal — pull-absent + gog_present cleared flag → push re-added → remote write thrash. Caught by idempotency test pre-commit|V22 extended
B3|2026-07-14|SteamClient::getOwnedGames set `include_played_free_games=1` → Steam auto-included F2P titles user never chose ("anyone technically owns" per Steam docs)|V23
B4|2026-07-14|SteamSyncer only ever upserted, ⊥ removed rows absent from fresh fetch → V23 fix stops new noise but existing stale rows (B3, + any genuinely-removed games) persist forever|V24
B5|2026-07-15|GameMatcher::match() ⊥ try/catch around searchGame — early syncs (before Twitch creds configured) threw on first attempt, aborting rest of batch → 529/770 games stuck provisional (no cover) w/ no retry path. Live-verified 19/20 sample matches instantly once creds valid|V26
B6|2026-07-15|GameMatcher searched raw Steam title only — titles w/ ®™© (LEGO® Worlds, Titanfall® 2) never matched IGDB's glyph-free canonical titles, stayed provisional forever (no retry differs by title, cache MISS permanent)|V27
