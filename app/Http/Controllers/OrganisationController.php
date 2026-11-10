<?php

namespace App\Http\Controllers;

use App\Portal\Alerts;
use App\Portal\Crm;
use App\Portal\Ctx;
use App\Portal\Deliver;
use App\Portal\Files;
use App\Portal\Jobs;
use App\Portal\PortalError;
use App\Portal\Retention;
use App\Portal\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/* Team: who can use the portal, with what role; invitations and resets by link; sign-in history;
   the audit trail; the messages the portal recorded but could not send; the pilot. Settings: the
   organisation as VSahha records it, contracts and tariffs, bank details (changed only by request),
   the requests sent, notifications, integrations, data & privacy, the person's own security. */
class OrganisationController extends Controller
{
    /* ------------------------------------------------------------ team */

    public function team(Request $r)
    {
        $ctx = me();
        $ctx->need('team.manage');
        $tab = in_array($r->query('tab'), ['people', 'roles', 'signins', 'audit', 'outbox', 'pilot'], true) ? $r->query('tab') : 'people';
        $org = $ctx->user->org_id;
        $data = ['tab' => $tab, 'users' => DB::table('users')->where('org_id', $org)->orderBy('created_at')->get(), 'link' => session('invite_link')];
        if ($tab === 'signins') {
            $data['signins'] = DB::table('signins as s')->leftJoin('users as u', 'u.id', '=', 's.user_id')->where('u.org_id', $org)->orderByDesc('s.at')->limit(100)->get(['s.at', 's.email', 's.ip', 's.ua', 's.outcome', 'u.name']);
        } elseif ($tab === 'audit') {
            $data['audit'] = DB::table('audit as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.org_id', $org)->orderByDesc('a.at')->limit(200)->get(['a.at', 'a.action', 'a.subject', 'a.detail', 'a.provider_id', 'a.ip', 'a.device', 'a.before_state', 'a.after_state', 'u.name']);
        } elseif ($tab === 'outbox') {
            $emails = DB::table('users')->where('org_id', $org)->pluck('email')->all();
            $data['outbox'] = DB::table('outbox')->whereIn('recipient', $emails)->orderByDesc('at')->limit(50)->get(['id', 'channel', 'recipient', 'subject', 'transmitted', 'reason', 'at']);
        } elseif ($tab === 'pilot') {
            $data['pilot'] = $this->pilot($ctx, max(1, (int) $r->query('days', 28)));
        }

        return view('team.index', $data);
    }

    /** usage is counted from the audit trail (actions, not page views or keystrokes) */
    private function pilot(Ctx $ctx, int $days): array
    {
        $org = $ctx->user->org_id;
        $since = gmdate('Y-m-d\TH:i:s.v\Z', time() - $days * 86400);
        $feedback = DB::table('feedback as f')->leftJoin('users as u', 'u.id', '=', 'f.user_id')->where('f.org_id', $org)->orderByDesc('f.at')->limit(200)->get(['f.id', 'f.at', 'f.page', 'f.rating', 'f.body', 'f.role', 'f.status', 'u.name']);
        $ratings = [];
        foreach (['easy', 'ok', 'hard'] as $k) {
            $ratings[$k] = $feedback->filter(fn ($f) => $f->rating === $k && $f->at >= $since)->count();
        }
        $usage = [];
        foreach (['eligibility.checked' => 'Cover checks', 'patient.checked.in' => 'Check-ins', 'claim.submitted' => 'Claims submitted', 'preauth.submitted' => 'Pre-authorizations requested', 'intake.read' => 'Smart Intake uploads',
            'claims.bulk.read' => 'Claim files checked', 'claim.replied' => 'Replies to VSahha', 'appeal.submitted' => 'Appeals', 'document.viewed' => 'Documents opened', 'copilot.asked' => 'Copilot questions'] as $action => $label) {
            $q = DB::table('audit')->where('org_id', $org)->where('action', $action)->where('at', '>=', $since);
            $usage[] = ['label' => $label, 'count' => (clone $q)->count(), 'people' => (clone $q)->distinct()->count('user_id')];
        }
        $active = DB::table('audit as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.org_id', $org)->where('a.at', '>=', $since)->groupBy('u.role')->get(['u.role', DB::raw('COUNT(DISTINCT a.user_id) AS n')]);
        $signins = DB::table('signins as s')->join('users as u', 'u.id', '=', 's.user_id')->where('u.org_id', $org)->where('s.at', '>=', $since);

        return ['days' => $days, 'since' => $since, 'feedback' => $feedback, 'ratings' => $ratings, 'usage' => $usage, 'active' => $active, 'signins' => (clone $signins)->count(), 'signedIn' => (clone $signins)->where('s.outcome', 'like', 'signed in%')->count()];
    }

    private function link(Request $r, string $token): string
    {
        return $r->getSchemeAndHttpHost()."/accept/{$token}";
    }

    public function invite(Request $r)
    {
        $ctx = me();
        $ctx->need('team.manage');
        $email = mb_strtolower(trim((string) $r->input('email')));
        if (! preg_match('/^[^@\s]+@[^@\s]+\.[^@\s]+$/', $email)) {
            throw new PortalError(400, 'BAD_EMAIL', 'Enter a valid e-mail address.');
        }
        $role = (string) $r->input('role');
        if (! isset(Vocab::ROLES[$role])) {
            throw new PortalError(400, 'BAD_ROLE', 'Choose a role.');
        }
        if (DB::table('users')->where('email', $email)->exists()) {
            throw new PortalError(409, 'EXISTS', 'Someone already uses this e-mail address.');
        }
        $userId = portal_id('USR', 4);
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        DB::transaction(function () use ($ctx, $userId, $email, $r, $role, $token) {
            DB::table('users')->insert(['id' => $userId, 'org_id' => $ctx->user->org_id, 'email' => $email, 'name' => mb_substr((string) ($r->input('name') ?: $email), 0, 80), 'role' => $role, 'status' => 'Invited', 'mfa' => false, 'created_at' => iso_now(), 'invited_by' => $ctx->user->id]);
            DB::table('invites')->insert(['id' => portal_sha($token), 'user_id' => $userId, 'kind' => 'invite', 'expires_at' => iso_later(7 * 86400), 'created_at' => iso_now()]);
        });
        $link = $this->link($r, $token);
        $sent = Deliver::send(['channel' => 'email', 'recipient' => $email, 'subject' => 'You are invited to the VSahha Provider Portal', 'body' => "Set your password here within 7 days: {$link}"]);
        $ctx->audit('team.invited', $userId, ['email' => $email, 'role' => $role], ['after' => ['email' => $email, 'role' => $role, 'status' => 'Invited']]);

        return redirect('/team')->with('invite_link', ['kind' => 'invite', 'transmitted' => $sent['transmitted'], 'link' => config('portal.demo') || ! $sent['transmitted'] ? $link : null]);
    }

    public function updateMember(Request $r, string $id)
    {
        $ctx = me();
        $ctx->need('team.manage');
        $u = DB::table('users')->where('id', $id)->where('org_id', $ctx->user->org_id)->first() ?? throw new PortalError(404, 'NOT_FOUND', 'No such team member.');
        $role = $r->input('role');
        $status = $r->input('status');
        if ($u->id === $ctx->user->id && ($status === 'Suspended' || ($role && $role !== 'Provider Admin'))) {
            throw new PortalError(409, 'SELF', 'You cannot suspend yourself or remove your own administrator role.');
        }
        if ($role && ! isset(Vocab::ROLES[$role])) {
            throw new PortalError(400, 'BAD_ROLE', 'Unknown role.');
        }
        if ($status && ! in_array($status, ['Active', 'Suspended'], true)) {
            throw new PortalError(400, 'BAD_STATUS', 'Unknown status.');
        }
        $set = array_filter(['role' => $role, 'status' => $status]);
        if ($r->has('mfa')) {
            $set['mfa'] = $r->boolean('mfa');
        }
        DB::table('users')->where('id', $u->id)->update($set);
        if ($status === 'Suspended') {
            DB::table('portal_sessions')->where('user_id', $u->id)->delete();
        }
        $after = DB::table('users')->where('id', $u->id)->first(['role', 'status', 'mfa']);
        $ctx->audit('team.updated', $u->id, $set, ['before' => ['role' => $u->role, 'status' => $u->status, 'mfa' => $u->mfa], 'after' => (array) $after]);
        toast(t('Saved'));

        return redirect()->back();
    }

    public function resetLink(Request $r, string $id)
    {
        $ctx = me();
        $ctx->need('team.manage');
        $u = DB::table('users')->where('id', $id)->where('org_id', $ctx->user->org_id)->first() ?? throw new PortalError(404, 'NOT_FOUND', 'No such team member.');
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        DB::table('invites')->insert(['id' => portal_sha($token), 'user_id' => $u->id, 'kind' => 'reset', 'expires_at' => iso_later(86400), 'created_at' => iso_now()]);
        $link = $this->link($r, $token);
        $sent = Deliver::send(['channel' => 'email', 'recipient' => $u->email, 'subject' => 'Reset your VSahha Provider Portal password', 'body' => "Set a new password within 24 hours: {$link}"]);
        $ctx->audit('team.reset', $u->id);

        return redirect('/team')->with('invite_link', ['kind' => 'reset', 'transmitted' => $sent['transmitted'], 'link' => config('portal.demo') || ! $sent['transmitted'] ? $link : null]);
    }

    public function feedbackStatus(Request $r, int $id)
    {
        $ctx = me();
        $ctx->need('team.manage');
        if (! in_array($r->input('status'), ['New', 'Read', 'Done'], true)) {
            throw new PortalError(400, 'BAD_STATUS', 'Unknown status.');
        }
        DB::table('feedback')->where('id', $id)->where('org_id', $ctx->user->org_id)->update(['status' => $r->input('status')]);

        return redirect()->back();
    }

    /* ------------------------------------------------------------ settings */

    public function settings(Request $r)
    {
        $ctx = me();
        $tabs = ['organisation', 'contracts', 'bank', 'requests', 'notifications', 'integrations', 'privacy', 'security'];
        $tab = in_array($r->query('tab'), $tabs, true) ? $r->query('tab') : ($ctx->all ? 'notifications' : 'organisation');
        $seesBank = (bool) array_filter(['payment.view', 'settings.view', 'bank.request'], fn ($c) => $ctx->roleCan($c));
        $data = ['tab' => $tab, 'seesBank' => $seesBank, 'profile' => null, 'contracts' => [], 'requests' => [], 'prefs' => Alerts::prefsFor($ctx->user), 'emailConnected' => DB::table('outbox')->where('channel', 'email')->where('transmitted', true)->exists() || in_array('email', Deliver::channels(), true)];
        if (! $ctx->all) {
            $profile = Crm::get("/v1/providers/{$ctx->providerId}", $ctx->crmOpts());
            $data['profile'] = $seesBank ? $profile : array_merge($profile, ['billing' => null]);
            if (in_array($tab, ['contracts'], true)) {
                $data['contracts'] = Crm::get("/v1/providers/{$ctx->providerId}/contracts", $ctx->crmOpts());
            }
            if ($tab === 'requests') {
                $requests = Crm::get('/v1/requests', $ctx->crmOpts());
                $data['requests'] = $seesBank ? $requests : array_values(array_filter($requests, fn ($x) => ! preg_match('/bank/i', $x['kind'] ?? '')));
            }
        }
        if ($tab === 'integrations' && $ctx->roleCan('team.manage')) {
            $data['events'] = Alerts::OUTBOUND_EVENTS;
            $data['endpoints'] = DB::table('endpoints')->where('org_id', $ctx->user->org_id)->orderBy('created_at')->get()->map(function ($x) {
                $x->last = DB::table('outbox')->where('channel', 'webhook')->where('recipient', $x->url)->orderByDesc('id')->first(['transmitted', 'reason', 'at']);

                return $x;
            });
            $data['created'] = session('endpoint_secret');
        }
        if ($tab === 'privacy' && $ctx->roleCan('team.manage')) {
            $policy = Retention::policy();
            $data['privacy'] = ['retention' => array_map(fn ($k, $l) => ['label' => $l, 'days' => $policy[$k]], array_keys(Retention::LABELS), Retention::LABELS), 'lastRun' => Retention::lastRun(),
                'keyFromEnv' => (bool) config('portal.files_key'), 'scanner' => Files::scannerEngine(), 'clamav' => (bool) config('portal.clamd_host'), 'transports' => Deliver::channels(), 'jobs' => Jobs::stats(), 'failedJobs' => Jobs::recentFailures(10),
                'files' => DB::table('files')->where('org_id', $ctx->user->org_id)->count(), 'removed' => DB::table('files')->where('org_id', $ctx->user->org_id)->whereNotNull('removed_at')->count()];
        }

        return view('settings.index', $data);
    }

    public function bankChange(Request $r)
    {
        $ctx = me();
        $ctx->need('bank.request');
        $ctx->single();
        $letter = ClaimsController::uploaded($ctx, $r, 'letter', 'bank_letter');
        $pushed = Files::push($ctx, $letter);
        $out = Crm::post("/v1/providers/{$ctx->providerId}/bank-change-requests", ['bankName' => $r->input('bankName'), 'accountHolder' => $r->input('accountHolder'), 'iban' => $r->input('iban'), 'swift' => $r->input('swift'), 'letterRef' => $pushed[0]['ref'] ?? null], $ctx->crmOpts(['idempotent' => true]));
        $iban = (string) $r->input('iban');
        $ctx->audit('bank.change.requested', $out['id'], null, ['after' => ['requestId' => $out['id'], 'status' => $out['status'] ?? 'Pending', 'bankName' => $r->input('bankName'), 'iban' => $iban !== '' ? '••••'.substr($iban, -4) : null]]);
        toast(t('Request sent to VSahha'));

        return redirect('/settings?tab=requests');
    }

    public function notifyPref(Request $r)
    {
        $ctx = me();
        $type = (string) $r->input('type');
        $email = $r->boolean('email');
        $was = collect(Alerts::prefsFor($ctx->user))->firstWhere('type', $type);
        if (! Alerts::setPref($ctx->user, $type, $email)) {
            throw new PortalError(400, 'BAD_TYPE', 'Your role does not receive this kind of notification.');
        }
        $ctx->audit('notify.pref', $type, ['email' => $email], ['before' => ['email' => (bool) ($was['email'] ?? false)], 'after' => ['email' => $email]]);
        toast(t('Saved'));

        return redirect('/settings?tab=notifications');
    }

    public function security(Request $r)
    {
        $ctx = me();
        $mfa = $r->boolean('mfa');
        $before = (bool) $ctx->user->mfa;
        DB::table('users')->where('id', $ctx->user->id)->update(['mfa' => $mfa]);
        if ($before !== $mfa) {
            $ctx->audit('prefs.changed', $ctx->user->id, null, ['before' => ['mfa' => $before], 'after' => ['mfa' => $mfa]]);
        }
        toast(t('Saved'));

        return redirect('/settings?tab=security');
    }

    public function addEndpoint(Request $r)
    {
        $ctx = me();
        $ctx->need('team.manage');
        $url = trim((string) $r->input('url'));
        if ($bad = Alerts::checkUrl($url)) {
            throw new PortalError(400, 'BAD_URL', $bad);
        }
        $events = array_values(array_intersect((array) $r->input('events', []), Alerts::OUTBOUND_EVENTS));
        if (! $events) {
            throw new PortalError(400, 'NO_EVENTS', 'Choose at least one event.');
        }
        $id = portal_id('END', 4);
        $secret = 'whsec_'.rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        DB::table('endpoints')->insert(['id' => $id, 'org_id' => $ctx->user->org_id, 'url' => $url, 'secret' => encrypt($secret), 'events' => json_encode($events), 'active' => true, 'created_by' => $ctx->user->id, 'created_at' => iso_now()]);
        $ctx->audit('endpoint.added', $id, ['url' => $url, 'events' => $events], ['after' => ['url' => $url, 'events' => $events]]);

        return redirect('/settings?tab=integrations')->with('endpoint_secret', $secret); // the full secret is shown once
    }

    public function removeEndpoint(string $id)
    {
        $ctx = me();
        $ctx->need('team.manage');
        $gone = DB::table('endpoints')->where('id', $id)->where('org_id', $ctx->user->org_id)->first();
        DB::table('endpoints')->where('id', $id)->where('org_id', $ctx->user->org_id)->delete();
        $ctx->audit('endpoint.removed', $id, null, ['before' => $gone ? ['url' => $gone->url, 'events' => json_of($gone->events, [])] : null]);

        return redirect('/settings?tab=integrations');
    }

    public function testEndpoint(string $id)
    {
        $ctx = me();
        $ctx->need('team.manage');
        $x = DB::table('endpoints')->where('id', $id)->where('org_id', $ctx->user->org_id)->first() ?? throw new PortalError(404, 'NOT_FOUND', 'No such endpoint.');
        $out = Alerts::testEndpoint($x);
        toast($out['transmitted'] ? t('Test event delivered') : t('Not delivered: {why}', ['why' => (string) $out['reason']]), $out['transmitted'] ? 'green' : 'red');

        return redirect('/settings?tab=integrations');
    }
}
