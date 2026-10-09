---
description: Run every automated check for a Scholix theme and report against the DONE checklist
argument-hint: <theme-folder>
---

Check the Scholix theme `$1` and report the result against the DONE checklist in the `scholix-theme-rules` skill.

Run:
1. `php artisan theme:spec-lint $1 --approved --json`: the spec is approved and the theme still matches it (key, `unsupported_modules`, token defaults, presets).
2. `php artisan theme:validate $1 --render --coverage --strict --json`: report every problem, grouped by check.
3. `php artisan theme:preview $1 --console --screenshots`: report pages with JavaScript errors, and where the screenshots are (`storage/app/theme-preview/$1/index.html`).
4. `php artisan test`: report the totals.
5. `git diff --name-only` plus `git status --short`: confirm every changed file is under `resources/views/themes/$1/` or `specs/themes/$1/`.

Then list:
- **Automated:** pass/fail per item.
- **Human review still needed:** screenshots at 375/768/1440, accessibility beyond colour contrast (labels, focus, alt text), `unsupported_modules`, any `module-fields.json` difference, third-party JS and font licences.

Don't fix anything in this command; report only.
