---
name: scholix-theme-rules
description: Rules for building or changing a Scholix website theme (resources/views/themes/{folder}) — data contracts, write boundary, view/CSS/JS rules, validation and the DONE checklist. Use whenever writing a Scholix theme spec, theme views, theme CSS/JS, or theme config.
---

# Scholix theme rules

A Scholix theme is **presentation only**: markup, CSS, JS and assets in one folder. All data comes from Scholix core as plain arrays. Read Scholix's `docs/THEMING_GUIDE.md` and `CLAUDE.md` in the open project for full detail. This skill is the checklist.

## Where you may write

Only inside the theme, while SDD theme mode is on (`php artisan theme:sdd {folder}`, run by a human):
- `resources/views/themes/{folder}/`: views, `config/1.0/*.json`, `custom/`, `common/`, `ui/`, `partials/`, `assets/` (CSS, JS, fonts, images)
- `specs/themes/{folder}/`: the theme spec: `theme.spec.json` (decisions, checked by `php artisan theme:spec-lint {folder}`) and `theme.spec.md` (design narrative). Templates: this skill's `templates/` folder.
  - `specs/themes/{folder}/build-plan.json` is the exception: `php artisan theme:plan` generates it. Never write it by hand; the hook refuses.

Everything else is Scholix core and is read-only: `app/`, `routes/`, `config/`, `database/`, other themes, `themes/base` and `themes/shared`, `resources/theme-contracts/`, CI, Docker and `.claude/`. The `scholix-sdd` hook blocks those writes, and CI fails the PR if any slip through.

**If the theme needs data or a change that core doesn't provide, stop and ask a human.** It becomes a core contract change in a separate PR. Never work around it inside the theme.

## Reference material: a vendor template, pages or screenshots

A human may hand over a complete template for the new theme — HTML pages, CSS, JS, images — and/or design screenshots, as the actual design brief. That is not "another Scholix theme": the never-copy-design rule below is about the five existing Scholix themes, not this. Handle it like the theme5/Kidko vendoring pattern:

Both the template and any screenshots are raw input, and both are kept in the same place, split by kind — `reference/` is storage, never a place anything renders from:
- `specs/themes/{folder}/reference/template/` — the vendor package as handed over (HTML pages, CSS, JS, images), unmodified.
- `specs/themes/{folder}/reference/screenshots/` — design screenshots, mockups, reference-site captures.

1. **Save it there first, as-is, before doing anything else with it.** A downloaded/extracted package is untrusted input: extract it into its own directory first and never execute anything from inside it.
2. **"Design" is not a storage location, it's the narrative that points at this material.** `theme.spec.md` §1–2 and `theme.spec.json`'s `design`/`identity.references` keys record *what to take* from each template/screenshot (colours, layout idea, imagery mood) — they never hold the files themselves; the files stay in `reference/`.
3. **Vendor only the real assets the theme will use** out of `reference/template/` — images, fonts, compiled CSS/JS — into `resources/views/themes/{folder}/assets/vendor-{slug}/`, keeping the vendor's own file layout inside that one folder, and declare the CSS/JS in `theme.json` `assets.*` (rule 10).
4. **Never render a vendor HTML page or `@include` vendor markup directly, from `reference/` or anywhere else.** Rebuild each page/section as a Blade view reading Scholix's own data contracts (`$section['data']`, `$homeSection['data']`, page variables); the vendor file is a visual/structural reference, not something to wire live data into verbatim.
5. **Every hard rule and lesson above still applies to vendored markup** — swap every hardcoded vendor colour to a platform token (grep for every distinct hex value, not just the two obvious brand colours), make every action element `cta_type`-driven (home sections and header included, not just inner pages), size every `<img>` for the platform's served crop, and add a `section-settings.json` entry for every section the vendor markup drives an admin setting for.
6. **Screenshots in `reference/screenshots/` are the acceptance reference, not something to parse pixel-by-pixel.** Compare `theme:preview --screenshots` output against them in review — they drive judgement calls (image `cover` vs `contain`, spacing, imagery mood), not a 1:1 clone.

## Data — never guess

- **Page data:** `resources/theme-contracts/pages/{page}.schema.json` plus `{page}.example.json` (anonymised real payload). Every public page in `config('themes.public_pages')` has one.
- **Section data:** `resources/theme-contracts/sections/*.schema.json`, each with `examples[0]`. Views read `$section['data']` / `$homeSection['data']`.
- **Section settings:** the `$homeSection['settings']` keys are indexed in `docs/THEME_SECTION_CONTRACTS.md`. Read them with fallbacks.
- **Layout data:** `$themeSurfaces[...]`, described by `resources/theme-contracts/surfaces.schema.json` and its example.
- **Links:** use `route()` only with names in `config('themes.route_vocabulary')`; prefer URLs from the payload. Form actions always come from data (`$themeSurfaces['forms']`, item `submit_url`).

## Hard rules (checked by `theme:validate`)

1. No models, queries, `App\` classes, facades or `config()` in views.
2. Platform state only via: flash `session('success'|'error'|'status'|'message'|'comment_submitted')`, `old()`, `request()->url|fullUrl|full|fullUrlWithQuery|getSchemeAndHttpHost|get|query`, `auth(...)->check()`.
3. Never reference another theme's views or files.
4. The layout includes `themes.shared.head`, `head-end`, `body-start` and `body-end`, and emits no title/meta/OG/tracking itself.
5. Raw output only via `{!! safe_html(...) !!}`, `{!! nl2br(e(...)) !!}` or `json_encode`.
6. No hex colours in markup; use the platform tokens (`var(--primary-color)` …) and theme variables in `common/`/`custom/`.
7. `config/1.0/module-fields.json` stays a verbatim copy of default's unless a human approves a change (it drives admin validation).
8. `unsupported_modules` in `theme.json` needs human approval.
9. Every public page of a supported module is rendered by the theme itself, not by `themes/base`.
10. JavaScript:
    - classic `<script>` files listed in `theme.json` `assets.js` (no `import`/`require`);
    - every library you use (jQuery, Owl, Swiper, Bootstrap JS…) must be loaded there;
    - guard every DOM lookup (`const el = …; if (el) { … }` or `?.`);
    - no `window.*` globals, no `document.write`;
    - inline scripts that need a library go in `@push('scripts')`.
11. Stay within the asset budget (`config('themes.strict.asset_budget_kb')`).
12. `theme.json` `key` equals the theme folder name (as `theme:make` writes it) and never changes. It is the technical id; the customer-facing name and key are set separately by a human in Super Admin → Themes, so don't name the folder after the marketing name. Tenant settings are stored under it, so it must be unique across all themes. Never copy another theme's key.
13. Place the platform features tenants turn on in the admin: the language switcher (`themes.shared.language-switcher` with `langToggleClass`/`langIcon`), the WhatsApp button (`themes.shared.whatsapp-button` with the theme's `icon`; set `--sx-wa-right`/`--sx-wa-bottom` to clear the back-to-top button), the notice ticker (`theme_view('layout.notice-ticker')`) and the platform credit (`themes.shared.footer-copyright` or `footer-credit`). Honour `header.show_login_button` and `header.show_top_bar`.
14. **Buttons and CTAs are always theme-token driven, never hardcoded.** The tenant's Button Style (`cta_type`) selects the `--action-*` variables that every button — primary, secondary, outline, ghost, link, header CTA, hero CTA, card actions, and the CTA-section button (`cta_section_button_style`) — must read from. On dark surfaces the filled-secondary style uses the lighter secondary shade and outline/link styles use the contrast colour as text only; no button fill is ever white or a contrast colour. Buttons inside a light panel on a dark section (a form, an address box) always use the normal light-surface style. Render every `cta_type` and both CTA-section styles and read the resulting variables before calling a theme done.
15. **The hero's three variants — image, video, carousel — are independently configured** (`home_section.hero.schema.json`: `type: image|video|carousel`, plus `video_url`/`poster_url`/`show_video` and the full `carousel` object: `autoplay`, `autoplay_speed`, `transition_speed`, `show_arrows`, `show_dots`, `animation`). Build and verify all three, not just the one in the example payload; every carousel/video admin field must visibly change the rendered hero.
16. **Home sections must follow their Auto / Light / Soft / Dark background setting.** The platform resolves `auto` to one of `home-section-bg-light|alt|dark` on the section shell. Dark-band styling lives only under `.home-section-bg-dark` — never hardcode a dark band, or Auto and Light do nothing. A light surface inside a dark band (card, panel, form, address box) must reset its text/muted/border variables and the "links on dark are white" rule, or its content goes white-on-white. Check every section the theme uses in Light, Soft and Dark.
17. **Images render at the size and crop the platform actually serves, not the vendor template's assumption.** Section/content images arrive as a `thumbnail` (300×300 centre crop) or `medium` (16:9 crop) variant; a wide logo needs the `original` variant with `object-fit: contain` inside a constrained box. Any `<img>` the markup doesn't explicitly size (`max-height`/`max-width`/`object-fit`) renders at the uploaded asset's native resolution, which will not match whatever the demo image happened to be.
18. **The splash screen's layout and lead-form settings are real tenant choices.** `splash.display_mode` (`split`/`image`/`content`/`form`) and `splash.form_type` (`enquiry`/`admission`/`brochure`) each change what renders. Build and check every `display_mode`, and confirm the lead form for each `form_type` renders and submits correctly.

## Lessons from the existing themes (SDD pilot)

The five existing themes have as-built specs in `specs/themes/{default,theme2,theme3,theme4,theme5}/`. The standing rule is to reuse **structure and contracts** across themes — what data exists, what it's shaped like — but never markup, CSS or assets: a new theme's look comes only from its own spec. `docs/SDD_PILOT_FINDINGS.md` has the full analysis.

1. **theme3 is the structural reference.** `layout/app` holds only the shell partials, navigation, footer and `<main>`. `common/variables` aliases the platform tokens, and `custom/default` composes `common/*` (compiled per tenant with their tokens). Bootstrap bundle plus one `theme.js`, no jQuery. Pages read `$themePage['sections']` by type and use `$pageBanner` with `custom.inner-page-banner`; home renders the hero, then `$homeRows` through `pages/home/home-{view}`.
2. **Config files come from the spec.** `php artisan theme:spec-apply {folder}` writes `settings.json`, `home-page.json`, `navigation.json` and the opt-outs. Never hand-edit them; change the spec instead.
3. **Render every customization you enable.** theme4 and theme5 enable the mega menu but never render `mega_sections`, so tenants build menus their site won't show. If you don't render it, disable it in the spec (`strict: capability`).
4. **Every optional image needs a fallback.** Contract image URLs are nullable: never print `<img src="">` (`strict: empty image`). theme2's initials placeholder is the good pattern.
5. **Ship every asset you reference.** theme5 falls back to six image files it doesn't contain (`strict: missing asset`).
6. **Rich text goes through `safe_html()`** (description, body, message): the most common strict finding in theme2–5.
7. **Motion respects `prefers-reduced-motion`** (theme3 `common/utilities` pattern for `[data-animate]`).
8. **The default CTA must pass AA.** White on coral fails in theme4 and theme5; `theme:spec-lint` checks it.
9. **A new tenant's first impression is the starter.** On activation, a tenant with no home layout gets `home_starter` and starter header/footer menus (`ThemeStarter`). Sections with no content stay hidden in every theme, so a home section view never needs its own empty state for a missing list.
10. **Decorations must not collide with shell elements.** The theme5 logo cloud overlaps the notice ticker. Review the screenshots with the ticker on.
11. **Platform features were missing in three of five themes.** theme3–5 had no language switcher, theme2–5 no WhatsApp button though every admin asks for the number, theme3–5 no platform credit (rule 13). Previews show a second language and the credit, so check the switcher and footer in the screenshots.
12. **Size the header for the full starter menu.** theme3 and theme4 overflowed between 992 and 1199px with 7 items plus their buttons; both now expand at xl. Check the header at 1024 and 1200px with the spec's `navigation.starter.header`, not the 3-item example menu.
13. **A theme-token-driven button class can exist and still not be used everywhere.** theme5 built a fully `cta_type`-driven button class for its inner pages, but the home page (hero, about, CTA, facilities) and the header CTA kept the vendor template's own static button class, so Button Style changed nothing there. Grep every element that functions as an action — home sections and header included, not just inner pages — and confirm it resolves to the token-driven class.
14. **Hero video and carousel settings are easy to wire only the "carousel of images" path and forget the rest.** Check that `video_url`/`poster_url`/`show_video` and every carousel field (autoplay, transition_speed, arrows, dots) actually change the rendered hero, not just that the `type` switch renders *something*.
15. **A theme's hero can render correctly and still have a blank admin Configure modal.** The modal's fields come from the theme's own `config/1.0/section-settings.json` `hero` entry — a theme that is missing that key (or ships only `about`/`events`/`blog`) opens an empty dialog even though `home-page.json` already shows the Hero panel and the hero itself renders. These are two independent gates on two different files; check both, not just that the hero looks right on the page.
16. **Dark bands hide a broken Auto/Light/Soft mode.** A section whose CSS assumes "I always sit on dark" (hardcoded white text, light accents) makes Auto and Light silently inert. Test by switching the setting per section, not by eyeballing one screenshot.
17. **Vendor-template logos and hero images are rarely pre-sized for a real tenant's upload.** Add an explicit `max-height`/`max-width`/`object-fit` instead of trusting the template's own CSS, which was tuned to one demo asset that happened to already be the right size.
18. **The splash screen is designed once and then forgotten.** All four `display_mode` values and all three `form_type` lead forms need their own look; it's easy to screenshot only the default (`split` / `enquiry`) combination and call it done.

## Workflow

`/scholix-sdd:spec` → (human approves) → `/scholix-sdd:plan` → `/scholix-sdd:build` (resumable, unit by unit) → `/scholix-sdd:check` → `/scholix-sdd:fix` (capped) → `/scholix-sdd:check`. When the spec has to change mid-build, use `/scholix-sdd:revise` → (human re-approves) → `/scholix-sdd:build`.

**The build plan** (`specs/themes/{folder}/build-plan.json`, written by `theme:plan`) splits the theme into units: `foundation`, `layout`, `ui.*`, `home.*`, `section.*` and `page.*`, each with its files, dependencies and input hashes. Unit status:

| Status | Meaning | What to do |
|---|---|---|
| `todo` | not built yet (or still the `theme:make` placeholder) | build it |
| `built` | has its own view, not yet verified | `--validate` |
| `done` | verified: no problems | — |
| `failed` | problems listed | `/scholix-sdd:fix`; record each try with `--attempt` |
| `stale` | a spec decision or contract it is built from changed (`changed_inputs`) | update it for that change, then `--reworked` |
| `obsolete` | no longer part of the theme | delete its files |
| `blocked` | more than 3 fix attempts | stop; a human decides |

Rules that keep the plan honest:
- **Gates:** `theme:plan` refuses while the spec isn't approved, so a spec under revision stops the build. `--complete` (run in `/scholix-sdd:check` and in CI) fails unless every unit is `done` against the current spec, contracts and SDD contract version.
- **Stale is cleared only by rework:** a stale unit stays stale through validation until you run `--reworked`. Passing validation doesn't mean it follows the changed decision.
- **Fixes are capped:** record each fix attempt **before** making it. Past the cap, report the unit; don't look for another way around it.

## Loop

```
php artisan theme:spec-lint {folder} --approved                   # the spec gate (a human approves the spec)
php artisan theme:plan {folder} --next=5                          # the plan; the next units, dependencies first
php artisan theme:make {folder}                                   # first time only
php artisan theme:spec-apply {folder}                             # config files from the spec (the foundation unit)
php artisan theme:plan {folder} --validate --next=5               # validate, record done/failed per unit, show what's next
php artisan theme:validate {folder} --render --coverage --strict  # the full report (add --json for a machine-readable one)
php artisan theme:preview {folder} --console --screenshots        # 0 JS errors; screenshots for human review
php artisan theme:spec-lint {folder}                              # the theme still matches its spec
php artisan theme:plan {folder} --validate --complete             # the done gate
```

The shell guard allows only what this loop needs. Commands or options that write outside the theme or run other programs are refused, for example `sed -i` outside the theme, `sort -o`, `git --output`, `awk` with `>`/`|`/`system()`, and artisan options such as `--out`, `--log-junit` or `--env`. If a command is refused, use the Write/Edit tools or ask a human.

## DONE

- [ ] Spec approved: `theme:spec-lint {folder} --approved` passes (also checks colour contrast of the defaults and presets)
- [ ] Token defaults + presets in `config/1.0/settings.json` match the spec (`theme:spec-lint`). The defaults are what every tenant gets on switching to the theme: their own colours and fonts are not kept.
- [ ] `theme.json` valid; the 4 config files present
- [ ] Layout with the shell partials; navigation and footer
- [ ] Every public page of supported modules rendered by the theme; UI kit 5/5
- [ ] `theme:validate --render --coverage --strict` = 0 problems
- [ ] Buttons checked across every `cta_type` and both CTA-section styles; no hardcoded button colour
- [ ] Hero checked in all three variants (image, video, carousel) with every carousel/video setting
- [ ] Home sections checked in Light, Soft and Dark, not just the default
- [ ] Splash screen checked in all four `display_mode` values and all three `form_type` lead forms
- [ ] Images sized/cropped explicitly (no native-resolution `<img>`); logos use the `original` variant with `object-fit: contain`
- [ ] `theme:plan {folder} --validate --complete` passes: every unit done against the current spec and contracts, none stale or blocked
- [ ] `php artisan test` green
- [ ] `theme:preview --console` = 0 JS errors; screenshots reviewed at 375/768/1440 (human)
- [ ] Accessibility checked: labels, focus, alt text, contrast of the rendered pages (human)
- [ ] Diff touches only the theme boundary (CI `theme-boundary.yml` green)
- [ ] Assets published, `themes` row created private, verified on a demo tenant, then published (human)
