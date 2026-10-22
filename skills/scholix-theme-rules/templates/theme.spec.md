# Theme spec: {Theme name}

> Folder: `{folder}`. The decisions (identity, tokens, module scope, home sections, libraries, budgets, accessibility level) live in **`theme.spec.json`** next to this file, which `php artisan theme:spec-lint {folder}` checks. This file is the design narrative.
> Items marked `TODO(human)` are decided by a person, not by Claude.

## 1. Audience and intent
- Who the site is for, what it must make easy, the tone in one paragraph.
- Reference sites (also in `identity.references`): what to take from each.

## 2. Design direction
- Keywords (also in `design.keywords`), density, imagery style, motion level, and why.

## 3. Typography
- Heading and body families (`tokens.defaults.header_font_family` / `body_font_family`; licences in `assets.fonts`), scale, weights, line lengths.

## 4. Colour system
- `tokens.defaults` is what **every tenant gets when switching to this theme**. Tenants don't keep their previous colours or fonts (Scholix decision D1). The defaults must work for any institution as delivered; presets are the alternatives tenants pick afterwards.
- How the platform tokens map to surfaces (background, text, links, CTA, borders).
- Presets (`tokens.presets`): the mood of each. `theme:spec-lint` checks text/background and CTA contrast for the defaults and every preset at the `accessibility.contrast` level.
- Buttons: the tenant's button colour (`cta_type`/`cta_section_button_style`) is a **separate** decision from primary/secondary — design every button family (primary, secondary, outline, ghost, link, CTA-section) so it still works when that colour differs from the preset, and on dark surfaces (rule 14).

## 5. Layout system
- Container widths, grid, breakpoints, section rhythm. Theme-owned scales (spacing, radius, shadow, type scale, motion) go in `common/variables`.

## 6. Navigation
- Mobile pattern, dropdown behaviour. The enabled styles and locations, and whether the mega menu exists, are in `navigation` and `customization` (JSON); every option enabled there must be rendered.

## 7. Home page
- The hero (always rendered): how each of its three variants looks — `image`, `video` (with its `poster_url`) and `carousel` (slide animation, arrows/dots, autoplay) — not just the one used in mockups.
- The composition and order of `home_sections`, and the option fields each section offers.
- How each enabled home section looks in **Auto, Light, Soft and Dark** (rule 16) — state the dark-band treatment explicitly; a section with no stated dark design will default to whatever Light produces under a dark background, which is usually wrong.

## 8. Splash screen
- Whether the theme's splash popup is used, and the look for each `display_mode`: `split`, `image`, `content`, `form`.
- The lead-form look for each `form_type`: `enquiry`, `admission`, `brochure` — field layout, button, dismiss control.

## 9. Page templates
- For each public page of a supported module: the contract it renders (`resources/theme-contracts/pages/{page}.schema.json`) and layout notes.

## 10. Section variants
- Visual options only (data variants come from the contracts).

## 11. UI kit
- Look of `ui/page`, `ui/section`, `ui/accordion`, `ui/button`, `ui/empty`.

## 12. Responsive behaviour
- Mobile-first rules; what collapses or hides at 375 / 768 / 1440.

## 13. Accessibility
- Landmarks, focus visibility, reduced motion, alt text. The level is set in `accessibility` (JSON).

## 14. SEO
- Inherited from the platform shell (title, meta, canonical, JSON-LD). The theme adds none. Heading hierarchy rules:

## 15. Constraints
- The `scholix-theme-rules` skill applies in full (write boundary, data contracts, hard rules 1–18).

## 16. Acceptance
- [ ] `php artisan theme:spec-lint {folder} --approved` = OK (spec approved, no `TODO(human)` left, spec ↔ theme consistent)
- [ ] `php artisan theme:validate {folder} --render --coverage --strict` = 0 problems
- [ ] `php artisan theme:preview {folder} --console` = 0 JS errors
- [ ] Hero checked in all three variants (image, video, carousel); home sections checked in Light, Soft and Dark; splash screen checked in every `display_mode`/`form_type` combination used
- [ ] Screenshots at 375/768/1440 approved — TODO(human)
- [ ] Accessibility pass — TODO(human)
- [ ] Theme switch default → {folder} → default verified on a demo tenant — TODO(human)
