---
description: Change an approved Scholix theme spec mid-build and work out which units it affects
argument-hint: <theme-folder> "<what should change>"
---

Revise the spec of the Scholix theme `$1`: $ARGUMENTS

The spec always changes before the theme does. This command changes the spec and shows the impact, but builds nothing. A human re-approves the spec; then `/scholix-sdd:build $1` reworks exactly the affected units.

1. Load the `scholix-theme-rules` skill. Read `specs/themes/$1/theme.spec.json`, `theme.spec.md` and the plan `specs/themes/$1/build-plan.json`. If there is no plan yet, the spec was never built from: edit it with `/scholix-sdd:spec $1` instead.
2. Edit the spec for the requested change only:
   - Set `"status": "draft"`: the spec is under revision. `theme:plan` then refuses, so no build runs against a half-changed spec.
   - Leave decisions that need a human as `TODO(human)` (the same list as in `/scholix-sdd:spec`): plans, plan tier, institution, density and motion, `modules.unsupported`, `assets.js_libraries`, font licences, the accessibility level.
   - Update the narrative in `theme.spec.md` to match.
3. Run `php artisan theme:spec-lint $1` and fix what it reports.
4. Show the impact: `php artisan theme:plan $1 --diff`. Report to the user:
   - which spec decisions changed;
   - which built units they make stale. Foundation, layout and the UI kit carry the look; sections and pages follow contracts;
   - new units: e.g. an added home section, or a module no longer opted out;
   - retired units: their files will have to be deleted;
   - any changed contracts.

   If the change reaches beyond the theme (new data, a new route), stop: that is a human core change, not a spec revision.
5. Stop. The user reviews the diff, resolves any `TODO(human)` items, sets `"status": "approved"`, and `php artisan theme:spec-lint $1 --approved` passes. Then `/scholix-sdd:build $1`. On its first `theme:plan` run, affected units become `stale`, new ones `todo` and retired ones `obsolete`, and the build loop works through them.
