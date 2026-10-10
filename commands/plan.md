---
description: Split an approved Scholix theme spec into a build plan (units, dependencies, status) before building
argument-hint: <theme-folder>
---

Make the build plan for the Scholix theme `$1`: the theme split into units small enough to build and verify one at a time. The plan is `specs/themes/$1/build-plan.json`. Scholix writes it (`php artisan theme:plan`); never edit it by hand, and the hook refuses if you try.

1. Load the `scholix-theme-rules` skill.
2. Check the gates. If one fails, stop and tell the user what to run (human steps):
   - the current git branch is `theme/$1`;
   - SDD theme mode is on for `$1` (`php artisan theme:sdd` shows `"$1"`);
   - the spec is approved: `php artisan theme:spec-lint $1 --approved` passes. `theme:plan` checks this itself and refuses a draft.
3. Run `php artisan theme:plan $1 --json`. It creates the plan (or refreshes an existing one) from the approved spec and the contracts:
   - `foundation`: config from the spec, `common/` variables, assets;
   - `layout`: layout shell, navigation, footer;
   - `ui.*`: the five UI-kit pieces;
   - `home.*`: hero plus the spec's `home_sections`;
   - `section.*`: inner section contracts of supported modules;
   - `page.*`: every public page of a supported module.

   Each unit lists its files, the units it depends on, and the hash of every input it is built from (spec decisions, contract files).
4. If `resources/views/themes/$1` exists already, run `php artisan theme:plan $1 --validate --json` so the plan reflects what is built and verified.
5. Report to the user:
   - the unit count per kind and per status;
   - the build order: `php artisan theme:plan $1 --next=10`;
   - anything the spec asks for that has no contract or would need core data. Stop on those; a human decides.

   Don't build anything in this command. The user reviews the scope, then runs `/scholix-sdd:build $1`.
