# UnysonPlus Theme Builder — Architecture & Implementation Plan

> Status: **PLANNING** (no code yet). This document is the source of truth for building the
> `theme-builder` extension and for documenting it later on https://unysonplus.github.io/docs/.
> Decisions locked with the user: **Bundle (Divi-faithful) template model**, **Phase 1 first**,
> planning doc written here.
>
> **DECISION (supersedes "extend" framing below): DROP the `header-footer-builder` extension
> entirely and absorb it into `theme-builder`.** The HF builder has **never been used in
> production**, so there is **no data to migrate and no back-compat to preserve**. The new
> `theme-builder` extension OWNS the header/footer CPTs and render path; the old extension folder is
> removed (working copy + the three XAMPP mirrors), and the theme's hooks are rewired to the new
> extension. Wherever this doc says "extend the HF builder," read it as "absorb its code into
> `theme-builder` and delete the original."

A Divi-style Theme Builder for UnysonPlus: create global **Header**, **Body**, and **Footer**
templates, bundle them into a **Template** with conditional assignment rules ("Use On / Exclude
From"), and render the winning template per request. Ships as its own extension, distributable in
child themes via an FSE-style file/DB hybrid.

---

## 1. What already exists (we build ON this — do NOT duplicate)

UnysonPlus is ~60% of the way to a Theme Builder already. Confirmed by code exploration:

### 1.1 Page builder — content storage & render (REUSE wholesale)
- **Storage:** post meta `fw:opt:ext:pb:page-builder:json` = JSON string of the builder tree
  (`section → row → column → simple`), plus an `fw_options` meta carrying
  `page-builder.builder_active` (the flag that makes the theme render builder content).
  - Storage class: `…/page-builder/includes/page-builder/includes/option-storage/class-fw-option-storage-type-post-meta-page-builder.php` (`_save()`, key `fw:opt:ext:pb:page-builder:json`).
- **Render path:** `the_posts` (prio 2) / `the_content` →
  `FW_Extension_Page_Builder::_filter_the_posts()` checks `is_builder_post()` →
  `get_post_content_shortcodes()` → `FW_Option_Type_Page_Builder::json_to_shortcodes()`
  (decode → `get_value_from_items()` → items-corrector → `get_shortcode_notation()`) →
  `do_shortcode()` → wrapped in `.fw-page-builder-content`.
  - Main class: `…/page-builder/class-fw-extension-page-builder.php` (`_filter_the_posts()` ~line 437, `is_builder_post()` ~line 324, `get_post_content_shortcodes()` ~line 380).
  - Option type: `…/page-builder/includes/page-builder/class-fw-option-type-page-builder.php` (`json_to_shortcodes()` ~line 439, `get_shortcode_notation()` ~line 387).
- **Editor-load gotcha (already documented in CLAUDE.md):** `get_value_from_attributes` runs in the
  items-corrector path, NOT on normal editor load — the modal opens with raw saved atts. Any new
  option-shape change needs a JS-side migrator. Not directly relevant to Phase 1 but keep in mind.

### 1.2 Header/Footer Builder extension (ABSORB & DELETE — never used in production)
> We lift its CPTs + render helper into `theme-builder` and **remove this extension**. No migration
> (no real data exists). The notes below document what we're absorbing.
- **Path (to be removed):** `framework/extensions/header-footer-builder/` (slug `unysonplus-header-footer-builder`, v1.0.3).
- **CPTs:** `up_header`, `up_footer` — registered in
  `class-fw-extension-header-footer-builder.php` (`_action_register_post_types()` ~line 51).
  - `public => false`, `show_in_menu => 'themes.php'`, **every capability mapped to
    `edit_theme_options`**, supports `title/editor/revisions`, page-builder support added in
    `hooks.php` (~line 22, after page-builder init at prio 10000).
  - Sentinel constant `UP_HFBUILDER_OWNS_CPTS` prevents the theme's fallback registration.
- **Render:** `fw_ext_hfbuilder_render($post_id, 'header'|'footer')` in `helpers.php` (~line 20):
  validates CPT + `publish` status, recursion-guards, pulls builder content via
  `fw_ext_page_builder_get_post_content()`, strips auto-generated wrapper sections
  (`fw_ext_hfbuilder_unwrap_auto_sections()`, filter `fw_ext_hfbuilder_strip_auto_sections`),
  `do_shortcode()`s, returns INNER HTML (theme adds `<header>`/`<footer>`).
- **Per-page assignment (LIMITED — this is what we generalize):** post meta `header_preset` /
  `footer_preset` (a preset post ID or `''`), injected as a side meta box via `fw_post_options`
  (`unysonplus_inject_preset_pickers()` in theme `inc/includes/header-footer-presets.php` ~line 419).
  Site-wide defaults: `general_pages.default_header_preset` / `default_footer_preset`.
- **NO conditional rules engine** (no per-category, per-post-type, per-archive, 404/search
  assignment). This is the gap.

### 1.3 Theme render integration points (already hook-friendly)
- **Header:** `template-parts/header-builder.php` branches on
  `unysonplus_get_active_header_render()` → `{ mode: 'builder', post_id, type, behavior }` vs
  `{ mode: 'slots', config }`. Resolution in `inc/includes/header-footer-presets.php`
  (`unysonplus_get_active_header_render()` ~line 290) and layout cascade
  `unysonplus_resolve_layout()` in `inc/includes/layout.php` (~line 281).
- **Footer:** mirror via `unysonplus_get_active_footer_render()`.
- **Body:** `page.php` / `single.php` → `get_header()` → `unysonplus_main_wrapper_open()` →
  `the_content()` → `unysonplus_main_wrapper_close()` → `get_footer()`.
  `unysonplus_is_page_builder_post()` already lets a builder post own the full width (skips the
  `.container/.with-sidebar` wrapper) — the seam a body template plugs into.
- **Rich hook surface** (theme `HOOKS.md`): `unysonplus_before/after_header`,
  `unysonplus_header_top/bottom`, `unysonplus_before/after_footer`, `unysonplus_before/after_main`,
  `unysonplus_before/after_loop`, `unysonplus_entry_*`, etc.
- **Classic PHP theme** — NOT block/FSE (no `theme.json`, no `/templates/*.html`). Our "FSE hybrid"
  is a borrowed *pattern* (file = default, DB = override), not actual block templates.

### 1.4 The conditional rules engine to generalize (`sidebars` extension)
- **Path:** `framework/extensions/sidebars/`. This is the closest existing "Use On" engine.
- **Condition vocabulary (REUSE):** prefixes `pt` (post types / specific posts by ID),
  `tx` (taxonomies / terms), `ar` (post-type archives), `ct` (conditional tags:
  `is_front_page`/`is_search`/`is_404`/`is_author`/`is_archive` with `check_priority` first|last),
  `df` (default / all).
  - Config: `includes/class-fw-extension-sidebars-config.php` (~line 8).
- **Storage:** a WP option; per type → `common` rule (+ `by_ids` array of specific-ID rules with
  `timestamp`s) → most-specific wins, newest `timestamp` breaks ties.
- **Evaluation cascade (REUSE the ordering):** conditional tags (first) → singular by ID →
  post-type archive → taxonomy term → taxonomy → conditional tags (last) → default.
  - Frontend: `includes/class-fw-extension-sidebars-frontend.php` (`get_preset_sidebars()` ~line 274,
    `_fw_check_conditional_tags()` ~line 218). Uses native `call_user_func` on whitelisted WP
    conditionals — **no `eval`**.

### 1.5 Extension & CPT patterns to follow
- Extension = `manifest.php` + `class-fw-extension-<name>.php extends FW_Extension` (`_init()` is the
  lifecycle entry; read settings on `init`, not in `_init`). Base:
  `framework/core/extends/class-fw-extension.php`.
- CPT registration model: `portfolio` extension (`_action_register_post_type()` on `init`).
- User-defined config + admin save (PRG, nonce, `fw_get_options_values_from_input`,
  `fw_set_db_ext_settings_option`): `post-types` extension.
- `template_include` override precedence (let theme templates win first, fall back to plugin views,
  last resort inject via `the_content`): `portfolio/hooks.php` (~line 28).
- Per-post option box injection: `fw_post_options` filter.

---

## 2. What's missing (the actual work)

1. **Conditional assignment engine** — "Use On / Exclude From" generalized from `sidebars` into a
   shared, reusable resolver keyed to templates.
2. **Body templates** (`up_body` CPT) — Divi's "Custom Body". Does not exist at all. Biggest piece.
3. **Bundling Template** (`up_template` CPT) — binds header+body+footer + one conditions block. The
   "card" in the Divi grid.
4. **Unified Theme Builder admin UI** — the Divi-style card grid (Default Website Template + per-
   condition templates), each card = header/body/footer slots + a conditions popup.
5. **FSE file/DB hybrid** — ship templates as child-theme `up-templates/*.json`, seed to CPTs with a
   manual-edit guard, user edits override to DB.
6. **Dynamic content** for single-post body templates (Post Title / Post Content / Featured Image /
   Meta elements) — the hardest part; **Phase 2**.

---

## 3. Chosen architecture — Bundle (Divi-faithful)

### 3.1 Data model
Four CPTs, **all owned by `theme-builder`** (the HF builder is gone). Parts hold layout; the Template
binds them + conditions (no layout of its own).

| CPT | Origin | Holds | Caps |
|---|---|---|---|
| `up_header` | absorbed from HF builder | builder content (header) | `edit_theme_options` |
| `up_footer` | absorbed from HF builder | builder content (footer) | `edit_theme_options` |
| `up_body` | **NEW** | builder content (full-page body) | `edit_theme_options` |
| `up_template` | **NEW** | refs `header_id`/`body_id`/`footer_id` (each optional) + `conditions` block | `edit_theme_options` |

> CPT slugs `up_header` / `up_footer` are **kept** (clean re-registration in the new extension, not a
> rename) so the theme's existing references and the `UP_HFBUILDER_OWNS_CPTS` sentinel logic carry
> over with minimal churn. The render helper `fw_ext_hfbuilder_render()` is reimplemented in
> `theme-builder` (or replaced by a `theme-builder`-namespaced renderer with a thin back-compat
> shim, since the theme calls it directly).

- A `up_template` stores **no builder JSON** — only references + conditions (post meta).
- `header_id`/`body_id`/`footer_id` optional: a missing slot falls through to the existing
  per-page override → site-wide default → global Theme Settings (header/footer) or normal loop (body).
- **"Default Website Template"** = the `up_template` whose condition is `df` (default / all). Exactly
  the first card in the user's Divi screenshot.

### 3.2 Conditions block (on each `up_template`)
```
conditions = {
  use_on:       [ rule, … ],   // OR-ed: template applies if ANY matches
  exclude_from: [ rule, … ],   // template suppressed if ANY matches (wins over use_on)
}
rule = {
  type:     'pt' | 'tx' | 'ar' | 'ct' | 'df',
  sub_type: 'page' | 'post' | '<cpt>' | 'category' | 'post_tag' | '<taxonomy>'
            | 'is_front_page' | 'is_404' | 'is_search' | 'is_home' | …,
  ids:      [ int, … ],   // specific post IDs or term IDs; empty = "all of sub_type"
}
```
Pure typed data. Evaluated only with native WP conditionals (`is_page`, `in_category`,
`is_post_type_archive`, `is_404`, `is_search`, …). **No `eval`, no query string concatenation,
no request-derived includes.**

### 3.3 Resolver (shared service)
On each front-end request, once, cached:
1. Collect all published `up_template`s.
2. Keep those where **any** `use_on` rule matches the current request.
3. Drop those where **any** `exclude_from` rule matches.
4. Rank survivors by **specificity** (reuse `sidebars` ordering):
   specific post/term ID > taxonomy term > post-type archive > conditional tag > default (`df`).
   Tie-break by newest `timestamp` (mirror `sidebars`).
5. Winner supplies `header_id` / `body_id` / `footer_id`.
6. Existing per-page `header_preset` / `footer_preset` post meta = **highest-specificity override**
   layered on top (so a single page can still override the matched template's header/footer).

### 3.4 Render integration
- **Header/Footer (small change):** extend `unysonplus_get_active_header_render()` /
  `…_footer_render()` so, when no per-page override is set, they consult the resolver for the winning
  template's `header_id` / `footer_id`. The templates already branch builder-vs-slots — minimal edit.
- **Body (new path):** hook `template_include` (preferred) — when the resolver yields a `body_id`,
  render that `up_body`'s builder content as the page body. Respect precedence: an explicit theme
  page template (`page-*.php` with `Template Name:`) and `unysonplus_is_page_builder_post()` on the
  actual queried post should be honored per the rules below.
- **Precedence (Phase 1, static bodies):**
  1. The queried post is itself a builder post (`builder_active`) → render its own content (a body
     template does NOT override a page the user explicitly built). *Decision to confirm in build.*
  2. Else a matching `up_template.body_id` → render that body template.
  3. Else normal theme loop.

### 3.4a Header/Footer fallback chain — the theme's slot-based settings are the BASELINE (NOT absorbed)
There are TWO header/footer systems; only the unused one is absorbed:
- **HF Builder presets** (`up_header`/`up_footer`, builder content) → absorbed into `theme-builder`.
- **Theme Settings slot-based header/footer** (theme-native: Header topbar/main/bottombar columns +
  layout types + sticky/transparent; Footer pre/main/post columns + copyright; rendered by the theme
  in "slots mode" via `template-parts/header-builder.php` / `footer-builder.php` + the
  `hf-custom-css.php` CSS pipeline) → **STAYS IN THE THEME. Do NOT absorb.**

**Why the slot system stays in the theme:** it's theme-native markup + CSS generation, and it is the
**graceful-degradation baseline** — the `theme-builder` extension is one optional extension; if it's
disabled or absent from a child theme, the theme must still render a working header/footer. Moving the
baseline into the extension would leave a deactivated site headerless/footerless. This mirrors Divi:
the Customizer's default header/footer is separate from the Theme Builder and serves as the fallback.

**Resolved precedence (header; footer identical):**
```
1. Theme Builder template matched by condition → header_id   (builder mode)   ← NEW (this extension)
2. Per-page override (header_preset post meta)               (builder mode)
3. Site-wide default (general_pages.default_header_preset)   (builder mode)
4. Theme Settings slot-based header                          (slots  mode)   ← FINAL FALLBACK (theme)
```
The two-mode resolver already implements steps 2–4 (`unysonplus_get_active_header_render()` returns
`builder` when a valid preset is assigned, else `slots`). Phase 1 only inserts step 1 ahead of the
per-page override when no per-page override is set.

### 3.4b What else to absorb / keep (scope guardrails)
| Feature | Action | Rationale |
|---|---|---|
| HF Builder extension (`up_header`/`up_footer`) | **Absorb + delete** | Unused; conceptually IS the Theme Builder |
| Per-page `header_preset`/`footer_preset` pickers | **Move ownership to `theme-builder`** | Drive builder-mode selection; the per-page override layer (step 2) |
| Site-wide `default_*_preset` (Theme Settings → General → Pages) | **Keep in theme; wire as step 3** | Global default; a theme-level setting |
| Slot-based Theme Settings header/footer | **Do NOT absorb (keep in theme)** | Theme-native baseline + graceful degradation = the fallback (step 4) |
| Sidebars conditional engine | **Reuse the matching logic; do NOT absorb the extension** | It's a used feature in its own right |
| `page-*.php` layout templates | **Keep** | Layout-chrome variations; body templates set similar flags but these remain |

### 3.5 FSE file/DB hybrid (Phase 3, but design now)
- Child theme ships `up-templates/*.json`. Each file:
  ```
  { "name": "...", "conditions": { "use_on": […], "exclude_from": […] },
    "header": <builder tree | null>, "body": <builder tree | null>, "footer": <builder tree | null> }
  ```
- On theme activation / import: seed into CPTs **only if absent and not user-modified** — reuse the
  existing `_upw_import_hash` **manual-edit guard** (same pattern as
  `unysonplus-website/wordpress/import.php` and the demo importers). File = pristine default; DB =
  override; `UPW_FORCE=1` re-seeds **only after** folding manual edits back to the source JSON.
- DB-first → works on read-only hosts (WP Engine). Files are read-only seeds, never written from
  user input.

---

## 4. Security model (boundaries — enforce all)

1. **User edits are data only, never executable.** Parts/templates are CPTs (DB). Nothing a user
   edits is ever written to a `.php` file or an `include()` path. The shipped `.json` seeds are the
   only files, and they are read-only data.
2. **Caps + nonce + sanitize on every write.** Gate on `edit_theme_options` (the cap `up_header`/
   `up_footer` already map all capabilities to). Nonce every save. Sanitize builder JSON through the
   existing page-builder save path; whitelist condition `type`/`sub_type` keys and cast `ids` to int.
3. **Resolver never includes request-derived paths.** It maps matched template → registered part ID
   only. No `include($_GET[...])`, no path built from request data (no LFI).
4. **Conditions evaluated via whitelisted WP conditionals only** — like `sidebars`’ `call_user_func`
   on a fixed list. No `eval`, no dynamic callable from stored strings outside the whitelist.
5. **Front-end output = existing builder XSS posture**, but a global header/footer/body renders on
   many pages → larger blast radius. Lean on existing builder sanitization; treat globals as higher-
   stakes. `wp_kses` any free-HTML at render where the builder doesn't already.
6. **Optional future "export to theme"** (NOT Phase 1): locked to fixed `up-templates/` folder,
   hard-coded `.json` extension (never derived from input), `sanitize_file_name()` + slug whitelist,
   reject path traversal, `edit_theme_options` + nonce, honor `DISALLOW_FILE_MODS`. Default: don't
   write files at all.

---

## 5. Phased implementation

### Phase 1 — Assignment engine + Template grid + header/footer + STATIC body templates
Goal: ship the Divi card UI and conditional assignment for the parts that already render, plus
static body layouts (404, archives, landing, "all pages" custom layouts). High value, low risk.

1. **Scaffold the `theme-builder` extension** — `manifest.php`
   (slug `unysonplus-theme-builder`, requires `shortcodes`, guard `page-builder` at runtime),
   `class-fw-extension-theme-builder.php`, `hooks.php`, `helpers.php`. Maps to a NEW GitHub repo
   **`UnysonPlus-Theme-Builder-Extension`** (name confirmed with the user — fully hyphenated, matching
   the sibling `UnysonPlus-Header-Footer-Builder-Extension`). **This repo does not exist yet** — it
   must be created on GitHub (e.g. `gh repo create`) before the copy/commit/push workflow in CLAUDE.md
   applies.
2. **Absorb the HF builder, then delete it.**
   - Move `up_header` / `up_footer` registration + the Type/Behavior meta box + the
     `fw_ext_hfbuilder_render()` / `fw_ext_hfbuilder_unwrap_auto_sections()` helpers + the
     `UP_HFBUILDER_OWNS_CPTS` sentinel into `theme-builder` (keep slugs, keep function names or add a
     thin back-compat shim since the theme calls them directly).
   - **Remove** `framework/extensions/header-footer-builder/` from the working copy AND the three
     XAMPP mirrors (`htdocs`, `htdocs/demos`, `htdocs/sshots`). Archive its GitHub repo
     **`UnysonPlus-Header-Footer-Builder-Extension`** (confirmed present under `Github Repository/`;
     no longer shipped — archive on GitHub rather than delete, so history is retained). No data
     migration — it was never used.
   - **Rewire the theme** (`unysonplus-theme`): `inc/includes/header-footer-presets.php`,
     `template-parts/header-builder.php`, `template-parts/footer-builder.php` — point any
     `fw_ext_hfbuilder_*` calls / CPT checks at the new `theme-builder` API. Bump `style.css`.
3. **Register `up_body` CPT** — same registration shape as `up_header`/`up_footer` (caps →
   `edit_theme_options`, page-builder support added after page-builder init, `show_in_menu` under the
   Theme Builder page). Add an `up_body` renderer (generalize the absorbed render helper to accept
   `'body'`).
4. **Register `up_template` CPT** — stores `header_id`/`body_id`/`footer_id` + `conditions` post meta
   (no builder content). Not directly user-edited as a post; managed through the grid UI.
5. **Shared resolver service** — generalize `sidebars`’ matching into a reusable class:
   request → matching templates → specificity rank → exclusions → winner. Per-request cache.
6. **Conditions UI** — the "Use On / Exclude From" popup (mirror Divi screenshot 2). Reuse Unyson
   option types; condition picker grouped by Pages / Posts / Archives / Conditional tags.
7. **Theme Builder admin grid** — bespoke management UI (NOT the metabox-holder convention — this is
   an exempt bespoke dashboard like the Shortcodes page per CLAUDE.md). Cards = templates with
   header/body/footer slots, edit-pencil opens the respective part in the page-builder editor,
   condition button opens the popup, "Add New Template".
8. **Wire render** — extend `unysonplus_get_active_header_render()` / `…_footer_render()` to consult
   the resolver; add the `template_include` body path with the precedence rules in §3.4.
9. **Version bump** — new `theme-builder` extension manifest; bump plugin `unysonplus.php` +
   `framework/manifest.php` in sync; bump theme `style.css` (rewiring touches the theme); changelog
   entry ONLY for the new feature (per CLAUDE.md rules). Mirror all changes — including the
   `header-footer-builder` **removal** — to the three XAMPP installs.

### Phase 2 — Dynamic body templates for singular content
- Dynamic-content elements: **Post Title**, **Post Content**, **Featured Image**, **Post Meta**,
  **Author/Date/Terms**. So a single `up_body` becomes a real post/page template.
- The loop/`the_post` integration so dynamic elements pull from the queried object.
- Archive/loop body templates (a "Blog" / "All Products" template that renders the query loop).

### Phase 3 — FSE file/DB hybrid + child-theme distribution
- `up-templates/*.json` seeding with the `_upw_import_hash` manual-edit guard.
- Activation/import seeding; `UPW_FORCE` / per-template re-seed.
- Distribute templates inside demo child themes; list new demos on `localhost/demos/`.

---

## 6. Open questions / decisions to confirm during build

- **Body vs. user-built page precedence** (§3.4): does a body template override a page the user
  explicitly built with the builder? Proposed: no (the explicit build wins). Confirm.
- **`up_template` as CPT vs. options row:** CPT chosen for revisions + standard list table + caps
  reuse. Revisit if the grid UI wants a lighter store.
- **Header/footer override layering:** keep the existing per-page `header_preset`/`footer_preset` as
  the top override above the matched template — confirm this is the desired precedence.
- **Multisite / per-site templates:** out of scope for Phase 1 unless required.
- **Caching:** resolver result cached per-request; consider object-cache keying by request signature
  if needed.

---

## 7. Key file references (for the build session)

- Page builder render: `unysonplus/framework/extensions/shortcodes/extensions/page-builder/class-fw-extension-page-builder.php`, `…/includes/page-builder/class-fw-option-type-page-builder.php`
- HF builder: `unysonplus/framework/extensions/header-footer-builder/{manifest.php,class-fw-extension-header-footer-builder.php,helpers.php,hooks.php}`
- Rules engine to generalize: `unysonplus/framework/extensions/sidebars/includes/{class-fw-extension-sidebars-config.php,class-fw-extension-sidebars-frontend.php}`
- CPT pattern: `unysonplus/framework/extensions/portfolio/class-fw-extension-portfolio.php`
- Admin save pattern: `unysonplus/framework/extensions/post-types/class-fw-extension-post-types.php`
- `template_include` precedence: `unysonplus/framework/extensions/portfolio/hooks.php`
- Theme render seams: `unysonplus-theme/template-parts/{header-builder.php,footer-builder.php}`, `unysonplus-theme/inc/includes/{header-footer-presets.php,layout.php}`, `unysonplus-theme/{page.php,single.php}`, `unysonplus-theme/HOOKS.md`
- Repo mapping + push workflow, versioning, manual-edit guard: workspace `CLAUDE.md`
