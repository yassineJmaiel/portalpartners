<?php

namespace App\Http\Controllers;

use App\Portal\Crm;
use App\Portal\Ctx;
use App\Portal\Files;
use App\Portal\PortalError;
use App\Portal\Retention;
use App\Portal\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* The list, one request (journey, decisions, GOP, utilisation, one-click claim), and the request
   wizard. The benefit picker offers only what the member's plan sells; the request goes through
   the CRM's own pipeline: eligibility → benefit → authority → medical queue. */
class PreauthController extends Controller
{
    public const TABS = ['all' => 'All', 'open' => 'With VSahha', 'action' => 'Action needed', 'auth' => 'Authorised', 'declined' => 'Closed or not authorised'];

    public const STEPS = ['Member', 'Physician & urgency', 'Treatments', 'Documents', 'Review & submit'];

    private static function inTab(string $k, array $p): bool
    {
        $s = Vocab::paStage($p['status'] ?? null)['id'];

        return match ($k) {
            'open' => in_array($s, ['checking', 'medical', 'insurer'], true),
            'action' => $s === 'action',
            'auth' => in_array($s, ['authorised', 'partly'], true),
            'declined' => in_array($s, ['declined', 'cancelled', 'expired', 'closed'], true),
            default => true,
        };
    }

    public function index(Request $r)
    {
        $ctx = me();
        $ctx->need('preauth.view');
        $all = Crm::get('/v1/preauths', $ctx->crmOpts());
        $tab = isset(self::TABS[$r->query('tab')]) ? $r->query('tab') : 'all';
        $q = mb_strtolower(trim((string) $r->query('q', '')));
        $found = fn ($p) => $q === '' || str_contains(mb_strtolower(implode(' ', [$p['ref'] ?? '', $p['id'] ?? '', $p['memberName'] ?? '', $p['memberId'] ?? '', $p['presentingComplaint'] ?? '', $p['gop']['gopNumber'] ?? ''])), $q);
        $counts = [];
        foreach (array_keys(self::TABS) as $k) {
            $counts[$k] = count(array_filter($all, fn ($p) => self::inTab($k, $p) && $found($p)));
        }

        return view('preauth.index', ['rows' => array_values(array_filter($all, fn ($p) => self::inTab($tab, $p) && $found($p))), 'tab' => $tab, 'counts' => $counts,
            'drafts' => DB::table('drafts')->where('org_id', $ctx->user->org_id)->whereIn('provider_id', $ctx->scope)->where('kind', 'preauth')->orderByDesc('updated_at')->get()]);
    }

    public function show(string $id)
    {
        $ctx = me();
        $ctx->need('preauth.view');
        $p = Crm::get('/v1/preauths/'.rawurlencode($id), $ctx->crmOpts());

        return view('preauth.show', ['p' => $p, 'notes' => NotesController::panel($ctx, 'preauth', $p['id'])]);
    }

    public function reply(Request $r, string $id)
    {
        $ctx = me();
        $ctx->need('preauth.submit');
        $ctx->single();
        $docs = array_map(fn ($d) => ['ref' => $d['ref'], 'name' => $d['name'], 'type' => $r->input('docType', 'Medical report')], ClaimsController::uploaded($ctx, $r, 'files', ''));
        $documents = Files::push($ctx, $docs);
        Crm::post('/v1/preauths/'.rawurlencode($id).'/replies', ['response' => $r->input('response'), 'documents' => $documents], $ctx->crmOpts(['idempotent' => true]));
        $ctx->audit('preauth.replied', $id, ['documents' => count($documents)]);
        toast(t('Sent back to the medical team'));

        return redirect("/preauth/{$id}");
    }

    public function gopRequest(Request $r, string $id)
    {
        $ctx = me();
        $ctx->need('preauth.submit');
        $ctx->single();
        $kind = $r->input('kind') === 'amount' ? 'amount' : 'validity';
        $body = ['kind' => $kind, 'reason' => $r->input('reason'), 'newValidUntil' => $kind === 'validity' ? $r->input('newValidUntil') : null, 'newAmount' => $kind === 'amount' ? $r->input('newAmount') : null];
        $out = Crm::post('/v1/preauths/'.rawurlencode($id).'/gop-requests', $body, $ctx->crmOpts(['idempotent' => true]));
        $ctx->audit('gop.change.requested', $id, ['kind' => $kind], ['after' => ['requestId' => $out['id'], 'kind' => $kind, 'newValidUntil' => $body['newValidUntil'], 'newAmount' => $body['newAmount']]]);
        toast(t('Request sent to VSahha'));

        return redirect("/preauth/{$id}");
    }

    /** one click from an authorised pre-auth to a claim draft linked to it */
    public function toClaim(string $id)
    {
        $ctx = me();
        $ctx->need('claim.submit');
        $ctx->single();
        $pa = Crm::get('/v1/preauths/'.rawurlencode($id), $ctx->crmOpts());
        $ok = array_values(array_filter($pa['treatmentLines'], fn ($t) => in_array($t['decision'], ['Approved', 'Partially Approved'], true)));
        if (! $ok) {
            throw new PortalError(409, 'NOT_AUTHORISED', 'Nothing on this request is authorised yet.');
        }
        $data = ['memberId' => $pa['memberId'], 'memberName' => $pa['memberName'], 'type' => 'Outpatient Care', 'invoiceNo' => '', 'treatmentDate' => ($ok[0]['serviceDate'] ?? null) ?: '', 'icd' => ($ok[0]['icd10'] ?? null) ?: '', 'diagnosis' => '',
            'diagnosisCertainty' => ($pa['diagnosisCertainty'] ?? null) === Vocab::UNKNOWN_ILLNESS ? Vocab::UNKNOWN_ILLNESS : 'Confirmed', 'physicianName' => ($pa['physicianName'] ?? null) ?: '', 'preAuthId' => $pa['id'], 'preAuthRef' => $pa['ref'],
            'gopNumber' => $pa['gop']['gopNumber'] ?? null, 'documents' => [], 'idemKey' => (string) Str::uuid(),
            'lines' => array_map(fn ($t) => ['key' => (string) Str::uuid(), 'description' => $t['description'], 'code' => $t['benefitCode'], 'quantity' => ($t['approvedQuantity'] ?? null) ?: (($t['requestedQuantity'] ?? null) ?: 1),
                'claimedAmount' => $t['approvedAmount'], 'serviceDate' => ($t['serviceDate'] ?? null) ?: '', 'icd' => $t['icd10'] ?? null, 'fromGop' => true, 'documents' => []], $ok)];
        $draftId = ClaimsController::saveDraft($ctx, null, 'claim', "{$pa['memberName']} · from {$pa['ref']}", $data, 'preauth');
        $ctx->audit('claim.drafted.from.preauth', $pa['ref'], ['draftId' => $draftId]);

        return redirect("/claims/new?draft={$draftId}");
    }

    /* ------------------------------------------------------------ the wizard */

    private function blankTx(): array
    {
        return ['key' => (string) Str::uuid(), 'description' => '', 'categoryId' => '', 'benefitCode' => '', 'subBenefitCode' => '', 'cpt' => '', 'icd10' => '', 'unknown' => false, 'requestedAmount' => '', 'serviceDate' => portal_today(),
            'requestedQuantity' => '', 'requestedSessions' => '', 'requestedDays' => '', 'clinicalJustification' => ''];
    }

    private function blank(): array
    {
        return ['memberId' => '', 'memberName' => '', 'physicianName' => '', 'physicianSpecialty' => '', 'urgency' => 'Routine', 'presentingComplaint' => '', 'clinicalHistory' => '', 'treatments' => [$this->blankTx()], 'documents' => [], 'idemKey' => (string) Str::uuid()];
    }

    private function draftRow(Ctx $ctx, ?string $id): ?object
    {
        return $id ? (DB::table('drafts')->where('id', $id)->where('org_id', $ctx->user->org_id)->first() ?? throw new PortalError(404, 'NOT_FOUND', 'No such draft.')) : null;
    }

    private function body(array $d): array
    {
        $unknown = (bool) array_filter($d['treatments'], fn ($x) => ! empty($x['unknown']));
        $out = $d;
        unset($out['_elig'], $out['idemKey'], $out['memberName']);
        $out['diagnosisCertainty'] = $unknown ? Vocab::UNKNOWN_ILLNESS : 'Confirmed';
        $out['treatments'] = array_map(function ($x) {
            unset($x['key'], $x['unknown']);

            return $x;
        }, $d['treatments']);
        $out['documents'] = array_map(fn ($x) => ['type' => $x['type'] ?? 'Other', 'ref' => $x['ref'] ?? null, 'name' => $x['name'] ?? null], $d['documents']);

        return $out;
    }

    public function wizard(Request $r)
    {
        $ctx = me();
        $ctx->need('preauth.submit');
        $ctx->single();
        $row = $this->draftRow($ctx, $r->query('draft'));
        $d = $row ? json_of($row->data, []) + $this->blank() : $this->blank();
        $step = max(0, min(4, (int) $r->query('step', $row && ! empty($d['memberId']) ? 1 : 0)));
        $plan = null;
        if (! empty($d['memberId'])) {
            try {
                $plan = Crm::get('/v1/members/'.rawurlencode($d['memberId'])."/plan?providerId={$ctx->providerId}", $ctx->crmOpts());
            } catch (PortalError) {
                $plan = null;
            }
        }
        $check = null;
        $checkError = null;
        if (! empty($d['memberId'])) {
            try {
                $check = Crm::post('/v1/preauths/validate', ['providerId' => $ctx->providerId, 'draft' => $this->body($d)], $ctx->crmOpts());
            } catch (PortalError $e) {
                $checkError = $e;
            }
        }

        return view('preauth.wizard', ['d' => $d, 'step' => $step, 'draftId' => $row->id ?? null, 'lookup' => (string) old('lookup', $r->query('member', '')), 'plan' => $plan, 'check' => $check, 'checkError' => $checkError, 'elig' => $d['_elig'] ?? null]);
    }

    public function wizardStep(Request $r)
    {
        $ctx = me();
        $ctx->need('preauth.submit');
        $ctx->single();
        $row = $this->draftRow($ctx, $r->input('draftId'));
        $d = $row ? json_of($row->data, []) + $this->blank() : $this->blank();
        foreach (['physicianName', 'physicianSpecialty', 'urgency', 'presentingComplaint', 'clinicalHistory'] as $k) {
            if ($r->has("d.{$k}")) {
                $d[$k] = (string) $r->input("d.{$k}");
            }
        }
        foreach ((array) $r->input('tx', []) as $i => $x) {
            if (! isset($d['treatments'][$i])) {
                continue;
            }
            foreach (['description', 'categoryId', 'benefitCode', 'subBenefitCode', 'cpt', 'icd10', 'requestedAmount', 'serviceDate', 'requestedQuantity', 'requestedSessions', 'requestedDays', 'clinicalJustification'] as $k) {
                if (array_key_exists($k, $x)) {
                    $d['treatments'][$i][$k] = trim((string) $x[$k]);
                }
            }
            /* a category chosen with no benefit under it asks for the whole category */
            if (($d['treatments'][$i]['benefitCode'] ?? '') === '' || ! str_starts_with((string) $d['treatments'][$i]['benefitCode'], (string) $d['treatments'][$i]['categoryId'])) {
                $d['treatments'][$i]['benefitCode'] = $d['treatments'][$i]['categoryId'];
            }
            if (array_key_exists('certainty', $x)) {
                $d['treatments'][$i]['unknown'] = $x['certainty'] === Vocab::UNKNOWN_ILLNESS;
            }
        }
        foreach (ClaimsController::uploaded($ctx, $r, 'files', '') as $f) {
            $d['documents'][] = ['type' => 'Medical report', 'ref' => $f['ref'], 'name' => $f['name']];
        }
        foreach ((array) $r->input('docType', []) as $ref => $type) {
            $d['documents'] = array_map(fn ($x) => ($x['ref'] ?? null) === $ref ? array_merge($x, ['type' => $type]) : $x, $d['documents']);
        }
        $nav = (string) $r->input('nav', 'stay');
        $step = (int) $r->input('step', 0);
        if ($nav === 'find') {
            $lookup = trim((string) $r->input('lookup'));
            $ctx->need('eligibility.check');
            $ctx->rateLimit("elig:{$ctx->user->id}", (int) Retention::config('eligibilityRateLimitPerHour', 120));
            $out = Crm::post('/v1/eligibility', ['providerId' => $ctx->providerId, 'q' => $lookup, 'cardNo' => $lookup, 'serviceDate' => portal_today()], $ctx->crmOpts());
            $ctx->audit('eligibility.checked', $out['member']['id'], ['serviceDate' => $out['serviceDate'], 'eligible' => $out['eligible']]);
            $d['_elig'] = array_intersect_key($out, array_flip(['eligible', 'summary', 'serviceDate', 'checks', 'member', 'policy', 'insurer', 'network']));
            $d['memberId'] = $out['member']['id'];
            $d['memberName'] = $out['member']['name'];
        } elseif ($nav === 'addTx') {
            $d['treatments'][] = $this->blankTx();
        } elseif (str_starts_with($nav, 'removeTx:') && count($d['treatments']) > 1) {
            array_splice($d['treatments'], (int) substr($nav, 9), 1);
        } elseif (str_starts_with($nav, 'removeDoc:')) {
            $ref = substr($nav, 10);
            $d['documents'] = array_values(array_filter($d['documents'], fn ($x) => ($x['ref'] ?? null) !== $ref));
        }
        $id = $row->id ?? null;
        if (! empty($d['memberId']) || $id) {
            $id = ClaimsController::saveDraft($ctx, $id, 'preauth', (($d['memberName'] ?? null) ?: t('New request')).' · '.($d['treatments'][0]['description'] ?? ''), $d, 'manual');
        }
        if ($nav === 'submit') {
            $body = $this->body($d);
            $body['documents'] = Files::push($ctx, $body['documents']);
            $out = Crm::post('/v1/preauths', ['providerId' => $ctx->providerId, 'draft' => $body], $ctx->crmOpts(['idempotencyKey' => $d['idemKey']]));
            if ($id) {
                DB::table('drafts')->where('id', $id)->where('org_id', $ctx->user->org_id)->delete();
            }
            $ctx->audit('preauth.submitted', $out['ref'], ['treatments' => count($body['treatments']), 'urgency' => $body['urgency'] ?? 'Routine']);

            return redirect("/preauth/{$out['caseId']}/received")->with('pa_done', $out);
        }
        if ($nav === 'save') {
            toast(t('Draft saved'));
        }
        if (str_starts_with($nav, 'go:')) {
            $step = (int) substr($nav, 3);
        }

        return redirect('/preauth/new?'.http_build_query(array_filter(['draft' => $id, 'step' => $nav === 'find' ? 0 : $step], fn ($v) => $v !== null)));
    }

    public function received(string $id)
    {
        me()->need('preauth.view');

        return view('preauth.received', ['id' => $id, 'done' => session('pa_done')]);
    }
}
