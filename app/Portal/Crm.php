<?php

namespace App\Portal;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/* The portal's only way to reach the VSahha CRM. Speaks the integration contract:
   OAuth2 client credentials, the provider scope on every call (X-Provider-Scope),
   the signed-in person's name for the CRM's audit trail (X-Portal-User), and an
   Idempotency-Key on writes so a retried submission can never create a second claim. */
class Crm
{
    private const TOKEN_KEY = 'crm.access_token';

    private const SYNCED_KEY = 'crm.last_ok';

    private static function base(): string
    {
        return (string) config('portal.crm_base_url');
    }

    private static function token(bool $fresh = false): string
    {
        if (! $fresh && ($t = Cache::get(self::TOKEN_KEY))) {
            return $t;
        }
        try {
            $res = Http::timeout(10)->connectTimeout(4)->asJson()->post(self::base().'/oauth/token', ['grant_type' => 'client_credentials',
                'client_id' => config('portal.crm_client_id'), 'client_secret' => config('portal.crm_client_secret')]);
        } catch (ConnectionException) {
            throw new CrmError(503, 'CRM_UNAVAILABLE', 'The VSahha CRM is not reachable right now. Nothing was sent; try again in a moment.');
        }
        if (! $res->successful()) {
            throw new CrmError(502, 'CRM_AUTH', 'The portal could not authenticate with the VSahha CRM.');
        }
        $t = (string) $res->json('access_token');
        Cache::put(self::TOKEN_KEY, $t, max(60, (int) $res->json('expires_in', 3600) - 30));

        return $t;
    }

    private static function call(string $method, string $path, array $o = [], bool $retry = true)
    {
        $headers = array_filter(['Authorization' => 'Bearer '.self::token(),
            'X-Provider-Scope' => isset($o['scope']) ? implode(',', $o['scope']) : null,
            'X-Portal-User' => isset($o['user']) ? rawurlencode($o['user']) : null,
            'Idempotency-Key' => $o['idempotencyKey'] ?? null] + ($o['headers'] ?? []));
        $req = Http::timeout(25)->connectTimeout(4)->withHeaders($headers);
        try {
            if (array_key_exists('raw', $o)) {
                $res = $req->withBody($o['raw'], $o['headers']['Content-Type'] ?? 'application/octet-stream')->send($method, self::base().$path);
            } elseif (array_key_exists('body', $o)) {
                $res = $req->asJson()->send($method, self::base().$path, ['json' => $o['body']]);
            } else {
                $res = $req->send($method, self::base().$path);
            }
        } catch (ConnectionException) {
            throw new CrmError(503, 'CRM_UNAVAILABLE', 'The VSahha CRM is not reachable right now. Nothing was sent; try again in a moment.');
        }
        if ($res->status() === 401 && $retry) {
            Cache::forget(self::TOKEN_KEY);

            return self::call($method, $path, $o, false);
        }

        return self::read($res);
    }

    private static function read(Response $res)
    {
        $json = str_contains((string) $res->header('Content-Type'), 'json');
        if (! $res->successful()) {
            $err = $json ? ($res->json() ?: []) : ['message' => $res->body()];
            throw new CrmError($res->status(), (string) ($err['code'] ?? 'CRM_ERROR'), (string) (($err['message'] ?? null) ?: 'The CRM refused the request.'), (array) ($err['details'] ?? []));
        }
        Cache::put(self::SYNCED_KEY, iso_now(), 86400);

        return $json ? $res->json() : $res->body();
    }

    public static function lastOk(): ?string
    {
        return Cache::get(self::SYNCED_KEY);
    }

    public static function get(string $path, array $o = [])
    {
        return self::call('GET', $path, $o);
    }

    public static function post(string $path, $body, array $o = [])
    {
        if (! empty($o['idempotent'])) {
            $o['idempotencyKey'] ??= (string) Str::uuid();
        }

        return self::call('POST', $path, $o + ['body' => $body === [] ? (object) [] : $body]);
    }

    public static function upload(string $bytes, array $o)
    {
        return self::call('POST', '/v1/documents', ['scope' => $o['scope'], 'user' => $o['user'] ?? null, 'raw' => $bytes,
            'headers' => ['Content-Type' => ($o['mime'] ?? null) ?: 'application/octet-stream', 'X-File-Name' => rawurlencode((string) $o['name']), 'X-Provider-Id' => (string) $o['providerId']]]);
    }

    /** whether the CRM answers; asked at most every few seconds, for the live chip and /health */
    public static function reachable(): bool
    {
        return (bool) Cache::remember('crm.reachable', 8, function () {
            try {
                return Http::timeout(3)->connectTimeout(2)->get(self::base().'/health')->successful() ? 1 : 0;
            } catch (\Throwable) {
                return 0;
            }
        });
    }
}
