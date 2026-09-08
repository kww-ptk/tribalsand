# AI Availability & Price Assistant — Plan

**Status:** Phase 1 (tool layer) + Phase 2 (RAG) BUILT & tested locally; deploy to prod RDS/ECS pending. Phase 3 (guest widget) not started.
**Author decision date:** 2026-09-08
**One-line:** An AI that answers "what's free for 4 pax from X to Y and what does it cost?" by **calling our live system**, not by reading a stale snapshot.

---

## 1. The core decision (and why)

The instinct is "build a RAG system." **For availability and prices, that is the wrong tool, and we are deliberately not doing it.**

RAG (retrieval-augmented generation) finds text that *looks similar* to the question and has the model write an answer from it. That is excellent for prose ("what's the villa like?") and useless — dangerous, even — for facts that are exact and live:

- A date range **either overlaps a booking or it doesn't.** That is arithmetic, not similarity.
- A price is **one specific number** produced by our rate resolver, not a paragraph to summarise.
- RAG works off a **snapshot** that is stale the moment someone books.
- When RAG is unsure it **guesses** — and a guessed price or a guessed "yes it's available" is precisely the mistake that causes chargebacks and double-bookings.

**What we do instead: tool calling.** The AI does not *know* the answer. It extracts the dates and pax from the question, **calls a read-only lookup in our own system**, and reads back the real result. The truth always originates in our database, so nothing is fabricated.

We keep RAG **only** for the descriptive layer (property character, policies, FAQs, activities). Two layers, cleanly separated:

| Question type | Mechanism | Source of truth |
|---|---|---|
| "What's free for 4 pax 12–15 Oct? Price?" | **Tool call** → PHP lookup | Live DB / rate resolver |
| "What's the Zuri villa like? Cancellation policy?" | **RAG** → pgvector | Embedded DB content |

This document plans **the tool layer first** (Phase 1). RAG is Phase 2.

---

## 2. What already exists (we are wrapping, not building)

The availability/price lookup is ~90% done. From `includes/db.php` and `api/check-availability.php`:

- **`ts_search_availability(string $check_in, string $check_out, int $guests = 1): array`**
  Cross-property: returns each published venue with its available, **already-priced** rooms, respecting the whole-villa vs individual-room mutual-exclusion. **This is the function the primary tool wraps.**
- **`room_stay_quote(int $room_id, float $default_price, string $check_in, string $check_out): array` → `['nights','total']`**
  The **single** pricing resolver (delegates to `rates_nightly_map()`). Returns `nights => 0` for an unparseable window ("not a quote") — callers must reject that.
- **`find_available_unit(int $room_id, string $ci, string $co)`** — per-room free/booked check.
- **`rates_window_ymd(string)`** — the read-window date validator; how `check-availability.php` 422s on bad input.

**Hard rule inherited from the codebase:** there is exactly one nightly-pricing loop, and the AI lookup **must call `room_stay_quote()`** — never re-implement price math. Two summations over one rate map is how two guests get quoted two different prices for the same night. The AI must never be able to introduce a second pricing path.

---

## 3. Architecture

```
Guest/staff question
        │
        ▼
  api/assistant.php  ──►  includes/ai.php  (provider adapter: chat_with_tools)
        │                        │
        │                        ├─ tool: check_availability(ci, co, guests[, venue])
        │                        ├─ tool: quote_stay(room_slug, ci, co)
        │                        ├─ tool: list_properties()
        │                        └─ (Phase 2) rag_search(question) → pgvector
        │
        ▼
  includes/assistant-tools.php  (thin, READ-ONLY wrappers over existing helpers)
        │
        ▼
  ts_search_availability() / room_stay_quote()  →  Postgres (live truth)
```

**Provider is a swappable detail.** OpenAI, Claude, and Gemini all do tool calling well. We hide the vendor behind **one file** (`includes/ai.php`, a single `chat_with_tools()` entry point). The tool layer and RAG layer are provider-agnostic; only that one file knows who the vendor is. Pick the specific model/pricing at build time from current docs — do not hardcode a model chosen today.

**The loop** (standard agentic tool-use):
1. Send the user message + tool definitions to the model.
2. If the model asks to call a tool → run the PHP wrapper → return the JSON result to the model.
3. Repeat until the model produces a final natural-language answer.
4. Return that answer (plus, ideally, the structured tool result so the UI can render a real availability card, not just prose).

---

## 4. Guardrails (non-negotiable)

1. **Read-only.** Every tool is a lookup. The AI can **quote**; it can **not** write a booking, place a hold, or mutate anything. Booking stays in the existing hold flow. A hallucination must never be able to create a commitment.
2. **One pricing path.** Tools call `room_stay_quote()`; no independent price math. Reject `nights === 0`.
3. **The model interprets; PHP decides.** The model's only jobs are: pull dates/pax out of the sentence, choose the tool, phrase the answer. All correctness lives in PHP.
4. **Fail honest, not confident.** If dates are ambiguous/invalid, the tool returns a structured error and the AI **asks a clarifying question** — it must not guess a date.
5. **Timezone.** Dates resolve in `Africa/Nairobi` (app-wide rule); "today"/"this weekend" must anchor to Nairobi-local, matching the DB.
6. **Scope by role (when internal).** Staff/manager assistant is scoped by `admin_venue_ids()` exactly like the rest of admin — a manager's assistant sees only their properties.

---

## 5. Phased delivery

### Phase 1 — Internal availability/price assistant (tool layer, no RAG)
The whole "what's available for N pax from X to Y, and the price" use case, staff-facing and low-risk.

- `includes/assistant-tools.php` — read-only wrappers: `tool_check_availability()`, `tool_quote_stay()`, `tool_list_properties()`. Each returns clean JSON + a structured error shape.
- `includes/ai.php` — provider adapter, `chat_with_tools($messages, $tools)`; runs the tool-call loop; keys from env.
- `api/assistant.php` — session-authed (admin), CSRF on POST, scoped by `admin_venue_ids()`; runs the loop; returns `{answer, tool_result}`.
- Admin UI — a simple chat panel in the portal (reuse the existing chat/polling styling; no native chrome per house rule).
- **Exit criteria:** staff ask in plain English and get an answer that **exactly matches** what the booking widget/`admin/rates.php` shows for the same dates. Validated against known bookings before anyone trusts it.

### Phase 2 — RAG for descriptions — **BUILT & tested locally (2026-09-08)**
- Enable **pgvector** on RDS (`CREATE EXTENSION vector`). Confirm RDS Postgres version supports it (it does on current versions). ✅ Migration `db/migrations/add_content_embeddings.sql` runs `CREATE EXTENSION IF NOT EXISTS vector` + the table + an HNSW cosine index; verified available on Neon (dev, PG 18.6).
- `content_embeddings` table (source, source_id, venue_id, title, chunk_index, chunk_text, content_hash, embedding vector(1536), updated_at). ✅
- `bin/reindex-content.php` — chunk + embed DB-driven prose (venue about/stay copy, room descriptions + features + FAQs, tours, sustainability). ✅ Idempotent (skips unchanged chunks by `content_hash`, prunes removed docs). **Reindex-on-save is a documented follow-up, not wired** — run the CLI after copy edits.
- Add a `search_property_info(query)` tool; the AI uses it for descriptive questions and the availability tools for factual ones — same loop, richer answers. ✅ Wired opt-in via `assistant_tool_definitions($withRag)` (Phase-1 shape unchanged); `api/assistant.php` passes `rag_supported()`.
- Embeddings: **OpenAI `text-embedding-3-small` (1536 dims)**, independent of the chat provider (`ai_embed()` in `includes/ai.php`). `rag_search()` orders by cosine distance with a `RAG_MIN_SCORE` floor for honest "I don't have that".
- Tests: `php tests/assistant_rag.php` (all pass; embed mocked, DB round-trip rolled back). Verified live end-to-end on OpenAI: descriptive Qs route to `search_property_info` and answer from retrieved content; Phase-1 factual tools unchanged.
- **OPEN (deploy):** apply `add_content_embeddings.sql` to prod RDS (via `/admin/migrate.php`), set `OPENAI_API_KEY` (or `AI_EMBED_KEY`) in ECS env, then run `php bin/reindex-content.php` once against prod. pgvector must be enabled on the RDS instance (available on current engine versions).

### Phase 3 — Guest-facing concierge widget
- Same engine behind a public endpoint, guarded like every other public form: **Turnstile fail-closed**, **IP rate-limit via `client_ip()`**, CSRF, strict read-only tools.
- Still quote-only: the AI hands off to the existing booking/enquiry flow to actually hold a room.

---

## 6. Requirements

### Functional
- FR1 — Answer natural-language availability questions ("anything free at Zuri next weekend for 4?") with live free/booked status.
- FR2 — Return the real price for a specific room + date range, identical to the booking widget's quote.
- FR3 — Handle cross-property ("anywhere free for 6 in December?") via `ts_search_availability()`.
- FR4 — Ask a clarifying question when dates/pax are missing or ambiguous; never guess.
- FR5 — (Phase 2) Answer descriptive questions from embedded content, with a graceful "I don't have that" when nothing relevant is retrieved.
- FR6 — Return a structured tool result alongside prose so the UI can render an availability card + a "continue to book" link into the existing flow.

### Non-functional
- NFR1 — **Correctness:** assistant price/availability must never diverge from the canonical resolver. This is the acceptance bar.
- NFR2 — **Read-only:** no tool mutates state.
- NFR3 — **Provider-swappable:** changing vendor is editing `includes/ai.php` only.
- NFR4 — **Pre-migration-safe / degrade gracefully:** if the AI key or pgvector is absent, the feature is hidden/disabled, never 500s (house pattern: `*_supported()` guards).
- NFR5 — **Timezone-correct:** Nairobi-local date resolution.
- NFR6 — **Secure:** internal = session + `admin_venue_ids()` scope; public = Turnstile + rate-limit + CSRF.
- NFR7 — **Observable & bounded cost:** log each turn (question, tools called, tokens); cap tool-call iterations per request.
- NFR8 — **Testable:** tool wrappers unit-tested in a rolled-back transaction (house pattern, e.g. `tests/assistant_tools.php`) — the AI call is mocked; the lookups are asserted against real DB rows.

### Environment (new)
```
AI_PROVIDER=            # claude | openai | gemini
AI_API_KEY=            # provider key (never committed)
AI_MODEL=              # chosen at build time from current docs
# Phase 2:
# pgvector extension on RDS; embedding model TBD at build time
```

---

## 7. Open decisions (defer to build time)
- **Provider + exact model + pricing** — check current docs when building; do not pin a stale model now. (For Claude, run the `claude-api` skill for live model IDs/pricing.)
- **Embedding model** for the pgvector index (Phase 2).
- **Cost controls** — per-request iteration cap, and whether guest-facing gets a daily budget/rate cap beyond IP limiting.

---

## 8. Risks
| Risk | Mitigation |
|---|---|
| AI quotes a price that differs from the booking page | Tools call `room_stay_quote()` only; acceptance test compares against the widget for identical dates |
| AI invents availability when unsure | Read-only tools return live truth; ambiguous input → clarifying question, never a guess |
| Vendor lock-in / price change | One adapter file; architecture provider-agnostic |
| AI creates a real booking | No write tools exist; booking stays in the human/existing flow |
| pgvector unavailable on RDS | Verify before Phase 2; Phase 1 needs no vector store at all |
| Runaway token cost | Iteration cap + logging + (guest) rate/budget limits |

---

## 9. First slice to build
**Phase 1, `tool_check_availability()` + `tool_quote_stay()` + a minimal `api/assistant.php`, admin-only, one provider.** No RAG, no guest exposure. It delivers the headline use case and lets us prove correctness against the live booking data before widening the audience.
