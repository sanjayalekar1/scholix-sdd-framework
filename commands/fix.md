---
description: Fix the failing units of a Scholix theme from the last validation, with a cap on attempts per unit
argument-hint: <theme-folder> [unit-id]
---

Fix the Scholix theme `$1` where validation failed. If a unit id is given ($2), fix only that unit. This command closes the loop after `/scholix-sdd:check` or a failed build step: it changes only the failing units, never the whole theme.

1. Load the `scholix-theme-rules` skill. The gates are the same as for `/scholix-sdd:build`: branch `theme/$1`, SDD mode on, approved spec, plan present.
2. Refresh the results: `php artisan theme:plan $1 --validate --json`. Work from the JSON:
   - units with status `failed` and their `problems`;
   - `unassigned_problems`: theme-wide problems that no unit owns. Fix each in the file it names, under the unit that file belongs to.
3. For each failed unit (or just $2), in plan order:
   1. Record the attempt **before** changing anything: `php artisan theme:plan $1 --attempt=<unit-id>`. If it reports the unit is blocked, stop working on that unit. Don't try another way around it.
   2. Fix only what its problems name, in the unit's own files. Typical causes and fixes are in the skill (Hard rules, Lessons). Use the contract example for data shapes. Never guess a variable, and never fix by moving work outside the theme.
   3. Re-check that unit: `php artisan theme:plan $1 --validate --json`. If it is `done`, go to the next unit. If it is still `failed`, go back to step 3.1 for it.
4. Each unit gets 3 attempts. After that the plan marks it `blocked`, and only a human can reset it (`theme:plan --unblock` is refused to you). For each blocked unit, report:
   - the unit id and its files;
   - the remaining problems, verbatim;
   - what you tried, and your best guess at the cause. Say whether it is a theme bug, a spec mistake (→ `/scholix-sdd:revise`) or missing core data (→ a human core change).
5. Finish with the plan summary (`php artisan theme:plan $1`) and say what is left. When nothing is failed or blocked, suggest `/scholix-sdd:check $1`.
