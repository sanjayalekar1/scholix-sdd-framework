<?php

use App\Support\ThemeBoundary;

/**
 * Plugin scholix-sdd. Requires App\Support\ThemeBoundary (loaded by
 * theme-write-guard.php from the open Scholix project).
 *
 * Decides whether a Bash command may run during SDD theme work: only
 * allowlisted commands, and every path a command writes (redirect target,
 * cp/mv destination, mkdir/rm/touch/tee operands, sed -i files, sort -o,
 * uniq output…) must be inside the theme boundary. Options that write files
 * or run other programs are refused. Anything it can't analyse safely is refused.
 *
 * This is an accident guard, not a sandbox: allowed commands that render the
 * theme (theme:validate --render, php artisan test) execute theme Blade, which
 * is PHP. Scholix CI (theme-boundary.yml) is the guarantee for the repository.
 */
final class ShellCommandGuard
{
    /** Commands that don't write files themselves (their write/exec options are handled below). */
    private const READ_ONLY = ['ls', 'cat', 'head', 'tail', 'grep', 'egrep', 'rg', 'find', 'wc', 'sort', 'uniq', 'diff', 'pwd', 'echo', 'printf', 'stat', 'file', 'du', 'tr', 'cut', 'awk', 'basename', 'dirname', 'realpath', 'which', 'true', 'false', 'test', 'jq'];

    /** Commands whose operands are written; the value-taking options of each. */
    private const WRITES_ALL_OPERANDS = [
        'mkdir' => ['-m', '--mode', '--context'],
        'touch' => ['-d', '--date', '-t', '-r', '--reference'],
        'rm' => [],
        'rmdir' => [],
        'tee' => [],
    ];

    /**
     * artisan commands theme work needs, with the options each may use
     * (theme:make only for the SDD theme). Other options are refused: some
     * write files elsewhere (--out, --log-junit, --coverage-html…) or switch
     * the environment (--env).
     */
    private const ARTISAN = [
        'theme:validate' => ['--render', '--coverage', '--strict', '--json'],
        'theme:preview' => ['--page', '--console', '--screenshots'],
        'theme:spec-lint' => ['--approved', '--json'],
        'theme:spec-apply' => [],
        // --unblock is a human step: it resets the fix-attempt cap.
        'theme:plan' => ['--validate', '--next', '--attempt', '--reworked', '--diff', '--complete', '--json'],
        'theme:sdd' => [], // status only: switching on/off is a human step
        'theme:make' => ['--extends', '--theme-version', '--override', '--purchased-template', '--force'],
        'test' => ['--filter', '--testsuite', '--group', '--exclude-group', '--stop-on-failure', '--stop-on-error', '--compact', '--parallel', '--processes', '--profile'],
        'view:clear' => [],
        'route:list' => ['--name', '--path', '--method', '--json', '--except-vendor', '--only-vendor'],
        'list' => [],
        'help' => [],
    ];

    private const ARTISAN_GLOBAL = ['--no-ansi', '--ansi', '-q', '--quiet', '-v', '-vv', '-vvv', '--verbose', '-n', '--no-interaction'];

    private const GIT_READ_ONLY = ['status', 'diff', 'log', 'show', 'ls-files', 'blame', 'branch', 'rev-parse'];

    /** git branch options that change branches. */
    private const GIT_BRANCH_CHANGES = ['-d', '-D', '-m', '-M', '-c', '-C', '-f', '-u', '-t', '--delete', '--move', '--copy', '--force', '--set-upstream-to', '--unset-upstream', '--edit-description', '--track', '--no-track', '--create-reflog'];

    /**
     * @param  array<int, string>  $guardFolders  folders outside the project that must stay read-only (this plugin, ~/.claude)
     */
    public function __construct(private string $theme, private string $root, private array $guardFolders = []) {}

    /** Null when the command may run; otherwise the reason it may not. */
    public function problem(string $command): ?string
    {
        $command = trim($command);

        if ($command === '') {
            return null;
        }

        if (preg_match('/`|\$\(|<\(|>\(/', $command) === 1) {
            return 'command substitution (`…`, $(…), process substitution) is not allowed — it can hide writes';
        }

        if (preg_match('/(^|[^\\\\])\\\\\n/', $command) === 1) {
            $command = preg_replace('/\\\\\n/', ' ', $command);
        }

        foreach ($this->segments($command) as $segment) {
            if (($problem = $this->segmentProblem($segment)) !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * Split on && || ; | & and newlines, outside quotes.
     *
     * @return array<int, string>
     */
    private function segments(string $command): array
    {
        $segments = [];
        $current = '';
        $quote = null;
        $length = strlen($command);

        for ($i = 0; $i < $length; $i++) {
            $char = $command[$i];

            if ($quote !== null) {
                $current .= $char;
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $current .= $char;

                continue;
            }

            $two = substr($command, $i, 2);
            if ($two === '&&' || $two === '||') {
                $segments[] = $current;
                $current = '';
                $i++;

                continue;
            }

            if ($char === ';' || $char === '|' || $char === "\n" || ($char === '&' && ($command[$i + 1] ?? '') !== '>' && ($command[$i - 1] ?? '') !== '>')) {
                $segments[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $segments[] = $current;

        return array_values(array_filter(array_map('trim', $segments), fn ($s) => $s !== ''));
    }

    private function segmentProblem(string $segment): ?string
    {
        [$targets, $segment] = $this->redirects($segment);

        foreach ($targets as $target) {
            if (($problem = $this->writeProblem($target, 'redirect target')) !== null) {
                return $problem;
            }
        }

        $words = $this->words($segment);

        if ($words === []) {
            return null;
        }

        // Leading environment assignments (FOO=bar cmd) are not allowed: they can redirect tools.
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $words[0]) === 1) {
            return 'environment variable assignments are not allowed';
        }

        $program = strtolower(basename($words[0]));
        $args = array_slice($words, 1);

        if (in_array($program, self::READ_ONLY, true)) {
            return $this->readOnlyProblem($program, $args, $segment);
        }

        if ($program === 'sed') {
            return $this->sedProblem($args);
        }

        if (isset(self::WRITES_ALL_OPERANDS[$program])) {
            [$operands] = $this->parse($args, self::WRITES_ALL_OPERANDS[$program]);

            return $this->allWritable($operands, "{$program} target");
        }

        if ($program === 'cp' || $program === 'mv') {
            [$operands, $options] = $this->parse($args, ['-t', '--target-directory', '-S', '--suffix']);
            $targets = array_merge($options['-t'] ?? [], $options['--target-directory'] ?? []);

            if ($program === 'mv') {
                // Moving removes the sources, so they are writes too.
                return $this->allWritable(array_merge($targets, $operands), 'mv source/destination');
            }

            return $this->allWritable($targets !== [] ? $targets : array_slice($operands, -1), 'cp destination');
        }

        if ($program === 'git') {
            return $this->gitProblem($args);
        }

        if ($program === 'node') {
            [, $options] = $this->parse($args, []);
            $flags = array_keys($options);

            return $flags !== [] && array_diff($flags, ['--check', '-c', '--version', '-v']) === []
                ? null
                : 'only "node --check <file>" is allowed';
        }

        if ($program === 'php' || $program === 'php.exe') {
            return $this->phpProblem($args);
        }

        return "\"{$program}\" is not an allowed command during theme work (allowed: read-only tools, mkdir/cp/mv/rm/touch/tee/sed -i inside the theme, git read-only, node --check, php artisan theme:*)";
    }

    /**
     * Write and execute options of otherwise read-only tools.
     *
     * @param  array<int, string>  $args
     */
    private function readOnlyProblem(string $program, array $args, string $segment): ?string
    {
        switch ($program) {
            case 'find':
                return preg_match('/\s-(delete|exec|execdir|ok|okdir|fprint0?|fprintf|fls)\b/', $segment) === 1
                    ? 'find with -delete/-exec/-ok/-fprint/-fls is not allowed'
                    : null;

            case 'awk':
                [$operands, $options] = $this->parse($args, ['-F', '-v', '-f', '--field-separator', '--assign', '--file', '-i', '--include', '-l', '--load', '-E', '--exec']);

                if (array_intersect(array_keys($options), ['-f', '--file', '-i', '--include', '-l', '--load', '-E', '--exec', '-o', '--pretty-print', '-p', '--profile', '-d', '--dump-variables', '-D', '--debug']) !== []) {
                    return 'awk -f/-i/-l/-E/-o/-p/-d/-D are not allowed (program files, extensions or output files)';
                }

                return preg_match('/system\s*\(|[>|]/', (string) ($operands[0] ?? '')) === 1
                    ? 'awk programs may not use system(), ">" or "|" (they write files or run commands); compare with "<" instead'
                    : null;

            case 'sort':
                [, $options] = $this->parse($args, ['-o', '--output', '-T', '--temporary-directory', '-k', '--key', '-t', '--field-separator', '-S', '--buffer-size', '--parallel', '--batch-size', '--compress-program', '--files0-from', '--random-source', '--sort']);

                if (isset($options['--compress-program'])) {
                    return 'sort --compress-program runs another program';
                }

                return $this->allWritable(array_merge($options['-o'] ?? [], $options['--output'] ?? [], $options['-T'] ?? [], $options['--temporary-directory'] ?? []), 'sort output');

            case 'uniq':
                [$operands] = $this->parse($args, ['-f', '-s', '-w', '--skip-fields', '--skip-chars', '--check-chars']);

                return isset($operands[1]) ? $this->writeProblem($operands[1], 'uniq output') : null;

            case 'file':
                [, $options] = $this->parse($args, ['-m', '--magic-file', '-f', '--files-from', '-F', '--separator', '-P', '--parameter', '-e', '--exclude']);

                return isset($options['-C']) || isset($options['--compile']) ? 'file -C writes a compiled magic file' : null;

            case 'rg':
                [, $options] = $this->parse($args, []);

                return isset($options['--pre']) ? 'rg --pre runs another program' : null;

            default:
                return null;
        }
    }

    /**
     * @param  array<int, string>  $args
     */
    private function sedProblem(array $args): ?string
    {
        [$operands, $options] = $this->parse($args, ['-e', '-f', '-l', '--expression', '--file', '--line-length']);

        if (isset($options['-f']) || isset($options['--file'])) {
            return 'sed -f is not allowed: pass the script inline so it can be checked';
        }

        $scripts = array_merge($options['-e'] ?? [], $options['--expression'] ?? []);
        $files = $operands;

        if ($scripts === []) {
            $scripts = array_slice($operands, 0, 1);
            $files = array_slice($operands, 1);
        }

        foreach ($scripts as $script) {
            // w/W write files and e runs a command — as commands (after an address or ;{}) or as the s///w flag.
            if (preg_match('/(^|[;{}\s\d$\/])\s*[wWe](\s|$)/', $script) === 1) {
                return 'sed scripts may not use the w, W or e commands (they write files or run commands)';
            }
        }

        $inPlace = isset($options['-i']) || isset($options['--in-place']);

        return $inPlace ? $this->allWritable($files, 'sed -i target') : null;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function gitProblem(array $args): ?string
    {
        [$operands, $options] = $this->parse($args, []);
        $sub = strtolower((string) ($operands[0] ?? ''));

        if (! in_array($sub, self::GIT_READ_ONLY, true)) {
            return "git {$sub} changes the repository — only ".implode('/', self::GIT_READ_ONLY).' are allowed; a human commits theme work';
        }

        if (isset($options['--output'])) {
            return 'git --output writes a file';
        }

        if ($sub === 'branch') {
            [$branchOperands, $branchOptions] = $this->parse(array_slice($args, array_search($operands[0], $args, true) + 1), ['--contains', '--no-contains', '--merged', '--no-merged', '--points-at', '--format', '--sort']);
            $listing = array_intersect(array_keys($branchOptions), ['-l', '--list', '-a', '--all', '-r', '--remotes']) !== [];

            if (array_intersect(array_keys($branchOptions), self::GIT_BRANCH_CHANGES) !== [] || ($branchOperands !== [] && ! $listing)) {
                return 'git branch may only list branches; a human creates, renames or deletes them';
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $args
     */
    private function phpProblem(array $args): ?string
    {
        if (($args[0] ?? '') !== 'artisan') {
            [, $options] = $this->parse($args, []);
            $flags = array_keys($options);

            return $flags !== [] && array_diff($flags, ['-l', '--syntax-check', '-v', '--version']) === []
                ? null
                : 'running PHP code is not allowed during theme work (allowed: php -l, php artisan '.implode('|', array_keys(self::ARTISAN)).')';
        }

        $command = (string) ($args[1] ?? 'list');

        if (! isset(self::ARTISAN[$command])) {
            return "php artisan {$command} is not allowed during theme work (allowed: theme:make {$this->theme}, ".implode(', ', array_diff(array_keys(self::ARTISAN), ['theme:make'])).')';
        }

        [$operands, $options] = $this->parse(array_slice($args, 2), []);

        if ($command === 'theme:make' && ($operands[0] ?? '') !== $this->theme) {
            return "theme:make is allowed only for the SDD theme \"{$this->theme}\"";
        }

        if ($command === 'theme:sdd' && ($operands !== [] || $options !== [])) {
            return 'php artisan theme:sdd may only show the status; switching SDD mode on or off is a human step';
        }

        $refused = array_diff(array_keys($options), self::ARTISAN[$command], self::ARTISAN_GLOBAL);

        if ($command === 'theme:plan' && in_array('--unblock', $refused, true)) {
            return 'php artisan theme:plan --unblock is a human step: a blocked unit failed its fix attempts and a person decides what happens next';
        }

        return $refused === []
            ? null
            : "php artisan {$command} ".implode(' ', $refused).' is not allowed during theme work (allowed options: '.(implode(' ', self::ARTISAN[$command]) ?: 'none').')';
    }

    /**
     * Positional operands and options of a command. $valued lists the options
     * that take a value (short "-o" or long "--output"), attached (-ofile,
     * --output=file) or as the next word; other options map to ''.
     *
     * @param  array<int, string>  $args
     * @param  array<int, string>  $valued
     * @return array{0: array<int, string>, 1: array<string, array<int, string>>}
     */
    private function parse(array $args, array $valued): array
    {
        $operands = [];
        $options = [];
        $count = count($args);

        for ($i = 0; $i < $count; $i++) {
            $arg = $args[$i];

            if ($arg === '--') {
                array_push($operands, ...array_slice($args, $i + 1));

                break;
            }

            if (str_starts_with($arg, '--')) {
                [$name, $value] = array_pad(explode('=', $arg, 2), 2, null);
                $options[$name][] = $value ?? (in_array($name, $valued, true) ? (string) ($args[++$i] ?? '') : '');

                continue;
            }

            if (strlen($arg) > 1 && $arg[0] === '-') {
                for ($j = 1; $j < strlen($arg); $j++) {
                    $flag = '-'.$arg[$j];

                    if (in_array($flag, $valued, true)) {
                        $rest = substr($arg, $j + 1);
                        $options[$flag][] = $rest !== '' ? $rest : (string) ($args[++$i] ?? '');

                        break;
                    }

                    $options[$flag][] = '';
                }

                continue;
            }

            $operands[] = $arg;
        }

        return [$operands, $options];
    }

    /**
     * Output redirections outside quotes, and the segment without them.
     * Writes: > >> >| &> &>> N> N>> >&file and <> (opens for writing).
     * >&N, N>&M and >&- only duplicate or close descriptors.
     *
     * @return array{0: array<int, string>, 1: string}
     */
    private function redirects(string $segment): array
    {
        $targets = [];
        $clean = '';
        $quote = null;
        $length = strlen($segment);

        for ($i = 0; $i < $length; $i++) {
            $char = $segment[$i];

            if ($quote !== null) {
                $clean .= $char;
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $clean .= $char;

                continue;
            }

            if ($char === '\\') {
                $clean .= $char.($segment[$i + 1] ?? '');
                $i++;

                continue;
            }

            if ($char !== '>' && ! ($char === '<' && ($segment[$i + 1] ?? '') === '>')) {
                $clean .= $char;

                continue;
            }

            $clean = (string) preg_replace('/(\d+|&)$/', '', $clean); // the operator's descriptor / &
            $i += $char === '<' ? 2 : 1;

            if ($char === '>' && in_array($segment[$i] ?? '', ['>', '|'], true)) {
                $i++;
            }

            if ($char === '>' && ($segment[$i] ?? '') === '&') {
                $i++;
                if (preg_match('/\G(\d+|-)(?=$|[\s;&|<>])/', $segment, $m, 0, $i) === 1) {
                    $i += strlen($m[0]) - 1;
                    $clean .= ' ';

                    continue;
                }
            }

            while ($i < $length && ctype_space($segment[$i])) {
                $i++;
            }

            $target = '';
            $targetQuote = null;

            for (; $i < $length; $i++) {
                $c = $segment[$i];

                if ($targetQuote !== null) {
                    if ($c === $targetQuote) {
                        $targetQuote = null;
                    } else {
                        $target .= $c;
                    }

                    continue;
                }

                if ($c === '"' || $c === "'") {
                    $targetQuote = $c;

                    continue;
                }

                if (ctype_space($c) || in_array($c, ['<', '>', '&', ';', '|'], true)) {
                    $i--;

                    break;
                }

                $target .= $c;
            }

            $targets[] = $target;
            $clean .= ' ';
        }

        return [$targets, $clean];
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function allWritable(array $paths, string $what): ?string
    {
        foreach ($paths as $path) {
            if (($problem = $this->writeProblem($path, $what)) !== null) {
                return $problem;
            }
        }

        return null;
    }

    private function writeProblem(string $path, string $what): ?string
    {
        $path = $this->unquote($path);

        if ($path === '' || $path === '/dev/null') {
            return null;
        }

        if (preg_match('/[*?\[{~$]/', $path) === 1) {
            return "{$what} \"{$path}\" uses a glob/variable — write explicit paths inside the theme";
        }

        $relative = ThemeBoundary::relative($path, $this->root);

        if ($relative === null) {
            foreach ($this->guardFolders as $folder) {
                if (ThemeBoundary::relative($path, $folder) !== null) {
                    return "{$what} {$path}: it is Claude Code configuration or this guard's plugin";
                }
            }

            return null; // outside the repository (scratch files)
        }

        $reason = ThemeBoundary::violation($this->theme, $relative) ?? self::generatedFileReason($this->theme, $relative);

        return $reason === null ? null : "{$what} {$relative}: {$reason}";
    }

    /**
     * Files inside the boundary that only a Scholix command may write: the
     * build plan is theme:plan's record of what was verified, so Claude
     * can't mark a unit done, clear a stale one or reset fix attempts by hand.
     */
    public static function generatedFileReason(string $theme, string $relative): ?string
    {
        return strcasecmp(ltrim(str_replace('\\', '/', $relative), '/'), "specs/themes/{$theme}/build-plan.json") === 0
            ? 'the build plan is written only by php artisan theme:plan (never by hand): use --attempt, --reworked or --validate'
            : null;
    }

    /**
     * Shell-like word split honouring quotes.
     *
     * @return array<int, string>
     */
    private function words(string $segment): array
    {
        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $segment, $matches, PREG_SET_ORDER);

        return array_map(fn ($m) => $m[1] !== '' ? $m[1] : (($m[2] ?? '') !== '' ? $m[2] : ($m[3] ?? '')), $matches);
    }

    private function unquote(string $value): string
    {
        return trim($value, "\"' ");
    }
}
