<?php

namespace App\Http\Controllers;

use App\Portal\Crm;
use App\Portal\Files;
use App\Portal\Intake\Intake;
use App\Portal\Vocab;
use Illuminate\Http\Request;

/* Appeals, messages and insights: what happens after a claim has been sent. */
class FollowUpController extends Controller
{
    public const COACH = ['RJ-01' => "The benefit limit was reached. Check the member's remaining limit on the Eligibility page before treating.",
        'RJ-02' => 'The claim went over the GOP. Request an amount increase on the pre-authorization before billing more than authorised.',
        'RJ-03' => 'A required document was missing. The claim wizard lists what each type of care needs — attach it before sending.',
        'RJ-04' => "The service is not in the member's plan. The benefit picker only offers what the plan sells; check it first.",
        'RJ-05' => 'This looked like a duplicate. The Clean Claim Check flags an invoice number already claimed.',
        'RJ-06' => 'The member was not covered on that date. Check cover on the treatment date, not today.',
        'RJ-07' => 'A clinical opinion was needed. A medical report with the claim avoids the referral.',
        'RJ-08' => 'Prior authorization was required. Benefits marked “pre-auth” need a GOP before treatment.',
        'RJ-09' => 'The waiting period was not served. The Eligibility page shows the waiting period per benefit.',
        'RJ-10' => 'Excluded as pre-existing. Ask VSahha before treating a condition that predates the policy.',
        'RJ-11' => 'Billed above the contracted tariff. Pick the tariff code on each line so the check compares it.',
        'RJ-12' => 'Sent too late. Submit within the period the policy allows — Smart Intake makes batches quick.',
        'RJ-14' => 'Treated outside the policy territory.', 'RJ-15' => 'See the reason written on the line.'];

    public function appeals()
    {
        $ctx = me();
        $ctx->need('claim.view');

        return view('appeals.index', ['appeals' => Crm::get('/v1/appeals', $ctx->crmOpts()),
            'candidates' => array_values(array_filter(Crm::get('/v1/claims', $ctx->crmOpts()), fn ($c) => in_array($c['decisionStatus'] ?? null, ['Partially Approved', 'Rejected'], true) && empty($c['memberReimbursement'])))]);
    }

    public function appeal(Request $r)
    {
        $ctx = me();
        $ctx->need('appeal.submit');
        $ctx->single();
        $documents = Files::push($ctx, ClaimsController::uploaded($ctx, $r, 'files', 'line_other'));
        $out = Crm::post('/v1/appeals', ['claimId' => $r->input('claimId'), 'lineIds' => (array) $r->input('lineIds', []), 'reason' => $r->input('reason'), 'documents' => $documents], $ctx->crmOpts(['idempotent' => true]));
        $ctx->audit('appeal.submitted', $out['id'], ['claimId' => $r->input('claimId'), 'lines' => $r->input('lineIds')]);
        toast(t('Appeal {id} sent', ['id' => $out['id']]));

        return redirect()->back();
    }

    public function messages(Request $r)
    {
        $ctx = me();
        $threads = Crm::get('/v1/messages', $ctx->crmOpts());
        $sel = (string) $r->query('t', $threads[0]['key'] ?? 'new');

        return view('messages.index', ['threads' => $threads, 'sel' => $sel, 'thread' => collect($threads)->firstWhere('key', $sel)]);
    }

    public function sendMessage(Request $r)
    {
        $ctx = me();
        $ctx->need('message.send');
        $ctx->single();
        $type = (string) $r->input('subjectType', 'general');
        $subject = $r->input('subjectId') ?: null;
        Crm::post('/v1/messages', ['providerId' => $ctx->providerId, 'subjectType' => $type, 'subjectId' => $subject, 'body' => $r->input('body')], $ctx->crmOpts(['idempotent' => true]));
        $ctx->audit('message.sent', $subject ?: 'general');
        toast(t('Sent'));

        return $r->input('subjectType', 'general') === 'general' ? redirect('/messages?t=general:general') : redirect()->back();
    }

    /** the provider's own figures, each with its denominator */
    public function insights()
    {
        $ctx = me();
        $ctx->need('insights.view');
        $claims = Crm::get('/v1/claims', $ctx->crmOpts());
        $decided = array_values(array_filter($claims, fn ($c) => in_array($c['decisionStatus'] ?? null, Vocab::DECIDED, true) && empty($c['memberReimbursement'])));
        $count = fn ($s) => count(array_filter($decided, fn ($c) => $c['decisionStatus'] === $s));
        $det = [];
        foreach (array_slice($claims, 0, 80) as $c) {
            try {
                $det[] = Crm::get('/v1/claims/'.rawurlencode($c['id']), $ctx->crmOpts());
            } catch (\Throwable) {
                // a claim that cannot be read is left out of the denominator
            }
        }
        $reasons = [];
        foreach ($det as $c) {
            foreach ($c['lines'] as $l) {
                if (! empty($l['reasonCode'])) {
                    $reasons[$l['reasonCode']] ??= ['code' => $l['reasonCode'], 'text' => $l['reason'] ?? '', 'lines' => 0, 'amount' => 0.0];
                    $reasons[$l['reasonCode']]['lines']++;
                    $reasons[$l['reasonCode']]['amount'] += (float) ($l['money']['rejected'] ?? 0);
                }
            }
        }
        usort($reasons, fn ($a, $b) => $b['lines'] <=> $a['lines']);
        $asked = fn ($c) => in_array('Information requested from you', array_column($c['timeline'] ?? [], 'title'), true);
        $clean = array_filter($det, fn ($c) => ! $asked($c));
        $detDecided = array_filter($det, fn ($c) => in_array($c['decisionStatus'] ?? null, Vocab::DECIDED, true) && empty($c['memberReimbursement']));
        $firstPass = array_filter($detDecided, fn ($c) => $c['decisionStatus'] === 'Approved' && ! $asked($c) && empty($c['appeals']));
        $decisionDays = [];
        $payDays = [];
        foreach ($det as $c) {
            foreach ($c['timeline'] ?? [] as $e) {
                if (str_starts_with($e['title'], 'Decided') && ! empty($c['receivedAt'])) {
                    $decisionDays[] = days_between($c['receivedAt'], $e['at']);
                    break;
                }
            }
            if (! empty($c['payment']['paidAt']) && ! empty($c['receivedAt'])) {
                $payDays[] = days_between($c['receivedAt'], $c['payment']['paidAt']);
            }
        }
        $median = function (array $xs) {
            if (! $xs) {
                return null;
            }
            sort($xs);
            $m = (int) floor(count($xs) / 2);

            return count($xs) % 2 ? $xs[$m] : ($xs[$m - 1] + $xs[$m]) / 2;
        };
        $months = [];
        foreach ($claims as $c) {
            $m = substr((string) (($c['receivedAt'] ?? null) ?: ($c['treatmentDate'] ?? '')), 0, 7);
            if ($m !== '') {
                $months[$m] ??= ['month' => $m, 'claims' => 0, 'claimed' => 0.0];
                $months[$m]['claims']++;
                $months[$m]['claimed'] += (float) ($c['claimed'] ?? 0);
            }
        }
        ksort($months);
        $received = array_filter(array_column($claims, 'receivedAt'));
        sort($received);

        return view('insights.index', ['d' => ['from' => $received[0] ?? null, 'claims' => count($claims), 'decided' => count($decided), 'approvedInFull' => $count('Approved'), 'partly' => $count('Partially Approved'), 'rejected' => $count('Rejected'),
            'clean' => count($clean), 'of' => count($det), 'firstPass' => count($firstPass), 'firstOf' => count($detDecided), 'decisionDays' => $median($decisionDays), 'decisionOf' => count($decisionDays), 'payDays' => $median($payDays), 'payOf' => count($payDays),
            'reasons' => $reasons, 'monthly' => array_slice(array_values($months), -12), 'intake' => Intake::accuracy($ctx)]]);
    }
}
