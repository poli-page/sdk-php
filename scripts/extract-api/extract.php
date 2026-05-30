<?php

declare(strict_types=1);

/**
 * scripts/extract-api/extract.php
 *
 * Walks the SDK's public surface via PHP reflection and emits MDX into
 * docs/src/content/docs/reference/, matching the shape defined in the
 * shared SDK docs convention (spec §4b).
 *
 * Outputs (overwriting any previous run):
 *   reference/client.mdx
 *   reference/methods/<slug>.mdx     (one per public method)
 *   reference/types.mdx
 *   reference/errors.mdx
 *   reference/runtime-support.mdx
 *   reference/_meta.json
 *
 * The list of methods and their example files is curated below — the spec
 * gives canonical slugs and the SDK ships matching examples/<slug>.php.
 *
 * No third-party dependencies. Uses the SDK's own Composer autoloader to
 * load classes for reflection.
 */

require __DIR__ . '/../../vendor/autoload.php';

$repoRoot = realpath(__DIR__ . '/../..');
if ($repoRoot === false) {
    fwrite(STDERR, "extractor: repo root not found\n");
    exit(1);
}

$referenceOut = $repoRoot . '/docs/src/content/docs/reference';
$examplesDir = $repoRoot . '/examples';

$composer = json_decode(
    (string) file_get_contents($repoRoot . '/composer.json'),
    associative: true,
    flags: JSON_THROW_ON_ERROR,
);
if (!is_array($composer) || !isset($composer['name'])) {
    fwrite(STDERR, "extractor: composer.json missing name\n");
    exit(1);
}
$packageName = (string) $composer['name'];
$packageVersion = packageVersionFromConst();

// 1. Reset the reference output tree.
rrmdir($referenceOut);
mkdir($referenceOut . '/methods', 0o755, recursive: true);

// 2. Emit pages.
file_put_contents($referenceOut . '/client.mdx', buildClientPage());
foreach (methodTargets() as $target) {
    $mdx = buildMethodPage($target, $examplesDir);
    file_put_contents($referenceOut . '/methods/' . $target['slug'] . '.mdx', $mdx);
}
file_put_contents($referenceOut . '/types.mdx', buildTypesPage());
file_put_contents($referenceOut . '/errors.mdx', buildErrorsPage());
file_put_contents($referenceOut . '/runtime-support.mdx', buildRuntimeSupportPage($packageVersion));
file_put_contents(
    $referenceOut . '/_meta.json',
    json_encode(buildMetaSidecar($packageName, $packageVersion), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
);

fwrite(STDOUT, "extractor: wrote {$referenceOut}\n");

// ---------------------------------------------------------------------------
// Method targets — canonical slugs from the shared spec, plus the matching
// example file in the repo's examples/ directory.
// ---------------------------------------------------------------------------

/**
 * @return list<array{
 *     slug: string,
 *     displayName: string,
 *     class: class-string,
 *     method: string|null,
 *     function?: string,
 *     example: string,
 *     errorCodes: list<string>,
 * }>
 */
function methodTargets(): array
{
    return [
        [
            'slug' => 'render-pdf',
            'displayName' => 'render->pdf',
            'class' => \PoliPage\Render::class,
            'method' => 'pdf',
            'example' => 'render-pdf.php',
            'errorCodes' => ['VALIDATION_ERROR', 'NOT_FOUND', 'QUOTA_EXCEEDED', 'timeout', 'network_error', 'INTERNAL_ERROR'],
        ],
        [
            'slug' => 'render-pdf-stream',
            'displayName' => 'render->pdfStream',
            'class' => \PoliPage\Render::class,
            'method' => 'pdfStream',
            'example' => 'render-pdf-stream.php',
            'errorCodes' => ['VALIDATION_ERROR', 'NOT_FOUND', 'QUOTA_EXCEEDED', 'timeout', 'network_error', 'INTERNAL_ERROR'],
        ],
        [
            'slug' => 'render-document',
            'displayName' => 'render->document',
            'class' => \PoliPage\Render::class,
            'method' => 'document',
            'example' => 'render-document.php',
            'errorCodes' => ['VALIDATION_ERROR', 'NOT_FOUND', 'QUOTA_EXCEEDED', 'INTERNAL_ERROR'],
        ],
        [
            'slug' => 'render-preview',
            'displayName' => 'render->preview',
            'class' => \PoliPage\Render::class,
            'method' => 'preview',
            'example' => 'render-preview.php',
            'errorCodes' => ['VALIDATION_ERROR', 'NOT_FOUND', 'QUOTA_EXCEEDED', 'INTERNAL_ERROR'],
        ],
        [
            'slug' => 'documents-get',
            'displayName' => 'documents->get',
            'class' => \PoliPage\Documents::class,
            'method' => 'get',
            'example' => 'documents-get.php',
            'errorCodes' => ['DOCUMENT_NOT_FOUND', 'INVALID_API_KEY', 'GONE', 'INTERNAL_ERROR'],
        ],
        [
            'slug' => 'documents-preview',
            'displayName' => 'documents->preview',
            'class' => \PoliPage\Documents::class,
            'method' => 'preview',
            'example' => 'documents-preview.php',
            'errorCodes' => ['DOCUMENT_NOT_FOUND', 'INVALID_API_KEY', 'INTERNAL_ERROR'],
        ],
        [
            'slug' => 'documents-thumbnails',
            'displayName' => 'documents->thumbnails',
            'class' => \PoliPage\Documents::class,
            'method' => 'thumbnails',
            'example' => 'documents-thumbnails.php',
            'errorCodes' => ['DOCUMENT_NOT_FOUND', 'VALIDATION_ERROR', 'INVALID_API_KEY', 'INTERNAL_ERROR'],
        ],
        [
            'slug' => 'documents-delete',
            'displayName' => 'documents->delete',
            'class' => \PoliPage\Documents::class,
            'method' => 'delete',
            'example' => 'documents-delete.php',
            'errorCodes' => ['DOCUMENT_NOT_FOUND', 'INVALID_API_KEY', 'INTERNAL_ERROR'],
        ],
        [
            'slug' => 'render-to-file',
            'displayName' => 'PoliPage\\renderToFile',
            'class' => \PoliPage\PoliPage::class, // unused for function targets
            'method' => null,
            'function' => 'PoliPage\\renderToFile',
            'example' => 'render-to-file.php',
            'errorCodes' => ['VALIDATION_ERROR', 'NOT_FOUND', 'QUOTA_EXCEEDED', 'timeout', 'network_error', 'invalid_options'],
        ],
    ];
}

// ---------------------------------------------------------------------------
// Page builders
// ---------------------------------------------------------------------------

function buildClientPage(): string
{
    $refl = new ReflectionClass(\PoliPage\PoliPage::class);
    $lede = firstParagraph(extractSummary($refl->getDocComment() ?: ''))
        ?: 'The Poli Page client — the single entry point to the PHP SDK.';

    return <<<MDX
---
title: Client
description: The PoliPage class — the entry point to the PHP SDK.
---

import MethodSignature from '@preset/components/MethodSignature.astro';

<MethodSignature lang="php" code={`new PoliPage\\PoliPage(apiKey: string, ...)`} />

{$lede}

## Constructor

Use the static factory for the default configuration:

```php
\$client = PoliPage\\PoliPage::client(\$_ENV['POLI_PAGE_API_KEY']);
```

Or call the full constructor to override `baseUrl`, `timeout`, `maxRetries`, inject a PSR-18 client, etc. See [`PoliPage`](../types/) for every parameter.

## Namespaces

The client exposes two namespace properties:

- [`render`](./methods/render-pdf/) — render PDFs (in memory, streaming, or as a stored document).
- [`documents`](./methods/documents-get/) — fetch, preview, thumbnail, or delete stored documents.

The standalone helper [`renderToFile`](./methods/render-to-file/) is a free function in the `PoliPage\\` namespace, autoloaded via Composer.

## See also
- [Types](../types/)
- [Errors](../errors/)
- [Runtime support](../runtime-support/)

MDX;
}

/**
 * @param array{
 *     slug: string,
 *     displayName: string,
 *     class: class-string,
 *     method: string|null,
 *     function?: string,
 *     example: string,
 *     errorCodes: list<string>,
 * } $target
 */
function buildMethodPage(array $target, string $examplesDir): string
{
    if (isset($target['function'])) {
        $refl = new ReflectionFunction($target['function']);
    } else {
        $cls = new ReflectionClass($target['class']);
        $refl = $cls->getMethod($target['method']);
    }

    $docComment = $refl->getDocComment() ?: '';
    $summary = extractSummary($docComment);
    $lede = firstParagraph($summary) ?: ($target['displayName'] . ' method.');
    $description = firstSentence($summary) ?: ($target['displayName'] . ' method.');

    $signature = renderSignature($target['displayName'], $refl);
    $params = collectParams($refl, $docComment);

    $parametersBlock = $params === []
        ? ''
        : "\n## Parameters\n\n<ParamsTable params={" . json_encode($params, JSON_UNESCAPED_SLASHES) . "} />\n";

    $returnType = renderReturnType($refl);
    $returnsBlock = $returnType === ''
        ? ''
        : "\n## Returns\n\n`{$returnType}`\n";

    $errorsBlock = '';
    if ($target['errorCodes'] !== []) {
        $rows = array_map(
            static fn (string $code): array => [
                'code' => $code,
                'when' => 'See [errors](../../../production/errors/) for the full description.',
            ],
            $target['errorCodes'],
        );
        $errorsBlock = "\n## Errors\n\n<ErrorTable errors={" . json_encode($rows, JSON_UNESCAPED_SLASHES) . "} />\n";
    }

    $examplePath = $examplesDir . '/' . $target['example'];
    if (!is_file($examplePath)) {
        fwrite(STDERR, "extractor: missing example {$examplePath}\n");
        exit(1);
    }
    $example = rtrim((string) file_get_contents($examplePath));

    $descLine = escapeFrontmatter($description);

    return <<<MDX
---
title: {$target['displayName']}
description: {$descLine}
sidebar:
  label: {$target['displayName']}
---

import MethodSignature from '@preset/components/MethodSignature.astro';
import ParamsTable from '@preset/components/ParamsTable.astro';
import ErrorTable from '@preset/components/ErrorTable.astro';

<MethodSignature lang="php" code={`{$signature}`} />

{$lede}
{$parametersBlock}{$returnsBlock}{$errorsBlock}
## Example

```php
{$example}
```

## See also
- [Errors](../../../production/errors/)
- [Configuration](../../../concepts/configuration/)

MDX;
}

function buildTypesPage(): string
{
    $publicTypes = [
        \PoliPage\PoliPage::class,
        \PoliPage\Render::class,
        \PoliPage\Documents::class,
        \PoliPage\RenderInput::class,
        \PoliPage\ProjectModeInput::class,
        \PoliPage\InlineModeInput::class,
        \PoliPage\PreviewResult::class,
        \PoliPage\DocumentDescriptor::class,
        \PoliPage\DocumentPreviewResult::class,
        \PoliPage\Thumbnail::class,
        \PoliPage\ThumbnailOptions::class,
        \PoliPage\RenderMetadata::class,
        \PoliPage\Events\RetryEvent::class,
    ];

    $blocks = [];
    foreach ($publicTypes as $fqcn) {
        $refl = new ReflectionClass($fqcn);
        $short = $refl->getShortName();
        $lede = firstParagraph(extractSummary($refl->getDocComment() ?: ''))
            ?: '_(See the source for the full definition.)_';
        $blocks[] = "### `{$short}`\n\n{$lede}\n";
    }

    $body = implode("\n", $blocks);

    return <<<MDX
---
title: Types
description: Public classes and interfaces exported from poli-page/sdk.
---

The PHP SDK exposes the types below. Import any of them with `use`:

```php
use PoliPage\\PoliPage;
use PoliPage\\ProjectModeInput;
use PoliPage\\DocumentDescriptor;
```

{$body}

For the full set of fields on each class and the `PoliPage\\Exception\\*` hierarchy, see [the source on GitHub](https://github.com/poli-page/sdk-php/tree/main/src).

MDX;
}

function buildErrorsPage(): string
{
    $sdkInternal = [
        ['code' => 'invalid_options', 'when' => 'Constructor arguments are missing or malformed.'],
        ['code' => 'network_error',   'when' => 'TCP/TLS-level failure reaching the API. Retryable.'],
        ['code' => 'timeout',         'when' => 'The request did not complete within `timeout`. Retryable.'],
        ['code' => 'aborted',         'when' => 'Caller-driven cancellation. Not retryable.'],
        ['code' => 'unknown_error',   'when' => 'A catch-all for unexpected failures.'],
        ['code' => 'DOWNLOAD_FAILED', 'when' => 'The presigned PDF URL returned a non-2xx response.'],
        ['code' => 'INTERNAL_ERROR',  'when' => 'The API or SDK reached an unexpected state.'],
    ];

    $apiAuth = [
        ['code' => 'MISSING_API_KEY', 'when' => 'No API key in the request.'],
        ['code' => 'INVALID_API_KEY', 'when' => 'The API key is malformed or revoked.'],
    ];

    $apiBilling = [
        ['code' => 'PAYMENT_REQUIRED',       'when' => 'Organization billing is past due.'],
        ['code' => 'FORBIDDEN',              'when' => 'The key does not have access to the requested resource.'],
        ['code' => 'ORGANIZATION_CANCELLED', 'when' => 'The organization has been cancelled.'],
        ['code' => 'ORGANIZATION_PURGED',    'when' => 'The organization has been purged.'],
    ];

    $apiNotFound = [
        ['code' => 'NOT_FOUND',          'when' => 'The project/template slug does not exist or is not published.'],
        ['code' => 'VERSION_NOT_FOUND',  'when' => 'The pinned version does not exist for this template.'],
        ['code' => 'DOCUMENT_NOT_FOUND', 'when' => 'No stored document matches the supplied id.'],
        ['code' => 'GONE',               'when' => 'The resource existed but has been deleted.'],
    ];

    $apiValidation = [
        ['code' => 'VALIDATION_ERROR',            'when' => '`data` does not satisfy the template schema.'],
        ['code' => 'MISSING_DATA',                'when' => 'Request body lacks the required `data` field.'],
        ['code' => 'MISSING_PROJECT_OR_TEMPLATE', 'when' => 'Project mode call without both `project` and `template`.'],
        ['code' => 'MISSING_TEMPLATE_SLUG',       'when' => 'Template slug is missing.'],
        ['code' => 'PROJECT_REQUIRED_FOR_DOCUMENT', 'when' => 'Document-producing call missing `project`.'],
        ['code' => 'INVALID_VERSION_FORMAT',      'when' => 'The `version` string is not a valid semver.'],
        ['code' => 'VERSION_REQUIRED',            'when' => 'Live keys require a pinned `version`.'],
        ['code' => 'INVALID_VERSION_FOR_KEY_ENV', 'when' => 'Sandbox key targeting a live-only version, or vice versa.'],
    ];

    $apiRate = [
        ['code' => 'QUOTA_EXCEEDED',      'when' => 'Per-key rate limit or monthly quota reached. Retryable.'],
        ['code' => 'OVERAGE_CAP_EXCEEDED', 'when' => 'Hard overage cap reached. Not retryable.'],
    ];

    $exceptionMap = [
        ['class' => 'PoliPage\\PoliPageException',                 'extends' => 'RuntimeException',     'when' => 'Base type for every SDK failure.'],
        ['class' => 'PoliPage\\Exception\\ApiStatusException',     'extends' => 'PoliPageException',    'when' => 'Any non-2xx HTTP response. `$status` is non-null.'],
        ['class' => 'PoliPage\\Exception\\BadRequestException',    'extends' => 'ApiStatusException',   'when' => 'HTTP 400.'],
        ['class' => 'PoliPage\\Exception\\AuthenticationException','extends' => 'ApiStatusException',   'when' => 'HTTP 401.'],
        ['class' => 'PoliPage\\Exception\\PermissionDeniedException', 'extends' => 'ApiStatusException', 'when' => 'HTTP 403.'],
        ['class' => 'PoliPage\\Exception\\NotFoundException',      'extends' => 'ApiStatusException',   'when' => 'HTTP 404.'],
        ['class' => 'PoliPage\\Exception\\GoneException',          'extends' => 'ApiStatusException',   'when' => 'HTTP 410.'],
        ['class' => 'PoliPage\\Exception\\RateLimitException',     'extends' => 'ApiStatusException',   'when' => 'HTTP 429.'],
        ['class' => 'PoliPage\\Exception\\InternalServerException','extends' => 'ApiStatusException',   'when' => 'HTTP 5xx.'],
        ['class' => 'PoliPage\\Exception\\ConnectionException',    'extends' => 'PoliPageException',    'when' => 'Transport-layer failure (no HTTP response).'],
        ['class' => 'PoliPage\\Exception\\TimeoutException',       'extends' => 'ConnectionException',  'when' => 'Per-request deadline exceeded.'],
    ];

    $j = static fn (array $rows): string => json_encode($rows, JSON_UNESCAPED_SLASHES);

    return <<<MDX
---
title: Errors
description: All error codes raised by PoliPageException, plus the exception hierarchy under PoliPage\\Exception.
---

import ErrorTable from '@preset/components/ErrorTable.astro';

Every failure thrown by the SDK is an instance of `PoliPage\\PoliPageException` with an `errorCode`. SDK-internal codes are lowercase; codes from the API are uppercase. Specialized subclasses under `PoliPage\\Exception\\*` narrow by HTTP status.

## SDK-internal

<ErrorTable errors={{$j($sdkInternal)}} />

## Authentication

<ErrorTable errors={{$j($apiAuth)}} />

## Billing and lifecycle

<ErrorTable errors={{$j($apiBilling)}} />

## Not found

<ErrorTable errors={{$j($apiNotFound)}} />

## Validation

<ErrorTable errors={{$j($apiValidation)}} />

## Rate and quota

<ErrorTable errors={{$j($apiRate)}} />

## Exception classes

| Class | Extends | When |
|---|---|---|
MDX
. "\n" . implode("\n", array_map(
    static fn (array $row): string => "| `{$row['class']}` | `{$row['extends']}` | {$row['when']} |",
    $exceptionMap,
)) . "\n";
}

function buildRuntimeSupportPage(string $packageVersion): string
{
    return <<<MDX
---
title: Runtime support
description: Supported PHP versions and operating systems for poli-page/sdk v{$packageVersion}.
---

import RuntimeMatrix from '@preset/components/RuntimeMatrix.astro';

The PHP SDK is built and tested against the matrix below.

<RuntimeMatrix matrix={{
  runtimes: ['8.3', '8.4'],
  os: ['linux', 'macos', 'windows'],
  cells: {
    '8.3': { linux: 'tested', macos: 'tested', windows: 'supported' },
    '8.4': { linux: 'tested', macos: 'tested', windows: 'supported' },
  },
}} />

The minimum supported PHP version is **8.3.0**. Earlier versions lack the readonly classes, asymmetric visibility, and typed-property defaults the SDK relies on.

## PSR contracts

- **PSR-18** (`psr/http-client`) for the HTTP client. Bring your own (Guzzle, Symfony HTTP Client, or a mock).
- **PSR-17** (`psr/http-factory`) for request/stream factories.
- **PSR-7** (`psr/http-message`) for requests, responses, and streams.
- **PSR-3** (`psr/log`) for the optional logger.

See [PSR-18 setup](../../concepts/psr-18/) for how the SDK auto-discovers these.

MDX;
}

/**
 * @return array{
 *     language: string,
 *     package: array{kind: string, name: string, version: string},
 *     extractedAt: string,
 *     extractorVersion: string,
 *     client: array{name: string, kind: string},
 *     methods: list<array{slug: string, name: string}>,
 *     errors: list<array{code: string}>,
 * }
 */
function buildMetaSidecar(string $packageName, string $packageVersion): array
{
    $methods = [];
    foreach (methodTargets() as $t) {
        $methods[] = ['slug' => $t['slug'], 'name' => $t['displayName']];
    }

    $errorCodes = [
        'invalid_options', 'network_error', 'timeout', 'aborted', 'unknown_error',
        'DOWNLOAD_FAILED', 'INTERNAL_ERROR',
        'MISSING_API_KEY', 'INVALID_API_KEY',
        'PAYMENT_REQUIRED', 'FORBIDDEN', 'ORGANIZATION_CANCELLED', 'ORGANIZATION_PURGED',
        'NOT_FOUND', 'VERSION_NOT_FOUND', 'DOCUMENT_NOT_FOUND', 'GONE',
        'VALIDATION_ERROR', 'MISSING_DATA', 'MISSING_PROJECT_OR_TEMPLATE',
        'MISSING_TEMPLATE_SLUG', 'PROJECT_REQUIRED_FOR_DOCUMENT',
        'INVALID_VERSION_FORMAT', 'VERSION_REQUIRED', 'INVALID_VERSION_FOR_KEY_ENV',
        'QUOTA_EXCEEDED', 'OVERAGE_CAP_EXCEEDED',
    ];

    return [
        'language' => 'php',
        'package' => ['kind' => 'composer', 'name' => $packageName, 'version' => $packageVersion],
        'extractedAt' => gmdate('Y-m-d\\TH:i:s\\Z'),
        'extractorVersion' => '0.1.0',
        'client' => ['name' => 'PoliPage', 'kind' => 'class'],
        'methods' => $methods,
        'errors' => array_map(
            static fn (string $code): array => ['code' => $code],
            $errorCodes,
        ),
    ];
}

// ---------------------------------------------------------------------------
// Reflection helpers
// ---------------------------------------------------------------------------

/**
 * @return list<array{name: string, type: string, required: bool, description: string}>
 */
function collectParams(ReflectionFunctionAbstract $refl, string $docComment): array
{
    $paramDocs = parseParamDocs($docComment);
    $out = [];
    foreach ($refl->getParameters() as $param) {
        $out[] = [
            'name' => '$' . $param->getName(),
            'type' => renderType($param->getType()),
            'required' => !$param->isOptional() && !$param->allowsNull(),
            'description' => $paramDocs[$param->getName()] ?? '(no description)',
        ];
    }

    return $out;
}

/**
 * @return array<string, string>
 */
function parseParamDocs(string $docComment): array
{
    $out = [];
    if ($docComment === '') {
        return $out;
    }
    foreach (preg_split('/\R/', $docComment) ?: [] as $line) {
        if (preg_match('/^\s*\*\s*@param\s+(?:\S+\s+)?\$([A-Za-z_][A-Za-z0-9_]*)\s*(.*)$/', $line, $m) === 1) {
            $out[$m[1]] = trim($m[2]);
        }
    }

    return $out;
}

function renderSignature(string $displayName, ReflectionFunctionAbstract $refl): string
{
    $params = [];
    foreach ($refl->getParameters() as $param) {
        $type = renderType($param->getType());
        $default = '';
        if ($param->isDefaultValueAvailable()) {
            try {
                $defaultValue = $param->getDefaultValue();
                $default = ' = ' . exportDefault($defaultValue);
            } catch (ReflectionException) {
                $default = '';
            }
        }
        $params[] = $type . ' $' . $param->getName() . $default;
    }
    $returnType = renderReturnType($refl);
    $returnSegment = $returnType !== '' ? ': ' . $returnType : '';

    return $displayName . '(' . implode(', ', $params) . ')' . $returnSegment;
}

function renderReturnType(ReflectionFunctionAbstract $refl): string
{
    $type = $refl->getReturnType();
    if ($type === null) {
        return '';
    }

    return renderType($type);
}

function renderType(?ReflectionType $type): string
{
    if ($type === null) {
        return 'mixed';
    }
    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(renderType(...), $type->getTypes()));
    }
    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(renderType(...), $type->getTypes()));
    }
    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();
        $rendered = match ($name) {
            'self', 'static' => $name,
            default => str_starts_with($name, '\\') ? $name : '\\' . $name,
        };
        // Simplify: drop the leading backslash on built-in scalar types for
        // readability — keeps the rendered signature close to source style.
        $rendered = preg_replace('/^\\\\(int|string|float|bool|array|void|mixed|null|object|callable|iterable|never|self|static)$/', '$1', $rendered) ?? $rendered;
        if ($type->allowsNull() && $rendered !== 'mixed' && $rendered !== 'null') {
            $rendered = '?' . $rendered;
        }

        return $rendered;
    }

    return 'mixed';
}

function exportDefault(mixed $value): string
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if (is_string($value)) {
        return "'" . addslashes($value) . "'";
    }
    if (is_array($value) && $value === []) {
        return '[]';
    }

    return var_export($value, return: true);
}

function extractSummary(string $docComment): string
{
    if ($docComment === '') {
        return '';
    }
    $lines = preg_split('/\R/', $docComment) ?: [];
    $out = [];
    foreach ($lines as $line) {
        $stripped = trim($line);
        if (in_array($stripped, ['/**', '*/'], true)) {
            continue;
        }
        $stripped = preg_replace('/^\*\s?/', '', $stripped) ?? $stripped;
        if (str_starts_with($stripped, '@')) {
            break;
        }
        $out[] = $stripped;
    }

    $text = trim(implode("\n", $out));

    // Strip inline phpDoc tags ({@see Foo}, {@inheritDoc}, etc.) — MDX
    // would otherwise parse `{` as the start of a JSX expression.
    $text = preg_replace('/\{@\w+[^}]*\}/', '', $text) ?? $text;

    // Escape any remaining stray `{`/`}` so MDX doesn't try to parse them
    // as JSX expressions inside the lede text.
    return str_replace(['{', '}'], ['&#123;', '&#125;'], $text);
}

function firstParagraph(string $summary): string
{
    if ($summary === '') {
        return '';
    }
    $parts = preg_split('/\n\s*\n/', $summary, 2) ?: [];

    return trim(str_replace("\n", ' ', $parts[0] ?? ''));
}

function firstSentence(string $summary): string
{
    $para = firstParagraph($summary);
    if ($para === '') {
        return '';
    }
    $pos = strpos($para, '. ');
    if ($pos === false) {
        return $para;
    }

    return substr($para, 0, $pos + 1);
}

function escapeFrontmatter(string $s): string
{
    $s = str_replace(["\n", '"'], [' ', '\\"'], $s);

    return substr($s, 0, 150);
}

function packageVersionFromConst(): string
{
    if (defined('PoliPage\\Internal\\Version::VERSION')) {
        /** @phpstan-ignore-next-line  runtime constant access */
        return (string) \PoliPage\Internal\Version::VERSION;
    }

    return '0.0.0';
}

function rrmdir(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    /** @var array<int, string> $entries */
    $entries = scandir($path) ?: [];
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = $path . '/' . $entry;
        if (is_dir($full)) {
            rrmdir($full);
        } else {
            unlink($full);
        }
    }
    rmdir($path);
}
