# Poli Page PHP SDK

Render polished PDFs from HTML templates via the [Poli Page](https://poli.page) API — straight from PHP.

## Install

```bash
composer require poli-page/sdk
```

Then pick any PSR-18 HTTP client + PSR-7 implementation you like (Guzzle, Symfony HttpClient, php-http/curl-client). The SDK auto-discovers whichever you have installed — see [PSR-18 setup](psr18-setup.md) for the exact combos and the reasoning.

Requires **PHP 8.3 or later**.

## First render

```php
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);

$pdf = $client->render->pdf(new ProjectModeInput(
    project: 'getting-started',
    template: 'welcome',
    version: '1.0.0',
    data: ['name' => 'World'],
));
// $pdf is a string of raw PDF bytes
```

Every Poli Page org ships a pre-provisioned `getting-started/welcome` template, so this snippet runs the moment you have an API key — no project setup needed.

## Where to go next

- [**Quickstart**](quickstart.md) — install, instantiate, render, save to disk.
- [**PSR-18 setup**](psr18-setup.md) — pick the HTTP client that fits your stack.
- [**Examples**](examples.md) — every public method walked through, with code from the runnable demo.
- [**Migration guide**](migration.md) — moving from earlier versions.
- [**Changelog**](changelog.md) — what shipped, when.

## Looking for the auto-generated API reference?

This site hosts the **narrative** docs — guides written for humans. The full method-by-method reference, generated from source, lives at:

→ [`docs.poli.page/reference/sdk/php/`](https://docs.poli.page/reference/sdk/php/)
