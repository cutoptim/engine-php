<p align="center">
  <a href="https://cutoptim.com/engine"><img src="https://raw.githubusercontent.com/cutoptim/engine-php/main/.github/logo.png" width="88" height="88" alt="CutOptim"></a>
</p>

<h1 align="center">CutOptim Engine API — PHP SDK</h1>

<p align="center">
  Cutting optimization over HTTPS: <b>2D panels</b>, <b>1D linear</b>, <b>timber</b> and <b>true-shape nesting</b>.<br>
  Deterministic plans, saw-ready cut sequences — PHP 8.1+, cURL, no dependencies.
</p>

<p align="center">
  <a href="https://packagist.org/packages/cutoptim/engine"><img alt="Packagist" src="https://img.shields.io/packagist/v/cutoptim/engine?color=f0c040&labelColor=0d0f14&label=packagist"></a>
  <a href="https://github.com/cutoptim/engine-php/actions/workflows/ci.yml"><img alt="CI" src="https://github.com/cutoptim/engine-php/actions/workflows/ci.yml/badge.svg"></a>
  <img alt="PHP 8.1+" src="https://img.shields.io/badge/php-%E2%89%A58.1-f0c040?labelColor=0d0f14">
  <img alt="Zero dependencies" src="https://img.shields.io/badge/dependencies-0-f0c040?labelColor=0d0f14">
  <a href="https://github.com/cutoptim/engine-php/blob/main/LICENSE"><img alt="MIT" src="https://img.shields.io/badge/license-MIT-f0c040?labelColor=0d0f14"></a>
</p>

<p align="center">
  <a href="https://cutoptim.com/engine/reference/quickstart">Quickstart</a> ·
  <a href="https://cutoptim.com/engine/reference">API reference</a> ·
  <a href="https://cutoptim.com/engine/reference/guides/webhooks">Webhooks</a> ·
  <a href="https://cutoptim.com/engine/reference/changelog">Changelog</a> ·
  <a href="https://cutoptim.com/dashboard#api-keys">Get an API key</a>
</p>

---

## Why this SDK

- **One method per endpoint, from the contract.** The client's operation table is generated from the [OpenAPI 3.1 document](https://cutoptim.com/engine/openapi.json); requests and answers are plain arrays shaped exactly as in the reference.
- **No dependencies.** cURL and JSON — drops into WordPress / WooCommerce, Laravel, Symfony or a plain script.
- **Retries that never cost twice.** Every optimize call carries an `Idempotency-Key`; a retried call returns the stored answer instead of a second charge.
- **Async jobs without the boilerplate.** `waitForJob()` polls the `max` engine for you — or let a webhook tell you.
- **Webhook verification built in.** `Webhook::verify()` checks the signature on the raw body, with the replay window and secret rotation handled.

## Install

```bash
composer require cutoptim/engine
```

A free **test key** (`co_test_…`, never billed) is enough to build the whole integration — create one on your [dashboard](https://cutoptim.com/dashboard#api-keys).

## Quickstart

```php
use CutOptim\Engine\Client;

$client = new Client(getenv('CUTOPTIM_KEY'));

$plan = $client->optimize2d([
    'parts' => [
        ['name' => 'Side', 'w' => 720, 'h' => 560, 'qty' => 2],
        ['name' => 'Shelf', 'w' => 764, 'h' => 540, 'qty' => 3, 'rotatable' => false],
    ],
    'stock' => [['w' => 2800, 'h' => 2070, 'price' => 48]],
    'options' => ['kerf' => 4],
]);

if (!Client::isJobAccepted($plan)) {
    printf("%d sheet(s), %s%% yield\n", $plan['metrics']['sheetCount'], $plan['metrics']['yieldPct']);
    foreach ($plan['sheets'] as $sheet) {
        foreach ($sheet['parts'] as $p) {
            echo $p['name'], ' ', $p['x'], ' ', $p['y'], ' ', $p['w'], ' ', $p['h'], $p['rotated'] ? ' rotated' : '', "\n";
        }
    }
}
```

The same input always returns the same plan — cache it, test against it, quote from it.

## Every mode

```php
// 1D linear — bars, profiles, tubes
$bars = $client->optimize1d(['parts' => [['name' => 'Rail', 'length' => 1200, 'qty' => 6]], 'stock' => [['length' => 6000]]]);

// Timber — lengths cut from beams of a given cross-section (sw × sh)
$timber = $client->optimizeWood([
    'parts' => [['name' => 'Joist', 'sw' => 45, 'sh' => 145, 'length' => 3600, 'qty' => 4]],
    'stock' => [['sw' => 45, 'sh' => 145, 'length' => 4800]],
]);

// True-shape nesting — polygons (or SVG/DXF sources) on sheets
$nest = $client->optimizeNest([
    'parts' => [['name' => 'Bracket', 'polygon' => [[0, 0], [120, 0], [120, 40], [40, 120], [0, 120]], 'qty' => 10]],
    'stock' => [['w' => 1000, 'h' => 500]],
]);
```

Check a request without spending a call — the validate endpoints need no key and give the same verdict:

```php
(new Client())->validate2D($request); // ['valid' => true, 'warnings' => [...]] or a CutOptimException (400)
```

## Async `max` jobs

`'engine' => 'max'` searches harder (seconds to a minute) and answers `202` with a job id instead of a plan:

```php
$accepted = $client->optimize2d($request + ['engine' => 'max']);
if (Client::isJobAccepted($accepted)) {
    $job = $client->waitForJob($accepted['jobId'], 300); // polls GET /v1/jobs/{id}, no quota
    if ($job['status'] === 'succeeded') {
        $result = $job['result'];
    }
}
```

## Webhooks

Instead of polling, register an endpoint on your [dashboard](https://cutoptim.com/dashboard#api-webhooks) and receive a signed `POST` (`job.succeeded` / `job.failed`) when a `max` job finishes. Verify it on the **raw** body:

```php
// Plain PHP
use CutOptim\Engine\Webhook;

$raw = file_get_contents('php://input');
if (!Webhook::verify($raw, $_SERVER['HTTP_CUTOPTIM_SIGNATURE'] ?? null, getenv('CUTOPTIM_WEBHOOK_SECRET'))) {
    http_response_code(400);
    exit;
}
$event = json_decode($raw, true);   // deduplicate on $event['id'] — delivery is at least once
http_response_code(204);            // answer within 10 s, then do the work
```

```php
// Laravel
Route::post('/cutoptim/webhook', function (Illuminate\Http\Request $request) {
    abort_unless(Webhook::verify($request->getContent(), $request->header('CutOptim-Signature'), config('services.cutoptim.webhook_secret')), 400);
    return response()->noContent();
});
```

During a secret rotation both secrets sign for 24 hours — pass both: `Webhook::verify($raw, $header, [$newSecret, $oldSecret])`.

## Retries and idempotency

| Situation | What the client does |
|---|---|
| `429 busy`, `503`, network error | retries (2× by default), waiting `Retry-After` when the API sends it |
| `409 idempotency_in_progress` | retries — the first request is still running; the retry receives its answer |
| any optimize call | sends an `Idempotency-Key` (generated, or yours) — the **same** key on every retry |

Pass your own key as the second argument to make retries safe across requests too:

```php
$client->optimizeNest($request, 'order-' . $orderId);
```

Within 24 hours the same key and request return the stored answer — not charged again, and a `max` job is not queued twice.

## Errors

Any non-2xx answer throws `CutOptimException`:

```php
use CutOptim\Engine\CutOptimException;

try {
    $client->optimize2d($request);
} catch (CutOptimException $e) {
    echo $e->getStatus(), ' ', $e->getErrorCode();   // branch on getErrorCode(), never on the message
    var_dump($e->getDetails(), $e->getRetryAfter());
}
```

| `getErrorCode()` | Status | Meaning |
|---|---|---|
| `invalid_request` | 400 | the request is wrong — `getDetails()['path']` names the field; never charged |
| `unauthorized` | 401 | missing or unknown API key |
| `quota_exceeded` | 402 | the account's monthly quota is used up — `getRetryAfter()` counts to the reset |
| `key_revoked` | 403 | the key is revoked, or the API subscription is not active |
| `idempotency_conflict` | 409 | the key was used for a different request — use a new key |
| `too_large` | 413 | an input cap was exceeded (parts, rows, body size) |
| `busy` | 429 | at capacity right now — retried automatically |
| `service_unavailable` | 503 | an engine is not available on this deployment |
| `solve_timeout` | 504 | the nesting solve ran out of time |
| `network_error` | 0 | the connection failed after the retries |
| `timeout` | 0 | `waitForJob()` gave up waiting (the job keeps running) |

The full list is on the [errors page](https://cutoptim.com/engine/reference/errors).

## Configuration

```php
new Client(
    apiKey: 'co_live_…',                    // not needed for getHealth() and validate*()
    baseUrl: 'https://api.cutoptim.com',    // default
    maxRetries: 2,                          // 429 / 503 / 409 in-progress / network errors
    timeoutS: 30,                           // per request
);
```

## API coverage

| Method | Endpoint | Quota |
|---|---|---|
| `optimize2d($req, $idempotencyKey = null)` | `POST /v1/optimize/2d` | 1 call |
| `optimize1d($req, $idempotencyKey = null)` | `POST /v1/optimize/1d` | 1 call |
| `optimizeWood($req, $idempotencyKey = null)` | `POST /v1/optimize/wood` | 1 call |
| `optimizeNest($req, $idempotencyKey = null)` | `POST /v1/optimize/nest` | 1 call |
| `validate2D` · `validate1D` · `validateWood` · `validateNest` | `POST /v1/validate/*` | free, no key |
| `importNest($req)` | `POST /v1/import/nest` | free (key required) |
| `getJob($id)` · `waitForJob($id)` | `GET /v1/jobs/{id}` | free |
| `getUsage()` | `GET /v1/usage` | free |
| `getHealth()` | `GET /v1/health` | free, no key |

## Versioning

The SDK follows semver. The API is `v1` and only grows within it: a new endpoint becomes a new method with a **minor** SDK release; nothing you use today is removed or narrowed. See [versioning and stability](https://cutoptim.com/engine/reference/versioning) and the [API changelog](https://cutoptim.com/engine/reference/changelog) (also as RSS).

## Support

- Questions and bugs: [GitHub issues](https://github.com/cutoptim/engine-php/issues) or [support@cutoptim.com](mailto:support@cutoptim.com)
- Limits and quotas: [cutoptim.com/engine/reference/limits](https://cutoptim.com/engine/reference/limits)

This repository is published from the CutOptim monorepo; pull requests are welcome and applied upstream.

## License

[MIT](https://github.com/cutoptim/engine-php/blob/main/LICENSE) © CutOptim
