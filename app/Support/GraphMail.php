<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/* Email through Microsoft 365 (Microsoft Graph, application permission Mail.Send).
   An app registered in Entra ID gets a token with its client secret (client credentials) and sends as a
   mailbox of the tenant: POST /users/{mailbox}/sendMail. Nothing here stores a message; the caller records it.
   Configured by MS_GRAPH_TENANT_ID, MS_GRAPH_CLIENT_ID, MS_GRAPH_CLIENT_SECRET and MS_GRAPH_SENDER. */
class GraphMail
{
    public static function configured(): bool
    {
        $c = config('services.msgraph');

        return ! empty($c['tenant_id']) && ! empty($c['client_id']) && ! empty($c['client_secret']) && ! empty($c['sender']);
    }

    /** do Laravel's Mail:: messages really leave (MAIL_MAILER=graph and Microsoft 365 configured)? */
    public static function live(): bool
    {
        return config('mail.default') === 'graph' && self::configured();
    }

    /** the transport the submission module hands to Channels::deliver, or null when Microsoft 365 is not configured */
    public static function mailer(): ?\Closure
    {
        return self::configured() ? fn (array $message) => self::send($message) : null;
    }

    private static function token(): string
    {
        $c = config('services.msgraph');

        return Cache::remember('msgraph.token.'.md5($c['tenant_id'].$c['client_id']), now()->addMinutes(50), function () use ($c) {
            $r = Http::asForm()->timeout(20)->post("https://login.microsoftonline.com/{$c['tenant_id']}/oauth2/v2.0/token", [
                'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'scope' => 'https://graph.microsoft.com/.default', 'grant_type' => 'client_credentials',
            ]);
            if (! $r->successful() || ! $r->json('access_token')) {
                throw new \RuntimeException('Microsoft sign-in failed: '.($r->json('error_description') ?: $r->status()));
            }

            return $r->json('access_token');
        });
    }

    /** the mailbox that sends: the message's own sender when it is in the tenant's domain, else the configured one */
    private static function mailbox(?string $from): string
    {
        $sender = (string) config('services.msgraph.sender');
        $domain = strtolower(substr(strrchr($sender, '@') ?: '', 1));

        return $from && $domain !== '' && str_ends_with(strtolower(trim($from)), '@'.$domain) ? trim($from) : $sender;
    }

    /**
     * @param  array  $message  to[], cc[], bcc[], from, replyTo, subject, body, html (bool), attachments[{name, contentType, content}]
     * @return array ['ok' => bool, 'id' => string|null, 'error' => string|null]
     */
    public static function send(array $message): array
    {
        /* test mode (MS_GRAPH_REDIRECT_TO): every message goes to one mailbox instead, and says where it would have gone */
        if ($redirect = trim((string) config('services.msgraph.redirect_to'))) {
            $meant = implode(', ', array_filter(array_merge((array) ($message['to'] ?? []), (array) ($message['cc'] ?? []), (array) ($message['bcc'] ?? []))));
            $message = array_merge($message, ['to' => [$redirect], 'cc' => [], 'bcc' => [], 'subject' => '[TEST → '.($meant ?: 'no recipient').'] '.($message['subject'] ?? '')]);
        }
        $addr = fn ($list) => array_values(array_map(fn ($a) => ['emailAddress' => ['address' => trim($a)]], array_filter((array) $list, fn ($a) => trim((string) $a) !== '')));
        $id = 'VSAHHA-'.now()->format('YmdHis').'-'.substr(bin2hex(random_bytes(4)), 0, 8);
        $payload = ['message' => array_filter([
            'subject' => (string) ($message['subject'] ?? ''),
            'body' => ['contentType' => ! empty($message['html']) ? 'HTML' : 'Text', 'content' => (string) ($message['body'] ?? '')],
            'toRecipients' => $addr($message['to'] ?? []),
            'ccRecipients' => $addr($message['cc'] ?? []) ?: null,
            'bccRecipients' => $addr($message['bcc'] ?? []) ?: null,
            'replyTo' => ! empty($message['replyTo']) ? $addr([$message['replyTo']]) : null,
            'attachments' => array_map(fn ($f) => ['@odata.type' => '#microsoft.graph.fileAttachment', 'name' => $f['name'],
                'contentType' => ($f['contentType'] ?? null) ?: 'application/octet-stream', 'contentBytes' => base64_encode((string) $f['content'])], $message['attachments'] ?? []) ?: null,
            'internetMessageHeaders' => [['name' => 'X-VSAHHA-Reference', 'value' => $id]],
        ]), 'saveToSentItems' => true];
        if (! $payload['message']['toRecipients']) {
            return ['ok' => false, 'id' => null, 'error' => 'No recipient address.'];
        }
        try {
            $r = Http::withToken(self::token())->timeout(30)->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode(self::mailbox($message['from'] ?? null)).'/sendMail', $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'id' => null, 'error' => $e->getMessage()];
        }

        return $r->status() === 202 ? ['ok' => true, 'id' => $id, 'error' => null]
            : ['ok' => false, 'id' => null, 'error' => 'Microsoft 365 refused the message: '.($r->json('error.message') ?: 'HTTP '.$r->status())];
    }
}
