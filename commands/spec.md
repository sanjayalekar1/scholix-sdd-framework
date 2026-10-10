---
description: Start a Scholix theme spec (specs/themes/{folder}/theme.spec.json + theme.spec.md) from the templates
argument-hint: <theme-folder> [one-line brief]
---

Create the theme specification for the Scholix theme `$1`. Brief from the user: $ARGUMENTS

1. Load the `scholix-theme-rules` skill. Its folder has the templates: `templates/theme.spec.json` (the decisions, checked by Scholix) and `templates/theme.spec.md` (the design narrative).
2. If `specs/themes/$1/theme.spec.json` already exists, read both files and continue them. Otherwise copy both templates to `specs/themes/$1/`, replacing `{folder}` with `$1` and `{Theme name}` with a display name.
3. Fill in what the brief and the open project tell you:
   - `tokens.defaults` (what every tenant gets on switching to the theme) and 4–8 `tokens.presets` (existing themes offer 4–10): token names from `ThemeCssCompiler::CSS_INPUT_KEYS`, in the shape of a theme's `config/1.0/settings.json` (see `resources/views/themes/theme3/config/1.0/settings.json`);
   - `home_sections`: keys of the `home_section.*` contracts in `resources/theme-contracts/sections`, in default order (not `hero`: it always renders);
   - `home_starter`: the subset a tenant gets when activating the theme with no home layout yet. Sections show only once they have content, so include the core lists plus content-independent ones (`about`, `get_in_touch` when offered);
   - `customization` and `navigation`: what tenants may change, and which nav styles the theme renders. Enable only what the theme will render; a mega menu is real work;
   - `navigation.starter.header` (and optionally `footer`): the theme's default menu, worded for its audience. Pages are route names from `routes/tenant.php`, dropdowns have `children`, one level deep. See the existing themes' `navigation.json` `starter`;
   - for complete examples, read the as-built specs of the existing themes in `specs/themes/*/` (theme3 is the structural reference);
   - the narrative sections of `theme.spec.md`.
   Leave every decision that needs a human as `TODO(human)`: owner, plans (plan product ids), plan tier, institution, density and motion, `modules.unsupported`, `assets.js_libraries`, font licences, and the accessibility level. Don't decide those yourself. `key` stays equal to the folder.
4. Run `php artisan theme:spec-lint $1` and fix every problem it reports. It checks the shape, the token/module/section/library names, colour contrast and the key.
5. Do not create theme files yet. Show the user the open `TODO(human)` items from the lint output. A human resolves them and sets `"status": "approved"`; `php artisan theme:spec-lint $1 --approved` must then pass before `/scholix-sdd:plan` and `/scholix-sdd:build`. To change a spec the theme is already being built from, use `/scholix-sdd:revise` instead.
