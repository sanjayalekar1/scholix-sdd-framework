<?php

/**
 * Tests for the scholix-sdd write-boundary hook, run as a real process the way
 * Claude Code runs it. Plain PHP — no composer.
 *
 *   php tests/run.php                      (Scholix checkout at ../multisite)
 *   SCHOLIX_PATH=/path/to/scholix php tests/run.php
 *
 * The boundary rule itself (App\Support\ThemeBoundary) and the CI diff check
 * are tested in the Scholix repository (tests/Unit/ThemeBoundaryTest.php).
 */

$plugin = dirname(__DIR__);
$scholix = rtrim((string) (getenv('SCHOLIX_PATH') ?: dirname($plugin).'/multisite'), '/\\');
$hook = $plugin.'/hooks/theme-write-guard.php';
$theme = 'new-sdd-theme';
$home = sys_get_temp_dir().'/sdd-hook-test-home';

if (! is_file($scholix.'/app/Support/ThemeBoundary.php')) {
    fwrite(STDERR, "Scholix checkout not found at {$scholix} (set SCHOLIX_PATH).\n");
    exit(2);
}

/** @return string hook stdout ('' = allow) */
function hook(string $hook, string $scholix, array|string $input, ?string $theme, string $home): string
{
    $env = array_merge(getenv(), ['CLAUDE_PROJECT_DIR' => $scholix, 'HOME' => $home, 'USERPROFILE' => $home]);
    unset($env['SCHOLIX_SDD_THEME']);
    if ($theme !== null) {
        $env['SCHOLIX_SDD_THEME'] = $theme;
    }
    $process = proc_open([PHP_BINARY, $hook], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $scholix, $env);
    fwrite($pipes[0], is_string($input) ? $input : (string) json_encode($input));
    fclose($pipes[0]);
    $out = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return $out;
}

function denied(string $out): bool
{
    return (json_decode($out, true)['hookSpecificOutput']['permissionDecision'] ?? null) === 'deny';
}

$passed = 0;
$failed = [];
$check = function (string $label, bool $ok) use (&$passed, &$failed): void {
    $ok ? $passed++ : $failed[] = $label;
};

$write = fn (string $path) => ['tool_name' => 'Write', 'tool_input' => ['file_path' => $path, 'content' => 'x']];
$bash = fn (string $command) => ['tool_name' => 'Bash', 'tool_input' => ['command' => $command]];

// Inactive without an SDD theme.
$check('inactive without SDD theme', hook($hook, $scholix, $write($scholix.'/app/x.php'), null, $home) === '');

// File tools.
foreach ([
    'resources/views/themes/new-sdd-theme/pages/home.blade.php',
    'resources/views/themes/new-sdd-theme/assets/js/theme.js',
    'specs/themes/new-sdd-theme/theme.spec.md',
] as $path) {
    $check("allow write {$path}", hook($hook, $scholix, $write($scholix.'/'.$path), $theme, $home) === '');
}
foreach ([
    'app/Http/Controllers/TenantWebsite/WebsiteController.php', 'app/Models/Courses.php',
    'database/migrations/2026_10_06_000000_x.php', 'routes/web.php', 'config/themes.php',
    'resources/views/tenant-admin/dashboard.blade.php', 'resources/views/themes/default/pages/home.blade.php',
    'resources/views/themes/base/pages/about-us.blade.php', 'resources/theme-contracts/pages/home.schema.json',
    'resources/js/app.js', 'docker/app/Dockerfile', '.github/workflows/build-and-push.yml',
    '.claude/sdd-theme', '.claude/settings.json', 'composer.json',
    'resources/views/themes/new-sdd-theme/../default/x.blade.php',
] as $path) {
    $check("block write {$path}", denied(hook($hook, $scholix, $write($scholix.'/'.$path), $theme, $home)));
}
foreach (['Edit', 'MultiEdit', 'NotebookEdit'] as $tool) {
    $check("block {$tool} core", denied(hook($hook, $scholix, ['tool_name' => $tool, 'tool_input' => ['file_path' => $scholix.'/routes/web.php', 'notebook_path' => $scholix.'/routes/web.php']], $theme, $home)));
}
$check('allow Read', hook($hook, $scholix, ['tool_name' => 'Read', 'tool_input' => ['file_path' => $scholix.'/app/Models/Courses.php']], $theme, $home) === '');
$check('allow scratch outside repo', hook($hook, $scholix, $write(sys_get_temp_dir().'/scratch.txt'), $theme, $home) === '');

// The guard protects itself: its plugin folder and ~/.claude.
$check('block write to plugin hook', denied(hook($hook, $scholix, $write($plugin.'/hooks/theme-write-guard.php'), $theme, $home)));
$check('block write to ~/.claude/settings.json', denied(hook($hook, $scholix, $write($home.'/.claude/settings.json'), $theme, $home)));
$check('block bash write to plugin', denied(hook($hook, $scholix, $bash('echo x > '.$plugin.'/hooks/hooks.json'), $theme, $home)));

// Shell.
foreach ([
    'php artisan theme:validate new-sdd-theme --render --strict', 'php artisan theme:make new-sdd-theme',
    'php artisan theme:preview new-sdd-theme --console',
    'mkdir -p resources/views/themes/new-sdd-theme/pages && ls resources/views/themes',
    'cp resources/views/themes/base/ui/page.blade.php resources/views/themes/new-sdd-theme/ui/page.blade.php',
    'echo x > resources/views/themes/new-sdd-theme/x.txt', 'sed -n 1,5p routes/web.php', 'git status && git diff',
    'node --check resources/views/themes/new-sdd-theme/assets/js/theme.js', 'grep -rn foo app 2>/dev/null | head -5',
    // Tightened commands still allow their normal use.
    'php artisan theme:spec-lint new-sdd-theme --approved --json', 'php artisan test --filter=ThemeContractTest',
    'php artisan theme:preview new-sdd-theme --page=home --console --screenshots', 'php -l resources/views/themes/new-sdd-theme/x.php',
    "sed -i 's/a/b/g' resources/views/themes/new-sdd-theme/assets/css/theme.css", "sed -n '/foo/p' routes/web.php",
    'sed -i -e s/a/b/ resources/views/themes/new-sdd-theme/x.css', 'sort -u README.md', 'sort -o resources/views/themes/new-sdd-theme/x.txt README.md',
    'uniq README.md', 'uniq -f 1 README.md', "awk -F, '{print \$2}' README.md", "awk '\$1 < 5' README.md",
    'cp -t resources/views/themes/new-sdd-theme/ui resources/views/themes/base/ui/page.blade.php',
    'git branch', 'git branch -a', 'git branch --list "theme/*"', 'git log --oneline -5', 'cat README.md 2>&1 | head -3',
    'echo x >> resources/views/themes/new-sdd-theme/x.txt', 'grep -c "a > b" README.md', 'ls >&2', 'rg --files app',
] as $command) {
    $check("allow bash: {$command}", hook($hook, $scholix, $bash($command), $theme, $home) === '');
}

// F4: write paths that slipped through the first allowlist, and their relatives.
foreach ([
    'sed --in-place s/a/b/ routes/web.php', 'sed --in-place=.bak s/a/b/ routes/web.php', 'sed -Ei s/a/b/ routes/web.php',
    "sed -n 'w app/x.php' README.md", "sed 's/a/b/w app/x.php' README.md", "sed '1e rm -rf app' README.md", 'sed -f x.sed README.md',
    'sort -o app/x.php README.md', 'sort --output=app/x.php README.md', 'sort -uo app/x.php README.md', 'sort --compress-program=sh README.md',
    'uniq README.md app/x.php', 'uniq -f 1 README.md app/x.php',
    'git diff --output=app/x.php', 'git log -p --output app/x.php', 'git branch -D master', 'git branch new-branch', 'git branch -m a b',
    'awk -i inplace "{print}" routes/web.php', "awk '{print > \"app/x.php\"}' README.md", "awk '{f=\"app/x\"; print \$0>f}' README.md",
    "awk '{system(\"rm x\")}' README.md", "awk '{print | \"sh\"}' README.md", 'awk -f prog.awk README.md', 'awk -d README.md',
    'cp -t app/ resources/views/themes/new-sdd-theme/a.css', 'cp --target-directory=app/ resources/views/themes/new-sdd-theme/a.css',
    'mv --target-directory=app/ resources/views/themes/new-sdd-theme/a.css',
    'echo x>app/x.php', 'echo x &>app/x.php', 'echo x &> app/x.php', 'echo x >| app/x.php', 'echo x >&app/x.php', 'cat <>app/x.php',
    'echo x 1>app/x.php', 'echo x>>"app/x y.php"',
    'find . -fprint0 app/x', 'find . -okdir rm {} ;', 'file -C -m resources/views/themes/new-sdd-theme/x', 'rg --pre sh foo',
    'php artisan test --log-junit=app/x.xml', 'php artisan test --coverage-html app/cov', 'php artisan theme:preview new-sdd-theme --out=app/x',
    'php artisan theme:validate new-sdd-theme --env=production', 'php -d auto_prepend_file=x.php -l x.php',
    'node --check -r ./evil.js x.js', 'node x.js',
] as $command) {
    $check("block bash: {$command}", denied(hook($hook, $scholix, $bash($command), $theme, $home)));
}
foreach ([
    'php artisan migrate', 'php artisan theme:sdd --stop', 'php artisan theme:make default --force', 'echo x > app/x.php',
    'sed -i s/a/b/ routes/web.php', 'cp resources/views/themes/new-sdd-theme/x.css resources/views/themes/default/x.css',
    'rm .claude/sdd-theme', 'git checkout -- app/', 'cat $(echo app/x)', 'php -r "file_put_contents(1,1);"', 'node -e "1"',
    'grep -rn x app | tee app/out.txt', 'find app -name x -delete', 'curl -o app/x https://example.test', 'FOO=1 ls',
] as $command) {
    $check("block bash: {$command}", denied(hook($hook, $scholix, $bash($command), $theme, $home)));
}
$check('block PowerShell', denied(hook($hook, $scholix, ['tool_name' => 'PowerShell', 'tool_input' => ['command' => 'Get-ChildItem']], $theme, $home)));
$check('fail closed on bad input', denied(hook($hook, $scholix, 'not json', $theme, $home)));

// Missing Scholix rule while SDD mode is on → blocked.
$check('fail closed outside Scholix', denied(hook($hook, sys_get_temp_dir(), $write(sys_get_temp_dir().'/x.txt'), $theme, $home)));

// F9: the plugin refuses a Scholix whose SDD contract it doesn't support (or that has none).
$fake = sys_get_temp_dir().'/sdd-hook-fake-scholix';
@mkdir($fake.'/app/Support', 0777, true);
copy($scholix.'/app/Support/ThemeBoundary.php', $fake.'/app/Support/ThemeBoundary.php');
@unlink($fake.'/app/Support/SddContract.php');
$inTheme = $write($fake.'/resources/views/themes/new-sdd-theme/x.blade.php');
$out = hook($hook, $fake, $inTheme, $theme, $home);
$check('block when Scholix has no SddContract', denied($out) && str_contains($out, 'SddContract'));
file_put_contents($fake.'/app/Support/SddContract.php', "<?php\nnamespace App\\Support;\nfinal class SddContract { public const VERSION = 99; }\n");
$out = hook($hook, $fake, $inTheme, $theme, $home);
$check('block an unsupported SDD contract version', denied($out) && str_contains($out, 'SDD contract 99'));
$check('allow the supported SDD contract (real Scholix)', hook($hook, $scholix, $write($scholix.'/resources/views/themes/new-sdd-theme/x.blade.php'), $theme, $home) === '');

// P6: every command the workflow tells Claude to run passes the hook, and every human step is refused.
// Commands are read from the Claude-facing files, so docs and guard can't drift apart.
$humanSteps = ["php artisan theme:sdd {$theme}", 'php artisan theme:sdd --stop', 'php artisan theme:page-contracts', 'git checkout', 'git commit', 'git push'];
$workflowCommands = [];
foreach (array_merge(glob($plugin.'/commands/*.md'), [$plugin.'/skills/scholix-theme-rules/SKILL.md']) as $doc) {
    $text = (string) file_get_contents($doc);
    preg_match_all('/`([^`\n]+)`/', $text, $inline);
    preg_match_all('/```\w*\n(.*?)```/s', $text, $blocks);
    foreach (array_merge($inline[1], ...array_map(fn ($b) => explode("\n", $b), $blocks[1])) as $candidate) {
        $candidate = trim((string) preg_replace('/\s+#.*$/', '', str_replace(['$1', '{folder}'], $theme, $candidate)));
        // Commands only (php/git/node + a subcommand or file), not prose fragments like "git --output".
        if (preg_match('/^(php|git|node) [a-z]/', $candidate) === 1 && preg_match('/[<{…]/u', $candidate) !== 1) {
            $workflowCommands[$candidate] = basename(dirname($doc)).'/'.basename($doc);
        }
    }
}
$check('workflow docs name commands to check', count($workflowCommands) >= 8);
foreach ($workflowCommands as $command => $source) {
    $human = array_filter($humanSteps, fn ($step) => str_starts_with($command, $step)) !== [];
    $out = hook($hook, $scholix, $bash($command), $theme, $home);
    $check(($human ? 'human step refused' : 'workflow step allowed')." ({$source}): {$command}", $human ? denied($out) : $out === '');
}

// P7: the package is well-formed: what /plugin install reads.
$json = fn (string $path) => json_decode((string) @file_get_contents($plugin.'/'.$path), true);
$manifest = $json('.claude-plugin/plugin.json');
$check('plugin.json: name, semver version, description', ($manifest['name'] ?? null) === 'scholix-sdd' && preg_match('/^\d+\.\d+\.\d+$/', (string) ($manifest['version'] ?? '')) === 1 && ($manifest['description'] ?? '') !== '');
$marketplace = $json('.claude-plugin/marketplace.json');
$check('marketplace.json lists scholix-sdd from this folder', ($marketplace['name'] ?? '') !== '' && ($marketplace['owner']['name'] ?? '') !== '' && ($marketplace['plugins'][0]['name'] ?? null) === 'scholix-sdd' && ($marketplace['plugins'][0]['source'] ?? null) === './');
$hookEntry = $json('hooks/hooks.json')['hooks']['PreToolUse'][0] ?? [];
$check('hooks.json runs the guard from the plugin root', str_contains((string) ($hookEntry['hooks'][0]['command'] ?? ''), '${CLAUDE_PLUGIN_ROOT}/hooks/theme-write-guard.php') && is_file($plugin.'/hooks/theme-write-guard.php'));
$check('hooks.json matcher covers every write tool and shell', array_diff(['Write', 'Edit', 'MultiEdit', 'NotebookEdit', 'Bash', 'PowerShell'], explode('|', (string) ($hookEntry['matcher'] ?? ''))) === []);
foreach (glob($plugin.'/commands/*.md') as $file) {
    $check('command frontmatter has a description: '.basename($file), preg_match('/\A---\n(?:.*\n)*?description: \S.*\n(?:.*\n)*?---\n/', str_replace("\r\n", "\n", (string) file_get_contents($file))) === 1);
}
foreach (glob($plugin.'/skills/*/SKILL.md') as $file) {
    $skill = str_replace("\r\n", "\n", (string) file_get_contents($file));
    $check('skill frontmatter name = folder: '.basename(dirname($file)), preg_match('/\A---\nname: '.preg_quote(basename(dirname($file)), '/').'\ndescription: \S/', $skill) === 1);
}
foreach (array_merge(glob($plugin.'/commands/*.md'), glob($plugin.'/skills/*/SKILL.md')) as $file) {
    $text = (string) file_get_contents($file);
    $check('no ${CLAUDE_PLUGIN_ROOT} or tests/Themes in '.basename($file), ! str_contains($text, '${CLAUDE_PLUGIN_ROOT}') && ! str_contains($text, 'tests/Themes'));
}
$templates = $plugin.'/skills/scholix-theme-rules/templates';
$check('spec templates exist (json valid, md present)', is_array(json_decode((string) @file_get_contents($templates.'/theme.spec.json'), true)) && is_file($templates.'/theme.spec.md'));

// The spec template is a valid draft for Scholix's own linter (shape and names), with TODO(human) items open.
$probe = 'zz-plugin-template-probe';
$specRoot = $scholix.'/specs';
$createdRoot = ! is_dir($specRoot);
@mkdir($specRoot.'/themes/'.$probe, 0777, true);
foreach (['theme.spec.json', 'theme.spec.md'] as $name) {
    file_put_contents($specRoot.'/themes/'.$probe.'/'.$name, str_replace(['{folder}', '{Theme name}'], [$probe, 'Template Probe'], (string) file_get_contents($templates.'/'.$name)));
}
$lint = proc_open([PHP_BINARY, $scholix.'/artisan', 'theme:spec-lint', $probe, '--json'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $lintPipes, $scholix);
$report = json_decode((string) stream_get_contents($lintPipes[1]), true);
fclose($lintPipes[1]);
fclose($lintPipes[2]);
proc_close($lint);
array_map('unlink', glob($specRoot.'/themes/'.$probe.'/*'));
@rmdir($specRoot.'/themes/'.$probe);
if ($createdRoot) {
    @rmdir($specRoot.'/themes');
    @rmdir($specRoot);
}
$check('spec template lints clean as a draft in Scholix', ($report['ok'] ?? false) === true && count($report['todo_human'] ?? []) > 0);

echo "Passed: {$passed}\nFailed: ".count($failed)."\n";
foreach ($failed as $label) {
    echo "  ✗ {$label}\n";
}
exit($failed === [] ? 0 : 1);
