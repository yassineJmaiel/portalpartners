<?php

namespace App\Portal\Intake;

use App\Portal\Crm;
use App\Portal\Ctx;
use App\Portal\Files;
use App\Portal\Jobs;
use App\Portal\PortalError;
use App\Portal\Retention;
use App\Portal\Vocab;
use Illuminate\Support\Facades\DB;
use Smalot\PdfParser\Parser;

/* Smart Intake: upload → read → split into documents → match the patient → read the diagnosis →
   quality signals → a person reviews → filed into claim drafts.
     patientAutoMatch (0.95)    a patient is filed automatically only when ONE candidate reaches it AND the date of birth matches;
     diagnosisConfidence (0.70) below it, or when the report is illegible, contradictory or silent, the diagnosis group is
                                "Unknown Illness" and the provider picks the code (R69 suggested). The document still goes under the right patient.
   Nothing here submits anything. Filing creates portal drafts; the provider opens each one in the claim wizard. */
class Intake
{
    private const CLINICAL = ['medical_report', 'discharge_summary', 'operative_note', 'referral_letter', 'lab_result', 'imaging_report', 'prescription', 'admission_request'];

    /* the fraud and quality signals the CRM's staff also see */
    private const FORWARDED_SIGNALS = ['duplicate-file', 'duplicate-image', 'duplicate-invoice', 'edited', 'totals', 'not-covered', 'other-provider'];

    /* signals that mean "a person should look at a value" */
    private const CHECK_KINDS = ['check', 'disagree'];

    /* the documents expected to state a diagnosis; without one they become Unknown Illness */
    private const DX_EXPECTED = ['medical_report', 'discharge_summary', 'operative_note'];

    /* the fields that depend on the kind of document, read again when a person changes it */
    private const TYPE_FIELDS = ['lines', 'medications', 'tests', 'modality', 'bodyPart', 'conclusion', 'procedure', 'paymentMethod', 'validity', 'validUntil', 'policyNo', 'invoiceNo', 'total'];

    public static function thresholds(): array
    {
        return array_merge(Vocab::INTAKE_DEFAULTS, (array) Retention::config('intake', []));
    }

    private static function json($v, $f = [])
    {
        return json_of($v, $f);
    }

    private static function enc($v): string
    {
        return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /* ---------------------------------------- reading the text out of a file */

    /** the text of each page, with wide gaps as double spaces so that table columns stay apart */
    public static function pdfPages(string $bytes): array
    {
        try {
            $pdf = (new Parser())->parseContent($bytes);
        } catch (\Throwable) {
            return [];
        }
        $pages = [];
        foreach ($pdf->getPages() as $page) {
            try {
                $pages[] = self::pageLines($page);
            } catch (\Throwable) {
                $pages[] = '';
            }
        }

        return $pages;
    }

    private static function pageLines($page): string
    {
        $rows = [];
        foreach ($page->getDataTm() as [$m, $str]) {
            if (trim((string) $str) === '') {
                continue;
            }
            $y = (int) (round((float) $m[5] / 3) * 3);
            $rows[$y][] = ['x' => (float) $m[4], 'str' => (string) $str, 'size' => abs((float) $m[0]) ?: 10];
        }
        if (! $rows) {
            return trim((string) $page->getText());
        }
        krsort($rows);
        $lines = [];
        foreach ($rows as $items) {
            usort($items, fn ($a, $b) => $a['x'] <=> $b['x']);
            $out = '';
            $end = null;
            foreach ($items as $it) {
                /* a glyph's width is not in the text layer here; half the font size per letter is near enough to tell a space from a column */
                if ($end !== null) {
                    $gap = $it['x'] - $end;
                    $out .= $gap > $it['size'] * 1.5 ? '   ' : ($gap > $it['size'] * 0.15 ? ' ' : '');
                }
                $out .= $it['str'];
                $end = $it['x'] + mb_strlen($it['str']) * $it['size'] * 0.5;
            }
            $lines[] = rtrim($out);
        }

        return implode("\n", $lines);
    }

    /** 8×8 average hash, to spot a re-sent photo */
    public static function imageHash(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $img = @imagecreatefromstring($bytes);
        if (! $img) {
            return null;
        }
        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $img, 0, 0, 0, 0, 8, 8, imagesx($img), imagesy($img));
        $grey = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $c = imagecolorat($small, $x, $y);
                $grey[] = (($c >> 16) & 255) * 0.3 + (($c >> 8) & 255) * 0.59 + ($c & 255) * 0.11;
            }
        }
        $mean = array_sum($grey) / 64;
        $out = '';
        foreach (array_chunk($grey, 4) as $nibble) {
            $v = 0;
            foreach ($nibble as $g) {
                $v = ($v << 1) | ($g > $mean ? 1 : 0);
            }
            $out .= dechex($v);
        }

        return $out;
    }

    private static function hasText(array $pages): bool
    {
        foreach ($pages as $t) {
            if (mb_strlen((string) preg_replace('/\s+/u', '', (string) $t)) > 40) {
                return true;
            }
        }

        return false;
    }

    private static function unreadableDoc(array $pages, ?string $reason): array
    {
        $list = $pages ?: [''];

        return ['pages' => range(1, count($list)), 'type' => 'other', 'typeConfidence' => 0, 'fields' => [], 'text' => '',
            'legibility' => ['legible' => false, 'handwritten' => null, 'diagnosisConfidence' => 0, 'contradictory' => false, 'illegibleSnippet' => $reason ?: 'No readable text']];
    }

    /** what the browser's text recognition sent, kept to the expected shape: it is the browser's word, not the portal's */
    private static function normaliseOcr(?array $ocr): ?array
    {
        if (! $ocr || ! is_array($ocr['pages'] ?? null)) {
            return null;
        }
        $clamp = fn ($n) => max(0.0, min(1.0, (float) $n));
        $pages = [];
        foreach (array_slice($ocr['pages'], 0, 50) as $p) {
            $lines = [];
            foreach (is_array($p['lines'] ?? null) ? $p['lines'] : [] as $l) {
                if (is_string($l['text'] ?? null)) {
                    $lines[] = ['text' => mb_substr($l['text'], 0, 500), 'confidence' => $clamp($l['confidence'] ?? 0), 'box' => is_array($l['box'] ?? null) && count($l['box']) === 4 ? array_map($clamp, array_values($l['box'])) : null];
                }
            }
            $pages[] = ['text' => mb_substr(is_string($p['text'] ?? null) ? $p['text'] : implode("\n", array_column($lines, 'text')), 0, 50000), 'lines' => $lines];
        }

        return ['engine' => 'OCR in the browser ('.mb_substr((string) (($ocr['engine'] ?? null) ?: 'Tesseract'), 0, 40).')', 'handwritten' => is_bool($ocr['handwritten'] ?? null) ? $ocr['handwritten'] : null, 'pages' => $pages];
    }

    /** a value read by OCR is only as sure as the line it was read from */
    private static function applyOcrConfidence(array $fields, array $ocrPages): array
    {
        $lineOf = function (array $f) use ($ocrPages) {
            $pages = ! empty($f['page']) ? array_filter([$ocrPages[$f['page'] - 1] ?? null]) : $ocrPages;
            $needle = trim((string) ($f['value'] ?? '')) ?: mb_substr((string) $f['snippet'], 0, 40);
            foreach ($pages as $p) {
                foreach ($p['lines'] as $l) {
                    if ($needle !== '' && str_contains($l['text'], $needle)) {
                        return $l;
                    }
                }
                foreach ($p['lines'] as $l) {
                    if (mb_strlen(trim($l['text'])) > 3 && str_contains((string) $f['snippet'], trim($l['text']))) {
                        return $l;
                    }
                }
            }

            return null;
        };
        $adjust = function ($f) use ($lineOf) {
            if (! is_array($f) || empty($f['snippet'])) {
                return $f;
            }
            $l = $lineOf($f);

            return array_merge($f, ['source' => 'ocr', 'confidence' => round(min((float) ($f['confidence'] ?? 1), $l ? $l['confidence'] : 0.5), 2), 'box' => $l['box'] ?? null]);
        };
        foreach ($fields as $k => $v) {
            $fields[$k] = is_array($v) && array_is_list($v) ? array_map($adjust, $v) : $adjust($v);
        }

        return $fields;
    }

    /** text PDFs are read by the rules; scans and photos by what the browser read on them; with neither, a person enters the details */
    private static function readFile(object $file, string $bytes, ?array $ocrSent): array
    {
        $pages = $file->mime === 'application/pdf' ? self::pdfPages($bytes) : ($file->mime === 'text/plain' ? [$bytes] : []);
        $ocr = self::hasText($pages) ? null : self::normaliseOcr($ocrSent);
        if ($ocr) {
            $pages = array_column($ocr['pages'], 'text');
        }
        if (! self::hasText($pages)) {
            return ['engine' => $ocr['engine'] ?? 'none', 'note' => $ocr ? 'OCR found no readable text in this file. Enter the details by hand.' : 'This file has no text layer (a scan or a photo) and no OCR reader read it, so enter the details by hand.',
                'documents' => [self::unreadableDoc($pages, $ocr ? 'OCR found no readable text' : null)]];
        }
        $t = self::thresholds()['diagnosisConfidence'];
        $docs = [];
        foreach (Rules::splitPages($pages) as $d) {
            $kind = Rules::classify($d['text']);
            /* what is read depends on what the document is: medicines on a prescription, tests on a laboratory result, lines on an invoice */
            $type = $kind['id'] === 'unreadable' ? 'other' : $kind['id'];
            $fields = Rules::extractFields($d + ['type' => $type]);
            $legibility = $kind['id'] === 'unreadable' ? ['legible' => false, 'handwritten' => null, 'diagnosisConfidence' => 0, 'contradictory' => false, 'illegibleSnippet' => 'No readable text on these pages'] : null;
            $ocrLines = null;
            if ($ocr) {
                $fields = self::applyOcrConfidence($fields, $ocr['pages']);
                $ocrLines = array_map(fn ($n) => ['page' => $n, 'lines' => $ocr['pages'][$n - 1]['lines'] ?? []], $d['pages']);
                /* OCR of a clinical page: the diagnosis is only as sure as the line it was read from */
                $pageLines = array_merge(...array_map(fn ($x) => $x['lines'], $ocrLines));
                $mean = $pageLines ? array_sum(array_column($pageLines, 'confidence')) / count($pageLines) : 0;
                $dx = $fields['diagnosisText'] ?? null;
                $dxConf = $dx ? $dx['confidence'] : $mean;
                if (in_array($kind['id'], self::CLINICAL, true) && $dxConf < $t) {
                    usort($pageLines, fn ($a, $b) => $a['confidence'] <=> $b['confidence']);
                    $legibility = ['legible' => false, 'handwritten' => $ocr['handwritten'], 'diagnosisConfidence' => $dxConf, 'contradictory' => false, 'reason' => $ocr['handwritten'] ? 'handwritten-illegible' : 'low-confidence',
                        'illegibleSnippet' => mb_substr((string) (($dx['snippet'] ?? null) ?: ($pageLines[0]['text'] ?? '')), 0, 160) ?: 'OCR read this page with low confidence'];
                } elseif ($dx) {
                    $legibility = ['legible' => true, 'handwritten' => $ocr['handwritten'], 'diagnosisConfidence' => $dxConf, 'contradictory' => false];
                }
            }
            $docs[] = ['pages' => $d['pages'], 'type' => $type, 'typeConfidence' => $kind['confidence'], 'typeWhy' => $kind['why'], 'text' => $d['text'], 'fields' => $fields, 'legibility' => $legibility, 'ocrLines' => $ocrLines];
        }

        return ['engine' => $ocr['engine'] ?? 'Rules', 'documents' => $docs];
    }

    /* ---------------------------------------- the patient */

    private static function matchPatient(array $opts, array $fields): array
    {
        $found = ['name' => $fields['patientName']['value'] ?? null, 'dob' => $fields['dob']['value'] ?? null, 'cardNo' => $fields['cardNo']['value'] ?? null, 'memberId' => $fields['memberId']['value'] ?? null];
        if (! $found['name'] && ! $found['cardNo'] && ! $found['memberId']) {
            return ['status' => 'none', 'memberId' => null, 'candidates' => [], 'reason' => 'No patient name, card number or member ID was read.'];
        }
        $members = Crm::post('/v1/members/candidates', $found, $opts);
        $scored = array_map(fn ($m) => array_merge($m, Matching::scoreCandidate($found, $m)), $members);
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);
        $t = self::thresholds()['patientAutoMatch'];
        $strong = array_values(array_filter($scored, fn ($c) => $c['score'] >= $t));
        /* exactly one member at the threshold AND the date of birth on the document matches: an exact card number
           without a date of birth is a strong proposal a person confirms in one click, not an automatic filing */
        if (count($strong) === 1 && ($strong[0]['dobMatches'] ?? null) === true) {
            return ['status' => 'auto', 'memberId' => $strong[0]['id'], 'memberName' => $strong[0]['name'], 'score' => $strong[0]['score'], 'evidence' => $strong[0]['evidence'], 'candidates' => $scored];
        }
        if ($scored) {
            $top = $scored[0];
            $why = count($strong) > 1 ? 'More than one member matches closely.'
                : (($top['identity'] ?? null) === false ? "The card or member ID matches, but the document's name or date of birth does not. Confirm this is the right person."
                : (($top['dobMatches'] ?? null) === null ? 'No date of birth was read on this document, so a person must confirm.'
                : (($top['dobMatches'] ?? null) !== true ? 'The date of birth does not match, so a person must confirm.' : 'The best match scores '.round($top['score'] * 100).'%, below the '.round($t * 100).'% needed to file it automatically.')));

            return ['status' => 'review', 'memberId' => null, 'suggested' => $top['id'], 'candidates' => $scored, 'reason' => $why];
        }

        return ['status' => 'none', 'memberId' => null, 'candidates' => [], 'reason' => 'No VSahha member matches what was read.'];
    }

    /* ---------------------------------------- the diagnosis */

    private static function icdItems(array $opts, string $q): array
    {
        try {
            return Crm::get('/v1/icd10?q='.rawurlencode($q), $opts)['items'] ?? [];
        } catch (PortalError) {
            return [];
        }
    }

    private static function hit(array $items, string $code): ?array
    {
        foreach ($items as $x) {
            if ($x['code'] === $code) {
                return $x;
            }
        }
        foreach ($items as $x) {
            if (str_starts_with($code, $x['code'])) {
                return $x;
            }
        }

        return null;
    }

    private static function wordsCovered(string $written, string $title): float
    {
        $words = fn ($s) => array_unique(array_filter(explode(' ', Matching::normName($s)), fn ($w) => strlen($w) > 3));
        $a = $words($written);
        $b = $words($title);

        return $a && $b ? round(count(array_intersect($a, $b)) / max(count($a), count($b)), 2) : 0;
    }

    /** the codes a person chooses from when words are written and no code is printed, with how much of the words each title covers */
    private static function suggestIcd(array $opts, string $written): array
    {
        $seen = [];
        foreach (array_slice(array_values(array_filter(explode(' ', Matching::normName($written)), fn ($x) => strlen($x) > 3)), 0, 4) as $w) {
            foreach (array_slice(self::icdItems($opts, $w), 0, 4) as $x) {
                $seen[$x['code']] ??= ['code' => $x['code'], 'title' => $x['title'], 'confidence' => self::wordsCovered($written, $x['title']), 'why' => "\"{$w}\" in \"{$written}\"", 'source' => 'rules'];
            }
        }
        $list = array_values($seen);
        usort($list, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);

        return array_slice($list, 0, 5);
    }

    private static function readDiagnosis(array $opts, array $doc): array
    {
        $f = $doc['fields'] ?? [];
        $leg = $doc['legibility'] ?? null;
        $clinical = in_array($doc['type'], self::CLINICAL, true);
        $t = self::thresholds()['diagnosisConfidence'];
        $codes = [];
        foreach (array_filter($f['icdPrinted'] ?? [], fn ($x) => Vocab::isIcd($x['value'])) as $p) {
            $hit = self::hit(self::icdItems($opts, $p['value']), $p['value']);
            $codes[] = ['code' => $p['value'], 'title' => $hit['title'] ?? null, 'inCatalogue' => (bool) $hit, 'confidence' => $hit ? $p['confidence'] : min($p['confidence'], 0.8), 'page' => $p['page'], 'snippet' => $p['snippet'], 'source' => $p['source']];
        }
        /* no printed code: a person picks one, from these */
        $written = $f['diagnosisText']['value'] ?? null;
        $suggestions = ! $codes && $written ? self::suggestIcd($opts, $written) : [];
        usort($codes, fn ($a, $b) => $b['confidence'] <=> $a['confidence']);
        $best = $codes[0] ?? null;
        $ocrConfidence = $leg && is_numeric($leg['diagnosisConfidence'] ?? null) && ($f['diagnosisText']['source'] ?? null) === 'ocr' ? (float) $leg['diagnosisConfidence'] : null;
        /* how sure the reading of the words is: a suggested code does not change it */
        $confidence = $best ? min($best['confidence'], $ocrConfidence ?? 1) : ($written ? ($ocrConfidence ?? ($f['diagnosisText']['confidence'] ?? 0)) : 0);

        /* a prescription, a laboratory result or an imaging report often states no diagnosis: the claim takes it
           from the visit's other documents, and only the whole visit without one becomes Unknown Illness (fileBatch) */
        $reason = null;
        if ($leg && ($leg['legible'] ?? null) === false) {
            $reason = $leg['reason'] ?? (($leg['handwritten'] ?? null) === false ? 'low-confidence' : 'handwritten-illegible');
        } elseif (! empty($leg['contradictory'])) {
            $reason = 'contradictory';
        } elseif (in_array($doc['type'], self::DX_EXPECTED, true) && ! $best && ! $written) {
            $reason = 'missing';
        } elseif (! $best && ! $written) {
            return ['present' => false, 'clinical' => $clinical, 'code' => null, 'title' => null, 'confidence' => 0, 'codes' => [], 'suggestions' => [], 'written' => null, 'unknown' => false, 'needsCode' => false, 'reason' => null, 'reasonText' => null, 'snippet' => null];
        } elseif ($confidence < $t) {
            $reason = 'low-confidence';
        }

        return ['present' => (bool) ($best || $written || $clinical || ($leg && ($leg['legible'] ?? null) === false)), 'clinical' => $clinical, 'code' => $reason ? null : ($best['code'] ?? null), 'title' => $reason ? null : ($best['title'] ?? null), 'confidence' => $confidence,
            'codes' => $codes, 'suggestions' => $suggestions, 'written' => $written, 'unknown' => (bool) $reason,
            /* readable words and no code: not Unknown Illness, a person picks the code */
            'needsCode' => ! $reason && ! $best,
            'reason' => $reason, 'reasonText' => $reason ? (Vocab::UNKNOWN_REASONS[$reason] ?? null) : null, 'snippet' => ($leg['illegibleSnippet'] ?? null) ?: ($f['diagnosisText']['snippet'] ?? null)];
    }

    /* ---------------------------------------- quality signals */

    private static function signalsFor(array $opts, string $providerId, array $doc, array $patient, array $fileInfo, array $context): array
    {
        $out = [];
        $f = $doc['fields'] ?? [];
        if ($t = Rules::lineTotalsSignal($f)) {
            $out[] = $t;
        }
        if ($context['pdfEdit']) {
            $out[] = $context['pdfEdit'];
        }
        $inv = $f['invoiceNo']['value'] ?? null;
        if ($inv) {
            foreach ($context['claims'] as $c) {
                if (($c['invoiceNo'] ?? null) === $inv && ($c['workflowStatus'] ?? null) !== 'Cancelled') {
                    $out[] = ['kind' => 'duplicate-invoice', 'severity' => 'block', 'text' => "Invoice {$inv} has already been claimed ({$c['id']})."];
                    break;
                }
            }
        }
        $tax = $f['taxNumber']['value'] ?? null;
        $ownTax = $context['profile']['billing']['taxNumber'] ?? null;
        if ($tax && $ownTax && str_replace(' ', '', Matching::normName($tax)) !== str_replace(' ', '', Matching::normName($ownTax))) {
            $out[] = ['kind' => 'other-provider', 'severity' => 'warn', 'text' => "The tax number on the invoice ({$tax}) is not yours ({$ownTax})."];
        }
        $provName = (string) ($context['profile']['name'] ?? '');
        $issuer = implode(' ', array_slice(preg_split('/\r?\n/', (string) ($doc['text'] ?? '')) ?: [], 0, 4));
        $firstWord = explode(' ', Matching::normName($provName))[0];
        if ($doc['type'] === 'invoice' && trim($issuer) !== '' && ($firstWord === '' || ! str_contains(Matching::normName($issuer), $firstWord))
            && preg_match('/(clinic|clinique|hospital|hôpital|hopital|centre|center|polyclinic|laborato)/iu', $issuer) && Matching::nameSimilarity($issuer, $provName) < 0.5) {
            $out[] = ['kind' => 'other-provider', 'severity' => 'warn', 'text' => 'The invoice header names another facility. Check that it is yours.'];
        }
        if (! empty($patient['memberId']) && ! empty($f['serviceDate']['value'])) {
            try {
                $e = Crm::post('/v1/eligibility', ['providerId' => $providerId, 'memberId' => $patient['memberId'], 'serviceDate' => $f['serviceDate']['value']], $opts);
                if (empty($e['eligible'])) {
                    $out[] = ['kind' => 'not-covered', 'severity' => 'block', 'text' => "Not covered on {$f['serviceDate']['value']}: {$e['summary']}"];
                }
            } catch (PortalError) {
                // eligibility is re-checked by the claim check
            }
        }
        if (! empty($fileInfo['duplicateOf'])) {
            $out[] = ['kind' => 'duplicate-file', 'severity' => 'block', 'text' => "This exact file was already uploaded ({$fileInfo['duplicateOf']->name}, ".substr((string) $fileInfo['duplicateOf']->at, 0, 10).').'];
        }
        if (! empty($fileInfo['nearDuplicate'])) {
            $out[] = ['kind' => 'duplicate-image', 'severity' => 'warn', 'text' => "This image looks like one already uploaded ({$fileInfo['nearDuplicate']}).'"];
        }

        return array_merge($out, Rules::verifyFields($doc['type'], $f, (string) ($doc['text'] ?? '')));
    }

    /* ---------------------------------------- a batch: the upload returns at once, the reading is a job */

    public static function startBatch(Ctx $ctx, array $items): string
    {
        $batchId = portal_id('ITK');
        DB::table('intake_batches')->insert(['id' => $batchId, 'org_id' => $ctx->user->org_id, 'provider_id' => $ctx->providerId, 'created_by' => $ctx->user->id, 'created_at' => iso_now(), 'engine' => 'Rules', 'status' => 'Reading']);
        Jobs::enqueue('intake.read', ['batchId' => $batchId, 'orgId' => $ctx->user->org_id, 'providerId' => $ctx->providerId, 'userName' => "{$ctx->user->name} <{$ctx->user->email}>", 'files' => $items], 0, 2);

        return $batchId;
    }

    /** the job: run again from scratch if it was interrupted */
    public static function readBatchJob(array $p, array $job = []): array
    {
        $batchId = $p['batchId'];
        DB::table('intake_documents')->where('batch_id', $batchId)->delete();
        try {
            $notes = self::processBatch($p);
            DB::table('intake_batches')->where('id', $batchId)->update(['status' => 'Read', 'error' => null, 'notes' => self::enc($notes)]);

            return ['documents' => DB::table('intake_documents')->where('batch_id', $batchId)->count()];
        } catch (\Throwable $e) {
            DB::table('intake_batches')->where('id', $batchId)->update(['status' => 'Failed', 'error' => mb_substr($e->getMessage(), 0, 300)]);
            throw $e;
        }
    }

    private static function processBatch(array $p): array
    {
        $providerId = $p['providerId'];
        $opts = ['scope' => [$providerId], 'user' => $p['userName'] ?? null];
        $claims = Crm::get('/v1/claims', $opts);
        $profile = Crm::get("/v1/providers/{$providerId}", $opts);
        $notes = [];
        $engines = [];
        foreach ($p['files'] as $item) {
            $file = Files::meta($item['ref'], $p['orgId']);
            if (! $file) {
                continue;
            }
            $bytes = Files::read($file->ref);
            /* the same bytes uploaded before, by this provider */
            $prior = DB::table('intake_documents as d')->join('files as f', 'f.ref', '=', 'd.file_ref')->where('f.sha256', $file->sha256)->where('d.provider_id', $providerId)->orderBy('d.created_at')->first(['d.id', 'd.name', 'd.created_at as at']);
            $fileInfo = ['duplicateOf' => $prior];
            $ahash = str_starts_with((string) $file->mime, 'image/') ? self::imageHash($bytes) : null;
            if ($ahash) {
                /* very close only: two pages on the same letterhead also look alike at 8×8 */
                foreach (DB::table('intake_documents')->where('provider_id', $providerId)->where('pages', 'like', '%ahash":"%')->get(['name', 'pages']) as $r) {
                    if (Rules::hamming(self::json($r->pages)['ahash'] ?? null, $ahash) <= 1) {
                        $fileInfo['nearDuplicate'] = $r->name;
                        break;
                    }
                }
            }
            $read = self::readFile($file, $bytes, $item['ocr'] ?? null);
            if (! empty($read['note'])) {
                $notes[] = "{$file->name}: {$read['note']}";
            }
            if ($read['engine'] !== 'none') {
                $engines[$read['engine']] = true;
            }
            $pdfEdit = $file->mime === 'application/pdf' ? Rules::pdfEditSignal($bytes) : null;
            $matched = [];
            foreach ($read['documents'] as &$doc) {
                $doc['patientMatch'] = self::matchPatient($opts, $doc['fields'] ?? []);
                if (! empty($doc['patientMatch']['memberId'])) {
                    $matched[] = $doc['patientMatch'];
                }
            }
            unset($doc);
            foreach ($read['documents'] as $doc) {
                $patient = $doc['patientMatch'];
                /* a page that names nobody (a scan, a handwritten report) belongs to the patient of the other documents
                   in the same file, when there is exactly one: proposed, never filed without a person's confirmation */
                $ids = array_values(array_unique(array_column($matched, 'memberId')));
                if ($patient['status'] === 'none' && count($ids) === 1) {
                    $m = $matched[0];
                    $cand = collect($m['candidates'] ?? [])->firstWhere('id', $m['memberId']) ?? [];
                    $patient = ['status' => 'review', 'memberId' => null, 'suggested' => $m['memberId'], 'candidates' => [array_merge($cand, ['id' => $m['memberId'], 'name' => $m['memberName'], 'score' => $m['score'], 'evidence' => ["Same file as the document filed under {$m['memberName']}"]])],
                        'reason' => 'This page names no patient. It came in the same file as the documents of the patient below — confirm.'];
                }
                /* the member found fills the fields; what the page said stays beside them */
                if (! empty($patient['memberId'])) {
                    $doc['fields'] = Rules::linkMember($doc['fields'] ?? [], self::candidateOf($patient));
                }
                $diagnosis = self::readDiagnosis($opts, $doc);
                $signals = self::signalsFor($opts, $providerId, $doc, $patient, $fileInfo, ['claims' => $claims, 'profile' => $profile, 'pdfEdit' => $pdfEdit]);
                $kinds = array_column($signals, 'kind');
                $status = in_array('duplicate-file', $kinds, true) ? 'Duplicate' : ($patient['status'] === 'auto' && empty($diagnosis['needsCode']) && ! array_intersect($kinds, self::CHECK_KINDS) ? 'Auto-matched' : 'Needs review');
                DB::table('intake_documents')->insert(['id' => portal_id('IDC'), 'batch_id' => $p['batchId'], 'provider_id' => $providerId, 'file_ref' => $file->ref, 'name' => $file->name,
                    'pages' => self::enc(['list' => $doc['pages'], 'ahash' => $ahash, 'engine' => $read['engine'], 'ocr' => $doc['ocrLines'] ?? null, 'typeWhy' => $doc['typeWhy'] ?? null]), 'doc_type' => $doc['type'], 'type_confidence' => $doc['typeConfidence'] ?? null,
                    'text' => mb_substr((string) ($doc['text'] ?? ''), 0, 20000), 'fields' => self::enc($doc['fields'] ?? []), 'candidates' => self::enc($patient['candidates'] ?? []), 'patient' => self::enc($patient), 'diagnosis' => self::enc($diagnosis),
                    'duplicate_of' => $prior->id ?? null, 'signals' => self::enc($signals), 'status' => $status, 'created_at' => iso_now(), 'updated_at' => iso_now()]);
            }
        }
        if ($engines) {
            DB::table('intake_batches')->where('id', $p['batchId'])->update(['engine' => implode(' · ', array_keys($engines))]);
        }

        return $notes;
    }

    private static function candidateOf(array $patient): array
    {
        return collect($patient['candidates'] ?? [])->firstWhere('id', $patient['memberId']) ?? ['id' => $patient['memberId'], 'name' => $patient['memberName'] ?? null];
    }

    private static function docView(object $r): array
    {
        $patient = self::json($r->patient);
        $pages = self::json($r->pages);
        $fields = self::json($r->fields);

        return ['id' => $r->id, 'batchId' => $r->batch_id, 'fileRef' => $r->file_ref, 'name' => $r->name, 'pages' => $pages['list'] ?? [], 'engine' => $pages['engine'] ?? null, 'ocr' => $pages['ocr'] ?? null,
            'type' => $r->doc_type, 'typeLabel' => Rules::docType($r->doc_type)['label'], 'typeConfidence' => $r->type_confidence, 'typeWhy' => $pages['typeWhy'] ?? null,
            'fields' => ! empty($patient['memberId']) ? Rules::linkMember($fields, self::candidateOf($patient)) : $fields,
            'patient' => $patient, 'diagnosis' => self::json($r->diagnosis), 'signals' => self::json($r->signals), 'status' => $r->status, 'draftId' => $r->draft_id, 'textPreview' => mb_substr((string) $r->text, 0, 1500)];
    }

    public static function getBatch(Ctx $ctx, string $batchId): ?array
    {
        $b = DB::table('intake_batches')->where('id', $batchId)->where('org_id', $ctx->user->org_id)->first();
        if (! $b) {
            return null;
        }

        return ['batch' => ['id' => $b->id, 'providerId' => $b->provider_id, 'createdAt' => $b->created_at, 'engine' => $b->engine, 'status' => $b->status, 'error' => $b->error],
            'notes' => self::json($b->notes), 'documents' => $b->status === 'Read' ? DB::table('intake_documents')->where('batch_id', $batchId)->orderBy('created_at')->get()->map(fn ($r) => self::docView($r))->all() : []];
    }

    public static function listBatches(Ctx $ctx): array
    {
        return DB::table('intake_batches')->where('org_id', $ctx->user->org_id)->whereIn('provider_id', $ctx->scope)->orderByDesc('created_at')->limit(30)->get()->map(fn ($b) => ['id' => $b->id, 'createdAt' => $b->created_at, 'engine' => $b->engine, 'status' => $b->status,
            'docs' => DB::table('intake_documents')->where('batch_id', $b->id)->count(), 'filed' => DB::table('intake_documents')->where('batch_id', $b->id)->where('status', 'Filed')->count()])->all();
    }

    /* ---------------------------------------- a person's review: every change is kept, so the reader's accuracy can be measured */

    public static function reviewDocument(Ctx $ctx, string $docId, array $change): ?array
    {
        $r = DB::table('intake_documents as d')->join('intake_batches as b', 'b.id', '=', 'd.batch_id')->where('d.id', $docId)->where('b.org_id', $ctx->user->org_id)->first(['d.*']);
        if (! $r) {
            return null;
        }
        $fields = self::json($r->fields);
        $patient = self::json($r->patient);
        $diagnosis = self::json($r->diagnosis);
        $fb = fn ($field, $proposed, $final) => DB::table('intake_feedback')->insert(['document_id' => $docId, 'provider_id' => $r->provider_id, 'doc_type' => $r->doc_type, 'field' => $field,
            'proposed' => $proposed === null ? null : (string) $proposed, 'final' => $final === null ? null : (string) $final, 'accepted' => (string) ($proposed ?? '') === (string) ($final ?? ''), 'at' => iso_now(), 'user_id' => $ctx->user->id]);

        $type = $r->doc_type;
        if (! empty($change['type']) && $change['type'] !== $r->doc_type) {
            $fb('type', $r->doc_type, $change['type']);
            $type = $change['type'];
            /* read again for the new kind (medicines, tests, invoice lines…); what a person typed stays as typed */
            if ($r->text) {
                $fresh = Rules::extractFields(['text' => $r->text, 'pages' => self::json($r->pages)['list'] ?? [1], 'type' => $type]);
                foreach (self::TYPE_FIELDS as $k) {
                    if (($fields[$k]['source'] ?? null) === 'person') {
                        continue;
                    }
                    if (! isset($fresh[$k]) || $fresh[$k] === []) {
                        unset($fields[$k]);
                    } else {
                        $fields[$k] = $fresh[$k];
                    }
                }
            }
        }
        foreach ($change['fields'] ?? [] as $k => $v) {
            $before = $fields[$k]['value'] ?? null;
            if ((string) ($before ?? '') !== (string) ($v ?? '')) {
                $fb($k, $before, $v);
                if ($v === '' || $v === null) {
                    unset($fields[$k]);
                } else {
                    $fields[$k] = ['value' => $v, 'confidence' => 1, 'page' => $fields[$k]['page'] ?? null, 'snippet' => 'Entered by hand', 'source' => 'person'];
                }
            } elseif ($before !== null) {
                $fb($k, $before, $v);
            }
        }
        if (array_key_exists('memberId', $change)) {
            $fb('patient', ($patient['memberId'] ?? null) ?: ($patient['suggested'] ?? null), $change['memberId']);
            $cand = collect($patient['candidates'] ?? [])->firstWhere('id', $change['memberId']);
            /* a member picked by card number may not be among the candidates: ask the CRM, within this provider's scope */
            $found = null;
            if ($change['memberId']) {
                $found = $cand;
                if (! $found) {
                    try {
                        $found = Crm::post('/v1/members/candidates', ['memberId' => $change['memberId']], $ctx->crmOpts())[0] ?? null;
                    } catch (PortalError) {
                        $found = null;
                    }
                }
            }
            $patient = array_merge($patient, ['status' => $change['memberId'] ? 'confirmed' : 'none', 'memberId' => $change['memberId'] ?: null, 'memberName' => $cand['name'] ?? $found['name'] ?? ($change['memberName'] ?? null)]);
            $fields = Rules::linkMember($fields, $found);
            if (! $found) {
                foreach (['memberId', 'patientName', 'dob', 'cardNo'] as $k) {
                    if (($fields[$k]['source'] ?? null) === 'crm') {
                        unset($fields[$k]);
                    }
                }
            }
        }
        if (! empty($change['diagnosis'])) {
            $code = Vocab::normIcd($change['diagnosis']['code'] ?? '');
            if (! Vocab::isIcd($code)) {
                throw new PortalError(400, 'BAD_ICD', ($change['diagnosis']['code'] ?? '').' is not a valid ICD-10 code.');
            }
            $fb('diagnosis', ($diagnosis['code'] ?? null) ?: ($diagnosis['suggestions'][0]['code'] ?? null), $code);
            $unknown = ($change['diagnosis']['certainty'] ?? null) === Vocab::UNKNOWN_ILLNESS;
            $why = ($diagnosis['reason'] ?? null) ?: 'low-confidence';
            $diagnosis = array_merge($diagnosis, ['code' => $code, 'title' => ($change['diagnosis']['title'] ?? null) ?: null, 'confirmedBy' => 'person', 'needsCode' => false, 'unknown' => $unknown, 'certainty' => $unknown ? Vocab::UNKNOWN_ILLNESS : 'Confirmed',
                'reason' => $unknown ? $why : null, 'reasonText' => $unknown ? (Vocab::UNKNOWN_REASONS[$why] ?? null) : null]);
        }
        /* the checks follow the document: a person's "checked" is remembered, a corrected field no longer disputes, and the rest are worked out again */
        $meta = self::json($r->pages);
        $dismissed = $meta['dismissed'] ?? [];
        if (! empty($change['checked'])) {
            $dismissed[] = $change['checked'];
            $fb('check', $change['checked'], 'checked');
        }
        $meta['dismissed'] = array_values(array_unique($dismissed));
        $edited = array_filter(array_keys($change['fields'] ?? []), fn ($k) => ($fields[$k]['source'] ?? null) === 'person' || empty($fields[$k]));
        $signals = array_values(array_filter(array_merge(
            array_filter(self::json($r->signals), fn ($x) => ! in_array($x['kind'], ['check', 'absent'], true) && ! ($x['kind'] === 'disagree' && in_array($x['field'] ?? null, $edited, true))),
            Rules::verifyFields($type, $fields, (string) $r->text)
        ), fn ($x) => ! in_array($x['text'], $meta['dismissed'], true)));
        $status = ! empty($patient['memberId']) && empty($diagnosis['needsCode']) && ! array_intersect(array_column($signals, 'kind'), self::CHECK_KINDS) ? 'Ready to file' : 'Needs review';
        DB::table('intake_documents')->where('id', $docId)->update(['doc_type' => $type, 'fields' => self::enc($fields), 'patient' => self::enc($patient), 'diagnosis' => self::enc($diagnosis), 'signals' => self::enc($signals), 'pages' => self::enc($meta), 'status' => $status, 'updated_at' => iso_now()]);

        return self::docView(DB::table('intake_documents')->where('id', $docId)->first());
    }

    public static function batchOfDoc(Ctx $ctx, string $docId): ?string
    {
        return DB::table('intake_documents as d')->join('intake_batches as b', 'b.id', '=', 'd.batch_id')->where('d.id', $docId)->where('b.org_id', $ctx->user->org_id)->value('d.batch_id');
    }

    /** what a person's review can change on a document: for the audit trail */
    public static function reviewState(Ctx $ctx, string $docId): ?array
    {
        $r = DB::table('intake_documents as d')->join('intake_batches as b', 'b.id', '=', 'd.batch_id')->where('d.id', $docId)->where('b.org_id', $ctx->user->org_id)->first(['d.doc_type', 'd.status', 'd.patient', 'd.diagnosis', 'd.fields']);
        if (! $r) {
            return null;
        }
        $p = self::json($r->patient);
        $dx = self::json($r->diagnosis);
        $fields = [];
        foreach (self::json($r->fields) as $k => $f) {
            if (is_array($f) && array_key_exists('value', $f) && $f['value'] !== null) {
                $fields[$k] = $f['value'];
            }
        }

        return ['type' => $r->doc_type, 'status' => $r->status, 'memberId' => $p['memberId'] ?? null, 'icd' => $dx['code'] ?? null, 'certainty' => ($dx['certainty'] ?? null) ?: (! empty($dx['unknown']) ? Vocab::UNKNOWN_ILLNESS : null), 'fields' => $fields];
    }

    /* ---------------------------------------- filing: same patient + dates within 7 days = one visit; within a visit, one
       claim per condition, as the CRM groups codes. A document with no condition of its own goes with its condition when
       there is one choice; with several it stays apart, for a person, instead of being guessed. A GOP is linked only when
       exactly one open GOP covers the claim's condition on its date. */

    private static function conditionsOf(Ctx $ctx, array $codes): array
    {
        $list = array_values(array_unique(array_filter($codes)));
        if (! $list) {
            return [];
        }

        return collect(Crm::get('/v1/icd10/conditions?codes='.rawurlencode(implode(',', $list)), $ctx->crmOpts()))->keyBy('code')->all();
    }

    private static function byCondition(array $docs, array $conditions): array
    {
        $keyOf = function ($d) use ($conditions) {
            if (! empty($d['diagnosis']['unknown'])) {
                return null;
            }
            if (! empty($d['diagnosis']['code'])) {
                $c = $conditions[$d['diagnosis']['code']] ?? null;

                return ! empty($c['opensCase']) ? $c['key'] : null;
            }

            return ! empty($d['diagnosis']['needsCode']) ? 'written:'.Matching::normName($d['diagnosis']['written']) : null;
        };
        $groups = [];
        $without = [];
        foreach ($docs as $d) {
            if ($k = $keyOf($d)) {
                $groups[$k][] = $d;
            } else {
                $without[] = $d;
            }
        }
        $known = $groups;
        $apart = [];
        foreach ($without as $d) {
            $sameFile = array_keys(array_filter($known, fn ($list) => in_array($d['fileRef'], array_column($list, 'fileRef'), true)));
            $home = count($sameFile) === 1 ? $sameFile[0] : (count($known) === 1 ? array_key_first($known) : null);
            if ($home !== null) {
                $groups[$home][] = $d;
            } else {
                $apart[] = $d;
            }
        }
        $out = [];
        foreach ($groups as $key => $list) {
            $out[] = ['key' => $key, 'docs' => $list];
        }
        if ($apart) {
            $out[] = ['key' => null, 'docs' => $apart];
        }

        return $out;
    }

    private static function marked(?array $f): ?array
    {
        return $f && ! in_array($f['source'] ?? null, ['person', 'crm'], true) ? ['value' => $f['value'], 'confidence' => $f['confidence'] ?? 0, 'page' => $f['page'] ?? null, 'snippet' => ($f['snippet'] ?? null) ?: null, 'source' => ($f['source'] ?? null) ?: 'rules'] : null;
    }

    public static function fileBatch(Ctx $ctx, string $batchId): array
    {
        $docs = array_values(array_filter(DB::table('intake_documents')->where('batch_id', $batchId)->whereIn('status', ['Ready to file', 'Needs review', 'Auto-matched'])->get()->map(fn ($r) => self::docView($r))->all(),
            fn ($d) => ! empty($d['patient']['memberId']) && ! in_array('duplicate-file', array_column($d['signals'], 'kind'), true)));
        if (! $docs) {
            return ['drafts' => [], 'skipped' => 'No document has a confirmed patient yet.'];
        }
        $openGops = array_values(array_filter(Crm::get('/v1/preauths', $ctx->crmOpts()), fn ($p) => ! empty($p['gop']) && in_array($p['status'] ?? null, ['Approved', 'Partially Approved', 'GOP Generated', 'GOP Sent', 'GOP Revised'], true) && ($p['gop']['expiresInDays'] ?? 1) >= 0 && empty($p['billed'])));
        $codes = array_column(array_column($docs, 'diagnosis'), 'code');
        foreach ($openGops as $p) {
            $codes = array_merge($codes, $p['icd10'] ?? []);
        }
        $conditions = self::conditionsOf($ctx, $codes);
        $byPatient = [];
        foreach ($docs as $d) {
            $byPatient[$d['patient']['memberId']][] = $d;
        }
        $drafts = [];
        foreach ($byPatient as $memberId => $list) {
            $dated = array_map(fn ($d) => ['d' => $d, 'date' => ($d['fields']['serviceDate']['value'] ?? null) ?: ($d['fields']['admissionDate']['value'] ?? null)], $list);
            usort($dated, fn ($a, $b) => strcmp((string) ($a['date'] ?: '9999'), (string) ($b['date'] ?: '9999')));
            $visits = [];
            foreach ($dated as ['d' => $d, 'date' => $date]) {
                $placed = false;
                foreach ($visits as &$v) {
                    if (! $date || ! $v['date'] || abs(days_between($date, $v['date'])) <= 7) {
                        $v['docs'][] = $d;
                        $v['date'] = $v['date'] ?: $date;
                        $placed = true;
                        break;
                    }
                }
                unset($v);
                if (! $placed) {
                    $visits[] = ['date' => $date, 'docs' => [$d]];
                }
            }
            foreach ($visits as $v) {
                foreach (self::byCondition($v['docs'], $conditions) as $g) {
                    $drafts[] = self::draftOf($ctx, $batchId, $memberId, $g['key'], $g['docs'], $v['date'], $openGops, $conditions);
                }
            }
        }

        return ['drafts' => $drafts];
    }

    private static function draftOf(Ctx $ctx, string $batchId, string $memberId, ?string $key, array $docs, ?string $date, array $openGops, array $conditions): array
    {
        /* a code that opens a condition leads; a symptom code does not hide it */
        $confirmed = array_values(array_filter(array_column($docs, 'diagnosis'), fn ($x) => ! empty($x['code']) && empty($x['unknown'])));
        usort($confirmed, fn ($a, $b) => (int) ! empty($conditions[$b['code']]['opensCase']) <=> (int) ! empty($conditions[$a['code']]['opensCase']));
        $unknownDoc = collect($docs)->first(fn ($d) => ! empty($d['diagnosis']['unknown']));
        $toCode = collect($docs)->first(fn ($d) => ! empty($d['diagnosis']['needsCode']));
        $primary = $confirmed[0] ?? null;
        $unknown = ! $primary && ! $toCode;
        $code = $primary['code'] ?? ($toCode ? '' : (($unknownDoc['diagnosis']['code'] ?? null) ?: self::thresholds()['unknownIllnessDefault']));
        $reason = $unknown ? (($unknownDoc['diagnosis']['reason'] ?? null) ?: 'missing') : null;
        $invoice = collect($docs)->first(fn ($d) => $d['type'] === 'invoice') ?? collect($docs)->first(fn ($d) => ! empty($d['fields']['lines']));
        $memberName = ($docs[0]['patient']['memberName'] ?? null) ?: $memberId;
        $validOn = fn ($p) => ! $date || ((empty($p['gop']['validFrom']) || $p['gop']['validFrom'] <= $date) && (empty($p['gop']['validUntil']) || $date <= $p['gop']['validUntil']));
        $covering = $key ? array_values(array_filter($openGops, fn ($p) => ($p['memberId'] ?? null) === $memberId && $validOn($p) && collect($p['icd10'] ?? [])->contains(fn ($c) => ($conditions[$c]['key'] ?? null) === $key))) : [];
        $gop = count($covering) === 1 ? $covering[0] : null;
        $documents = [];
        foreach ($docs as $d) {
            $t = Rules::docType($d['type']);
            if (! empty($t['claimDoc'])) {
                $documents[] = ['id' => $t['claimDoc'], 'ref' => $d['fileRef'], 'name' => "{$d['typeLabel']} — {$d['name']}"];
            }
        }
        $lineDocs = [];
        foreach ($docs as $d) {
            $t = Rules::docType($d['type']);
            if (! empty($t['lineDoc']) && $d['type'] !== 'invoice') {
                $lineDocs[] = ['id' => $t['lineDoc'], 'ref' => $d['fileRef'], 'name' => "{$d['typeLabel']} — {$d['name']}"];
            }
        }
        $lines = array_map(function ($l) use ($date, $invoice, $lineDocs) {
            $tariffCode = preg_match('/^SVC-/', (string) ($l['code'] ?? '')) ? $l['code'] : null;

            return ['description' => $l['description'], 'tariffCode' => $tariffCode, 'quantity' => $l['quantity'] ?: 1, 'claimedAmount' => $l['amount'], 'code' => null, 'serviceDate' => $date,
                'documents' => array_merge([['id' => 'line_invoice', 'ref' => $invoice['fileRef'], 'name' => "Invoice — {$invoice['name']}"]], $lineDocs),
                /* what was read for this line, to mark each value until a person confirms it */
                'ai' => ['confidence' => $l['confidence'] ?? 0, 'page' => $l['page'] ?? null, 'source' => ($l['source'] ?? null) ?: 'rules', 'snippet' => ($l['snippet'] ?? null) ?: null, 'description' => $l['description'], 'quantity' => $l['quantity'] ?: 1, 'amount' => $l['amount'], 'tariffCode' => $tariffCode]];
        }, $invoice['fields']['lines'] ?? []);
        $fieldOf = function (string $k, $value = null) use ($docs) {
            foreach ($docs as $d) {
                $f = $d['fields'][$k] ?? null;
                if ($f && ($value === null || $f['value'] === $value)) {
                    return $f;
                }
            }

            return null;
        };
        $treatmentDate = $date ?: '';
        $type = Rules::claimTypeFor($docs);
        $invoiceNo = ($invoice['fields']['invoiceNo']['value'] ?? null) ?: '';
        $primaryRead = $primary && ($primary['confirmedBy'] ?? null) !== 'person' ? collect($primary['codes'] ?? [])->firstWhere('code', $primary['code']) : null;
        $allAuto = ! array_filter($docs, fn ($d) => ($d['patient']['status'] ?? null) !== 'auto');
        $data = ['memberId' => $memberId, 'memberName' => $memberName, 'type' => $type, 'invoiceNo' => $invoiceNo, 'treatmentDate' => $treatmentDate, 'admissionDate' => $fieldOf('admissionDate')['value'] ?? '', 'dischargeDate' => $fieldOf('dischargeDate')['value'] ?? '',
            'physicianName' => $fieldOf('physician')['value'] ?? '', 'icd' => $code, 'diagnosis' => $primary ? (($primary['title'] ?? null) ?: $primary['code']) : ($toCode ? $toCode['diagnosis']['written'] : Vocab::UNKNOWN_ILLNESS),
            'secondaryIcd' => array_values(array_filter(array_column(array_slice($confirmed, 1), 'code'), fn ($c) => $c !== $code)), 'diagnosisCertainty' => $unknown ? Vocab::UNKNOWN_ILLNESS : 'Confirmed', 'unknownReason' => $reason, 'unknownSnippet' => $unknown ? ($unknownDoc['diagnosis']['snippet'] ?? null) : null,
            /* readable words without a code: the codes a person chooses from in the wizard */
            'dxSuggestions' => ! $primary && $toCode ? ($toCode['diagnosis']['suggestions'] ?? []) : [], 'preAuthId' => $gop['id'] ?? null, 'documents' => $documents,
            'lines' => $lines ?: [['description' => '', 'quantity' => 1, 'claimedAmount' => '', 'code' => null, 'documents' => []]], 'intakeId' => $batchId,
            /* every value a reader filled in, not only the invoice's */
            'aiFields' => ['member' => $allAuto ? ['value' => $memberId, 'confidence' => min(array_map(fn ($d) => $d['patient']['score'] ?? 0, $docs)), 'page' => null, 'snippet' => null, 'source' => 'rules'] : null,
                'type' => ['value' => $type, 'confidence' => ($invoice['typeConfidence'] ?? null) ?? ($docs[0]['typeConfidence'] ?? 0), 'page' => null, 'snippet' => null, 'source' => 'rules'],
                'invoiceNo' => self::marked($invoice['fields']['invoiceNo'] ?? null), 'treatmentDate' => self::marked($fieldOf('serviceDate', $treatmentDate ?: null) ?? $fieldOf('admissionDate', $treatmentDate ?: null)),
                'admissionDate' => self::marked($fieldOf('admissionDate')), 'dischargeDate' => self::marked($fieldOf('dischargeDate')), 'physicianName' => self::marked($fieldOf('physician')),
                'icd' => $primaryRead ? ['value' => $primaryRead['code'], 'confidence' => $primary['confidence'], 'page' => $primaryRead['page'] ?? null, 'snippet' => ($primaryRead['snippet'] ?? null) ?: null, 'source' => ($primaryRead['source'] ?? null) ?: 'rules'] : null,
                'total' => self::marked($invoice['fields']['total'] ?? null)]];
        $draftId = portal_id('DRF');
        $label = $unknown ? Vocab::UNKNOWN_ILLNESS : ($primary ? trim("{$code} ".($primary['title'] ?? '')) : "{$toCode['diagnosis']['written']} (no code yet)");
        $title = "{$memberName} · {$label}".($invoiceNo ? " · {$invoiceNo}" : '');
        DB::table('drafts')->insert(['id' => $draftId, 'org_id' => $ctx->user->org_id, 'provider_id' => $ctx->providerId, 'kind' => 'claim', 'title' => mb_substr($title, 0, 200), 'data' => self::enc($data), 'source' => 'intake', 'created_by' => $ctx->user->id, 'created_at' => iso_now(), 'updated_at' => iso_now()]);
        DB::table('intake_documents')->whereIn('id', array_column($docs, 'id'))->update(['status' => 'Filed', 'draft_id' => $draftId, 'updated_at' => iso_now()]);

        return ['id' => $draftId, 'title' => $title, 'memberId' => $memberId, 'unknown' => $unknown, 'needsCode' => $toCode && ! $primary, 'reason' => $reason, 'documents' => count($docs), 'preAuthId' => $data['preAuthId'], 'gopChoices' => count($covering) > 1 ? array_column($covering, 'ref') : []];
    }

    /** the signals to pass on with a claim, read here from what the portal itself found, never from the browser */
    public static function signalsForClaim(Ctx $ctx, ?string $draftId, ?string $memberId, ?string $batchId): array
    {
        $rows = DB::table('intake_documents as d')->join('intake_batches as b', 'b.id', '=', 'd.batch_id')->where('b.org_id', $ctx->user->org_id)->where('d.provider_id', $ctx->providerId)
            ->where(fn ($w) => $w->where('d.draft_id', $draftId ?: '')->orWhere('d.batch_id', $batchId ?: ''))->get(['d.name', 'd.signals', 'd.patient', 'd.draft_id']);
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            /* this claim's documents, and this patient's duplicates, which are never filed into a claim */
            if (! (($draftId && $r->draft_id === $draftId) || (! $r->draft_id && (self::json($r->patient)['memberId'] ?? null) === $memberId))) {
                continue;
            }
            foreach (self::json($r->signals) as $s) {
                $k = "{$s['kind']}|{$s['text']}";
                if (in_array($s['kind'], self::FORWARDED_SIGNALS, true) && ! isset($seen[$k])) {
                    $seen[$k] = true;
                    $out[] = ['kind' => $s['kind'], 'severity' => $s['severity'], 'text' => $s['text'], 'document' => $r->name];
                }
            }
        }

        return $out;
    }

    /** accuracy of the reader, from people's corrections */
    public static function accuracy(Ctx $ctx): array
    {
        return DB::table('intake_feedback')->where('provider_id', $ctx->providerId)->groupBy('doc_type', 'field')->orderByDesc('n')->get(['doc_type', 'field', DB::raw('COUNT(*) AS n'), DB::raw('SUM(accepted) AS ok')])
            ->map(fn ($r) => ['docType' => $r->doc_type, 'field' => $r->field, 'reviewed' => (int) $r->n, 'accepted' => (int) $r->ok])->all();
    }
}
