<?php

namespace App\Portal\Intake;

/* Patient matching: names, cards and dates. A document is filed under a patient automatically only
   when one candidate is clearly right, never on a guess; every score comes with the evidence for
   it, so a person reviewing the match can see why. */
class Matching
{
    public const CARD_RE = '/\bV\s*S[\s.-]*\d{4}[\s.-]*\d{4}[\s.-]*\d{4}\b/i';

    public const MEMBER_ID_RE = '/\bMBR-\d{3,6}\b/i';

    /* Arabic letters to their usual Latin spelling, enough to compare "منصور" on an invoice with "Mansour" on the member file */
    private const AR = ['ا' => 'a', 'أ' => 'a', 'إ' => 'i', 'آ' => 'a', 'ب' => 'b', 'ت' => 't', 'ث' => 'th', 'ج' => 'j', 'ح' => 'h', 'خ' => 'kh',
        'د' => 'd', 'ذ' => 'dh', 'ر' => 'r', 'ز' => 'z', 'س' => 's', 'ش' => 'ch', 'ص' => 's', 'ض' => 'd', 'ط' => 't', 'ظ' => 'z',
        'ع' => 'a', 'غ' => 'gh', 'ف' => 'f', 'ق' => 'k', 'ك' => 'k', 'ل' => 'l', 'م' => 'm', 'ن' => 'n', 'ه' => 'h', 'ة' => 'a',
        'و' => 'ou', 'ي' => 'i', 'ى' => 'a', 'ئ' => 'i', 'ؤ' => 'ou', 'ء' => ''];

    /* spellings that vary between French- and English-influenced transliteration */
    private const FOLD = [['/ou/', 'u'], ['/ch/', 'sh'], ['/kh/', 'h'], ['/ph/', 'f'], ['/th/', 't'], ['/ee/', 'i'], ['/y/', 'i'], ['/(.)\1/', '$1']];

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'fev' => 2, 'mar' => 3, 'apr' => 4, 'avr' => 4, 'may' => 5, 'mai' => 5, 'jun' => 6, 'juin' => 6, 'jul' => 7, 'juil' => 7,
        'aug' => 8, 'aou' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    public static function deaccent(string $s): string
    {
        return (string) preg_replace('/[\x{0300}-\x{036f}]/u', '', (string) \Normalizer::normalize($s, \Normalizer::FORM_D));
    }

    public static function normName($value): string
    {
        $s = mb_strtolower(trim((string) $value));
        $s = (string) preg_replace_callback('/[\x{0600}-\x{06ff}]/u', fn ($m) => self::AR[$m[0]] ?? '', $s);
        $s = self::deaccent($s);
        $s = (string) preg_replace('/\b(dr|mr|mrs|ms|mme|mlle|m)\.?\s+/', ' ', $s);
        $s = (string) preg_replace('/[^a-z\s-]/', ' ', $s);
        $s = str_replace('-', ' ', $s);

        return trim((string) preg_replace('/\s+/', ' ', $s));
    }

    private static function fold(string $token): string
    {
        foreach (self::FOLD as [$re, $to]) {
            $token = (string) preg_replace($re, $to, $token);
        }

        return $token;
    }

    public static function nameTokens($value): array
    {
        return array_values(array_map(fn ($t) => self::fold($t), array_filter(explode(' ', self::normName($value)), fn ($t) => strlen($t) > 1)));
    }

    private static function tokenSim(string $a, string $b): float
    {
        return 1 - levenshtein($a, $b) / max(strlen($a), strlen($b));
    }

    /** 0..1, order-free: "Mansour Leila" and "Leila Mansour" are the same person. A missing middle name costs a little, not everything. */
    public static function nameSimilarity($a, $b): float
    {
        $ta = self::nameTokens($a);
        $tb = self::nameTokens($b);
        if (! $ta || ! $tb) {
            return 0;
        }
        [$short, $long] = count($ta) <= count($tb) ? [$ta, $tb] : [$tb, $ta];
        $used = [];
        $total = 0;
        foreach ($short as $t) {
            $best = 0;
            $at = -1;
            foreach ($long as $i => $u) {
                if (isset($used[$i])) {
                    continue;
                }
                $s = self::tokenSim($t, $u);
                if ($s > $best) {
                    $best = $s;
                    $at = $i;
                }
            }
            if ($at >= 0) {
                $used[$at] = true;
            }
            $total += $best;
        }
        $coverage = count($short) / count($long);

        return round(($total / count($short)) * (0.85 + 0.15 * $coverage), 3);
    }

    /** VSahha card numbers: VS-4410-2201-8845; spaces, dots and a lower-case prefix are accepted on input */
    public static function normCard($value): ?string
    {
        $digits = (string) preg_replace('/[^0-9A-Z]/', '', mb_strtoupper((string) $value));

        return preg_match('/^VS(\d{4})(\d{4})(\d{4})$/', $digits, $m) ? "VS-{$m[1]}-{$m[2]}-{$m[3]}" : null;
    }

    /** dates compared as ISO days: 12/04/1988 (day first, the convention on this network's invoices), 1988-04-12, 12 Apr 1988 */
    public static function normDate($value): ?string
    {
        $s = self::deaccent(mb_strtolower(trim((string) $value)));
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            return self::iso((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})$/', $s, $m)) {
            $y = (int) $m[3];

            return self::iso($y < 100 ? ($y > 40 ? 1900 + $y : 2000 + $y) : $y, (int) $m[2], (int) $m[1]);
        }
        $month = fn ($w) => self::MONTHS[substr($w, 0, 4)] ?? self::MONTHS[substr($w, 0, 3)] ?? null;
        if (preg_match('/^(\d{1,2})(?:er|st|nd|rd|th)?\s+([a-z]+)\.?,?\s+(\d{4})$/', $s, $m) && ($mo = $month($m[2]))) {
            return self::iso((int) $m[3], $mo, (int) $m[1]);
        }
        if (preg_match('/^([a-z]+)\.?\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})$/', $s, $m) && ($mo = $month($m[1]))) {
            return self::iso((int) $m[3], $mo, (int) $m[2]);
        }

        return null;
    }

    private static function iso(int $y, int $mo, int $d): ?string
    {
        return $y > 1900 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** one member against what was read from a document: the score and the evidence a reviewer sees */
    public static function scoreCandidate(array $found, array $member): array
    {
        $evidence = [];
        /* an exact card number or member ID identifies the member, unless what else the document says
           contradicts it: another name on someone's card is a person's decision, never an automatic filing */
        $byIdentity = function (string $label, float $full) use ($found, $member, &$evidence) {
            $evidence[] = $label;
            $dobOk = empty($found['dob']) || self::normDate($found['dob']) === ($member['dob'] ?? null);
            if (! empty($found['dob']) && ! $dobOk) {
                $evidence[] = "Date of birth differs ({$found['dob']} read, ".($member['dob'] ?? '—').' on file)';
            }
            $sim = ! empty($found['name']) ? self::nameSimilarity($found['name'], $member['name'] ?? '') : null;
            $nameOk = $sim === null || $sim >= 0.7;
            if (! $nameOk) {
                $evidence[] = "The name on the document differs (\"{$found['name']}\" read, \"{$member['name']}\" on file)";
            }

            return ['score' => $dobOk && $nameOk ? $full : 0.6, 'dobMatches' => ! empty($found['dob']) ? $dobOk : null, 'identity' => $dobOk && $nameOk, 'evidence' => $evidence];
        };
        if (! empty($found['cardNo']) && self::normCard($found['cardNo']) && self::normCard($found['cardNo']) === ($member['cardNo'] ?? null)) {
            return $byIdentity("Card number {$member['cardNo']} matches", 1);
        }
        if (! empty($found['memberId']) && mb_strtoupper((string) $found['memberId']) === ($member['id'] ?? null)) {
            return $byIdentity("Member ID {$member['id']} matches", 0.99);
        }
        $sim = ! empty($found['name']) ? self::nameSimilarity($found['name'], $member['name'] ?? '') : 0;
        $dob = ! empty($found['dob']) ? self::normDate($found['dob']) : null;
        $dobMatches = $dob ? $dob === ($member['dob'] ?? null) : null;
        if (! empty($found['name'])) {
            $evidence[] = 'Name similarity '.round($sim * 100)."% (\"{$found['name']}\" vs \"{$member['name']}\")";
        }
        if ($dob) {
            $evidence[] = $dobMatches ? "Date of birth {$member['dob']} matches" : "Date of birth differs ({$dob} read, ".($member['dob'] ?? '—').' on file)';
        }
        $score = $sim * 0.7 + ($dobMatches ? 0.3 : 0);
        if ($dobMatches === false) {
            $score = min($score, 0.5);
        }

        return ['score' => round($score, 3), 'dobMatches' => $dobMatches, 'evidence' => $evidence];
    }
}
