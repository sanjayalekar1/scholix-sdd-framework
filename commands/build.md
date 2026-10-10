---
description: Build a Scholix theme unit by unit from its build plan, inside the theme write boundary (resumable)
argument-hint: <theme-folder> [unit-id]
---

Build the Scholix theme `$1` from `specs/themes/$1/theme.spec.json` (the decisions), `theme.spec.md` (the design narrative) and the build plan `specs/themes/$1/build-plan.json`. If a unit id is given ($2), build only that unit.

The plan makes the build resumable: every unit's status is recorded, so a new session (or one after `/compact`) continues where the last one stopped. Re-read the skill and the spec at the start of every session.

1. Load the `scholix-theme-rules` skill and follow it exactly.
2. Check the gates. If one fails, stop and tell the user what to run (these are human steps):
   - the current git branch is `theme/$1`;
   - SDD theme mode is on for `$1`: `php artisan theme:sdd` shows `"$1"`. If not, the user runs `php artisan theme:sdd $1` (it also checks the page contracts against runtime payloads) and restarts Claude Code;
   - the spec is approved and a plan exists. If `specs/themes/$1/build-plan.json` is missing, run `/scholix-sdd:plan $1` first. `theme:plan` refuses while the spec is a draft (a revision is open); then the user finishes `/scholix-sdd:revise $1` first.
3. If `resources/views/themes/$1` doesn't exist, run `php artisan theme:make $1`. Its placeholder views carry a `theme:make scaffold` line. The plan counts them as not built, and strict validation reports them, until you replace them with the theme's design.
4. Loop until `php artisan theme:plan $1 --next=5` lists nothing:
   1. Take the first unit it lists, or $2 if given. Read its contract inputs:
      - pages: `resources/theme-contracts/pages/{page}.example.json` and `.schema.json`;
      - sections: `resources/theme-contracts/sections/{key}.schema.json`, using `examples[0]`;
      - layout: `surfaces.example.json`.
   2. Build it by status:
      - **todo**: build the unit's files as the skill describes.
        - For `foundation`, run `php artisan theme:spec-apply $1` (never hand-edit those config files), then write the `common/` variables the way `themes/theme3/common/variables` does.
        - For `layout`, render every customization the spec enables (mega menu → `mega_sections`; layout style; nav alignment).
      - **stale**: one of the unit's inputs changed (`changed_inputs`): a spec decision or a contract. Update the unit for that change only. For `foundation`, re-run `php artisan theme:spec-apply $1`. Then record it with `php artisan theme:plan $1 --reworked=<unit-id>`.
      - **obsolete**: the unit is no longer part of the theme. Delete the files it lists.
      - **failed**: use `/scholix-sdd:fix $1` instead.
   3. Verify with `php artisan theme:plan $1 --validate --next=5`. This runs `theme:validate --render --coverage --strict` and the spec ↔ theme check, and records each unit as done or failed.
      - If the unit you built failed, fix it now. Each further fix counts as an attempt: run `php artisan theme:plan $1 --attempt=<unit-id>` before each one.
      - After 3 attempts the unit is blocked: stop working on it and report its problems.
      - Problems listed as "theme-wide" belong to no unit; fix them in the unit they concern.
5. If a write is blocked by the boundary, or you need data no contract provides, stop and explain. Never work around the boundary. If the spec turns out to be wrong, stop: changing it is `/scholix-sdd:revise $1`, and the spec changes before the theme does.
6. When `--next` is empty, finish with `/scholix-sdd:check $1`.
