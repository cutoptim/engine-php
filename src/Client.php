<?php

declare(strict_types=1);

namespace CutOptim\Engine;

/**
 * The CutOptim Engine API client (cURL, no dependencies). Requests and answers are plain arrays — the shapes are in the
 * OpenAPI document (https://cutoptim.com/engine/openapi.json) and the reference.
 *
 * - Every optimize call carries an Idempotency-Key (yours, or a generated one), so retries are never charged twice and
 *   never queue a second max job.
 * - 429 / 503 / 409 idempotency_in_progress / network errors are retried (default 2 times), honouring Retry-After.
 * - A non-2xx answer throws CutOptimException with the API's stable error code.
 * - engine "max" answers 202 with a jobId: $client->waitForJob($jobId) — or register a webhook.
 *
 * @method array optimize2d(array $request, ?string $idempotencyKey = null)
 * @method array optimize1d(array $request, ?string $idempotencyKey = null)
 * @method array optimizeWood(array $request, ?string $idempotencyKey = null)
 * @method array optimizeNest(array $request, ?string $idempotencyKey = null)
 * @method array validate2D(array $request)
 * @method array validate1D(array $request)
 * @method array validateWood(array $request)
 * @method array validateNest(array $request)
 * @method array importNest(array $request)
 * @method array getUsage()
 * @method array getHealth()
 */
final class Client
{
    private string $baseUrl;

    public function __construct(
        private readonly ?string $apiKey = null,
        string $baseUrl = 'https://api.cutoptim.com',
        private readonly int $maxRetries = 2,
        private readonly int $timeoutS = 30,
    ) {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /** Every operation in the generated table (Operations::ALL) is a method: optimize2d(), validate2D(), getUsage(), … */
    public function __call(string $name, array $args): array
    {
        $op = Operations::ALL[$name] ?? null;
        if ($op === null || str_contains($op['path'], '{')) {
            throw new \BadMethodCallException("CutOptim\\Engine\\Client has no operation {$name}()");
        }
        $body = $op['method'] === 'GET' ? null : ($args[0] ?? []);
        return $this->call($op['method'], $op['path'], $body, $op['idempotent'] ? ($args[1] ?? null) : null, $op['idempotent']);
    }

    public function getJob(string $jobId): array
    {
        return $this->call('GET', '/v1/jobs/' . rawurlencode($jobId));
    }

    /** True when an optimize answer is the async 202 of engine "max". */
    public static function isJobAccepted(array $result): bool
    {
        return isset($result['jobId'], $result['status']) && is_string($result['jobId']) && $result['status'] === 'queued';
    }

    /** Poll GET /v1/jobs/{id} (no quota) until the job succeeded or failed. */
    public function waitForJob(string $jobId, int $timeoutS = 300, float $intervalS = 2.0): array
    {
        $deadline = microtime(true) + $timeoutS;
        $wait = $intervalS;
        while (true) {
            $job = $this->getJob($jobId);
            if (in_array($job['status'] ?? null, ['succeeded', 'failed'], true)) {
                return $job;
            }
            if (microtime(true) + $wait > $deadline) {
                throw new CutOptimException(0, 'timeout', "job {$jobId} is still " . ($job['status'] ?? '?') . ' after the wait timeout');
            }
            usleep((int) ($wait * 1_000_000));
            $wait = min($wait * 1.5, 10.0);
        }
    }

    private function call(string $method, string $path, ?array $body = null, ?string $idempotencyKey = null, bool $idempotent = false): array
    {
        $headers = ['Accept: application/json', 'User-Agent: cutoptim-engine-php/' . Operations::VERSION];
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }
        $payload = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($body === [] ? new \stdClass() : $body, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        if ($idempotent) {
            // One key for the whole call, retries included.
            $headers[] = 'Idempotency-Key: ' . ($idempotencyKey ?? bin2hex(random_bytes(16)));
        }
        for ($attempt = 0; ; $attempt++) {
            $responseHeaders = [];
            $ch = curl_init($this->baseUrl . $path);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutS,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $p = strpos($line, ':');
                    if ($p !== false) {
                        $responseHeaders[strtolower(trim(substr($line, 0, $p)))] = trim(substr($line, $p + 1));
                    }
                    return strlen($line);
                },
            ]);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            $text = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $netError = $text === false ? curl_error($ch) : null;
            curl_close($ch);
            if ($netError !== null) {
                if ($attempt >= $this->maxRetries) {
                    throw new CutOptimException(0, 'network_error', $netError);
                }
                usleep($this->backoffUs($attempt, null));
                continue;
            }
            $json = $text === '' ? null : json_decode((string) $text, true);
            if ($status >= 200 && $status < 300) {
                return is_array($json) ? $json : [];
            }
            $retryAfter = isset($responseHeaders['retry-after']) ? (int) $responseHeaders['retry-after'] : null;
            $code = is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : 'http_' . $status;
            $retryable = $status === 429 || $status === 503 || ($status === 409 && $code === 'idempotency_in_progress');
            if ($retryable && $attempt < $this->maxRetries) {
                usleep($this->backoffUs($attempt, $retryAfter));
                continue;
            }
            $message = is_array($json) && is_string($json['message'] ?? null) ? $json['message'] : 'HTTP ' . $status;
            throw new CutOptimException($status, $code, $message, is_array($json) ? ($json['details'] ?? null) : null, $retryAfter);
        }
    }

    private function backoffUs(int $attempt, ?int $retryAfterS): int
    {
        if ($retryAfterS) {
            return min($retryAfterS, 60) * 1_000_000;
        }
        return (int) min(500_000 * (2 ** $attempt), 8_000_000);
    }
}
