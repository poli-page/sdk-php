# Quickstart

Your first render in three lines of PHP. Five minutes start-to-finish, assuming you have a `pp_test_*` API key.

## 1. Install the SDK and an HTTP client

The SDK declares only PSR-18 / PSR-17 / PSR-3 interfaces plus [`php-http/discovery`](https://github.com/php-http/discovery) as hard dependencies. Pick any concrete HTTP client + PSR-7 implementation — discovery auto-wires it.

=== "Guzzle (~80% of PHP apps)"

    ```bash
    composer require poli-page/sdk guzzlehttp/guzzle guzzlehttp/psr7
    ```

=== "Symfony HTTP Client"

    ```bash
    composer require poli-page/sdk symfony/http-client nyholm/psr7
    ```

=== "Lightweight (no curl extension needed)"

    ```bash
    composer require poli-page/sdk php-http/curl-client nyholm/psr7
    ```

See [PSR-18 setup](psr18-setup.md) for the full reasoning behind each combo.

## 2. Instantiate the client

```php
use PoliPage\PoliPage;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
```

The mode is determined by the key prefix — `pp_test_*` for sandbox, `pp_live_*` for production. Both hit the same endpoint; the SDK forwards the key as a Bearer token without inspecting it.

## 3. Render a PDF

```php
use PoliPage\ProjectModeInput;

$pdf = $client->render->pdf(new ProjectModeInput(
    project: 'getting-started',
    template: 'welcome',
    version: '1.0.0',
    data: ['name' => 'World'],
));

file_put_contents('welcome.pdf', $pdf);
```

`$pdf` is a string of raw PDF bytes. The `getting-started/welcome` template ships with every Poli Page org, so this works the moment your key is set.

## Streaming large PDFs

For documents larger than a few MB, stream to disk instead of buffering in memory:

```php
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

use function PoliPage\renderToFile;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

renderToFile(
    $client,
    new ProjectModeInput(
        project: 'getting-started',
        template: 'welcome',
        version: '1.0.0',
        data: ['name' => 'World'],
    ),
    './welcome.pdf',
);
```

`renderToFile` streams the response in 8 KB chunks — bounded memory regardless of document size.

## Error handling

The SDK throws a small hierarchy under `PoliPage\Exception`, all rooted in `PoliPageException`. Catch by subclass for the cases you care about:

```php
use PoliPage\Exception\AuthenticationException;
use PoliPage\Exception\BadRequestException;
use PoliPage\Exception\RateLimitException;

try {
    $pdf = $client->render->pdf(new ProjectModeInput(...));
} catch (AuthenticationException $e) {
    // 401 / 403 — refresh credentials
} catch (RateLimitException $e) {
    // 429 after retries exhausted — back off
} catch (BadRequestException $e) {
    // 400 — inspect $e->errorCode for the validation failure
}
```

Every exception exposes `errorCode`, `status`, and `requestId` for logging and support tickets.

## Next steps

- [**Examples**](examples.md) — walks through every public method against the live API.
- [**PSR-18 setup**](psr18-setup.md) — when the default discovery isn't what you want.
