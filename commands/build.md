---
description: Build a Scholix theme from its approved spec, inside the theme write boundary
argument-hint: <theme-folder>
---

Build the Scholix theme `$1` from `specs/themes/$1/theme.spec.json` (the decisions) and `theme.spec.md` (the design narrative).

1. Load the `scholix-theme-rules` skill and follow it exactly.
2. Check the preconditions. If any fails, stop and tell the user what to run (these are human steps):
   - the current git branch is `theme/$1`;
   - SDD theme mode is on for `$1`: `php artisan theme:sdd` shows `"$1"`. If not, the user runs `php artisan theme:sdd $1` (it also checks the page contracts against runtime payloads) and restarts Claude Code;
   - the spec is approved: `php artisan theme:spec-lint $1 --approved` passes.
3. If `resources/views/themes/$1` doesn't exist, run `php artisan theme:make $1`.
4. Build in this order:
   1. `php artisan theme:spec-apply $1`: writes the spec's tokens, presets, customization, home catalogue, navigation and module opt-outs into the theme's config files (never edit those by hand). Then the `common/` variables, aliasing the platform tokens the way `themes/theme3/common/variables` does
   2. layout, navigation and footer: render every customization the spec enables (mega menu → `mega_sections` in the header navigation; layout style; nav alignment)
   3. UI kit (`ui/*`)
   4. home sections (the spec's `home_sections`)
   5. inner sections
   6. pages

   Read each page's data from `resources/theme-contracts/pages/{page}.example.json` and each section's from its contract example.
5. After each step run `php artisan theme:validate $1 --render --coverage --strict` and `php artisan theme:spec-lint $1`, and fix what they report.
6. If a write is blocked by the boundary, or you need data no contract provides, stop and explain. Never work around the boundary. If the spec turns out to be wrong, stop and ask: the spec changes before the theme does.
7. Finish with `/scholix-sdd:check $1`.
