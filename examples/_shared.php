<?php

declare(strict_types=1);

namespace PoliPage\Examples;

const ENV_FILE = __DIR__ . '/../.env';

function useColor(): bool
{
    if (getenv('NO_COLOR') === '1') {
        return false;
    }
    // STDOUT may be undefined when the script is executed by a non-CLI SAPI.
    if (!\defined('STDOUT')) {
        return false;
    }

    return \function_exists('posix_isatty') && @posix_isatty(STDOUT);
}

function ansi(string $code, string $text): string
{
    return useColor() ? "\x1b[{$code}m{$text}\x1b[0m" : $text;
}

function bold(string $s): string
{
    return ansi('1', $s);
}
function dim(string $s): string
{
    return ansi('2', $s);
}
function red(string $s): string
{
    return ansi('31', $s);
}
function green(string $s): string
{
    return ansi('32', $s);
}
function yellow(string $s): string
{
    return ansi('33', $s);
}
function cyan(string $s): string
{
    return ansi('36', $s);
}

function step(int $n, int $total, string $name): void
{
    echo "\n" . cyan(bold("[{$n}/{$total}] {$name}")) . "\n";
}

function fileLink(string $relPath): string
{
    return cyan('file://' . realpath($relPath));
}

/**
 * Parse a `.env`-style file. Comments + blank lines skipped, surrounding
 * single/double quotes stripped, last occurrence of a key wins.
 *
 * @return array<string, string>
 */
function readEnvFile(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $result = [];
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return [];
    }
    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $eq = strpos($line, '=');
        if ($eq === false) {
            continue;
        }
        $key = trim(substr($line, 0, $eq));
        $value = trim(substr($line, $eq + 1));
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        $result[$key] = $value;
    }

    return $result;
}

function appendToEnvFile(string $path, string $key, string $value): void
{
    $existing = is_file($path) ? (file_get_contents($path) ?: '') : '';
    $needsLeadingNewline = $existing !== '' && !str_ends_with($existing, "\n");
    file_put_contents(
        $path,
        ($needsLeadingNewline ? "\n" : '') . "{$key}={$value}\n",
        \FILE_APPEND,
    );
}

function resolveBaseUrl(): string
{
    $env = getenv('POLI_PAGE_BASE_URL');
    if (is_string($env) && $env !== '') {
        return $env;
    }
    $fromFile = readEnvFile(ENV_FILE)['POLI_PAGE_BASE_URL'] ?? null;
    if (is_string($fromFile) && $fromFile !== '') {
        return $fromFile;
    }

    return 'https://api.poli.page';
}

/**
 * Resolve POLI_PAGE_API_KEY: env > .env > interactive prompt.
 * On a successful prompt the pasted key is appended to `.env` so the
 * next run is silent. Exits the process with a friendly error if the
 * pasted value does not look like a test key.
 */
function ensureApiKey(): string
{
    $env = getenv('POLI_PAGE_API_KEY');
    if (is_string($env) && $env !== '') {
        return $env;
    }

    $fromFile = readEnvFile(ENV_FILE)['POLI_PAGE_API_KEY'] ?? null;
    if (is_string($fromFile) && $fromFile !== '') {
        echo dim('  using POLI_PAGE_API_KEY from ' . ENV_FILE) . "\n";

        return $fromFile;
    }

    $rule = dim('  ─────────────────────────────────────────────────────────────────────');
    echo "\n";
    echo $rule . "\n";
    echo bold(yellow('   No POLI_PAGE_API_KEY found.')) . "\n";
    echo $rule . "\n\n";
    echo "   This demo needs a test key (" . cyan('pp_test_*') . ") to\n";
    echo "   talk to the Poli Page API. Test keys never bill or send real\n";
    echo "   documents.\n\n";
    echo bold("   How to get one:") . "\n";
    echo "     1. Sign in at " . cyan('https://app.poli.page') . "\n";
    echo "     2. Go to your organization's API keys page:\n";
    echo '          ' . cyan('https://app.poli.page/orgs/{YOUR_ORG}/keys') . "\n";
    echo dim("        (replace {YOUR_ORG} with your org slug — visible in the") . "\n";
    echo dim("         dashboard URL when you're inside your organization)") . "\n";
    echo "     3. Click \"Create key\" and copy\n";
    echo "        the value (starts with " . cyan('pp_test_') . ").\n\n";
    echo "   Paste it below — we'll save it to " . cyan('.env') . " (repo root) so\n";
    echo "   future runs pick it up automatically. (You can also set\n";
    echo '   ' . dim('POLI_PAGE_API_KEY') . " in your shell — that wins over the file.)\n\n";

    echo bold('   Paste your pp_test_* key') . ' (or Ctrl-C to cancel): ';
    $line = fgets(\STDIN);
    if ($line === false) {
        echo "\n  " . red('✗') . " stdin closed before a key was provided. Aborting.\n";
        exit(1);
    }
    $key = trim($line);

    if (!str_starts_with($key, 'pp_test_')) {
        echo "\n  " . red('✗') . " Expected a key starting with `pp_test_`. Aborting.\n";
        exit(1);
    }

    appendToEnvFile(ENV_FILE, 'POLI_PAGE_API_KEY', $key);
    echo "  " . green('✔') . ' saved to ' . cyan(ENV_FILE) . "\n";

    return $key;
}
