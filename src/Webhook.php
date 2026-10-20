<?php

declare(strict_types=1);

namespace CutOptim\Engine;

/**
 * Verify a CutOptim webhook delivery (the CutOptim-Signature header):
 *   t=<unix seconds>,v1=<hex HMAC-SHA256(secret, "<t>.<raw body>")>[,v1=…]
 * Pass the RAW body (file_get_contents('php://input')) — a re-encoded body does not match. During a secret rotation the
 * header carries two v1 values; either matching your secret is enough.
 */
final class Webhook
{
    public const SIGNATURE_HEADER = 'CutOptim-Signature';
    public const DEFAULT_TOLERANCE_S = 300;

    /** @param string|string[] $secrets */
    public static function verify(string $rawBody, ?string $header, string|array $secrets, int $toleranceS = self::DEFAULT_TOLERANCE_S, ?int $nowS = null): bool
    {
        if ($header === null || strlen($header) > 4096) {
            return false;
        }
        $t = null;
        $v1 = [];
        foreach (explode(',', $header) as $part) {
            $i = strpos($part, '=');
            if ($i === false || $i === 0) {
                return false;
            }
            $k = trim(substr($part, 0, $i));
            $v = trim(substr($part, $i + 1));
            if ($k === 't') {
                if ($t !== null || !preg_match('/^\d{1,12}$/', $v)) {
                    return false;
                }
                $t = (int) $v;
            } elseif ($k === 'v1') {
                if (!preg_match('/^[0-9a-f]{64}$/', $v)) {
                    return false;
                }
                $v1[] = $v;
            }
        }
        if ($t === null || $v1 === []) {
            return false;
        }
        $now = $nowS ?? time();
        if (abs($now - $t) > $toleranceS) {
            return false;
        }
        foreach ((array) $secrets as $secret) {
            $want = hash_hmac('sha256', $t . '.' . $rawBody, $secret);
            foreach ($v1 as $got) {
                if (hash_equals($want, $got)) {
                    return true;
                }
            }
        }
        return false;
    }
}
