# scholix-sdd-framework

Spec-driven theme generation for Scholix, as a Claude Code plugin (`scholix-sdd`).

It gives Claude:
- a **workflow**: `/scholix-sdd:spec`, `/scholix-sdd:plan`, `/scholix-sdd:build`, `/scholix-sdd:check`, `/scholix-sdd:fix`, `/scholix-sdd:revise`;
- the **rules** for Scholix themes (skill `scholix-theme-rules`);
- a **hard write boundary**: while SDD theme mode is on, a PreToolUse hook blocks every write outside the theme's folder.

Scholix itself keeps everything a theme is checked against: theme contracts, `theme:validate` / `theme:preview` / `theme:page-contracts`, the boundary rule (`app/Support/ThemeBoundary.php`), the spec schema and linter (`resources/theme-contracts/spec.schema.json`, `theme:spec-lint`), the CI checks and the theme specs (`specs/themes/{folder}/`). This plugin reads those from the open Scholix project. It doesn't copy them.

**Compatibility.** Scholix versions this interface in `app/Support/SddContract.php`. This plugin supports **SDD contract 2** (Scholix with `theme:plan`); the hook refuses theme work against a Scholix with another version, or without the file.

## Install

In Claude Code, with the Scholix repository open:

```
/plugin marketplace add sanjayalekar1/scholix-sdd-framework
/plugin install scholix-sdd@scholix-sdd-framework
```

For local development of the plugin itself, use the clone instead: `/plugin marketplace add C:\xampp\htdocs\scholix-sdd-framework`. Restart Claude Code after installing; `/hooks` should list the `theme-write-guard` PreToolUse hook.

Requirements: `php` on PATH (the hook is PHP). Node and Chrome/Edge are only needed for the JS checks in `theme:validate` / `theme:preview --console`.

## Building a theme

```
git checkout -b theme/{folder}              # CI checks the boundary on theme/* branches
php artisan theme:sdd {folder}              # a human switches SDD mode on (Claude can't); it refuses a registered theme
                                            # (--existing overrides) and stale page contracts (--skip-contract-check)
# restart Claude Code, then:
/scholix-sdd:spec {folder} "premium modern school"
# a human resolves the TODO(human) items in specs/themes/{folder}/theme.spec.json (+ .md),
# sets "status": "approved", and php artisan theme:spec-lint {folder} --approved passes
/scholix-sdd:plan {folder}                  # the build plan: units, dependencies, status (specs/themes/{folder}/build-plan.json)
/scholix-sdd:build {folder}                 # unit by unit; resumable in a new session
/scholix-sdd:check {folder}                 # every check + the plan gate (theme:plan --validate --complete)
/scholix-sdd:fix {folder}                   # only the failed units; 3 attempts each, then blocked for a human
php artisan theme:sdd --stop                # a human switches SDD mode off
```

To change the spec mid-build: `/scholix-sdd:revise {folder} "what changes"` sets it back to draft and shows the impact (`theme:plan --diff`). Building stops until a human re-approves. Then `/scholix-sdd:build` reworks the stale units, builds new ones and deletes retired ones.

### What makes it resilient

| Failure | Handled by |
|---|---|
| The session ends or runs out of context mid-build | The plan records every unit's status; `/scholix-sdd:build` continues from `theme:plan --next` |
| Rules forgotten on page 40 | Each unit is small, verified on its own, and re-read from the skill and contracts |
| The spec changes after the build started | `/scholix-sdd:revise` → `--diff` → re-approval → affected units become `stale` (cleared only by `--reworked`), new ones `todo`, removed ones `obsolete` |
| A fix that never converges | `--attempt` before every fix; a unit becomes `blocked` after 3, and only a human can `--unblock` it (the hook refuses it to Claude) |
| Scholix contracts change under a built theme | Each unit stores its contract hashes and the SDD contract version; changed ones become `stale`, and `--complete` fails |
| A step skipped or a unit hand-marked done | Gates are commands, not instructions: `theme:plan` refuses an unapproved spec, the hook refuses writes to `build-plan.json`, and CI runs `theme:plan --validate --complete`, which re-validates every unit |
| The `theme:make` placeholder taken for the design | Scaffold views carry a marker; the plan counts them as not built and `theme:validate --strict` reports them |

## The write boundary

The hook is active only while `{project}/.claude/sdd-theme` exists (written by `php artisan theme:sdd {folder}`) or `SCHOLIX_SDD_THEME` is set. Then:

| Tool | Rule |
|---|---|
| Write / Edit / MultiEdit / NotebookEdit | only `resources/views/themes/{folder}/`, `specs/themes/{folder}/` (not `build-plan.json`: only `theme:plan` writes it) |
| Bash | allowlist only: read-only tools; `mkdir`/`cp`/`mv`/`rm`/`touch`/`tee`/`sed -i`/redirects *into* the theme; read-only `git` (no `--output`, `git branch` lists only); `node --check`; `php -l`; `php artisan theme:validate/theme:preview/theme:spec-lint/theme:plan/test` with an option allowlist (`theme:plan --unblock` is a human step), `theme:make {folder}`, `theme:sdd` (status only). Refused: command substitution, env assignments, and options that write elsewhere or run programs (`sed -f`/`w`/`e`, `sort -o`, `uniq` output files, `awk` with `>`/`\|`/`system()`/`-i`, `cp/mv -t`, `find -fprint`, `rg --pre`, `file -C`, artisan `--out`/`--log-junit`/`--coverage-*`/`--env`…) |
| PowerShell | blocked |
| This plugin, `~/.claude` | blocked, so the guard can't be edited or disabled |

The rule comes from Scholix's `app/Support/ThemeBoundary.php`. If SDD mode is on but that file (or `SddContract.php`) isn't there, every write is blocked (fail closed).

### What the hook is not

The hook guards against **accidents**, not against hostile code. Some allowed commands execute theme code: `theme:validate --render`, `theme:preview` and `php artisan test` render the theme's Blade views, and Blade is PHP. Theme PHP could write files anywhere, use the project's `.env` database credentials, or change `~/.claude`. `theme:validate`'s view linter refuses the obvious patterns, but it is a pattern check, not a sandbox. So:
- **Scholix CI is the guarantee.** `theme-boundary.yml` fails any `theme/*` PR that changes a file outside the boundary. Make its job (**Theme Boundary / theme-boundary**) and **SDD theme spec** a *required status check* for `develop` and `prod` in the GitHub branch protection settings. Theme work must happen on `theme/{folder}` branches: CI checks nothing on other branches.
- Run SDD sessions on a development checkout whose `.env` points at a **local** database with no production credentials.
- Review the diff of every theme PR.

## Tests

```
php tests/run.php                          # expects Scholix at ../multisite
SCHOLIX_PATH=/path/to/scholix php tests/run.php
```

The tests cover:
- the write boundary and the shell guard, including every known bypass and allow-cases for normal theme work;
- the SDD contract check;
- **workflow consistency:** every command in `commands/*.md` and the skill is run through the hook. Claude's steps must be allowed and human steps (`theme:sdd {folder}`, `--stop`, `theme:page-contracts`, `git checkout/commit/push`) refused;
- **package shape:** `plugin.json`, `marketplace.json`, `hooks.json`, command/skill frontmatter, and the templates. The spec template must lint clean as a draft in Scholix.

After publishing, verify the real install once (Scholix `docs/SDD_PENDING_ISSUES.md`, P7).
