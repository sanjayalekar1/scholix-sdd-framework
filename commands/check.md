---
description: Run every automated check for a Scholix theme and report against the DONE checklist
argument-hint: <theme-folder>
---

Check the Scholix theme `$1` and report the result against the DONE checklist in the `scholix-theme-rules` skill.

Run:
1. `php artisan theme:spec-lint $1 --approved --json`: the spec is approved and the theme still matches it (key, `unsupported_modules`, token defaults, presets).
2. `php artisan theme:plan $1 --validate --complete --json`: the gate. Every unit must be `done` against the current spec, contracts and SDD contract version. Report the units that are not, grouped by status:
   - `failed`: list their problems;
   - `stale`: list their `changed_inputs`. A contract change since the unit was built is the Scholix-side drift this catches;
   - `todo`, `obsolete` or `blocked`.
3. `php artisan theme:validate $1 --render --coverage --strict --json`: report every problem, grouped by check.
4. `php artisan theme:preview $1 --console --screenshots`: report pages with JavaScript errors, and where the screenshots are (`storage/app/theme-preview/$1/index.html`).
5. `php artisan test`: report the totals.
6. `git diff --name-only` plus `git status --short`: confirm every changed file is under `resources/views/themes/$1/` or `specs/themes/$1/`.

Then list:
- **Automated:** pass/fail per item. If the plan gate fails, the next step is `/scholix-sdd:fix $1` for failed units, or `/scholix-sdd:build $1` for todo, stale and obsolete ones.
- **Human review still needed:** screenshots at 375/768/1440, accessibility beyond colour contrast (labels, focus, alt text), `unsupported_modules`, any `module-fields.json` difference, third-party JS and font licences, and any `blocked` unit.

Don't fix anything in this command; report only.
