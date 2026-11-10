<?php

namespace App\Portal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/* One place decides who hears about an event, and how. An event about a provider (from a
   CRM webhook, or raised by the portal for an expiring GOP or contract) becomes:
     · a notification in the bell of every organisation with that branch;
     · an e-mail to each active person whose role can see that kind of record and who
       wants e-mails for that event, through the one send seam;
     · a signed POST to the organisation's own systems registered in Settings → Integrations. */
class Alerts
{
    public const NOTIFY_TYPES = [
        'claim.info.requested' => ['VSahha needs information on a claim', 'claim.view', true],
        'preauth.info.requested' => ['The medical team needs information on a pre-authorization', 'preauth.view', true],
        'claim.decided' => ['A claim is decided', 'claim.view', false],
        'gop.issued' => ['A GOP is issued or revised', 'preauth.view', false],
        'gop.expiring' => ['A GOP expires within 7 days', 'preauth.view', true],
        'payment.released' => ['A payment is released', 'payment.view', true],
        'appeal.decided' => ['An appeal is decided', 'claim.view', false],
        'contract.expiring' => ['A contract ends within 30 days', 'settings.view', true],
        'diagnosis.recoded' => ['An Unknown Illness diagnosis is re-coded', 'claim.view', false],
    ];

    private const PREF_OF = ['gop.revised' => 'gop.issued', 'appeal.updated' => 'appeal.decided'];

    public const OUTBOUND_EVENTS = ['claim.decided', 'payment.released', 'gop.issued', 'info.requested'];

    private const OUTBOUND_OF = ['claim.decided' => 'claim.decided', 'payment.released' => 'payment.released', 'gop.issued' => 'gop.issued', 'gop.revised' => 'gop.issued',
        'claim.info.requested' => 'info.requested', 'preauth.info.requested' => 'info.requested'];

    public static function emailWanted(object $user, string $type): bool
    {
        $key = self::PREF_OF[$type] ?? $type;
        $def = self::NOTIFY_TYPES[$key] ?? null;
        if (! $def || ! Vocab::roleCan($user->role, $def[1])) {
            return false;
        }
        $row = DB::table('notify_prefs')->where('user_id', $user->id)->where('type', $key)->first();

        return $row ? (bool) $row->email : $def[2];
    }

    public static function prefsFor(object $user): array
    {
        $out = [];
        foreach (self::NOTIFY_TYPES as $type => [$label, $cap]) {
            if (Vocab::roleCan($user->role, $cap)) {
                $out[] = ['type' => $type, 'label' => $label, 'email' => self::emailWanted($user, $type)];
            }
        }

        return $out;
    }

    public static function setPref(object $user, string $type, bool $email): bool
    {
        $def = self::NOTIFY_TYPES[$type] ?? null;
        if (! $def || ! Vocab::roleCan($user->role, $def[1])) {
            return false;
        }
        DB::table('notify_prefs')->updateOrInsert(['user_id' => $user->id, 'type' => $type], ['email' => $email]);

        return true;
    }

    /** $n: title, body, link. $e: id, type, providerId, data */
    public static function raise(array $e, ?array $n): void
    {
        foreach (DB::table('org_providers')->where('provider_id', $e['providerId'] ?? '')->pluck('org_id') as $orgId) {
            if ($n) {
                DB::table('notifications')->insert(['org_id' => $orgId, 'provider_id' => $e['providerId'], 'type' => $e['type'], 'title' => mb_substr($n['title'], 0, 250), 'body' => ($n['body'] ?? null) ?: null, 'link' => $n['link'] ?? null, 'at' => iso_now()]);
                foreach (DB::table('users')->where('org_id', $orgId)->where('status', 'Active')->get() as $u) {
                    if (self::emailWanted($u, $e['type'])) {
                        Jobs::enqueue('deliver', ['channel' => 'email', 'recipient' => $u->email, 'subject' => $n['title'], 'body' => trim(($n['body'] ?? '')."\n\nOpen the VSahha Provider Portal to see it.")]);
                    }
                }
            }
            self::sendOutbound($orgId, $e);
        }
    }

    public static function sign(string $secret, string $body): string
    {
        $t = time();

        return "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
    }

    private static function payload(array $e, string $type): string
    {
        $data = $e['data'] ?? [];
        unset($data['message']); // free text from VSahha staff stays in the portal

        return json_encode(['id' => ($e['id'] ?? null) ?: 'evt_'.bin2hex(random_bytes(12)), 'type' => $type, 'providerId' => $e['providerId'] ?? null, 'occurredAt' => iso_now(), 'data' => (object) $data], JSON_UNESCAPED_UNICODE);
    }

    private static function sendOutbound(string $orgId, array $e): void
    {
        $type = self::OUTBOUND_OF[$e['type']] ?? null;
        if (! $type) {
            return;
        }
        foreach (DB::table('endpoints')->where('org_id', $orgId)->where('active', true)->get() as $x) {
            if (in_array($type, json_of($x->events, []), true)) {
                Jobs::enqueue('deliver', ['channel' => 'webhook', 'recipient' => $x->url, 'subject' => $type, 'body' => self::payload($e, $type), 'endpointId' => $x->id]);
            }
        }
    }

    /** a webhook is signed at each attempt, so a retry is not refused as stale */
    public static function deliverJob(array $m): array
    {
        if (($m['channel'] ?? null) === 'webhook' && ! empty($m['endpointId'])) {
            $x = DB::table('endpoints')->where('id', $m['endpointId'])->first();
            if (! $x || ! $x->active) {
                throw new JobGaveUp('The endpoint was removed.');
            }
            $m['headers'] = ['X-VSahha-Signature' => self::sign(decrypt($x->secret), (string) $m['body']), 'X-VSahha-Event' => (string) $m['subject']];
        }
        $out = Deliver::send($m);
        /* a channel with no transport has nothing to retry */
        if (! $out['transmitted'] && in_array($m['channel'], Deliver::channels(), true)) {
            throw new \RuntimeException((string) $out['reason']);
        }

        return ['transmitted' => $out['transmitted']];
    }

    public static function testEndpoint(object $endpoint): array
    {
        $body = self::payload(['id' => 'evt_test_'.bin2hex(random_bytes(4)), 'type' => 'test', 'providerId' => null, 'data' => ['note' => 'A test event from the VSahha Provider Portal.']], 'test');

        return Deliver::send(['channel' => 'webhook', 'recipient' => $endpoint->url, 'subject' => 'test', 'body' => $body,
            'headers' => ['X-VSahha-Signature' => self::sign(decrypt($endpoint->secret), $body), 'X-VSahha-Event' => 'test']]);
    }

    /** an endpoint must be HTTPS; private addresses are refused so the portal cannot reach an internal network. In demo mode a local address may be tried */
    public static function checkUrl($raw): ?string
    {
        $u = parse_url(trim((string) $raw));
        if (! $u || empty($u['scheme']) || empty($u['host'])) {
            return 'Enter a full address, starting with https://';
        }
        $host = strtolower($u['host']);
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if (config('portal.demo') && $local && $u['scheme'] === 'http') {
            return null;
        }
        if ($u['scheme'] !== 'https') {
            return 'The address must start with https://';
        }
        if ($local || preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.|0\.)/', $host) || str_ends_with($host, '.internal')) {
            return 'A private or local address cannot receive events.';
        }

        return null;
    }

    private static function once(string $key): bool
    {
        if (DB::table('raised_alerts')->where('key', $key)->exists()) {
            return false;
        }
        DB::table('raised_alerts')->insert(['key' => $key, 'at' => iso_now()]);

        return true;
    }

    /** a GOP expiring within 7 days and a contract ending within 30, each raised once */
    public static function scanExpiries(): void
    {
        foreach (DB::table('org_providers')->distinct()->pluck('provider_id') as $providerId) {
            $o = ['scope' => [$providerId], 'user' => 'Provider portal (expiry check)'];
            try {
                foreach (Crm::get('/v1/preauths', $o) as $p) {
                    $gop = $p['gop'] ?? null;
                    if (! $gop || ! isset($gop['expiresInDays']) || $gop['expiresInDays'] < 0 || $gop['expiresInDays'] > 7 || ! self::once("gop.expiring:{$gop['gopNumber']}:{$gop['currentVersion']}")) {
                        continue;
                    }
                    self::raise(['type' => 'gop.expiring', 'providerId' => $providerId, 'data' => ['caseId' => $p['id'], 'gopNumber' => $gop['gopNumber'], 'validUntil' => $gop['validUntil']]],
                        ['title' => "{$gop['gopNumber']} expires on {$gop['validUntil']}", 'body' => "{$p['memberName']} · {$p['ref']}. Treat and bill before then, or request an extension.", 'link' => "/preauth/{$p['id']}"]);
                }
                foreach (Crm::get("/v1/providers/{$providerId}/contracts", $o) as $c) {
                    $end = $c['end'] ?? null;
                    $soon = $end && days_between(portal_today(), $end) <= 30 && days_between(portal_today(), $end) >= 0;
                    if (($c['status'] ?? null) === 'Active' && $soon && self::once("contract.expiring:{$c['id']}:{$end}")) {
                        self::raise(['type' => 'contract.expiring', 'providerId' => $providerId, 'data' => ['contractId' => $c['id'], 'end' => $end]],
                            ['title' => 'Contract '.(($c['number'] ?? null) ?: $c['id'])." ends on {$end}", 'body' => 'Contact the VSahha provider desk about its renewal.', 'link' => '/settings?tab=contracts']);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("expiry check for {$providerId}: {$e->getMessage()}");
            }
        }
    }

    /** a CRM event, in the words the provider reads */
    public static function notificationFor(array $e): ?array
    {
        $d = $e['data'] ?? [];
        $g = fn ($k, $f = '') => $d[$k] ?? $f;

        return match ($e['type'] ?? '') {
            'claim.status.changed' => $g('to') === 'Submitted for Validation' ? null : ['title' => $g('claimId').': '.Vocab::claimStatusLine($g('to')), 'link' => '/claims/'.$g('claimId')],
            'claim.decided' => ['title' => $g('claimId').' decided — '.$g('decision'), 'body' => $g('to') === 'Rejected' ? 'See the reason on each line. You can appeal where the reason allows it.' : 'The payment is scheduled.', 'link' => '/claims/'.$g('claimId')],
            'claim.info.requested' => ['title' => 'VSahha needs information on '.$g('claimId'), 'body' => $g('message'), 'link' => '/claims/'.$g('claimId')],
            'preauth.status.changed' => ['title' => $g('ref').': '.$g('to'), 'link' => '/preauth/'.$g('caseId')],
            'preauth.info.requested' => ['title' => 'The medical team needs information on '.$g('ref'), 'link' => '/preauth/'.$g('caseId')],
            'gop.issued' => ['title' => $g('gopNumber').' issued', 'body' => 'The guarantee of payment is ready to download.', 'link' => '/preauth/'.$g('caseId')],
            'gop.revised' => ['title' => $g('gopNumber').' revised — version '.$g('version'), 'body' => 'The new version replaces the previous one from its issue date.', 'link' => '/preauth/'.$g('caseId')],
            'gop.expiring' => ['title' => $g('gopNumber').' expires on '.$g('validUntil'), 'body' => 'Treat and bill before then, or request an extension.', 'link' => '/preauth/'.$g('caseId')],
            'payment.released' => ['title' => 'Payment '.$g('reference').' released — '.trim(number_format((float) $g('amount', 0), 2).' '.$g('currency')), 'body' => 'Settles '.$g('claimId').'.', 'link' => '/payments/'.$g('reference')],
            'provider.standing.changed' => ['title' => 'Your network standing is now '.$g('status'), 'body' => $g('canSubmit', null) === false ? 'New claims and pre-authorizations cannot be submitted. History, payments and documents stay available.' : '', 'link' => '/settings'],
            'contract.changed' => ['title' => 'Contract '.($g('number') ?: $g('contractId')).' updated'.($g('status') ? ' — '.$g('status') : ''), 'body' => 'See the new terms and tariffs.', 'link' => '/settings?tab=contracts'],
            'diagnosis.recoded' => ['title' => 'Diagnosis re-coded on '.$g('claimId'), 'body' => trim($g('from').' → '.$g('to').' '.$g('label')), 'link' => '/claims/'.$g('claimId')],
            'appeal.decided' => ['title' => 'Appeal '.$g('appealId').': '.$g('status'), 'link' => '/appeals'],
            'appeal.updated' => ['title' => 'Appeal '.$g('appealId').' is under review', 'link' => '/appeals'],
            'request.decided' => ['title' => $g('kind').': '.$g('status'), 'body' => $g('note'), 'link' => $g('subjectType') === 'preauth' ? '/preauth/'.$g('subjectId') : '/settings?tab=requests'],
            'message.received' => $g('from') === 'vsahha' ? ['title' => 'New message from VSahha', 'body' => $g('subjectId') ? 'About '.$g('subjectId') : '', 'link' => '/messages'] : null,
            default => null,
        };
    }
}
