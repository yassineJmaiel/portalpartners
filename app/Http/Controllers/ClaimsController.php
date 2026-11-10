<?php

namespace App\Http\Controllers;

use App\Portal\Crm;
use App\Portal\Csv;
use App\Portal\Ctx;
use App\Portal\Docs;
use App\Portal\Files;
use App\Portal\Intake\Intake;
use App\Portal\PortalError;
use App\Portal\Retention;
use App\Portal\Vocab;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/* The list by stage; one claim: where it is, what was decided on each line and why, what
   VSahha needs, the money, the documents, the thread. And the five-step wizard ending in the
   Clean Claim Check: the CRM's own assessment of the claim before it exists. A claim's draft is
   saved on every step, so the wizard is plain forms and a page can always be reopened. */
class ClaimsController extends Controller
{
    public const TABS = ['all' => 'All', 'action' => 'Action needed', 'review' => 'In review', 'payment' => 'Payment', 'paid' => 'Paid', 'decided' => 'Decided or closed', 'drafts' => 'Drafts'];

    public const STEPS = ['Patient', 'Encounter', 'Service lines', 'Documents', 'Check & submit'];

    public const TOP_MARKS = ['member', 'type', 'invoiceNo', 'treatmentDate', 'admissionDate', 'dischargeDate', 'physicianName', 'icd'];

    public const LINE_MARKS = ['description', 'quantity', 'amount', 'tariffCode'];

    private static function inTab(string $tab, array $c): bool
    {
        $s = Vocab::claimStageId($c['workflowStatus'] ?? null);

        return match ($tab) {
            'all' => true,
            'review' => in_array($s, ['received', 'review', 'medical'], true),
            'payment' => $s === 'scheduled',
            'decided' => in_array($s, ['decided', 'closed'], true),
            default => $s === $tab,
        };
    }

    /** the amount a claim is filtered on is the one the list shows */
    private static function shown(array $c): float
    {
        return (float) (! empty($c['money']) ? ($c['money']['paid'] ?? $c['money']['insurerPayable'] ?? 0) : ($c['claimed'] ?? 0));
    }

    private function matcher(Request $r): callable
    {
        $q = mb_strtolower(trim((string) $r->query('q', '')));
        $from = (string) $r->query('from', '');
        $to = (string) $r->query('to', '');
        $min = (string) $r->query('min', '');
        $max = (string) $r->query('max', '');

        return function (array $c) use ($q, $from, $to, $min, $max) {
            if ($q !== '') {
                $hay = mb_strtolower(implode(' ', array_map(fn ($k) => (string) ($c[$k] ?? ''), ['id', 'memberName', 'memberId', 'invoiceNo', 'icd', 'diagnosis'])));
                if (! str_contains($hay, $q)) {
                    return false;
                }
            }
            $t = (string) ($c['treatmentDate'] ?? '');

            return ! (($from !== '' && $t < $from) || ($to !== '' && $t > $to) || ($min !== '' && self::shown($c) < (float) $min) || ($max !== '' && self::shown($c) > (float) $max));
        };
    }

    public function index(Request $r)
    {
        $ctx = me();
        $ctx->need('claim.view');
        $claims = Crm::get('/v1/claims', $ctx->crmOpts());
        $drafts = $this->drafts($ctx, 'claim');
        $stage = (string) $r->query('stage', '');
        $initial = (string) ($r->query('tab') ?: ($stage === 'scheduled' ? 'payment' : ($stage === 'medical' ? 'review' : ($stage ?: 'all'))));
        $tab = isset(self::TABS[$initial]) ? $initial : 'all';
        $matches = $this->matcher($r);
        $rows = array_values(array_filter($claims, fn ($c) => self::inTab($tab === 'drafts' ? 'all' : $tab, $c) && $matches($c)));
        $counts = [];
        foreach (array_keys(self::TABS) as $k) {
            $counts[$k] = $k === 'drafts' ? count($drafts) : count(array_filter($claims, fn ($c) => self::inTab($k, $c) && $matches($c)));
        }

        return view('claims.index', compact('rows', 'drafts', 'tab', 'counts') + ['filtered' => (bool) array_filter($r->only(['q', 'from', 'to', 'min', 'max']), fn ($v) => $v !== null && $v !== '')]);
    }

    public function export(Request $r)
    {
        $ctx = me();
        $ctx->need('claim.view');
        $tab = (string) $r->query('tab', 'all');
        $matches = $this->matcher($r);
        $rows = array_filter(Crm::get('/v1/claims', $ctx->crmOpts()), fn ($c) => self::inTab(isset(self::TABS[$tab]) && $tab !== 'drafts' ? $tab : 'all', $c) && $matches($c));
        $esc = fn ($v) => '"'.str_replace('"', '""', (string) ($v ?? '')).'"';
        $out = [$esc("Exported by {$ctx->user->name} (".$ctx->organisation()->name.') on '.iso_now().' — confidential'),
            implode(',', array_map($esc, ['Claim', 'Invoice', 'Patient', 'Treated', 'Status', 'Decision', 'Claimed', 'Approved', 'Payable', 'Paid', 'Currency']))];
        foreach ($rows as $c) {
            $out[] = implode(',', array_map($esc, [$c['id'], $c['invoiceNo'] ?? '', $c['memberName'] ?? '', $c['treatmentDate'] ?? '', Vocab::claimStatusLine($c['workflowStatus'] ?? ''), $c['decisionStatus'] ?? '', $c['claimed'] ?? '',
                $c['money']['approved'] ?? '', $c['money']['insurerPayable'] ?? '', $c['money']['paid'] ?? '', $c['currency'] ?? '']));
        }
        $ctx->audit('claims.exported', null, ['rows' => count($rows)]);

        return response(implode("\n", $out), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="claims-'.portal_today().'.csv"']);
    }

    private function drafts(Ctx $ctx, string $kind): array
    {
        return DB::table('drafts as d')->leftJoin('users as u', 'u.id', '=', 'd.created_by')->where('d.org_id', $ctx->user->org_id)->whereIn('d.provider_id', $ctx->scope)->where('d.kind', $kind)
            ->orderByDesc('d.updated_at')->get(['d.id', 'd.kind', 'd.title', 'd.source', 'd.updated_at', 'u.name as by'])->all();
    }

    public function show(string $id)
    {
        $ctx = me();
        $ctx->need('claim.view');
        $c = Crm::get('/v1/claims/'.rawurlencode($id), $ctx->crmOpts());

        return view('claims.show', ['c' => $c, 'notes' => NotesController::panel($ctx, 'claim', $c['id'])]);
    }

    public function received(string $id)
    {
        me()->need('claim.view');

        return view('claims.received', ['id' => $id, 'unknown' => session('claim_unknown')]);
    }

    /** what VSahha received, and its target date for a decision */
    public function acknowledgement(string $id)
    {
        $ctx = me();
        $ctx->need('claim.view');
        $c = Crm::get('/v1/claims/'.rawurlencode($id), $ctx->crmOpts());
        $provider = Crm::get("/v1/providers/{$c['providerId']}", $ctx->crmOpts());
        $ctx->audit('document.viewed', "acknowledgement:{$c['id']}");

        return response(Docs::acknowledgement($c, $provider))->header('Content-Security-Policy', SecurityHeaders::DOC_CSP);
    }

    public function reply(Request $r, string $id)
    {
        $ctx = me();
        $ctx->need('claim.submit');
        $ctx->single();
        $docs = self::uploaded($ctx, $r, 'files', 'line_other');
        $documents = Files::push($ctx, $docs);
        Crm::post('/v1/claims/'.rawurlencode($id).'/replies', ['message' => $r->input('message'), 'documents' => $documents], $ctx->crmOpts(['idempotent' => true]));
        $ctx->audit('claim.replied', $id, ['documents' => count($documents)]);
        toast(t('Sent. The claim is back with VSahha.'));

        return redirect("/claims/{$id}");
    }

    public function withdraw(string $id)
    {
        $ctx = me();
        $ctx->need('claim.submit');
        $ctx->single();
        $before = Crm::get('/v1/claims/'.rawurlencode($id), $ctx->crmOpts());
        Crm::post('/v1/claims/'.rawurlencode($id).'/withdraw', ['reason' => 'Withdrawn by the provider'], $ctx->crmOpts());
        $ctx->audit('claim.withdrawn', $id, ['reason' => 'Withdrawn by the provider'], ['before' => ['workflowStatus' => $before['workflowStatus']], 'after' => ['workflowStatus' => 'Cancelled']]);
        toast(t('Withdrawn'));

        return redirect("/claims/{$id}");
    }

    /** files chosen on a form: scanned and kept in the portal; they reach the CRM only with the record that cites them */
    public static function uploaded(Ctx $ctx, Request $r, string $field, string $docId): array
    {
        $out = [];
        foreach (array_filter(array_merge([], (array) $r->file($field))) as $file) {
            $f = Files::accept($ctx, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
            $out[] = ['id' => $docId, 'ref' => $f['ref'], 'name' => $f['name']];
        }

        return $out;
    }

    /* ------------------------------------------------------------ drafts */

    public static function saveDraft(Ctx $ctx, ?string $id, string $kind, string $title, array $data, string $source): string
    {
        $exists = $id && DB::table('drafts')->where('id', $id)->where('org_id', $ctx->user->org_id)->exists();
        $id = $exists ? $id : portal_id('DRF');
        $row = ['title' => mb_substr($title, 0, 200), 'data' => json_encode($data, JSON_UNESCAPED_UNICODE), 'updated_at' => iso_now()];
        if ($exists) {
            DB::table('drafts')->where('id', $id)->update($row);
        } else {
            DB::table('drafts')->insert($row + ['id' => $id, 'org_id' => $ctx->user->org_id, 'provider_id' => $ctx->providerId, 'kind' => $kind, 'source' => $source, 'created_by' => $ctx->user->id, 'created_at' => iso_now()]);
        }

        return $id;
    }

    public function deleteDraft(string $id)
    {
        $ctx = me();
        $d = DB::table('drafts')->where('id', $id)->where('org_id', $ctx->user->org_id)->first();
        if ($d) {
            $ctx->need($d->kind === 'preauth' ? 'preauth.submit' : 'claim.submit');
            DB::table('drafts')->where('id', $d->id)->delete();
            $ctx->audit('draft.deleted', $d->id, ['kind' => $d->kind], ['before' => ['title' => $d->title]]);
        }

        return redirect()->back();
    }

    private function blankLine(): array
    {
        return ['key' => (string) Str::uuid(), 'description' => '', 'code' => '', 'tariffCode' => '', 'quantity' => 1, 'claimedAmount' => '', 'serviceDate' => '', 'gopId' => null, 'documents' => []];
    }

    private function draftRow(Ctx $ctx, ?string $id): ?object
    {
        if (! $id) {
            return null;
        }

        return DB::table('drafts')->where('id', $id)->where('org_id', $ctx->user->org_id)->first() ?? throw new PortalError(404, 'NOT_FOUND', 'No such draft.');
    }

    private function title(array $d): string
    {
        return (($d['memberName'] ?? null) ?: (($d['memberId'] ?? null) ?: t('New patient'))).' · '
            .(($d['diagnosisCertainty'] ?? null) === Vocab::UNKNOWN_ILLNESS ? Vocab::UNKNOWN_ILLNESS : (($d['icd'] ?? null) ?: t('no diagnosis'))).(! empty($d['invoiceNo']) ? " · {$d['invoiceNo']}" : '');
    }

    /** what is sent to the CRM: only the claim's own fields */
    public static function draftBody(array $d): array
    {
        $doc = fn ($x) => ['id' => $x['id'] ?? null, 'ref' => $x['ref'] ?? null, 'name' => $x['name'] ?? null];

        return ['memberId' => $d['memberId'] ?? '', 'type' => $d['type'] ?? null, 'invoiceNo' => $d['invoiceNo'] ?? '', 'treatmentDate' => $d['treatmentDate'] ?? '', 'admissionDate' => $d['admissionDate'] ?? null, 'dischargeDate' => $d['dischargeDate'] ?? null,
            'physicianName' => $d['physicianName'] ?? null, 'icd' => $d['icd'] ?? null, 'diagnosis' => $d['diagnosis'] ?? null, 'secondaryIcd' => $d['secondaryIcd'] ?? [], 'diagnosisCertainty' => $d['diagnosisCertainty'] ?? 'Confirmed',
            'unknownReason' => $d['unknownReason'] ?? null, 'unknownSnippet' => $d['unknownSnippet'] ?? null, 'preAuthId' => $d['preAuthId'] ?? null, 'intakeId' => $d['intakeId'] ?? null,
            'documents' => array_map($doc, $d['documents'] ?? []),
            'lines' => array_map(fn ($l) => ['description' => $l['description'] ?? '', 'code' => $l['code'] ?? '', 'tariffCode' => ($l['tariffCode'] ?? null) ?: null, 'quantity' => (float) ($l['quantity'] ?? 0) ?: 1,
                'claimedAmount' => (float) ($l['claimedAmount'] ?? 0), 'serviceDate' => ($l['serviceDate'] ?? null) ?: ($d['treatmentDate'] ?? ''), 'gopId' => ($l['gopId'] ?? null) ?: null, 'icd' => ($l['icd'] ?? null) ?: null,
                'documents' => array_map($doc, $l['documents'] ?? [])], $d['lines'] ?? [])];
    }

    /** what a person did with each value a reader filled in: kept in the audit trail */
    public static function aiReview(array $d): array
    {
        $out = ['confirmed' => [], 'edited' => [], 'unconfirmed' => []];
        $held = ['member' => $d['memberId'] ?? null, 'type' => $d['type'] ?? null, 'invoiceNo' => $d['invoiceNo'] ?? null, 'treatmentDate' => $d['treatmentDate'] ?? null, 'admissionDate' => $d['admissionDate'] ?? null,
            'dischargeDate' => $d['dischargeDate'] ?? null, 'physicianName' => $d['physicianName'] ?? null, 'icd' => $d['icd'] ?? null];
        $note = function (string $name, $read, $now, bool $confirmed) use (&$out) {
            if ($read === null || $read === '') {
                return;
            }
            if ((string) $read !== (string) ($now ?? '')) {
                $out['edited'][] = $name;
            } else {
                $out[$confirmed ? 'confirmed' : 'unconfirmed'][] = $name;
            }
        };
        foreach (self::TOP_MARKS as $k) {
            if (! empty($d['aiFields'][$k])) {
                $note($k, $d['aiFields'][$k]['value'] ?? null, $held[$k], in_array($k, $d['aiConfirmed'] ?? [], true));
            }
        }
        foreach ($d['lines'] ?? [] as $i => $l) {
            if (! empty($l['ai'])) {
                $vals = ['description' => $l['description'] ?? '', 'quantity' => (float) ($l['quantity'] ?? 0), 'amount' => (float) ($l['claimedAmount'] ?? 0), 'tariffCode' => ($l['tariffCode'] ?? null) ?: null];
                foreach (self::LINE_MARKS as $k) {
                    if (isset($l['ai'][$k])) {
                        $note('line '.($i + 1)." {$k}", is_numeric($l['ai'][$k]) ? (float) $l['ai'][$k] : $l['ai'][$k], $vals[$k], in_array($k, $l['aiConfirmed'] ?? [], true));
                    }
                }
            }
        }

        return $out;
    }

    /* ------------------------------------------------------------ the wizard */

    public function wizard(Request $r)
    {
        $ctx = me();
        $ctx->need('claim.submit');
        $ctx->single();
        $row = $this->draftRow($ctx, $r->query('draft'));
        $d = $row ? json_of($row->data, []) : ['memberId' => '', 'memberName' => '', 'type' => 'Outpatient Care', 'invoiceNo' => '', 'treatmentDate' => portal_today(), 'icd' => '', 'diagnosis' => '',
            'diagnosisCertainty' => 'Confirmed', 'documents' => [], 'lines' => [$this->blankLine()]];
        $d['type'] = ($d['type'] ?? null) ?: 'Outpatient Care';
        $d['documents'] ??= [];
        $d['lines'] = array_values(array_map(fn ($l) => array_merge($this->blankLine(), $l), ($d['lines'] ?? null) ?: [$this->blankLine()]));
        $step = max(0, min(4, (int) $r->query('step', $row && ! empty($d['memberId']) ? 1 : 0)));
        $lookup = (string) old('lookup', $r->query('member', ''));

        $plan = null;
        $visits = [];
        $tariffs = [];
        if (! empty($d['memberId'])) {
            try {
                $plan = Crm::get('/v1/members/'.rawurlencode($d['memberId'])."/plan?providerId={$ctx->providerId}", $ctx->crmOpts());
            } catch (PortalError) {
                $plan = null;
            }
            $visits = DB::table('check_ins')->where('provider_id', $ctx->providerId)->where('org_id', $ctx->user->org_id)->where('member_id', $d['memberId'])->orderByDesc('at')->limit(20)->get()->all();
        }
        try {
            foreach (Crm::get("/v1/providers/{$ctx->providerId}/contracts", $ctx->crmOpts()) as $c) {
                if (($c['status'] ?? null) === 'Active') {
                    $tariffs = [...$tariffs, ...($c['lines'] ?? [])];
                }
            }
        } catch (PortalError) {
            $tariffs = [];
        }
        /* the Clean Claim Check, refreshed as the claim takes shape */
        $check = null;
        $checkError = null;
        if (! empty($d['memberId'])) {
            try {
                $check = Crm::post('/v1/claims/validate', ['providerId' => $ctx->providerId, 'draft' => self::draftBody($d)], $ctx->crmOpts());
            } catch (PortalError $e) {
                $checkError = $e;
            }
        }
        $visit = null;
        foreach ($visits as $v) {
            if ($v->day === ($d['treatmentDate'] ?? null)) {
                $visit = $v;
                break;
            }
        }

        return view('claims.wizard', ['d' => $d, 'step' => $step, 'draftId' => $row->id ?? null, 'savedAt' => $row->updated_at ?? null, 'lookup' => $lookup, 'plan' => $plan, 'tariffs' => $tariffs, 'check' => $check, 'checkError' => $checkError,
            'visit' => $visit, 'elig' => $d['_elig'] ?? null, 'review' => ! empty($d['intakeId']) ? self::aiReview($d) : null]);
    }

    private function applyForm(Ctx $ctx, Request $r, array $d): array
    {
        foreach (['type', 'invoiceNo', 'treatmentDate', 'admissionDate', 'dischargeDate', 'physicianName', 'icd', 'diagnosis', 'diagnosisCertainty', 'unknownReason'] as $k) {
            if ($r->has("d.{$k}")) {
                $d[$k] = trim((string) $r->input("d.{$k}"));
            }
        }
        if (array_key_exists('icd', $d) && ($d['diagnosisCertainty'] ?? 'Confirmed') !== Vocab::UNKNOWN_ILLNESS && $r->has('d.icd') && ! $r->filled('d.diagnosis')) {
            $d['diagnosis'] = $d['icd'];
        }
        if ($r->has('d.icd') && ($d['diagnosisCertainty'] ?? null) === Vocab::UNKNOWN_ILLNESS) {
            $d['unknownReason'] = ($d['unknownReason'] ?? null) ?: 'low-confidence';
            $d['diagnosis'] = Vocab::UNKNOWN_ILLNESS;
        }
        if ($r->filled('secondary_add')) {
            $code = Vocab::normIcd($r->input('secondary_add'));
            if (Vocab::isIcd($code) && $code !== ($d['icd'] ?? null) && ! in_array($code, $d['secondaryIcd'] ?? [], true)) {
                $d['secondaryIcd'] = [...($d['secondaryIcd'] ?? []), $code];
            }
        }
        foreach ((array) $r->input('lines', []) as $i => $l) {
            if (! isset($d['lines'][$i])) {
                continue;
            }
            foreach (['code', 'description', 'quantity', 'claimedAmount', 'tariffCode', 'serviceDate', 'icd', 'gopId'] as $k) {
                if (array_key_exists($k, $l)) {
                    $d['lines'][$i][$k] = trim((string) $l[$k]);
                }
            }
        }
        /* documents chosen on this step: a required one replaces what was held for it */
        foreach ((array) $r->file('doc', []) as $id => $file) {
            if ($file) {
                $f = Files::accept($ctx, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
                $d['documents'] = [...array_values(array_filter($d['documents'] ?? [], fn ($x) => ($x['id'] ?? null) !== $id)), ['id' => $id, 'ref' => $f['ref'], 'name' => $f['name']]];
            }
        }
        foreach ((array) $r->file('linedoc', []) as $i => $byId) {
            foreach ((array) $byId as $id => $file) {
                if ($file && isset($d['lines'][$i])) {
                    $f = Files::accept($ctx, (string) file_get_contents($file->getRealPath()), $file->getClientOriginalName());
                    $d['lines'][$i]['documents'] = [...array_values(array_filter($d['lines'][$i]['documents'] ?? [], fn ($x) => ($x['id'] ?? null) !== $id)), ['id' => $id, 'ref' => $f['ref'], 'name' => $f['name']]];
                }
            }
        }
        foreach (self::uploaded($ctx, $r, 'other', 'lab_results') as $x) {
            $d['documents'][] = $x;
        }
        foreach ((array) $r->input('otherType', []) as $ref => $id) {
            $d['documents'] = array_map(fn ($x) => ($x['ref'] ?? null) === $ref ? array_merge($x, ['id' => $id]) : $x, $d['documents'] ?? []);
        }

        return $d;
    }

    /** a member's cover on the treatment date; recorded once, when the member or the date changes */
    private function cover(Ctx $ctx, array $d, array $ref): array
    {
        $ctx->need('eligibility.check');
        $ctx->rateLimit("elig:{$ctx->user->id}", (int) Retention::config('eligibilityRateLimitPerHour', 120));
        $out = Crm::post('/v1/eligibility', array_filter($ref + ['providerId' => $ctx->providerId, 'serviceDate' => ($d['treatmentDate'] ?? null) ?: portal_today()]), $ctx->crmOpts());
        $id = portal_id('ELG');
        DB::table('eligibility_checks')->insert(['id' => $id, 'at' => iso_now(), 'user_id' => $ctx->user->id, 'provider_id' => $ctx->providerId, 'member_id' => $out['member']['id'], 'member_name' => $out['member']['name'],
            'service_date' => $out['serviceDate'], 'eligible' => (bool) $out['eligible'], 'summary' => $out['summary'],
            'snapshot' => json_encode(['member' => $out['member'], 'policy' => $out['policy'], 'insurer' => $out['insurer'], 'checks' => $out['checks'], 'network' => $out['network'], 'enrolledFrom' => $out['enrolledFrom'] ?? null], JSON_UNESCAPED_UNICODE)]);
        $ctx->audit('eligibility.checked', $out['member']['id'], ['serviceDate' => $out['serviceDate'], 'eligible' => $out['eligible']]);

        return array_intersect_key($out, array_flip(['eligible', 'summary', 'serviceDate', 'checks', 'member', 'policy', 'insurer', 'network'])) + ['checkId' => $id];
    }

    public function wizardStep(Request $r)
    {
        $ctx = me();
        $ctx->need('claim.submit');
        $ctx->single();
        $row = $this->draftRow($ctx, $r->input('draftId'));
        $d = $row ? json_of($row->data, []) : ['memberId' => '', 'memberName' => '', 'type' => 'Outpatient Care', 'invoiceNo' => '', 'treatmentDate' => portal_today(), 'icd' => '', 'diagnosis' => '',
            'diagnosisCertainty' => 'Confirmed', 'documents' => [], 'lines' => [$this->blankLine()], 'idemKey' => (string) Str::uuid()];
        $d['lines'] = array_values(($d['lines'] ?? null) ?: [$this->blankLine()]);
        $wasDate = $d['treatmentDate'] ?? null;
        $d = $this->applyForm($ctx, $r, $d);
        $nav = (string) $r->input('nav', 'stay');
        $step = (int) $r->input('step', 0);

        if ($nav === 'find') {
            $lookup = trim((string) $r->input('lookup'));
            $d['treatmentDate'] = $r->input('d.treatmentDate', $d['treatmentDate'] ?? portal_today());
            $cover = $this->cover($ctx, $d, ['q' => $lookup, 'cardNo' => $lookup]);
            $d['_elig'] = $cover;
            $d['memberId'] = $cover['member']['id'];
            $d['memberName'] = $cover['member']['name'];
        } elseif (! empty($d['memberId']) && $wasDate !== ($d['treatmentDate'] ?? null)) {
            $d['_elig'] = $this->cover($ctx, $d, ['memberId' => $d['memberId']]);
        }

        if ($nav === 'addLine') {
            $d['lines'][] = $this->blankLine();
        } elseif (str_starts_with($nav, 'removeLine:') && count($d['lines']) > 1) {
            array_splice($d['lines'], (int) substr($nav, 11), 1);
        } elseif (str_starts_with($nav, 'removeDoc:')) {
            $ref = substr($nav, 10);
            $d['documents'] = array_values(array_filter($d['documents'] ?? [], fn ($x) => ($x['ref'] ?? null) !== $ref));
        } elseif (str_starts_with($nav, 'removeSecondary:')) {
            $code = substr($nav, 16);
            $d['secondaryIcd'] = array_values(array_filter($d['secondaryIcd'] ?? [], fn ($x) => $x !== $code));
        } elseif (str_starts_with($nav, 'confirm:')) {
            $d['aiConfirmed'] = [...($d['aiConfirmed'] ?? []), substr($nav, 8)];
        } elseif (preg_match('/^confirmLine:(\d+):(\w+)$/', $nav, $m) && isset($d['lines'][$m[1]])) {
            $d['lines'][$m[1]]['aiConfirmed'] = [...($d['lines'][$m[1]]['aiConfirmed'] ?? []), $m[2]];
        } elseif ($nav === 'confirmAll') {
            $d['aiConfirmed'] = array_values(array_filter(self::TOP_MARKS, fn ($k) => ! empty($d['aiFields'][$k])));
            $d['lines'] = array_map(fn ($l) => array_merge($l, ['aiConfirmed' => self::LINE_MARKS]), $d['lines']);
        } elseif ($nav === 'known') {
            $d['diagnosisCertainty'] = 'Confirmed';
            $d['unknownReason'] = null;
            $d['diagnosis'] = $d['icd'] ?? '';
        } elseif (str_starts_with($nav, 'suggest:')) {
            foreach ($d['dxSuggestions'] ?? [] as $s) {
                if (($s['code'] ?? null) === substr($nav, 8)) {
                    $d['icd'] = $s['code'];
                    $d['diagnosis'] = ($s['title'] ?? null) ?: $s['code'];
                    $d['diagnosisCertainty'] = 'Confirmed';
                }
            }
        }

        $source = ! empty($d['intakeId']) ? 'intake' : (! empty($d['preAuthId']) ? 'preauth' : 'manual');
        $d['idemKey'] ??= (string) Str::uuid();
        $id = $row->id ?? null;
        if (! empty($d['memberId']) || $id) {
            $id = self::saveDraft($ctx, $id, 'claim', $this->title($d), $d, $source);
        }

        if ($nav === 'submit') {
            return $this->submit($ctx, $d, $id, $r);
        }
        if ($nav === 'save') {
            toast(t('Draft saved'));
        }
        if (str_starts_with($nav, 'go:')) {
            $step = (int) substr($nav, 3);
        }
        if ($nav === 'find') {
            $step = 0;
        }

        return redirect('/claims/new?'.http_build_query(array_filter(['draft' => $id, 'step' => $step], fn ($v) => $v !== null)));
    }

    private function submit(Ctx $ctx, array $d, ?string $draftId, Request $r)
    {
        $body = self::draftBody($d);
        if (! $r->boolean('ack')) {
            $check = Crm::post('/v1/claims/validate', ['providerId' => $ctx->providerId, 'draft' => $body], $ctx->crmOpts());
            if (array_filter($check['issues'] ?? [], fn ($i) => $i['severity'] === 'warn') && $check['canSubmit']) {
                throw new PortalError(422, 'ACK_NEEDED', 'Read the points to check, and tick the box to send the claim as it is.');
            }
        }
        $body['documents'] = Files::push($ctx, $body['documents']);
        $body['lines'] = array_map(fn ($l) => array_merge($l, ['documents' => Files::push($ctx, $l['documents'])]), $body['lines']);
        /* presence comes from this portal's own check-in record, never from the browser */
        $visit = DB::table('check_ins')->where('provider_id', $ctx->providerId)->where('org_id', $ctx->user->org_id)->where('member_id', (string) $body['memberId'])->where('day', (string) $body['treatmentDate'])->first();
        $body['presence'] = $visit ? ['checkInId' => $visit->id, 'method' => $visit->method, 'at' => $visit->at, 'consentAt' => $visit->consent_at ?: null] : null;
        /* the signals come from what this portal found on the documents, never from the browser */
        $body['qualitySignals'] = Intake::signalsForClaim($ctx, $draftId, $body['memberId'], $body['intakeId'] ?? null);
        $out = Crm::post('/v1/claims', ['providerId' => $ctx->providerId, 'draft' => $body], $ctx->crmOpts(['idempotencyKey' => $d['idemKey']]));
        if ($draftId) {
            DB::table('drafts')->where('id', $draftId)->where('org_id', $ctx->user->org_id)->delete();
        }
        /* what the person did with each value Smart Intake filled in: confirmed, edited or left */
        $ctx->audit('claim.submitted', $out['claimId'], ['invoiceNo' => $body['invoiceNo'], 'lines' => count($body['lines']), 'diagnosisCertainty' => $body['diagnosisCertainty'], 'qualitySignals' => count($body['qualitySignals']),
            'aiReview' => ! empty($d['intakeId']) ? self::aiReview($d) : null], ['after' => ['claimId' => $out['claimId'], 'workflowStatus' => 'Submitted for Validation']]);

        return redirect("/claims/{$out['claimId']}/received")->with('claim_unknown', $body['diagnosisCertainty'] === Vocab::UNKNOWN_ILLNESS ? $body['icd'] : null);
    }

    /* ------------------------------------------------------------ bulk claims from a CSV */

    public function bulk()
    {
        $ctx = me();
        $ctx->need('claim.submit');
        $ctx->single();

        return view('claims.bulk', ['result' => session('bulk')]);
    }

    public function template()
    {
        me()->need('claim.submit');
        $csv = implode(',', Csv::BULK_COLUMNS)."\n".'INV-1001,VS-4410-2201-8845,'.portal_today().',Outpatient Care,M54.5,Dr. Name,OP-02,Consultation,1,120,'.portal_today().",\n";

        return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="vsahha-claims-template.csv"']);
    }

    /* every row gets a verdict; rows that pass the Clean Claim Check become drafts to review and submit. Nothing is submitted here. */
    public function bulkRead(Request $r)
    {
        $ctx = me();
        $ctx->need('claim.submit');
        $ctx->single();
        $file = $r->file('csv');
        $csv = $file ? (string) file_get_contents($file->getRealPath()) : (string) $r->input('text', '');
        $groups = Csv::parseBulk($csv);
        if (! $groups['rows']) {
            throw new PortalError(400, 'EMPTY', 'No rows were found. Use the template: one row per service line, the same invoice number on every line of one claim.');
        }
        if (count($groups['claims']) > 200) {
            throw new PortalError(400, 'TOO_MANY', 'Send at most 200 claims in one file.');
        }
        $out = [];
        foreach ($groups['claims'] as $g) {
            $v = ['invoiceNo' => $g['invoiceNo'], 'rows' => $g['rows'], 'memberRef' => $g['memberRef'], 'problems' => $g['problems'], 'warnings' => [], 'missingDocuments' => 0];
            if (! $v['problems']) {
                try {
                    $ctx->rateLimit("elig:{$ctx->user->id}", (int) Retention::config('eligibilityRateLimitPerHour', 120)); // a file is no way round the look-up limit
                    $elig = Crm::post('/v1/eligibility', ['providerId' => $ctx->providerId, 'q' => $g['memberRef'], 'cardNo' => $g['memberRef'], 'serviceDate' => $g['draft']['treatmentDate']], $ctx->crmOpts());
                    $g['draft']['memberId'] = $elig['member']['id'];
                    $v['memberName'] = $elig['member']['name'];
                    $check = Crm::post('/v1/claims/validate', ['providerId' => $ctx->providerId, 'draft' => $g['draft']], $ctx->crmOpts());
                    $v['score'] = $check['score'];
                    /* documents cannot travel in a CSV: a missing one is a to-do on the draft, not a rejection */
                    $isDoc = fn ($i) => $i['area'] === 'documents' || str_starts_with($i['text'], 'Required document not attached');
                    foreach ($check['issues'] as $i) {
                        if ($i['severity'] === 'block' && ! $isDoc($i)) {
                            $v['problems'][] = $i['text'];
                        } elseif ($i['severity'] === 'warn' && ! $isDoc($i)) {
                            $v['warnings'][] = $i['text'];
                        } elseif ($i['area'] === 'documents') {
                            $v['missingDocuments']++;
                        }
                    }
                } catch (PortalError $e) {
                    $v['problems'][] = $e->getMessage();
                }
            }
            if (! $v['problems']) {
                $data = array_merge($g['draft'], ['memberName' => $v['memberName'], 'documents' => [], 'idemKey' => (string) Str::uuid(), 'lines' => array_map(fn ($l) => array_merge($l, ['key' => (string) Str::uuid(), 'documents' => []]), $g['draft']['lines'])]);
                $v['draftId'] = self::saveDraft($ctx, null, 'claim', "{$v['memberName']} · ".($g['draft']['icd'] ?? '')." · {$g['invoiceNo']}", $data, 'bulk');
            }
            $v['verdict'] = ! empty($v['draftId']) ? 'Draft ready' : 'Not accepted';
            $out[] = $v;
        }
        $ctx->audit('claims.bulk.read', null, ['rows' => count($groups['rows']), 'claims' => count($out), 'drafts' => count(array_filter($out, fn ($v) => ! empty($v['draftId'])))]);

        return redirect('/claims/bulk')->with('bulk', ['rows' => count($groups['rows']), 'ignored' => $groups['ignored'], 'claims' => $out]);
    }
}
