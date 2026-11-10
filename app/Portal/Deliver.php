<?php

namespace App\Portal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/* The one send seam. Every e-mail, SMS or outbound webhook the portal sends goes through
   here. With no transport configured the message is RECORDED with transmitted = 0 and the
   reason, and nothing anywhere reports it as sent. */
class Deliver
{
    public static function channels(): array
    {
        return array_keys(array_filter(['webhook' => true, 'email' => (bool) config('portal.mail_enabled'), 'sms' => (bool) config('portal.sms_url')]));
    }

    /** @return array{transmitted: bool, reason: ?string} */
    public static function send(array $m): array
    {
        $channel = $m['channel'];
        $transmitted = false;
        $reason = "No {$channel} transport is configured.";
        if (in_array($channel, self::channels(), true)) {
            try {
                self::transport($channel, $m);
                $transmitted = true;
                $reason = null;
            } catch (\Throwable $e) {
                $reason = mb_substr($e->getMessage(), 0, 240);
            }
        }
        DB::table('outbox')->insert(['channel' => $channel, 'recipient' => $m['recipient'] ?? null, 'subject' => isset($m['subject']) ? mb_substr($m['subject'], 0, 250) : null,
            'body' => $m['body'] ?? null, 'transmitted' => $transmitted, 'reason' => $reason, 'at' => iso_now()]);

        return compact('transmitted', 'reason');
    }

    private static function transport(string $channel, array $m): void
    {
        if ($channel === 'email') {
            Mail::raw((string) ($m['body'] ?? ''), fn ($mail) => $mail->to($m['recipient'])->subject((string) ($m['subject'] ?? '')));

            return;
        }
        if ($channel === 'sms') {
            $req = Http::timeout(15);
            if ($token = config('portal.sms_token')) {
                $req = $req->withToken($token);
            }
            $res = $req->withoutRedirecting()->post((string) config('portal.sms_url'), ['to' => $m['recipient'], 'text' => $m['body'], 'from' => config('portal.sms_from')]);
            if (! $res->successful()) {
                throw new \RuntimeException("The SMS gateway answered {$res->status()}.");
            }

            return;
        }
        /* outbound webhook: a POST that must answer 2xx within 10 seconds */
        try {
            $res = Http::timeout(10)->withoutRedirecting()->withHeaders($m['headers'] ?? [])->withBody((string) $m['body'], 'application/json')->post((string) $m['recipient']);
        } catch (\Throwable) {
            throw new \RuntimeException('The endpoint did not answer within 10 seconds.');
        }
        if (! $res->successful()) {
            throw new \RuntimeException("The endpoint answered {$res->status()}.");
        }
    }
}
