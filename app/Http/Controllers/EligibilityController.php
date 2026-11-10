<?php

namespace App\Http\Controllers;

use App\Portal\Crm;
use App\Portal\Ctx;
use App\Portal\Deliver;
use App\Portal\Docs;
use App\Portal\PortalError;
use App\Portal\Retention;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/* The four CRM checks, the cover, the plan's benefits, a certificate verifiable by QR.
   Only what a provider needs about a member. Check-in records that a patient is here today:
   presence is "verified" only when the card was scanned or the patient gave the code texted
   to their phone; a typed number is "recorded". */
class EligibilityController extends Controller
{
    private function runCheck(Ctx $ctx, array $b): array
    {
        $ctx->need('eligibility.check');
        $ctx->single();
        $ctx->rateLimit("elig:{$ctx->user->id}", (int) Retention::config('eligibilityRateLimitPerHour', 120));
        $out = Crm::post('/v1/eligibility', array_filter(['providerId' => $ctx->providerId, 'cardNo' => $b['cardNo'] ?? null, 'memberId' => $b['memberId'] ?? null, 'name' => $b['name'] ?? null, 'dob' => $b['dob'] ?? null,
            'q' => $b['q'] ?? null, 'serviceDate' => $b['serviceDate'] ?? null, 'benefitCode' => ($b['benefitCode'] ?? null) ?: null], fn ($v) => $v !== null && $v !== ''), $ctx->crmOpts());
        $id = portal_id('ELG');
        DB::table('eligibility_checks')->insert(['id' => $id, 'at' => iso_now(), 'user_id' => $ctx->user->id, 'provider_id' => $ctx->providerId, 'member_id' => $out['member']['id'], 'member_name' => $out['member']['name'],
            'service_date' => $out['serviceDate'], 'eligible' => (bool) $out['eligible'], 'summary' => $out['summary'],
            'snapshot' => json_encode(['member' => $out['member'], 'policy' => $out['policy'], 'insurer' => $out['insurer'], 'checks' => $out['checks'], 'network' => $out['network'], 'enrolledFrom' => $out['enrolledFrom'] ?? null], JSON_UNESCAPED_UNICODE)]);
        $ctx->audit('eligibility.checked', $out['member']['id'], ['serviceDate' => $out['serviceDate'], 'eligible' => $out['eligible']]);

        return $out + ['checkId' => $id];
    }

    public function check(Request $r)
    {
        $ctx = me();
        $mode = $r->input('mode') === 'name' ? 'name' : 'card';
        $q = trim((string) $r->input('q', ''));
        $res = $this->runCheck($ctx, ($mode === 'card' ? ['q' => $q, 'cardNo' => $q] : ['name' => trim((string) $r->input('name')), 'dob' => $r->input('dob')]) + ['serviceDate' => $r->input('date', portal_today())]);

        return redirect('/eligibility?'.http_build_query(array_filter(['check' => $res['checkId'], 'mode' => $mode, 's' => $mode === 'card' && $r->filled('scanned') && $r->input('scanned') === $q ? 1 : null])));
    }

    public function index(Request $r)
    {
        $ctx = me();
        $ctx->need('eligibility.check');
        $mode = $r->query('mode') === 'name' ? 'name' : 'card';
        $res = null;
        $error = null;
        $benefit = (string) $r->query('benefit', '');
        $checkId = (string) $r->query('check', '');
        $date = portal_today();
        if ($checkId !== '') {
            $row = DB::table('eligibility_checks as e')->join('users as u', 'u.id', '=', 'e.user_id')->where('e.id', $checkId)->where('u.org_id', $ctx->user->org_id)->whereIn('e.provider_id', $ctx->scope)->first(['e.*']);
            if ($row) {
                $date = $row->service_date;
                try {
                    /* the same member and date read again; the check itself was recorded when it was made */
                    $res = Crm::post('/v1/eligibility', array_filter(['providerId' => $row->provider_id, 'memberId' => $row->member_id, 'serviceDate' => $row->service_date, 'benefitCode' => $benefit ?: null]), $ctx->crmOpts()) + ['checkId' => $row->id];
                } catch (PortalError $e) {
                    $error = $e;
                }
            }
        }
        $history = DB::table('eligibility_checks as e')->join('users as u', 'u.id', '=', 'e.user_id')->whereIn('e.provider_id', $ctx->scope)->where('u.org_id', $ctx->user->org_id)
            ->orderByDesc('e.at')->limit(15)->get(['e.id', 'e.at', 'e.member_name', 'e.service_date', 'e.eligible', 'u.name as by']);

        return view('eligibility.index', compact('mode', 'date', 'benefit', 'res', 'error', 'history', 'checkId') + ['q' => (string) old('q', $r->query('q', '')), 'name' => (string) old('name', ''), 'dob' => (string) old('dob', ''),
            'wasScanned' => (bool) $r->query('s'), 'checkIn' => $res ? self::checkInFor($ctx, $res['member']['id']) : null, 'consent' => session('consent')]);
    }

    public function certificate(Request $r, string $id)
    {
        $ctx = me();
        $e = DB::table('eligibility_checks as e')->join('users as u', 'u.id', '=', 'e.user_id')->where('e.id', $id)->where('u.org_id', $ctx->user->org_id)->first(['e.*', 'u.name as by'])
            ?? throw new PortalError(404, 'NOT_FOUND', 'No such check.');
        $org = Crm::get("/v1/providers/{$e->provider_id}", ['scope' => [$e->provider_id]]);

        return response(Docs::certificate($e, json_of($e->snapshot, []), $org, $r->getSchemeAndHttpHost()))->header('Content-Security-Policy', SecurityHeaders::DOC_CSP);
    }

    /* ------------------------------------------------------------ check-ins */

    private static function view(object $r): array
    {
        return ['id' => $r->id, 'memberId' => $r->member_id, 'memberName' => $r->member_name, 'day' => $r->day, 'at' => $r->at, 'method' => $r->method, 'consentAt' => $r->consent_at ?: null,
            'verified' => $r->method === 'card scanned' || (bool) $r->consent_at, 'checkId' => $r->check_id, 'by' => $r->by ?? null,
            'consentPending' => ! $r->consent_at && $r->consent_hash && $r->consent_expires > iso_now(), 'consentSentTo' => $r->consent_sent_to ?: null];
    }

    private static function checkIns(Ctx $ctx)
    {
        return DB::table('check_ins as c')->join('users as u', 'u.id', '=', 'c.user_id')->whereIn('c.provider_id', $ctx->scope)->where('c.org_id', $ctx->user->org_id);
    }

    public static function checkInsToday(Ctx $ctx): array
    {
        return self::checkIns($ctx)->where('c.day', portal_today())->orderByDesc('c.at')->get(['c.*', 'u.name as by'])->map(fn ($r) => self::view($r))->all();
    }

    private static function checkInFor(Ctx $ctx, string $memberId): ?array
    {
        $r = self::checkIns($ctx)->where('c.member_id', $memberId)->where('c.day', portal_today())->first(['c.*', 'u.name as by']);

        return $r ? self::view($r) : null;
    }

    private function checkInOf(Ctx $ctx, string $id): object
    {
        $r = self::checkIns($ctx)->where('c.id', $id)->first(['c.*', 'u.name as by']) ?? throw new PortalError(404, 'NOT_FOUND', 'No such check-in.');
        if ($r->day !== portal_today()) {
            throw new PortalError(409, 'NOT_TODAY', 'Consent is asked on the day of the visit.');
        }

        return $r;
    }

    public function checkIn(Request $r)
    {
        $ctx = me();
        $ctx->need('eligibility.check');
        $ctx->single();
        $e = DB::table('eligibility_checks')->where('id', (string) $r->input('checkId'))->where('provider_id', $ctx->providerId)->whereIn('user_id', DB::table('users')->where('org_id', $ctx->user->org_id)->select('id'))->first()
            ?? throw new PortalError(404, 'NOT_FOUND', "Check the patient's cover first, then check them in.");
        if (! $e->eligible) {
            throw new PortalError(409, 'NOT_COVERED', 'This patient is not covered on that date, so they cannot be checked in for VSahha billing.');
        }
        if ($e->service_date !== portal_today()) {
            throw new PortalError(409, 'NOT_TODAY', 'A check-in is for today. The cover check was made for another date.');
        }
        $exists = DB::table('check_ins')->where('provider_id', $ctx->providerId)->where('member_id', $e->member_id)->where('day', portal_today())->exists();
        if (! $exists) {
            $method = $r->input('method') === 'card scanned' ? 'card scanned' : 'card number typed';
            DB::table('check_ins')->insert(['id' => portal_id('CHK', 4), 'org_id' => $ctx->user->org_id, 'provider_id' => $ctx->providerId, 'member_id' => $e->member_id, 'member_name' => $e->member_name,
                'day' => portal_today(), 'at' => iso_now(), 'method' => $method, 'check_id' => $e->id, 'user_id' => $ctx->user->id]);
            $ctx->audit('patient.checked.in', $e->member_id, ['method' => $method, 'checkId' => $e->id]);
        }

        return redirect()->back();
    }

    /* a 6-digit code goes to the member's phone on file at VSahha (the portal never keeps the
       number); the patient gives it to the front desk. 10 minutes, 5 tries, 20 codes an hour per person. */
    public function consent(string $id)
    {
        $ctx = me();
        $ctx->need('eligibility.check');
        $ctx->single();
        $c = $this->checkInOf($ctx, $id);
        if ($c->consent_at) {
            return redirect()->back();
        }
        $ctx->rateLimit("consent:{$ctx->user->id}", (int) Retention::config('consentCodesPerHour', 20));
        $contact = Crm::get('/v1/members/'.rawurlencode($c->member_id)."/contact?providerId={$ctx->providerId}&purpose=presence-consent", $ctx->crmOpts());
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $sent = Deliver::send(['channel' => 'sms', 'recipient' => $contact['phone'],
            'body' => 'VSahha: your check-in code at '.$ctx->providerLabel()." is {$code}. Give it to the front desk only if you are there now. It expires in 10 minutes."]);
        DB::table('check_ins')->where('id', $c->id)->update(['consent_hash' => portal_sha("{$c->id}:{$code}"), 'consent_expires' => iso_later(600), 'consent_tries' => 0, 'consent_sent_to' => $contact['masked']]);
        $ctx->audit('presence.consent.requested', $c->member_id, ['checkIn' => $c->id, 'transmitted' => $sent['transmitted']]);

        return redirect()->back()->with('consent', ['checkIn' => $c->id, 'sentTo' => $contact['masked'], 'transmitted' => $sent['transmitted'], 'demoCode' => config('portal.demo') && ! $sent['transmitted'] ? $code : null]);
    }

    public function consentVerify(Request $r, string $id)
    {
        $ctx = me();
        $ctx->need('eligibility.check');
        $ctx->single();
        $c = $this->checkInOf($ctx, $id);
        if ($c->consent_at) {
            return redirect()->back();
        }
        if (! $c->consent_hash || $c->consent_expires < iso_now()) {
            throw new PortalError(409, 'EXPIRED', 'The code has expired. Send a new one.');
        }
        if ($c->consent_tries >= 5) {
            throw new PortalError(429, 'TOO_MANY', 'Too many wrong codes. Send a new one.');
        }
        if (! hash_equals($c->consent_hash, portal_sha("{$c->id}:".trim((string) $r->input('code'))))) {
            DB::table('check_ins')->where('id', $c->id)->increment('consent_tries');
            $left = 4 - $c->consent_tries;
            throw new PortalError(400, 'BAD_CODE', "That code is not right. {$left} attempt".($left === 1 ? '' : 's').' left.');
        }
        DB::table('check_ins')->where('id', $c->id)->update(['consent_at' => iso_now(), 'consent_hash' => null]);
        $ctx->audit('presence.consent.confirmed', $c->member_id, ['checkIn' => $c->id]);

        return redirect()->back();
    }
}
