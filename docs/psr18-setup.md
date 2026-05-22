# PSR-18 setup

The Poli Page SDK depends on **interfaces only** — PSR-18 (HTTP client),
PSR-17 (request / stream factories), and PSR-3 (logger). At runtime
you provide a concrete implementation of each. This file shows the three
common setup patterns; pick whichever fits your stack.

## TL;DR

Install one HTTP client + one PSR-7 implementation alongside the SDK:

```bash
# Most common (~80% of PHP apps): Guzzle + its PSR-7
composer require poli-page/sdk guzzlehttp/guzzle guzzlehttp/psr7

# Symfony-native alternative
composer require poli-page/sdk symfony/http-client nyholm/psr7

# Lightweight, no curl extension needed
composer require poli-page/sdk php-http/curl-client nyholm/psr7
```

Construct the client with the one-liner; the SDK auto-discovers the
installed client + factories via `php-http/discovery`:

```php
use PoliPage\PoliPage;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
```

## Option 1 — Guzzle (recommended)

Guzzle is the de-facto PHP HTTP client. Its native `send()` method
honours per-request options (timeouts, retries, proxies), which means
**the SDK's `timeout:` constructor argument is fully enforced** when
the underlying client is Guzzle.

```bash
composer require poli-page/sdk guzzlehttp/guzzle guzzlehttp/psr7
```

```php
use PoliPage\PoliPage;
use PoliPage\ProjectModeInput;

$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
// or with overrides:
$client = new PoliPage(
    apiKey: $_ENV['POLI_PAGE_API_KEY'],
    timeout: 10.0,        // Guzzle honours this per-request
    maxRetries: 3,
);

$pdf = $client->render->pdf(new ProjectModeInput(
    project: 'billing', template: 'invoice', version: '1.0.0',
    data: ['invoiceNumber' => 'INV-001'],
));
```

## Option 2 — Symfony HTTP Client

Native fit for Symfony codebases. The Symfony HTTP Client implements
PSR-18 via its `Psr18Client` adapter; discovery picks it up automatically.

```bash
composer require poli-page/sdk symfony/http-client nyholm/psr7
```

```php
$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
```

**Per-request timeout note.** PSR-18 itself doesn't expose per-request
options, and Symfony's `Psr18Client` adapter doesn't either — the
SDK's `timeout:` argument is best-effort and falls back to whatever
timeout you've set on your underlying `HttpClient` instance. To get
guaranteed per-request timeouts on Symfony, build the client with the
timeout pre-configured:

```php
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Psr18Client;
use Nyholm\Psr7\Factory\Psr17Factory;

$psr17 = new Psr17Factory();
$psr18 = new Psr18Client(HttpClient::create(['timeout' => 10.0]));

$client = new PoliPage(
    apiKey: $_ENV['POLI_PAGE_API_KEY'],
    httpClient: $psr18,
    requestFactory: $psr17,
    streamFactory: $psr17,
);
```

## Option 3 — Lightweight (`php-http/curl-client`)

Smallest dependency footprint. Pure-PHP wrapper around the curl
extension. Like Symfony, no per-request timeout support — set it on the
client at construction.

```bash
composer require poli-page/sdk php-http/curl-client nyholm/psr7
```

```php
$client = PoliPage::client($_ENV['POLI_PAGE_API_KEY']);
```

## Logging (PSR-3)

The SDK logs one DEBUG line per HTTP attempt, INFO on success, WARN on
retry, ERROR on terminal failure. The `Authorization` header is never
logged. Pass any PSR-3 implementation — Monolog, Symfony Logger, Laravel
`Log` facade, or your own:

```php
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logger = new Logger('polipage');
$logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));

$client = new PoliPage(
    apiKey: $_ENV['POLI_PAGE_API_KEY'],
    logger: $logger,
);
```

## Explicit injection (advanced)

Skip discovery entirely when you need pinned implementations or want to
share a configured client across multiple SDK instances:

```php
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use PoliPage\PoliPage;

$factory = new HttpFactory();
$http = new GuzzleClient([
    'timeout' => 10.0,
    'connect_timeout' => 5.0,
    // ... handler stack, middleware, proxies, mTLS, etc.
]);

$client = new PoliPage(
    apiKey: $_ENV['POLI_PAGE_API_KEY'],
    httpClient: $http,
    requestFactory: $factory,
    streamFactory: $factory,
);
```

## Troubleshooting

**`PoliPageException` with code `invalid_options` at construction.**
Discovery couldn't find a PSR-18 client (or PSR-17 factory) in your
`vendor/`. Install one of the combinations above, or pass explicit
implementations via the constructor.

**Timeouts don't fire.** Confirm your underlying client supports
per-request timeouts (Guzzle does; Symfony's `Psr18Client` doesn't).
Otherwise configure the timeout on the client at construction time.

**Retries seem off.** The SDK retries on 5xx, 429, network errors, and
timeouts. If your client transforms timeouts into something the SDK
doesn't recognise as a timeout (some adapters re-throw without
preserving the cURL `errno`), the retry will still happen — but the
final exception will be `ConnectionException` rather than
`TimeoutException`. Set a PSR-3 logger to see the wire trail.
