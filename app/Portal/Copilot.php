<?php

namespace App\Portal;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;

/* Provider Copilot: questions about the provider's own records, answered from the signed-in branch's
   claims, pre-authorizations, GOPs, payments and patients' plans, and from nothing else.
     · every record is fetched through the CRM client with the session's provider scope, exactly like
       the screens, so another provider's record answers "not in your records", the same words whether
       or not it exists;
     · each kind of record needs the role's capability;
     · questions that ask for an action, clinical advice, another provider, a member's bank or
       reimbursement details, or the assistant's own instructions are refused before any record is read;
     · every answer cites the record IDs it comes from;
     · it reads; nothing here submits, changes or deletes anything.
   A sentence is kept as [English, values] and translated when it is shown. */
class Copilot
{
    public const ID_RE = '/\b(?:CLM-[A-Z0-9]+(?:-[A-Z0-9]+)*|PA-\d{4}-\d{4,}|PAC-\d+|GOP-\d{4}-\d{4,}|PAY-[A-Z0-9]+(?:-[A-Z0-9]+)*|EOB-\d{4}-\d{4,}|INV-[A-Z0-9]+(?:-[A-Z0-9]+)*|MBR-\d+|PRV-\d+|ELG-[A-F0-9]{10})\b/i';

    public const STARTERS = ['Which GOPs expire this week?', 'What does VSahha need from us?', "Which claims are past VSahha's target?", 'What is VSahha due to pay us?', 'What are our most common rejection reasons?', 'What was our last payment?'];

    public const KIND_ICON = ['claim' => 'file', 'preauth' => 'clip', 'gop' => 'badge', 'payment' => 'wallet', 'eob' => 'receipt', 'member' => 'user', 'eligibility' => 'shield', 'record' => 'doc'];

    private const ACTION = [
        '/^(please |can you |could you |pls )?(submit|send|approve|reject|decline|delete|remove|cancel|withdraw|pay|release|change|modify|edit|create|book|mark|appeal|resubmit)\b/u',
        '/^(peux-tu |pouvez-vous |merci de |veuillez )?(soumets|soumettre|soumettez|envoie|envoyer|envoyez|approuve|approuver|supprime|supprimer|annule|annuler|paie|payer|payez|modifie|modifier|cree|creer|retire|retirer|reponds|repondre)\b/u',
        '/^(من فضلك )?(أرسل|ارسل|قدم|قدّم|وافق|ارفض|احذف|ألغ|ادفع|عدل|عدّل|أنشئ|انشئ|اسحب)/u',
    ];

    private const MEDICAL = [
        '/\b(what|which|how much) (dose|dosage|medicine|medication|drug|treatment) (of|should|for|to)\b/u', '/\b(dose|dosage) (of|for)\b/u', '/\bhow (should|do|can) (i|we) treat\b/u', '/\b(symptoms? of|diagnose (this|him|her|the patient)|should (he|she|the patient) take|is it safe to)\b/u',
        '/\b(posologie|quelle dose|quel traitement (pour|donner)|comment traiter|symptomes? de|faut-il prescrire)\b/u',
        '/(جرعة|كيف أعالج|كيف نعالج|أعراض|ما العلاج المناسب|هل يجب أن يأخذ)/u',
    ];

    private const OTHER_PROVIDER = [
        '/\b(other|another|competing|rival) (clinic|clinics|provider|providers|hospital|hospitals|pharmacy|pharmacies|laborator(y|ies))\b/u', '/\bcompetitors?\b/u',
        '/\b(autres?|une autre) (clinique|cliniques|prestataire|prestataires|hopital|hopitaux|pharmacie|laboratoire)s?\b/u', '/\bconcurrents?\b/u',
        '/(مزود آخر|مزودين آخرين|عيادة أخرى|مستشفى آخر|المنافس)/u',
    ];

    private const MEMBER_MONEY = [
        "/\b(member|patient)'?s? (bank|iban|account number|card details|reimbursement)\b/u", '/\b(iban|swift|bic|rib)\b/u', '/\breimburse(d|ment)? (to|for) (the )?(member|patient)\b/u',
        "/\b(compte bancaire|coordonnees bancaires|remboursement) (de l'|du |de l)?(adherent|patient)\b/u",
        '/(الحساب البنكي للمريض|حساب المنخرط|رقم الحساب|تعويض المريض)/u',
    ];

    private const INJECTION = [
        '/\b(ignore|forget|disregard) (all |the |your |previous |prior )*(instructions|rules|prompt)\b/u', '/\b(system prompt|your instructions|developer mode|jailbreak)\b/u',
        '/\b(ignore|oublie) (les |tes |vos )?(instructions|consignes|regles)\b/u', '/(تجاهل التعليمات|التعليمات السابقة)/u',
    ];

    private const W = [
        'help' => ['/^(help|aide|مساعدة)\b/u', '/\bwhat can you (do|answer)\b/u', '/\bque (peux|pouvez)-(tu|vous) (faire|repondre)\b/u', '/(ماذا يمكنك|بماذا تساعد)/u'],
        'gop' => ['/\bgops?\b/u', '/\bguarantee/u', '/\bgarantie/u', '/ضمان|خطاب ضمان/u'],
        'expire' => ['/\bexpir/u', '/\b(echeance|echoit|echoient|arrive a echeance)\b/u', '/(ينتهي|تنتهي|انتهاء)/u'],
        'unbilled' => ['/\b(unbilled|not (yet )?billed|no claim|bill|billing)\b/u', '/\b(non factur|pas (encore )?factur|facturer)/u', '/(لم تتم فوترة|بدون مطالبة|فوترة)/u'],
        'info' => ['/\b(information|info|documents?)\b.*\b(request|requested|asked|need|needs|missing|outstanding)\b/u', '/\bwhat does vsahha need\b/u', "/\b(demande d'information|demandes d'information|pieces manquantes|informations demandees|a completer)\b/u", '/(طلب معلومات|معلومات مطلوبة|وثائق ناقصة)/u'],
        'overdue' => ['/\b(overdue|late|past (the )?(target|deadline)|sla|delayed)\b/u', '/\b(en retard|retard|hors delai)\b/u', '/(متأخر|متأخرة|تجاوز)/u'],
        'awaiting' => ['/\b(awaiting|waiting for|expected|owed|due|outstanding|receivable)\b.*\b(payment|paid|money)\b/u', '/\bwhen (will|do) (i|we) (get )?(be )?paid\b/u', '/\bhow much (does|do) vsahha owe\b/u', '/\b(due to pay|owes? (us|me)|to pay (us|me)|will .*pay (us|me))\b/u', '/\b(doit nous payer|nous doit|va nous payer)\b/u', '/\b(en attente de paiement|attente de paiement|nous doit|a recevoir|quand serons-nous payes)\b/u', '/(بانتظار الدفع|المبالغ المستحقة|متى سندفع|متى سنقبض)/u'],
        'payment' => ['/\b(payment|payments|paid|remittance|transfer)\b/u', '/\b(paiement|paiements|paye|payes|virement|versement)\b/u', '/(دفعة|دفعات|مدفوع|تحويل)/u'],
        'reasons' => ['/\b(reject|rejected|rejection|declin|denial|denied|refus|not payable)\w*\b.*\b(reason|reasons|why|top|most|common|main)\b/u', '/\b(top|main|common|most) .*(reject|rejection|denial|refus)/u', '/\b(motifs?|raisons?) (de |des )?(refus|rejet)/u', '/(أسباب الرفض|سبب الرفض)/u'],
        'pending' => ['/\b(pending|in review|under review|waiting for (a )?decision|not (yet )?decided|undecided|open claims)\b/u', '/\b(en attente|en cours|pas encore decide|non decide)/u', '/(قيد المراجعة|لم يتم البت|معلقة)/u'],
        'count' => ['/\bhow many claims\b/u', '/\bcombien de (demandes|dossiers|reclamations)\b/u', '/(كم عدد المطالبات)/u'],
        'left' => ['/\b(left|remaining|remain|balance|limit|limits|used|available|how much .* (cover|benefit))\b/u', '/\b(reste|restant|restante|solde|plafond|utilise|disponible)\b/u', '/(متبق|المتبقي|رصيد|سقف|الحد)/u'],
        'why' => ['/\bwhy\b/u', '/\bpourquoi\b/u', '/لماذا/u', '/\b(status|where is|state)\b/u', '/\b(statut|ou en est)\b/u', '/(حالة|أين)/u'],
    ];

    private const BENEFIT_WORDS = [
        ['/physio|kine|kinesi|reeducation|rehab|العلاج الطبيعي|ترويض/u', ['RH-01', 'RH']],
        ['/dental|dentist|dentaire|dents|أسنان/u', ['DE']],
        ['/optic|optique|lunettes|glasses|نظارات|بصر/u', ['OP-OPT', 'OPT', 'OV']],
        ['/pharmac|medicament|medicine|prescription|صيدلية|أدوية/u', ['PH']],
        ['/matern|pregnan|grossesse|accouchement|حمل|ولادة/u', ['MT']],
        ['/\bmri\b|\birm\b|رنين/u', ['DI-02-02']],
        ['/imaging|radiolog|scanner|اشعة|أشعة/u', ['DI-02', 'DI']],
        ['/specialist|specialiste|أخصائي/u', ['OP-02']],
        ['/\bgp\b|general practitioner|generaliste|طبيب عام/u', ['OP-01']],
        ['/inpatient|hospital|hospitalis|admission|استشفاء|مستشفى/u', ['IP']],
        ['/emergenc|urgence|طوارئ/u', ['ER']],
        ['/outpatient|ambulatoire|consultation|عيادات خارجية/u', ['OP']],
    ];

    private array $memo = [];

    public function __construct(private Ctx $ctx)
    {
    }

    /* ---------------------------------------- a sentence, an amount, a date, as the page shows them */

    private static function S(string $text, ?array $vars = null): array
    {
        return $vars ? [$text, $vars] : [$text];
    }

    private static function M($m, $c): array
    {
        return ['m' => $m, 'c' => $c];
    }

    private static function D($d): array
    {
        return ['d' => $d];
    }

    public static function tr($x): string
    {
        if ($x === null) {
            return '';
        }
        if (is_array($x)) {
            if (array_key_exists('m', $x)) {
                return money($x['m'], $x['c'] ?? null);
            }
            if (array_key_exists('d', $x)) {
                return day($x['d']);
            }

            return t($x[0], isset($x[1]) ? array_map([self::class, 'tr'], $x[1]) : []);
        }

        return (string) $x;
    }

    /** record IDs in a sentence become links to the record they cite */
    public static function linked(string $text, array $cites): HtmlString
    {
        $by = [];
        foreach ($cites as $c) {
            $by[$c['id']] = $c;
        }
        $out = '';
        $last = 0;
        preg_match_all(self::ID_RE, $text, $m, PREG_OFFSET_CAPTURE);
        foreach ($m[0] as [$id, $at]) {
            $out .= e(substr($text, $last, $at - $last));
            $c = $by[$id] ?? null;
            $out .= ! empty($c['link']) ? '<a class="cp-id" href="'.e($c['link']).'"'.(self::opensApart($c['link']) ? ' target="_blank" rel="noreferrer"' : '').' dir="ltr">'.e($id).'</a>' : '<b class="cp-id plain" dir="ltr">'.e($id).'</b>';
            $last = $at + strlen($id);
        }

        return new HtmlString($out.e(substr($text, $last)));
    }

    public static function opensApart(string $link): bool
    {
        return str_starts_with($link, '/documents/') || str_starts_with($link, '/eligibility/');
    }

    private static function norm($s): string
    {
        $s = (string) preg_replace('/[\x{0300}-\x{036f}]/u', '', (string) \Normalizer::normalize((string) $s, \Normalizer::FORM_D));

        return trim((string) preg_replace('/\s+/u', ' ', str_replace('’', "'", mb_strtolower($s))));
    }

    private static function any(string $q, array $res): bool
    {
        foreach ($res as $re) {
            if (preg_match($re, $q)) {
                return true;
            }
        }

        return false;
    }

    private static function idsIn($text): array
    {
        preg_match_all(self::ID_RE, (string) $text, $m);

        return array_values(array_unique(array_map('mb_strtoupper', $m[0])));
    }

    private static function kindOf(string $id): string
    {
        foreach (['/^CLM-/' => 'claim', '/^PAC-|^PA-/' => 'preauth', '/^GOP-/' => 'gop', '/^PAY-/' => 'payment', '/^EOB-/' => 'eob', '/^INV-/' => 'invoice', '/^MBR-/' => 'member', '/^ELG-/' => 'eligibility', '/^PRV-/' => 'provider'] as $re => $k) {
            if (preg_match($re, $id)) {
                return $k;
            }
        }

        return 'record';
    }

    /* ---------------------------------------- records: the provider's own, through the provider scope, fetched once per question */

    private function once(string $k, callable $fn)
    {
        return $this->memo[$k] ??= $fn();
    }

    private function can(string $cap): bool
    {
        return $this->ctx->roleCan($cap);
    }

    private function need(string $cap): void
    {
        if (! $this->can($cap)) {
            throw new CopilotStop(self::refusal('role', self::S('Your role ({role}) does not include “{what}”, so I cannot answer this.', ['role' => $this->ctx->user->role, 'what' => self::S(Vocab::CAPABILITIES[$cap])])));
        }
    }

    private function claims(): array
    {
        $this->need('claim.view');

        return $this->once('claims', fn () => Crm::get('/v1/claims', $this->ctx->crmOpts()));
    }

    private function preauths(): array
    {
        $this->need('preauth.view');

        return $this->once('preauths', fn () => Crm::get('/v1/preauths', $this->ctx->crmOpts()));
    }

    private function payments(): array
    {
        $this->need('payment.view');

        return $this->once('payments', fn () => Crm::get('/v1/payments', $this->ctx->crmOpts()));
    }

    private function claim(string $id): ?array
    {
        $k = mb_strtoupper($id);
        foreach ($this->claims() as $c) {
            if (mb_strtoupper($c['id']) === $k || mb_strtoupper((string) ($c['invoiceNo'] ?? '')) === $k) {
                return $this->once("claim:{$c['id']}", fn () => Crm::get('/v1/claims/'.rawurlencode($c['id']), $this->ctx->crmOpts()));
            }
        }

        return null;
    }

    private function preauth(string $ref): ?array
    {
        $k = mb_strtoupper($ref);
        foreach ($this->preauths() as $p) {
            $gops = array_map(fn ($g) => mb_strtoupper((string) ($g['gopNumber'] ?? '')), $p['gops'] ?? []);
            if (mb_strtoupper($p['id']) === $k || mb_strtoupper((string) $p['ref']) === $k || mb_strtoupper((string) ($p['gop']['gopNumber'] ?? '')) === $k || in_array($k, $gops, true)) {
                return $this->once("pa:{$p['id']}", fn () => Crm::get('/v1/preauths/'.rawurlencode($p['id']), $this->ctx->crmOpts()));
            }
        }

        return null;
    }

    private function payment(string $id): ?array
    {
        $k = mb_strtoupper($id);
        foreach ($this->payments() as $p) {
            if (mb_strtoupper((string) ($p['id'] ?? '')) === $k || mb_strtoupper((string) ($p['reference'] ?? '')) === $k) {
                return $p;
            }
        }

        return null;
    }

    /** patients this provider has records for: the only members it may ask about */
    private function patients(): array
    {
        $out = [];
        if ($this->can('claim.view')) {
            foreach ($this->claims() as $c) {
                $out[$c['memberId']] = $c['memberName'];
            }
        }
        if ($this->can('preauth.view')) {
            foreach ($this->preauths() as $p) {
                $out[$p['memberId']] = $p['memberName'];
            }
        }
        $list = [];
        foreach ($out as $id => $name) {
            $list[] = ['id' => $id, 'name' => $name];
        }

        return $list;
    }

    private function balance(string $memberId, array $codes): array
    {
        $this->need('eligibility.check');
        $this->ctx->rateLimit("elig:{$this->ctx->user->id}", (int) Retention::config('eligibilityRateLimitPerHour', 120));
        $opts = $this->ctx->crmOpts();
        $body = ['providerId' => $this->ctx->providerId, 'memberId' => $memberId, 'serviceDate' => portal_today()];
        $base = Crm::post('/v1/eligibility', $body, $opts);
        $benefit = null;
        foreach ($codes as $c) {
            foreach ($base['benefits'] as $b) {
                if ($b['code'] === $c) {
                    $benefit = $b;
                    break 2;
                }
            }
        }
        if (! $benefit) {
            foreach ($base['benefits'] as $b) {
                foreach ($codes as $c) {
                    if (str_starts_with($b['code'], $c)) {
                        $benefit = $b;
                        break 2;
                    }
                }
            }
        }
        if (! $benefit) {
            return ['base' => $base, 'benefit' => null];
        }
        $out = Crm::post('/v1/eligibility', $body + ['benefitCode' => $benefit['code']], $opts);
        /* recorded like a check made on the Eligibility page, so the answer can cite it */
        $checkId = portal_id('ELG');
        DB::table('eligibility_checks')->insert(['id' => $checkId, 'at' => iso_now(), 'user_id' => $this->ctx->user->id, 'provider_id' => $this->ctx->providerId, 'member_id' => $out['member']['id'], 'member_name' => $out['member']['name'], 'service_date' => $out['serviceDate'], 'eligible' => (bool) $out['eligible'], 'summary' => $out['summary'],
            'snapshot' => json_encode(['member' => $out['member'], 'policy' => $out['policy'], 'insurer' => $out['insurer'], 'checks' => $out['checks'], 'network' => $out['network'], 'enrolledFrom' => $out['enrolledFrom'] ?? null], JSON_UNESCAPED_UNICODE)]);
        $this->ctx->audit('eligibility.checked', $out['member']['id'], ['serviceDate' => $out['serviceDate'], 'eligible' => $out['eligible'], 'via' => 'copilot', 'benefit' => $benefit['code']]);

        return ['base' => $base, 'benefit' => $benefit, 'out' => $out, 'checkId' => $checkId];
    }

    /* ---------------------------------------- answer shape */

    private static function answer(string $kind, array $blocks, array $cites = [], array $extra = []): array
    {
        $seen = [];
        $citations = [];
        foreach ($cites as $c) {
            if (! empty($c['id']) && ! isset($seen[$c['id']])) {
                $seen[$c['id']] = true;
                $citations[] = $c;
            }
        }

        return ['kind' => $kind, 'blocks' => $blocks, 'citations' => $citations] + $extra;
    }

    private static function suggest(): array
    {
        return array_map(fn ($s) => [$s], self::STARTERS);
    }

    private static function refusal(string $reason, array $sentence): array
    {
        return self::answer('refusal', [['type' => 'p', 't' => $sentence]], [], ['reason' => $reason, 'suggestions' => array_slice(self::suggest(), 0, 3)]);
    }

    private static function p(array $sentence): array
    {
        return ['type' => 'p', 't' => $sentence];
    }

    private static function citeClaim(array $c): array
    {
        return ['id' => $c['id'], 'kind' => 'claim', 'label' => self::S('Claim'), 'link' => '/claims/'.rawurlencode($c['id'])];
    }

    private static function citePreauth(array $p): array
    {
        return ['id' => $p['ref'], 'kind' => 'preauth', 'label' => self::S('Pre-authorization'), 'link' => '/preauth/'.rawurlencode($p['id'])];
    }

    private static function citeGop(array $p): ?array
    {
        return ! empty($p['gop']) ? ['id' => $p['gop']['gopNumber'], 'kind' => 'gop', 'label' => self::S('GOP'), 'link' => '/preauth/'.rawurlencode($p['id'])] : null;
    }

    private static function citePayment(array $p): array
    {
        return ['id' => ($p['reference'] ?? null) ?: $p['id'], 'kind' => 'payment', 'label' => self::S('Payment'), 'link' => '/payments/'.rawurlencode(($p['reference'] ?? null) ?: $p['id'])];
    }

    private static function citeEob(?string $id): ?array
    {
        return $id ? ['id' => $id, 'kind' => 'eob', 'label' => self::S('EOB'), 'link' => '/documents/eob/'.rawurlencode($id)] : null;
    }

    private static function citeMember(string $id): array
    {
        return ['id' => $id, 'kind' => 'member', 'label' => self::S('Patient'), 'link' => null];
    }

    private function notMine(string $id): array
    {
        return self::answer('not-found', [self::p(self::S("{id} is not in the records of {provider}. I can only see this branch's own records.", ['id' => $id, 'provider' => $this->ctx->providerLabel()]))]);
    }

    /* ---------------------------------------- one record */

    private function explainClaim(array $c): array
    {
        $st = Vocab::claimStage($c['workflowStatus'] ?? null);
        $cur = $c['currency'] ?? null;
        $blocks = [self::p(self::S('{id} · {name} · treated on {date} · invoice {invoice}.', ['id' => $c['id'], 'name' => $c['memberName'], 'date' => self::D($c['treatmentDate'] ?? null), 'invoice' => ($c['invoiceNo'] ?? null) ?: '—'])),
            self::p(self::S('Stage: {stage}.', ['stage' => self::S($st['label'])]))];
        $cites = [self::citeClaim($c)];
        if (! empty($c['memberReimbursement'])) {
            $blocks[] = self::p(self::S('This claim is a member reimbursement. Its decision and payment go to the member and are not shown to providers.'));

            return self::answer('answer', $blocks, $cites);
        }
        $decided = in_array($c['decisionStatus'] ?? null, Vocab::DECIDED, true);
        if (! empty($c['money'])) {
            $blocks[] = ['type' => 'facts', 'rows' => [[self::S('Claimed'), self::M($c['claimed'], $cur)], [self::S('Approved'), self::M($c['money']['approved'], $cur)], [self::S('Not payable'), self::M($c['money']['rejected'], $cur)],
                [self::S("Patient's share"), self::M($c['money']['memberShare'], $cur)], [self::S('VSahha pays you'), self::M($c['money']['insurerPayable'], $cur)]]];
            $cut = array_values(array_filter($c['lines'] ?? [], fn ($l) => in_array($l['decision'] ?? null, ['Rejected', 'Partially Approved'], true)));
            if ($cut) {
                $blocks[] = self::p(count($cut) === 1 ? self::S('1 line was reduced or not paid:') : self::S('{n} lines were reduced or not paid:', ['n' => count($cut)]));
                $blocks[] = ['type' => 'list', 'items' => array_map(fn ($l) => ['t' => self::S('{line} · {description}: {decision}, {claimed} claimed, {approved} approved. Reason {code}: {reason}{appeal}', [
                    'line' => $l['id'] ?? '—', 'description' => ($l['description'] ?? null) ?: (($l['benefit'] ?? null) ?: '—'), 'decision' => self::S($l['decision']), 'claimed' => self::M($l['claimed'] ?? 0, $cur), 'approved' => self::M($l['money']['approved'] ?? 0, $cur),
                    'code' => ($l['reasonCode'] ?? null) ?: '—', 'reason' => ($l['reason'] ?? null) ?: self::S('not recorded'), 'appeal' => ! empty($l['appealable']) ? self::S(' — this line can be appealed.') : ''])], $cut)];
            } elseif ($decided) {
                $blocks[] = self::p(self::S("No line was reduced or rejected. Any difference between claimed and paid is the patient's share under the plan."));
            }
        } elseif (! $decided) {
            $blocks[] = self::p(self::S('No decision yet: nothing has been reduced.'));
            if (! empty($c['sla'])) {
                $blocks[] = self::p(self::S("VSahha's target is {n} working days, due on {due}. Current state: {state}.", ['n' => $c['sla']['target'], 'due' => self::D($c['sla']['due']), 'state' => self::S($c['sla']['state'])]));
            }
        }
        if (! empty($c['infoRequest'])) {
            $blocks[] = self::p(self::S('VSahha asked for information on {date}: “{message}”. Reply from the claim page.', ['date' => self::D($c['infoRequest']['at']), 'message' => $c['infoRequest']['message']]));
        }
        if (! empty($c['missingDocuments'])) {
            $blocks[] = self::p(self::S('Documents still missing: {list}.', ['list' => implode(', ', $c['missingDocuments'])]));
        }
        if (! empty($c['payment'])) {
            $blocks[] = self::p(self::S('Paid on {date}, payment reference {ref}.', ['date' => self::D($c['payment']['paidAt']), 'ref' => $c['payment']['reference']]));
            $cites[] = ['id' => $c['payment']['reference'], 'kind' => 'payment', 'label' => self::S('Payment'), 'link' => '/payments/'.rawurlencode($c['payment']['reference'])];
        }
        if (! empty($c['eobId'])) {
            $cites[] = self::citeEob($c['eobId']);
        }
        if (! empty($c['preAuthId'])) {
            $cites[] = ['id' => $c['preAuthId'], 'kind' => 'preauth', 'label' => self::S('Pre-authorization'), 'link' => '/preauth/'.rawurlencode($c['preAuthId'])];
        }
        foreach ($c['appeals'] ?? [] as $a) {
            $blocks[] = self::p(self::S('Appeal {id}: {status}.', ['id' => $a['id'], 'status' => self::S(($a['status'] ?? null) ?: 'Submitted')]));
        }

        return self::answer('answer', $blocks, $cites);
    }

    private function explainPreauth(array $p, ?string $asked): array
    {
        $blocks = [self::p(self::S('{ref} · {name} · requested on {date} by {physician}.', ['ref' => $p['ref'], 'name' => $p['memberName'], 'date' => self::D($p['requestDate'] ?? null), 'physician' => ($p['physicianName'] ?? null) ?: '—'])),
            self::p(self::S('Stage: {stage}.', ['stage' => self::S(Vocab::paStage($p['status'] ?? null)['label'])])),
            ['type' => 'facts', 'rows' => [[self::S('Requested'), self::M($p['requested'] ?? 0, $p['currency'] ?? null)], [self::S('Authorised'), self::M($p['authorised'] ?? 0, $p['currency'] ?? null)]]]];
        $cut = array_values(array_filter($p['treatmentLines'] ?? [], fn ($l) => in_array($l['decision'] ?? null, ['Rejected', 'Partially Approved'], true) || (($l['approvedAmount'] ?? null) !== null && $l['approvedAmount'] < ($l['requestedAmount'] ?? 0))));
        if ($cut) {
            $blocks[] = ['type' => 'list', 'items' => array_map(fn ($l) => ['t' => self::S('{description}: {decision}, {requested} requested, {approved} authorised. {reason}', ['description' => $l['description'] ?? '—', 'decision' => self::S(($l['decision'] ?? null) ?: '—'),
                'requested' => self::M($l['requestedAmount'] ?? 0, $l['currency'] ?? null), 'approved' => self::M($l['approvedAmount'] ?? 0, $l['currency'] ?? null), 'reason' => ($l['rejectionReason'] ?? null) ?: (($l['partialReason'] ?? null) ?: self::S('No reason is recorded on this line.'))])], $cut)];
        }
        if (! empty($p['gop'])) {
            $g = $p['gop'];
            $blocks[] = self::p(self::S('{gop} (version {v}) is valid from {from} to {until}{left}. {billed}', ['gop' => $g['gopNumber'], 'v' => $g['currentVersion'] ?? 1, 'from' => self::D($g['validFrom'] ?? null), 'until' => self::D($g['validUntil'] ?? null),
                'left' => ($g['expiresInDays'] ?? null) !== null && $g['expiresInDays'] >= 0 ? self::S(' — {n} days left', ['n' => $g['expiresInDays']]) : '', 'billed' => ! empty($p['billed']) ? self::S('A claim has been made against it.') : self::S('No claim has been made against it yet.')]));
        }
        if (! empty($p['infoRequired'])) {
            $blocks[] = self::p(self::S('The medical team needs information from you. Reply from the pre-authorization page.'));
        }
        if ($asked && preg_match('/^GOP-/', $asked) && ! empty($p['gop']) && $p['gop']['gopNumber'] !== $asked) {
            $blocks[] = self::p(self::S('{asked} is an earlier version; the current GOP is {gop}.', ['asked' => $asked, 'gop' => $p['gop']['gopNumber']]));
        }

        return self::answer('answer', $blocks, array_filter([self::citePreauth($p), self::citeGop($p)]));
    }

    private function explainPayment(array $p): array
    {
        $cur = $p['currency'] ?? null;
        $blocks = [self::p(self::S('{ref}: {amount} by {method} on {date} ({status}).', ['ref' => $p['reference'], 'amount' => self::M($p['amount'], $cur), 'method' => self::S(($p['method'] ?? null) ?: 'Bank transfer'), 'date' => self::D($p['paymentDate'] ?? null), 'status' => self::S((string) ($p['status'] ?? ''))]))];
        if (! empty($p['claims'])) {
            $blocks[] = ['type' => 'list', 'items' => array_map(fn ($c) => ['t' => self::S("{id} · {name} · {payable} paid of {billed} billed (patient's share {share})", ['id' => $c['id'], 'name' => $c['memberName'], 'payable' => self::M($c['payable'], $cur), 'billed' => self::M($c['billed'], $cur), 'share' => self::M($c['memberShare'], $cur)])], $p['claims'])];
        }
        if (! empty($p['proof']['bankReference'])) {
            $blocks[] = self::p(self::S('Bank reference {ref}.', ['ref' => $p['proof']['bankReference']]));
        }
        $cites = [self::citePayment($p)];
        foreach ($p['claims'] ?? [] as $c) {
            $cites[] = self::citeClaim($c);
        }
        foreach ($p['eobs'] ?? [] as $e) {
            $cites[] = self::citeEob($e['id']);
        }

        return self::answer('answer', $blocks, array_filter($cites));
    }

    /* ---------------------------------------- across records */

    private function gopsExpiring(string $q): array
    {
        $n = self::any($q, ["/\btoday\b|aujourd'hui|اليوم/u"]) ? 0 : (self::any($q, ['/\btomorrow\b|demain|غدا/u']) ? 1 : (self::any($q, ['/\bthis week\b|cette semaine|هذا الأسبوع|7 days|7 jours/u']) ? 7
            : (self::any($q, ['/\bthis month\b|ce mois|هذا الشهر/u']) ? 30 : (preg_match('/(\d{1,3}) ?(days|jours|يوم|أيام)/u', $q, $m) ? (int) $m[1] : 30))));
        $rows = array_values(array_filter($this->preauths(), fn ($p) => ! empty($p['gop']) && ($p['gop']['expiresInDays'] ?? null) !== null && $p['gop']['expiresInDays'] >= 0 && $p['gop']['expiresInDays'] <= $n));
        usort($rows, fn ($a, $b) => $a['gop']['expiresInDays'] <=> $b['gop']['expiresInDays']);
        if (! $rows) {
            return self::answer('answer', [self::p(self::S('No GOP expires in the next {n} days.', ['n' => $n]))]);
        }
        $cites = [];
        foreach ($rows as $p) {
            $cites[] = self::citeGop($p);
            $cites[] = self::citePreauth($p);
        }

        return self::answer('answer', [self::p(count($rows) === 1 ? self::S('1 GOP expires in the next {n} days:', ['n' => $n]) : self::S('{count} GOPs expire in the next {n} days:', ['n' => $n, 'count' => count($rows)])),
            ['type' => 'list', 'items' => array_map(fn ($p) => ['t' => self::S('{gop} · {name} ({ref}): valid until {until}, {left} days left · {amount} authorised · {billed}', ['gop' => $p['gop']['gopNumber'], 'name' => $p['memberName'], 'ref' => $p['ref'], 'until' => self::D($p['gop']['validUntil']), 'left' => $p['gop']['expiresInDays'], 'amount' => self::M($p['authorised'], $p['currency'] ?? null),
                'billed' => ! empty($p['billed']) ? self::S('already billed') : self::S('not billed yet')])], $rows)]], $cites);
    }

    private function gopsUnbilled(): array
    {
        $rows = array_values(array_filter($this->preauths(), fn ($p) => ! empty($p['gop']) && empty($p['billed']) && ($p['gop']['expiresInDays'] ?? 0) >= 0));
        if (! $rows) {
            return self::answer('answer', [self::p(self::S('Every valid GOP already has a claim.'))]);
        }
        $cites = [];
        foreach ($rows as $p) {
            $cites[] = self::citeGop($p);
            $cites[] = self::citePreauth($p);
        }

        return self::answer('answer', [self::p(self::S('These GOPs are valid and have no claim yet:')), ['type' => 'list', 'items' => array_map(fn ($p) => ['t' => self::S('{gop} · {name} ({ref}): {amount} authorised, valid until {until}', ['gop' => $p['gop']['gopNumber'], 'name' => $p['memberName'], 'ref' => $p['ref'], 'amount' => self::M($p['authorised'], $p['currency'] ?? null), 'until' => self::D($p['gop']['validUntil'])])], $rows)]], $cites);
    }

    private function infoRequests(): array
    {
        if (! $this->can('claim.view') && ! $this->can('preauth.view')) {
            $this->claims();
        }
        $claims = $this->can('claim.view') ? array_values(array_filter($this->claims(), fn ($c) => ! empty($c['infoRequest']))) : [];
        $pas = $this->can('preauth.view') ? array_values(array_filter($this->preauths(), fn ($p) => ! empty($p['infoRequired']))) : [];
        if (! $claims && ! $pas) {
            return self::answer('answer', [self::p(self::S('VSahha is not waiting for anything from you: no claim or pre-authorization has an open information request.'))]);
        }

        return self::answer('answer', [self::p(self::S('VSahha is waiting for information on:')), ['type' => 'list', 'items' => array_merge(
            array_map(fn ($c) => ['t' => self::S('{id} · {name}: “{message}” (asked {date})', ['id' => $c['id'], 'name' => $c['memberName'], 'message' => $c['infoRequest']['message'], 'date' => self::D($c['infoRequest']['at'])])], $claims),
            array_map(fn ($p) => ['t' => self::S('{ref} · {name}: the medical team needs information.', ['ref' => $p['ref'], 'name' => $p['memberName']])], $pas))]],
            array_merge(array_map([self::class, 'citeClaim'], $claims), array_map([self::class, 'citePreauth'], $pas)));
    }

    private function overdue(): array
    {
        $rows = array_values(array_filter($this->claims(), fn ($c) => ! empty($c['sla']) && in_array($c['sla']['state'], ['Overdue', 'Breached', 'At Risk', 'At risk'], true)));
        if (! $rows) {
            return self::answer('answer', [self::p(self::S("No claim is past or close to VSahha's target."))]);
        }

        return self::answer('answer', [self::p(self::S("These claims are past or close to VSahha's {n}-working-day target:", ['n' => $rows[0]['sla']['target']])),
            ['type' => 'list', 'items' => array_map(fn ($c) => ['t' => self::S('{id} · {name} · {amount} · due {due} · {state}', ['id' => $c['id'], 'name' => $c['memberName'], 'amount' => self::M($c['claimed'], $c['currency'] ?? null), 'due' => self::D($c['sla']['due']), 'state' => self::S($c['sla']['state'])])], $rows)],
            self::p(self::S('Nothing is needed from you on these unless VSahha asks; you can message the claims team from each claim.'))], array_map([self::class, 'citeClaim'], $rows));
    }

    private function awaiting(): array
    {
        $rows = array_values(array_filter($this->claims(), fn ($c) => in_array(Vocab::claimStageId($c['workflowStatus'] ?? null), ['scheduled', 'paying'], true) && empty($c['memberReimbursement'])));
        if (! $rows) {
            return self::answer('answer', [self::p(self::S('No decided claim is waiting for payment.'))]);
        }
        $total = array_sum(array_map(fn ($c) => $c['money']['insurerPayable'] ?? 0, $rows));

        return self::answer('answer', [self::p(self::S('VSahha has decided {n} claims that are not paid yet, {total} in all:', ['n' => count($rows), 'total' => self::M($total, $rows[0]['currency'] ?? null)])),
            ['type' => 'list', 'items' => array_map(fn ($c) => ['t' => self::S('{id} · {name} · {amount} · {stage} since {date}', ['id' => $c['id'], 'name' => $c['memberName'], 'amount' => self::M($c['money']['insurerPayable'] ?? 0, $c['currency'] ?? null), 'stage' => self::S(Vocab::claimStage($c['workflowStatus'] ?? null)['label']), 'date' => self::D($c['statusSince'] ?? null)])], $rows)],
            self::p(self::S('Undecided claims are not counted: their payable amount is not known yet.'))], array_map([self::class, 'citeClaim'], $rows));
    }

    private function paymentsAnswer(string $q): array
    {
        $rows = $this->payments();
        $month = substr(portal_today(), 0, 7);
        $prev = gmdate('Y-m', strtotime("{$month}-01 -1 month UTC"));
        $label = null;
        $in = fn ($m) => array_values(array_filter($rows, fn ($p) => str_starts_with((string) ($p['paymentDate'] ?? ''), $m)));
        if (self::any($q, ['/\bthis month\b|ce mois|هذا الشهر/u'])) {
            $rows = $in($month);
            $label = $month;
        } elseif (self::any($q, ['/\blast month\b|mois dernier|le mois precedent|الشهر الماضي/u'])) {
            $rows = $in($prev);
            $label = $prev;
        } elseif (self::any($q, ['/\b(last|latest|most recent)\b|dernier|derniere|آخر/u'])) {
            $rows = array_slice($rows, 0, 1);
        }
        if (! $rows) {
            return self::answer('answer', [self::p($label ? self::S('No payment was made in {month}.', ['month' => $label]) : self::S('No payment has been made to this branch yet.'))]);
        }
        if (count($rows) === 1) {
            return $this->explainPayment($rows[0]);
        }
        $some = array_slice($rows, 0, 12);

        return self::answer('answer', [self::p(self::S('{n} payments, {total} in all:', ['n' => count($rows), 'total' => self::M(array_sum(array_column($rows, 'amount')), $rows[0]['currency'] ?? null)])),
            ['type' => 'list', 'items' => array_map(fn ($p) => ['t' => self::S('{ref} · {date} · {amount} · {claims}', ['ref' => $p['reference'], 'date' => self::D($p['paymentDate'] ?? null), 'amount' => self::M($p['amount'], $p['currency'] ?? null), 'claims' => implode(', ', array_column($p['claims'] ?? [], 'id'))])], $some)]], array_map([self::class, 'citePayment'], $some));
    }

    private function reasons(): array
    {
        $decided = array_filter($this->claims(), fn ($c) => in_array($c['decisionStatus'] ?? null, Vocab::DECIDED, true) && empty($c['memberReimbursement']));
        $details = [];
        foreach (array_slice($decided, 0, 80) as $c) {
            try {
                if ($d = $this->claim($c['id'])) {
                    $details[] = $d;
                }
            } catch (PortalError) {
                // one claim that cannot be read does not stop the count
            }
        }
        $map = [];
        foreach ($details as $c) {
            foreach ($c['lines'] ?? [] as $l) {
                if (! empty($l['reasonCode']) && in_array($l['decision'] ?? null, ['Rejected', 'Partially Approved'], true)) {
                    $map[$l['reasonCode']] ??= ['code' => $l['reasonCode'], 'reason' => $l['reason'] ?? null, 'lines' => 0, 'amount' => 0, 'currency' => $c['currency'] ?? null, 'claims' => []];
                    $map[$l['reasonCode']]['lines']++;
                    $map[$l['reasonCode']]['amount'] += $l['money']['rejected'] ?? 0;
                    $map[$l['reasonCode']]['claims'][$c['id']] = true;
                }
            }
        }
        $rows = array_values($map);
        usort($rows, fn ($a, $b) => $b['lines'] <=> $a['lines']);
        if (! $rows) {
            return self::answer('answer', [self::p(self::S('None of your {n} decided claims has a rejected or reduced line.', ['n' => count($details)]))], array_map([self::class, 'citeClaim'], $details));
        }
        $cites = [];
        foreach ($rows as $x) {
            foreach (array_keys($x['claims']) as $id) {
                $cites[] = self::citeClaim(['id' => $id]);
            }
        }

        return self::answer('answer', [self::p(self::S('Across your {n} decided claims, lines were reduced or not paid for these reasons:', ['n' => count($details)])),
            ['type' => 'list', 'items' => array_map(fn ($x) => ['t' => self::S('{code} · {reason}: {lines} lines, {amount} not paid · {claims}', ['code' => $x['code'], 'reason' => $x['reason'] ?: '—', 'lines' => $x['lines'], 'amount' => self::M($x['amount'], $x['currency']), 'claims' => implode(', ', array_keys($x['claims']))])], $rows)]], $cites);
    }

    private function pending(): array
    {
        $rows = array_values(array_filter($this->claims(), fn ($c) => in_array(Vocab::claimStageId($c['workflowStatus'] ?? null), ['review', 'action', 'medical'], true)));
        if (! $rows) {
            return self::answer('answer', [self::p(self::S('No claim is waiting for a decision.'))]);
        }

        return self::answer('answer', [self::p(self::S('{n} claims are waiting for a decision:', ['n' => count($rows)])),
            ['type' => 'list', 'items' => array_map(fn ($c) => ['t' => self::S('{id} · {name} · {amount} · {stage}', ['id' => $c['id'], 'name' => $c['memberName'], 'amount' => self::M($c['claimed'], $c['currency'] ?? null), 'stage' => self::S(Vocab::claimStage($c['workflowStatus'] ?? null)['label'])])], $rows)]], array_map([self::class, 'citeClaim'], $rows));
    }

    private function counts(): array
    {
        $claims = $this->claims();
        $rows = [];
        foreach (Vocab::CLAIM_STAGES as $id => [$label]) {
            $n = count(array_filter($claims, fn ($c) => Vocab::claimStageId($c['workflowStatus'] ?? null) === $id));
            if ($n) {
                $rows[] = [self::S($label), (string) $n];
            }
        }

        return self::answer('answer', [self::p(self::S('This branch has {n} claims at VSahha:', ['n' => count($claims)])), ['type' => 'facts', 'rows' => $rows]]);
    }

    /* ---------------------------------------- patients */

    private function findPatient(string $q, string $raw): array
    {
        $ids = array_values(array_filter(self::idsIn($raw), fn ($x) => str_starts_with($x, 'MBR-')));
        $pts = $this->patients();
        if ($ids) {
            return ['asked' => $ids[0], 'patient' => collect($pts)->firstWhere('id', $ids[0])];
        }
        $nq = ' '.preg_replace('/[^\p{L}\p{N} ]/u', ' ', self::norm($q)).' ';
        $hit = array_values(array_filter($pts, function ($p) use ($nq) {
            $parts = array_values(array_filter(explode(' ', self::norm($p['name'])), fn ($w) => mb_strlen($w) > 1));
            if (! $parts) {
                return false;
            }
            $all = count($parts) > 1 && ! array_filter($parts, fn ($w) => ! str_contains($nq, " {$w} "));

            return str_contains($nq, ' '.implode(' ', $parts).' ') || $all;
        }));

        return ['asked' => null, 'patient' => $hit[0] ?? null, 'ambiguous' => count($hit) > 1 ? $hit : null];
    }

    private function patientSummary(array $p): array
    {
        $claims = $this->can('claim.view') ? array_values(array_filter($this->claims(), fn ($c) => $c['memberId'] === $p['id'])) : [];
        $pas = $this->can('preauth.view') ? array_values(array_filter($this->preauths(), fn ($x) => $x['memberId'] === $p['id'])) : [];

        return self::answer('answer', [self::p(self::S("{name} ({id}) in this branch's records:", ['name' => $p['name'], 'id' => $p['id']])), ['type' => 'list', 'items' => array_merge(
            array_map(fn ($c) => ['t' => self::S('{id} · treated {date} · {amount} · {stage}', ['id' => $c['id'], 'date' => self::D($c['treatmentDate'] ?? null), 'amount' => self::M($c['claimed'], $c['currency'] ?? null), 'stage' => self::S(Vocab::claimStage($c['workflowStatus'] ?? null)['label'])])], $claims),
            array_map(fn ($x) => ['t' => self::S('{ref} · {status} · {amount} authorised{gop}', ['ref' => $x['ref'], 'status' => self::S(Vocab::paStage($x['status'] ?? null)['label']), 'amount' => self::M($x['authorised'] ?? 0, $x['currency'] ?? null), 'gop' => ! empty($x['gop']) ? self::S(' · {gop} until {until}', ['gop' => $x['gop']['gopNumber'], 'until' => self::D($x['gop']['validUntil'])]) : ''])], $pas))]],
            array_merge([self::citeMember($p['id'])], array_map([self::class, 'citeClaim'], $claims), array_map([self::class, 'citePreauth'], $pas)));
    }

    private function benefitLeft(array $p, string $q): array
    {
        $hit = null;
        foreach (self::BENEFIT_WORDS as $x) {
            if (preg_match($x[0], $q)) {
                $hit = $x;
                break;
            }
        }
        if (! $hit) {
            $res = $this->balance($p['id'], ['__none__']);

            return self::answer('clarify', [self::p(self::S('Which benefit do you mean for {name}? The plan covers:', ['name' => $p['name']])),
                ['type' => 'list', 'items' => array_values(array_map(fn ($b) => ['t' => self::S('{name} ({code})', ['name' => $b['name'], 'code' => $b['code']])], array_filter($res['base']['benefits'], fn ($b) => ($b['level'] ?? null) === 'Category')))]], [self::citeMember($p['id'])]);
        }
        $res = $this->balance($p['id'], $hit[1]);
        if (empty($res['benefit'])) {
            return self::answer('answer', [self::p(self::S("{name}'s plan does not include that benefit.", ['name' => $p['name']]))], [self::citeMember($p['id'])]);
        }
        $sel = $res['out']['selected'];
        $cur = $sel['currency'] ?? null;
        $blocks = [self::p(self::S('{benefit} for {name} ({id}), checked today:', ['benefit' => $sel['name'], 'name' => $p['name'], 'id' => $p['id']]))];
        if (empty($res['out']['eligible'])) {
            $blocks[] = self::p(self::S('Not covered today: {summary}.', ['summary' => $res['out']['summary']]));
        }
        $limits = $sel['limits'] ?? [];
        $blocks[] = ['type' => 'list', 'items' => array_map(fn ($l) => ['t' => ! empty($l['unlimited']) ? self::S('{label}: no limit', ['label' => $l['label']]) : self::S('{label}: {remaining} left of {limit} ({used} used or reserved)', ['label' => $l['label'], 'remaining' => self::M($l['remaining'], $cur), 'limit' => self::M($l['limit'], $cur), 'used' => self::M($l['used'], $cur)])], $limits)];
        $terms = [];
        if (($sel['coinsurance'] ?? null) !== null) {
            $terms[] = self::S('patient pays {n}% in network', ['n' => $sel['coinsurance']]);
        }
        if (! empty($sel['copay'])) {
            $terms[] = self::S('co-payment {amount}', ['amount' => self::M($sel['copay'], $cur)]);
        }
        if (! empty($sel['visitLimit'])) {
            $terms[] = self::S('{n} visits per {period}', ['n' => $sel['visitLimit'], 'period' => self::S(($sel['visitPeriod'] ?? null) ?: 'year')]);
        }
        if (! empty($sel['preAuth']) && $sel['preAuth'] !== 'Not required') {
            $terms[] = self::S('pre-authorization: {rule}', ['rule' => self::S($sel['preAuth'])]);
        }
        if ($terms) {
            $blocks[] = ['type' => 'terms', 'items' => $terms];
        }
        if (! $limits) {
            $blocks[] = self::p(self::S('No money limit is configured for this benefit.'));
        }
        $blocks[] = self::p(self::S('This is the balance today, not a guarantee of payment: claims are assessed when they are received.'));

        return self::answer('answer', $blocks, [self::citeMember($p['id']), ['id' => $res['checkId'], 'kind' => 'eligibility', 'label' => self::S('Eligibility check'), 'link' => '/eligibility/'.rawurlencode($res['checkId']).'/certificate']]);
    }

    /* ---------------------------------------- the engine */

    private function byRecords(string $raw, ?string $about): array
    {
        $q = self::norm($raw);
        $ids = self::idsIn($raw);
        $W = self::W;
        if (self::any($q, $W['help'])) {
            return self::answer('help', [self::p(self::S("I answer questions about this branch's own claims, pre-authorizations, GOPs, payments and patients' benefits, and I show the records each answer comes from. I only read: I never submit, change or delete anything."))], [], ['suggestions' => self::suggest()]);
        }
        $first = collect($ids)->first(fn ($x) => ! preg_match('/^MBR-|^PRV-|^ELG-/', $x));
        /* a follow-up ("why?", "and its status?") stays on the previous answer's record, but only when the question names no record of its own */
        $target = $first ?: ($about && ! $ids && self::any($q, array_merge($W['why'], ['/\b(it|its|this|that|ce|cette|celui|celle)\b/u', '/(هذه|هذا|ذلك)/u'])) ? $about : null);
        if ($target) {
            $k = self::kindOf($target);
            if ($k === 'claim' || $k === 'invoice') {
                $c = $this->claim($target);

                return $c ? $this->explainClaim($c) : $this->notMine($target);
            }
            if ($k === 'preauth' || $k === 'gop') {
                $p = $this->preauth($target);

                return $p ? $this->explainPreauth($p, $target) : $this->notMine($target);
            }
            if ($k === 'payment') {
                $p = $this->payment($target);

                return $p ? $this->explainPayment($p) : $this->notMine($target);
            }
            if ($k === 'eob') {
                foreach ($this->payments() as $p) {
                    foreach ($p['eobs'] ?? [] as $e) {
                        if ($e['id'] === $target) {
                            $c = $this->claim($e['claimId']);

                            return $c ? $this->explainClaim($c) : $this->notMine($target);
                        }
                    }
                }

                return $this->notMine($target);
            }
        }
        if (self::any($q, $W['gop']) && self::any($q, $W['expire'])) {
            return $this->gopsExpiring($q);
        }
        if (self::any($q, $W['gop']) && self::any($q, $W['unbilled'])) {
            return $this->gopsUnbilled();
        }
        if (self::any($q, $W['info'])) {
            return $this->infoRequests();
        }
        if (self::any($q, $W['overdue'])) {
            return $this->overdue();
        }
        if (self::any($q, $W['awaiting'])) {
            return $this->awaiting();
        }
        if (self::any($q, $W['reasons'])) {
            return $this->reasons();
        }
        $hasMember = (bool) array_filter($ids, fn ($x) => str_starts_with($x, 'MBR-'));
        $pf = $hasMember || self::any($q, $W['left']) || ! self::any($q, array_merge($W['payment'], $W['pending'], $W['count'], $W['gop'])) ? $this->findPatient($q, $raw) : ['patient' => null];
        if (! empty($pf['asked']) && empty($pf['patient'])) {
            return $this->notMine($pf['asked']);
        }
        if (! empty($pf['ambiguous'])) {
            return self::answer('clarify', [self::p(self::S('Several patients match. Which one do you mean?')), ['type' => 'list', 'items' => array_map(fn ($p) => ['t' => self::S('{name} ({id})', ['name' => $p['name'], 'id' => $p['id']])], $pf['ambiguous'])]], array_map(fn ($p) => self::citeMember($p['id']), $pf['ambiguous']));
        }
        if (! empty($pf['patient'])) {
            return self::any($q, $W['left']) || self::any($q, array_column(self::BENEFIT_WORDS, 0)) ? $this->benefitLeft($pf['patient'], $q) : $this->patientSummary($pf['patient']);
        }
        if (self::any($q, $W['left'])) {
            return self::answer('clarify', [self::p(self::S("Whose benefits? Give the patient's name or member ID — I can only look at patients who have a claim or pre-authorization with this branch."))]);
        }
        if (self::any($q, $W['gop'])) {
            return $this->gopsExpiring('this month');
        }
        if (self::any($q, $W['payment'])) {
            return $this->paymentsAnswer($q);
        }
        if (self::any($q, $W['pending'])) {
            return $this->pending();
        }
        if (self::any($q, $W['count'])) {
            return $this->counts();
        }

        return self::answer('unknown', [self::p(self::S("I can only answer questions about this branch's own claims, pre-authorizations, GOPs, payments and patients' benefits. Try one of these:"))], [], ['suggestions' => self::suggest()]);
    }

    public function ask(string $question, ?string $about = null): array
    {
        $raw = trim(mb_substr($question, 0, 600));
        $q = self::norm($raw);
        $started = microtime(true);
        $other = self::S('I can only answer about {provider}. Other providers\' records are never available here.', ['provider' => $this->ctx->providerLabel()]);
        $out = null;
        if ($raw === '') {
            $out = self::answer('unknown', [self::p(self::S("Ask a question about your claims, pre-authorizations, GOPs, payments or patients' benefits."))], [], ['suggestions' => self::suggest()]);
        } elseif (array_filter(self::idsIn($raw), fn ($id) => str_starts_with($id, 'PRV-') && $id !== mb_strtoupper((string) $this->ctx->providerId))) {
            $out = self::refusal('other-provider', $other);
        } elseif (self::any($q, self::INJECTION)) {
            $out = self::refusal('instructions', self::S("I can't change how I work. I answer questions about this branch's own records only."));
        } elseif (self::any($q, self::MEMBER_MONEY)) {
            $out = self::refusal('member-money', self::S("Members' bank and reimbursement details are never shown to providers, so I cannot answer this."));
        } elseif (self::any($q, self::OTHER_PROVIDER)) {
            $out = self::refusal('other-provider', $other);
        } elseif (self::any($q, self::MEDICAL)) {
            $out = self::refusal('clinical', self::S("I can't give clinical or medical advice. I answer questions about claims, cover and payments only."));
        } elseif (self::any($q, self::ACTION)) {
            $out = self::refusal('action', self::S('I only read records; I never submit, change or delete anything. Use the Claims, Pre-authorizations or Payments page to do this.'));
        }
        if (! $out) {
            try {
                $out = $this->byRecords($raw, self::idsIn((string) $about)[0] ?? null);
            } catch (CopilotStop $s) {
                $out = $s->answer;
            }
        }
        $out = ['id' => 'ASK-'.strtoupper(bin2hex(random_bytes(4))), 'mode' => 'records', 'at' => iso_now()] + $out;
        $this->ctx->audit('copilot.asked', $out['id'], ['kind' => $out['kind'], 'reason' => $out['reason'] ?? null, 'mode' => 'records', 'cited' => array_column($out['citations'], 'id'), 'ms' => (int) ((microtime(true) - $started) * 1000)]);

        return $out;
    }
}
