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

Everything else is Scholix core and is read-only: `app/`, `routes/`, `config/`, `database/`, other themes, `themes/base` and `themes/shared`, `resources/theme-contracts/`, CI, Docker and `.claude/`. The `scholix-sdd` hook blocks those writes, and CI fails the PR if any slip through.

**If the theme needs data or a change that core doesn't provide, stop and ask a human.** It becomes a core contract change in a separate PR. Never work around it inside the theme.

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

## Lessons from the existing themes (SDD pilot)

The five existing themes have as-built specs in `specs/themes/{default,theme2,theme3,theme4,theme5}/`. Read them as examples of complete specs; `docs/SDD_PILOT_FINDINGS.md` has the full analysis.

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

## Loop

```
php artisan theme:spec-lint {folder} --approved                   # the spec gate (a human approves the spec)
php artisan theme:make {folder}                                   # first time only
php artisan theme:spec-apply {folder}                             # config files from the spec
php artisan theme:validate {folder} --render --coverage --strict  # fix until 0 problems (add --json for a machine-readable report)
php artisan theme:preview {folder} --console --screenshots        # 0 JS errors; screenshots for human review
php artisan theme:spec-lint {folder}                              # the theme still matches its spec
```

The shell guard allows only what this loop needs. Commands or options that write outside the theme or run other programs are refused, for example `sed -i` outside the theme, `sort -o`, `git --output`, `awk` with `>`/`|`/`system()`, and artisan options such as `--out`, `--log-junit` or `--env`. If a command is refused, use the Write/Edit tools or ask a human.

## DONE

- [ ] Spec approved: `theme:spec-lint {folder} --approved` passes (also checks colour contrast of the defaults and presets)
- [ ] Token defaults + presets in `config/1.0/settings.json` match the spec (`theme:spec-lint`). The defaults are what every tenant gets on switching to the theme: their own colours and fonts are not kept.
- [ ] `theme.json` valid; the 4 config files present
- [ ] Layout with the shell partials; navigation and footer
- [ ] Every public page of supported modules rendered by the theme; UI kit 5/5
- [ ] `theme:validate --render --coverage --strict` = 0 problems
- [ ] `php artisan test` green
- [ ] `theme:preview --console` = 0 JS errors; screenshots reviewed at 375/768/1440 (human)
- [ ] Accessibility checked: labels, focus, alt text, contrast of the rendered pages (human)
- [ ] Diff touches only the theme boundary (CI `theme-boundary.yml` green)
- [ ] Assets published, `themes` row created private, verified on a demo tenant, then published (human)
