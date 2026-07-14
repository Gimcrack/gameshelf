# FORMAT — SPEC.md caveman encoding

Rules for SPEC.md writes. Skills spec/build/backprop/check read this.

## Sections (fixed order)

- §G goal — 1-line caveman goal + source ref.
- §C constraints — bullet list. Stack, limits, tradeoffs, non-goals.
- §I interfaces — bullets prefixed `api:` | `ext:` | `env:` | `db:`. External surfaces only.
- §V invariants — numbered `V1:`… Properties that must always hold.
- §T tasks — pipe table `id|status|task|cites`.
- §B bugs — pipe table `id|date|cause|fix`.

## Symbols

- `!` must / required
- `⊥` not / never / excluded / out of scope
- `?` maybe / uncertain / needs confirm
- `∀` for all / every
- `∈` / `∉` member / not member
- `→` leads to / becomes / returns
- `|` or (inline alternatives)
- `≠` distinct from

## §T table

- `id`: T1, T2… monotonic, never reuse.
- `status`: `.` pending, `x` done.
- `task`: caveman, one line, verbatim paths/idents/code.
- `cites`: §V/§I/§C deps, comma-sep: `V2,I.api,§C.nuxt-mode`.

## §B table

- `id`: B1… monotonic.
- `date`: YYYY-MM-DD.
- `cause`: root cause, caveman.
- `fix`: V-ref if invariant added (`V17`), else short fix note.
- Every bug gets §B row. New invariant preferred, optional.

## Style

- Caveman everywhere: drop articles/filler, fragments OK.
- Preserve identifiers, paths, code, env vars verbatim.
- Numbering monotonic — never reuse §V.N, §T.N, §B.N.
- Amendments annotate in place ("Added T5", "Was Nuxt 3 → upgrade T10") — no silent rewrites.
