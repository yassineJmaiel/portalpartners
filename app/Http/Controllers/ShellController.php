<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SecurityHeaders;
use App\Portal\Alerts;
use App\Portal\Crm;
use App\Portal\Ctx;
use App\Portal\Docs;
use App\Portal\Files;
use App\Portal\Lang;
use App\Portal\PortalError;
use App\Portal\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/* What every page shares: the branch, the bell, the search palette, the live line, the ICD-10
   picker, the file store; and the two public doors: the QR verification page and the CRM webhook. */
class ShellController extends Controller
{
    public function setProvider(Request $r)
    {
        $ctx = me();
        $id = (string) $r->input('providerId');
        if ($id === Ctx::ALL && count($ctx->providers) > 1) {
            DB::table('portal_sessions')->where('id', $ctx->session->id)->update(['provider_id' => Ctx::ALL]);

            return redirect('/');
        }
        if (! in_array($id, array_column($ctx->providers, 'id'), true)) {
            throw new PortalError(404, 'NOT_FOUND', 'No such branch in your organisation.');
        }
        DB::table('portal_sessions')->where('id', $ctx->session->id)->update(['provider_id' => $id]);
        $ctx->audit('branch.switched', $id, null, ['before' => ['providerId' => $ctx->providerId], 'after' => ['providerId' => $id]]);

        return redirect()->back();
    }

    /** the bell's rows, or with ?after=<id> only the newer ones, for the browser alerts */
    public function notifications(Request $r)
    {
        $ctx = me();
        $q = $ctx->scopeNotifications(DB::table('notifications')->where('org_id', $ctx->user->org_id));
        $rows = $r->has('after') ? $q->where('id', '>', (int) $r->query('after'))->orderBy('id')->limit(20)->get() : $q->orderByDesc('at')->limit(60)->get();

        return response()->json($rows->map(fn ($n) => ['id' => $n->id, 'title' => Lang::say($n->title), 'body' => $n->body ? Lang::say($n->body) : null, 'link' => $n->link, 'ago' => ago($n->at), 'read' => (bool) $n->read_at]));
    }

    public function notificationsRead()
    {
        $ctx = me();
        DB::table('notifications')->where('org_id', $ctx->user->org_id)->whereNull('read_at')->where(fn ($w) => $w->whereNull('user_id')->orWhere('user_id', $ctx->user->id))->update(['read_at' => iso_now()]);

        return redirect()->back();
    }

    /** the live line: pages ask every few seconds; the CRM's events have already reached the portal by webhook */
    public function live()
    {
        $ctx = me();
        $latest = $ctx->scopeNotifications(DB::table('notifications')->where('org_id', $ctx->user->org_id))->max('id');
        $row = $latest ? DB::table('notifications')->where('id', $latest)->first() : null;

        return response()->json(['unread' => $ctx->unread(), 'latest' => (int) $latest, 'title' => $row ? Lang::say($row->title) : null, 'link' => $row->link ?? null, 'up' => Crm::reachable(), 'synced' => Crm::lastOk()]);
    }

    /** the palette: the provider's own claims, pre-auths and payments; a card number goes straight to an eligibility check */
    public function search(Request $r)
    {
        $ctx = me();
        $q = trim((string) $r->query('q', ''));
        $s = mb_strtolower($q);
        $m = fn (...$v) => $s === '' || str_contains(mb_strtolower(implode(' ', array_map('strval', $v))), $s);
        $out = [];
        if ($ctx->can('eligibility.check') && (preg_match('/^v\s*s[\s.-]*\d/i', $q) || preg_match('/^mbr-\d/i', $q))) {
            $out[] = ['icon' => 'shield', 'title' => t('Check cover for {q}', ['q' => mb_strtoupper($q)]), 'sub' => t('Eligibility'), 'to' => '/eligibility?q='.urlencode($q)];
        }
        try {
            if ($ctx->roleCan('claim.view')) {
                $n = 0;
                foreach (Crm::get('/v1/claims', $ctx->crmOpts()) as $c) {
                    if ($n < 6 && $m($c['id'], $c['memberName'] ?? '', $c['invoiceNo'] ?? '', $c['icd'] ?? '')) {
                        $out[] = ['icon' => 'file', 'title' => $c['id'], 'sub' => ($c['memberName'] ?? '').' · '.($c['invoiceNo'] ?? '').' · '.Vocab::claimStatusLine($c['workflowStatus'] ?? ''), 'to' => '/claims/'.rawurlencode($c['id'])];
                        $n++;
                    }
                }
            }
            if ($ctx->roleCan('preauth.view')) {
                $n = 0;
                foreach (Crm::get('/v1/preauths', $ctx->crmOpts()) as $p) {
                    if ($n < 5 && $m($p['ref'], $p['memberName'] ?? '', $p['gop']['gopNumber'] ?? '')) {
                        $out[] = ['icon' => 'clip', 'title' => $p['ref'], 'sub' => ($p['memberName'] ?? '').(! empty($p['gop']) ? " · {$p['gop']['gopNumber']}" : '').' · '.($p['status'] ?? ''), 'to' => '/preauth/'.rawurlencode($p['id'])];
                        $n++;
                    }
                }
            }
            if ($ctx->roleCan('payment.view')) {
                $n = 0;
                foreach (Crm::get('/v1/payments', $ctx->crmOpts()) as $p) {
                    if ($n < 4 && $m($p['reference'], ...array_column($p['claims'], 'id'), ...array_column($p['claims'], 'memberName'))) {
                        $out[] = ['icon' => 'bank', 'title' => $p['reference'], 'sub' => "{$p['amount']} {$p['currency']} · {$p['paymentDate']}", 'to' => '/payments/'.rawurlencode($p['reference'])];
                        $n++;
                    }
                }
            }
        } catch (PortalError) {
            // the search still offers what it can while the CRM is away
        }
        foreach ([['shield', "Check a patient's cover", '/eligibility', 'eligibility.check'], ['plus', 'New claim', '/claims/new', 'claim.submit'], ['stetho', 'Request pre-authorization', '/preauth/new', 'preauth.submit'], ['upload', 'Upload invoices', '/intake', 'intake.use']] as [$ic, $title, $to, $cap]) {
            if ($ctx->can($cap) && $m(t($title))) {
                $out[] = ['icon' => $ic, 'title' => t($title), 'sub' => t('Action'), 'to' => $to];
            }
        }
        foreach ($out as &$row) {
            $row['icon'] = (string) icon($row['icon'], 15);
        }

        return response()->json(array_slice($out, 0, 14));
    }

    public function icd10(Request $r)
    {
        $ctx = me();
        $q = trim((string) $r->query('q', ''));
        $out = Crm::get('/v1/icd10?q='.rawurlencode($q), $ctx->crmOpts());
        $code = Vocab::normIcd($q);
        /* a well-formed code the catalogue does not list may still be used; the medical team checks it */
        if (Vocab::isIcd($code) && ! in_array($code, array_column($out['items'], 'code'), true)) {
            array_unshift($out['items'], ['code' => $code, 'title' => 'Not in the VSahha catalogue — the medical team will check it', 'source' => 'typed']);
        }

        return response()->json($out);
    }

    public function file(Request $r, string $ref)
    {
        $f = Files::meta($ref, me()->user->org_id) ?? throw new PortalError(404, 'NOT_FOUND', 'No such file.');
        /* a download manager on the computer can take a PDF away from the page (it answers 204 in the server's place): the preview can ask for the bytes as text */
        if ($r->query('as') === 'data') {
            return response()->json(['mime' => $f->mime, 'data' => base64_encode(Files::read($f->ref))]);
        }
        $shown = (bool) preg_match('#^(application/pdf|image/(png|jpeg|webp|gif))$#', (string) $f->mime);

        /* a sandboxed response cannot open in the browser's PDF viewer; a PDF has no page script to contain, and nosniff keeps it a PDF */
        $policy = $f->mime === 'application/pdf' ? "default-src 'none'; style-src 'unsafe-inline'; object-src 'self'; frame-ancestors 'self'" : 'sandbox';

        return response(Files::read($f->ref), 200, ['Content-Type' => $shown ? $f->mime : 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => $policy,
            'Content-Disposition' => ($shown ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($f->name)]);
    }

    public function feedback(Request $r)
    {
        $ctx = me();
        $rating = in_array($r->input('rating'), ['easy', 'ok', 'hard'], true) ? $r->input('rating') : null;
        $body = mb_substr(trim((string) $r->input('body')), 0, 2000);
        if (! $rating && $body === '') {
            throw new PortalError(400, 'EMPTY', 'Choose how it went, or write a few words.');
        }
        $ctx->rateLimit("feedback:{$ctx->user->id}", 30);
        $page = mb_substr(preg_replace('/[^#\w\/?=&.-]/', '', (string) $r->input('page')), 0, 120);
        DB::table('feedback')->insert(['org_id' => $ctx->user->org_id, 'user_id' => $ctx->user->id, 'role' => $ctx->user->role, 'page' => $page, 'rating' => $rating, 'body' => $body ?: null, 'language' => $ctx->user->language, 'at' => iso_now()]);
        $ctx->audit('feedback.sent', $page, ['rating' => $rating]);
        toast(t('Thank you — your feedback was sent.'));

        return redirect()->back();
    }

    /* ------------------------------------------------------------ public doors */

    /** behind every QR code: validity only */
    public function verify(string $ref)
    {
        if (preg_match('/^ELG-/', $ref)) {
            $e = DB::table('eligibility_checks')->where('id', $ref)->first(['id', 'at', 'service_date', 'eligible']);
            $out = $e ? ['ref' => $ref, 'kind' => 'Eligibility certificate', 'valid' => true, 'issuedAt' => $e->at, 'note' => 'Cover was '.($e->eligible ? 'confirmed' : 'not confirmed')." for {$e->service_date}."] : ['ref' => $ref, 'valid' => false];
        } else {
            try {
                $out = Crm::get('/v1/verify/'.rawurlencode($ref));
            } catch (PortalError) {
                $out = ['ref' => $ref, 'valid' => false, 'unreachable' => true];
            }
        }

        return response(Docs::verifyPage($ref, $out));
    }

    /** for the hosting platform's health check: no personal data, no session */
    public function health()
    {
        $up = Crm::reachable();
        $failed = DB::table('portal_jobs')->where('status', 'failed')->where('updated_at', '>', gmdate('Y-m-d\TH:i:s.v\Z', time() - 86400))->count();

        return response()->json(['ok' => $up, 'portal' => 'up', 'crm' => $up ? 'reachable' : 'unreachable', 'failedJobsLastDay' => $failed, 'demo' => (bool) config('portal.demo')], $up ? 200 : 503);
    }

    /** a CRM event: signed, not replayable, handled once */
    public function webhook(Request $r)
    {
        $raw = $r->getContent();
        $sig = (string) $r->header('X-VSahha-Signature');
        $t = preg_match('/t=(\d+)/', $sig, $m) ? (int) $m[1] : 0;
        $v1 = preg_match('/v1=([a-f0-9]{64})/', $sig, $m) ? $m[1] : '';
        $secret = (string) config('portal.webhook_secret');
        if ($secret === '' || ! $t || $v1 === '' || abs(time() - $t) > 300 || ! hash_equals(hash_hmac('sha256', "{$t}.{$raw}", $secret), $v1)) {
            return response()->json(['code' => 'BAD_SIGNATURE', 'message' => 'The webhook signature is not valid.'], 401);
        }
        $e = json_decode($raw, true);
        if (! is_array($e) || empty($e['id'])) {
            return response()->json(['code' => 'BAD_JSON'], 400);
        }
        if (DB::table('webhook_inbox')->where('event_id', $e['id'])->exists()) {
            return response()->json(['ok' => true, 'duplicate' => true]);
        }
        DB::table('webhook_inbox')->insert(['event_id' => $e['id'], 'type' => (string) ($e['type'] ?? ''), 'provider_id' => $e['providerId'] ?? null, 'received_at' => iso_now(), 'payload' => $raw]);
        Alerts::raise($e, Alerts::notificationFor($e)); // bell, e-mail by preference, the provider's own systems

        return response()->json(['ok' => true]);
    }
}
