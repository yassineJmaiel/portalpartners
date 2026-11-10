<?php

namespace App\Http\Controllers;

use App\Portal\Crm;
use App\Portal\Vocab;
use Illuminate\Support\Facades\DB;

/* Check a patient · what needs you · quick actions · claims by stage · money · latest
   payments. Everything is live CRM data. */
class HomeController extends Controller
{
    public function index()
    {
        $ctx = me();
        $can = fn ($c) => $ctx->roleCan($c);
        $profile = $ctx->profile();
        $claims = $can('claim.view') ? Crm::get('/v1/claims', $ctx->crmOpts()) : [];
        $preauths = $can('preauth.view') ? Crm::get('/v1/preauths', $ctx->crmOpts()) : [];
        $payments = $can('payment.view') ? Crm::get('/v1/payments', $ctx->crmOpts()) : [];
        $tasks = [];

        foreach ($claims as $c) {
            if (! empty($c['infoRequest'])) {
                $tasks[] = ['kind' => 'claim-info', 'tone' => 'amber', 'link' => "/claims/{$c['id']}", 'title' => t('VSahha needs information on {id}', ['id' => $c['id']]),
                    'text' => t('{name} · {message}', ['name' => $c['memberName'], 'message' => $c['infoRequest']['message']]), 'action' => 'Reply'];
            }
        }
        foreach ($preauths as $p) {
            $gop = $p['gop'] ?? null;
            if (! empty($p['infoRequired'])) {
                $tasks[] = ['kind' => 'pa-info', 'tone' => 'amber', 'link' => "/preauth/{$p['id']}", 'title' => t('The medical team needs information on {ref}', ['ref' => $p['ref']]),
                    'text' => t('{name} · {message}', ['name' => $p['memberName'], 'message' => $p['presentingComplaint']]), 'action' => 'Reply'];
            }
            if ($gop && empty($p['billed']) && in_array($p['status'], ['Approved', 'Partially Approved', 'GOP Generated', 'GOP Sent', 'GOP Revised'], true) && ($gop['expiresInDays'] ?? 0) >= 0) {
                $tasks[] = ['kind' => 'bill-gop', 'tone' => 'teal', 'link' => "/preauth/{$p['id']}", 'title' => t('Bill {gop} for {name}', ['gop' => $gop['gopNumber'], 'name' => $p['memberName']]),
                    'text' => t('{ref} · {gop} · {amount} authorised · no claim yet', ['ref' => $p['ref'], 'gop' => $gop['gopNumber'], 'amount' => "{$p['authorised']} {$p['currency']}"]), 'until' => $gop['validUntil'] ?? null, 'action' => 'Create claim', 'caseId' => $p['id']];
            }
            if ($gop && isset($gop['expiresInDays']) && $gop['expiresInDays'] >= 0 && $gop['expiresInDays'] <= 30) {
                $tasks[] = ['kind' => 'gop-expiring', 'tone' => 'amber', 'link' => "/preauth/{$p['id']}",
                    'title' => t($gop['expiresInDays'] === 1 ? '{gop} expires tomorrow' : '{gop} expires in {n} days', ['gop' => $gop['gopNumber'], 'n' => $gop['expiresInDays']]),
                    'text' => t('{name} · {amount} authorised', ['name' => $p['memberName'], 'amount' => "{$p['authorised']} {$p['currency']}"]), 'until' => $gop['validUntil'] ?? null, 'action' => 'View GOP'];
            }
        }
        foreach ([['accreditation', $profile['accreditation'] ?? null, $profile['accreditationExpiry'] ?? null], ['licence', 'Operating licence', $profile['licenseExpiry'] ?? null]] as [$k, $label, $exp]) {
            if (! $exp) {
                continue;
            }
            $d = days_between(portal_today(), $exp);
            if ($d >= 0 && $d <= 60) {
                $tasks[] = ['kind' => $k, 'tone' => 'blue', 'link' => '/settings', 'title' => t('{label} expires in {n} days', ['label' => $label ?: $k, 'n' => $d]), 'text' => t('Upload the renewal so your network standing stays complete.'), 'until' => $exp, 'action' => 'Upload'];
            }
            if ($d < 0) {
                $tasks[] = ['kind' => $k, 'tone' => 'red', 'link' => '/settings', 'title' => t('{label} expired on {d}', ['label' => $label ?: $k, 'd' => $exp]), 'text' => t('Upload the renewal.'), 'until' => $exp, 'action' => 'Upload'];
            }
        }
        /* records a teammate handed to this person */
        $handoffs = DB::table('record_notes as n')->join('users as a', 'a.id', '=', 'n.author_id')->where('n.org_id', $ctx->user->org_id)->whereIn('n.provider_id', $ctx->scope)
            ->where('n.assignee_id', $ctx->user->id)->where('n.kind', 'handoff')->whereNull('n.done_at')->orderBy('n.created_at')->get(['n.subject_type', 'n.subject_id', 'n.body', 'a.name as author']);
        foreach ($handoffs as $n) {
            $base = ['claim' => '/claims/', 'preauth' => '/preauth/'][$n->subject_type] ?? '/payments/';
            $tasks[] = ['kind' => 'handoff', 'tone' => 'violet', 'link' => $base.$n->subject_id, 'title' => t('{name} handed {ref} to you', ['name' => $n->author, 'ref' => $n->subject_id]), 'text' => (string) $n->body, 'action' => 'Open'];
        }
        $drafts = DB::table('drafts')->where('org_id', $ctx->user->org_id)->whereIn('provider_id', $ctx->scope)->where('kind', 'claim')->orderByDesc('updated_at')->get(['title']);
        if (count($drafts) && $ctx->can('claim.submit')) {
            $tasks[] = ['kind' => 'drafts', 'tone' => 'blue', 'link' => '/claims?tab=drafts', 'title' => plural(count($drafts), '1 claim draft not yet submitted', '{n} claim drafts not yet submitted'),
                'text' => implode(' · ', array_column(array_slice($drafts->all(), 0, 2), 'title')), 'action' => 'Open'];
        }

        $stages = array_map(fn () => ['count' => 0, 'amount' => 0.0], Vocab::CLAIM_STAGES);
        foreach ($claims as $c) {
            $st = Vocab::claimStageId($c['workflowStatus']);
            $stages[$st]['count']++;
            $stages[$st]['amount'] += $st === 'paid' ? (float) ($c['money']['paid'] ?? 0) : ($st === 'scheduled' ? (float) ($c['money']['insurerPayable'] ?? 0) : (float) ($c['claimed'] ?? 0));
        }
        $stageOf = fn ($c) => Vocab::claimStageId($c['workflowStatus']);
        $scheduled = array_filter($claims, fn ($c) => $stageOf($c) === 'scheduled');
        $inReview = array_filter($claims, fn ($c) => in_array($stageOf($c), ['received', 'review', 'action', 'medical'], true));

        return view('home.index', ['profile' => $profile, 'tasks' => $tasks, 'stages' => $stages, 'claimsTotal' => count($claims),
            'infoRequests' => count(array_filter($claims, fn ($c) => ! empty($c['infoRequest']))) + count(array_filter($preauths, fn ($p) => ! empty($p['infoRequired']))),
            'inProgress' => array_slice(array_values(array_filter($claims, fn ($c) => ! in_array($stageOf($c), ['paid', 'closed', 'draft'], true))), 0, 5),
            'money' => ['currency' => ($payments[0]['currency'] ?? null) ?: (($claims[0]['currency'] ?? null) ?: 'USD'), 'paid' => array_sum(array_map(fn ($p) => (float) ($p['amount'] ?? 0), $payments)), 'paidCount' => count($payments),
                'lastPaid' => $payments[0]['paymentDate'] ?? null, 'awaiting' => array_sum(array_map(fn ($c) => (float) ($c['money']['insurerPayable'] ?? 0), $scheduled)), 'awaitingCount' => count($scheduled),
                'inReview' => array_sum(array_map(fn ($c) => (float) ($c['claimed'] ?? 0), $inReview)), 'inReviewCount' => count($inReview), 'bank' => $profile['billing']['bankName'] ?? null],
            'payments' => array_slice($payments, 0, 4), 'syncedAt' => iso_now(),
            'checkIns' => $ctx->roleCan('eligibility.check') ? EligibilityController::checkInsToday($ctx) : []]);
    }
}
