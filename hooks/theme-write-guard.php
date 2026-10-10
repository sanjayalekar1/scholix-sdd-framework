<?php

/**
 * Claude Code PreToolUse hook (plugin scholix-sdd): the hard write boundary
 * for SDD theme work in a Scholix checkout.
 *
 * Inactive (allows everything) unless SDD theme mode is on in the open
 * project — switched on by a human only:
 *   php artisan theme:sdd {theme}      (writes {project}/.claude/sdd-theme)
 *   or env SCHOLIX_SDD_THEME={theme}
 *
 * The boundary rule is NOT defined here: it is Scholix's own
 * {project}/app/Support/ThemeBoundary.php (single source of truth, also used
 * by Scholix CI). While SDD mode is on:
 *   - Write / Edit / MultiEdit / NotebookEdit: target path must be inside the theme boundary;
 *     this plugin's folder and ~/.claude are protected too (so the guard can't be edited or disabled);
 *   - Bash: allowlisted commands only; every path a command writes must be inside the boundary;
 *   - PowerShell: blocked.
 * It also refuses to work against a Scholix whose SDD contract
 * ({project}/app/Support/SddContract.php VERSION) it doesn't support.
 *
 * Input: hook JSON on stdin. Output: a PreToolUse deny decision (JSON) or nothing (allow).
 */

$root = rtrim((string) (getenv('CLAUDE_PROJECT_DIR') ?: getcwd()), '/\\');
$theme = trim((string) (getenv('SCHOLIX_SDD_THEME') ?: @file_get_contents($root.'/.claude/sdd-theme')));

if ($theme === '') {
    exit(0); // SDD theme mode is off (or this is not a Scholix project)
}

$deny = function (string $reason): void {
    echo json_encode(['hookSpecificOutput' => [
        'hookEventName' => 'PreToolUse',
        'permissionDecision' => 'deny',
        'permissionDecisionReason' => '[SDD theme write boundary] '.$reason,
    ]], JSON_UNESCAPED_SLASHES);
    exit(0);
};

$rule = $root.'/app/Support/ThemeBoundary.php';
$contract = $root.'/app/Support/SddContract.php';

if (! is_file($rule)) {
    $deny("SDD theme mode is on but {$rule} (Scholix's boundary rule) was not found; blocked. Open the Scholix repository as the project.");
}

/** Versions of Scholix's SDD contract (App\Support\SddContract::VERSION) this plugin understands. */
const SUPPORTED_SDD_CONTRACTS = [2];

if (! is_file($contract)) {
    $deny('SDD theme mode is on but this Scholix has no app/Support/SddContract.php — it is older than this plugin (supports SDD contract '.implode(', ', SUPPORTED_SDD_CONTRACTS).'). Update Scholix or use a matching plugin version.');
}

require_once $rule;
require_once $contract;
require_once __DIR__.'/ShellCommandGuard.php';

use App\Support\SddContract;
use App\Support\ThemeBoundary;

if (! in_array(SddContract::VERSION, SUPPORTED_SDD_CONTRACTS, true)) {
    $deny('this Scholix uses SDD contract '.SddContract::VERSION.'; the scholix-sdd plugin supports '.implode(', ', SUPPORTED_SDD_CONTRACTS).'. Update the plugin (/plugin update scholix-sdd) before theme work.');
}

if (! ThemeBoundary::isValidThemeName($theme)) {
    $deny("SDD theme \"{$theme}\" is not a valid theme folder; ask a human to run php artisan theme:sdd {theme}.");
}

$input = json_decode((string) stream_get_contents(STDIN), true);

if (! is_array($input)) {
    $deny('could not read the tool call; blocked while SDD theme mode is on.'); // fail closed
}

$tool = (string) ($input['tool_name'] ?? '');
$args = (array) ($input['tool_input'] ?? []);
$describe = ThemeBoundary::describe($theme).'.';

/** Folders outside the project that would let Claude disable or edit the guard. */
$guardFolders = array_filter([
    dirname(__DIR__),                                                     // this plugin
    (getenv('HOME') ?: getenv('USERPROFILE') ?: '') !== '' ? (getenv('HOME') ?: getenv('USERPROFILE')).'/.claude' : '',
]);

switch ($tool) {
    case 'Write':
    case 'Edit':
    case 'MultiEdit':
    case 'NotebookEdit':
        $path = (string) ($args['file_path'] ?? $args['notebook_path'] ?? '');
        $relative = ThemeBoundary::relative($path, $root);

        if ($relative === null) {
            foreach ($guardFolders as $folder) {
                if (ThemeBoundary::relative($path, $folder) !== null) {
                    $deny("Blocked write to {$path}: it is Claude Code configuration or this guard's plugin. {$describe}");
                }
            }

            exit(0); // outside the repository (scratch files)
        }

        if (($reason = ThemeBoundary::violation($theme, $relative) ?? ShellCommandGuard::generatedFileReason($theme, $relative)) !== null) {
            $deny("Blocked write to {$relative}: {$reason}. {$describe} If the theme needs core data or a core change, stop and ask a human.");
        }

        exit(0);

    case 'Bash':
        $problem = (new ShellCommandGuard($theme, $root, array_values($guardFolders)))->problem((string) ($args['command'] ?? ''));

        if ($problem !== null) {
            $deny("Blocked shell command: {$problem}. {$describe}");
        }

        exit(0);

    case 'PowerShell':
        $deny('PowerShell is not available during SDD theme work; use the file tools (Write/Edit) or allowlisted Bash commands.');

    default:
        exit(0); // read-only and other tools
}
