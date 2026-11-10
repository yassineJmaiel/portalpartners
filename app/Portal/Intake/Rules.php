<?php

namespace App\Portal\Intake;

use App\Portal\Vocab;

/* Smart Intake, the rule-based reader. It works on the text of a document and every value it returns
   carries value · confidence (0..1) · page · snippet (the text it was read from) · source, so a person
   can see where it came from. It never fills a field it did not read; a document with no text comes
   back marked unreadable, not guessed at. What it reads depends on what the document is. */
class Rules
{
    /* titles: what a document of this kind is headed with (counts most); words: found in its body;
       claimDoc / lineDoc: the CRM document-library ids the type feeds */
    public const DOC_TYPES = [
        'invoice' => ['label' => 'Invoice', 'claimDoc' => 'invoice', 'lineDoc' => 'line_invoice', 'paDoc' => 'Quotation / estimate',
            'titles' => ['invoice', 'facture', 'فاتورة', 'tax invoice', "note d'honoraires", 'note d honoraires', 'bill'],
            'words' => ['amount due', 'net a payer', 'total ttc', 'invoice no', 'n° facture', 'bill to', 'montant total', 'unit price', 'prix unitaire', 'total amount', 'المجموع', 'subtotal', 'sous-total']],
        'detailed_bill' => ['label' => 'Detailed bill', 'claimDoc' => 'detailed_bill', 'lineDoc' => 'line_invoice', 'paDoc' => 'Other',
            'titles' => ['detailed bill', 'itemised bill', 'itemized bill', 'releve detaille', 'detail des actes', 'statement of account'],
            'words' => ['itemised', 'itemized', 'breakdown', 'detail des actes']],
        'receipt' => ['label' => 'Receipt', 'claimDoc' => 'receipt', 'lineDoc' => null, 'paDoc' => 'Other',
            'titles' => ['receipt', 'payment receipt', 'recu', 'recu de paiement', 'quittance', 'إيصال', 'وصل'],
            'words' => ['amount paid', 'montant paye', 'received from', 'recu de', 'paid in full', 'acquitte', 'payment method', 'mode de paiement', 'mode de reglement']],
        'quotation' => ['label' => 'Quotation / estimate', 'claimDoc' => null, 'lineDoc' => null, 'paDoc' => 'Quotation / estimate',
            'titles' => ['quotation', 'devis', 'estimate', 'cost estimate', 'proforma', 'pro forma', 'pro-forma', 'عرض سعر'],
            'words' => ['valid for', 'valable', 'estimated cost', 'cout estime', 'estimation']],
        'prescription' => ['label' => 'Prescription', 'claimDoc' => 'prescription', 'lineDoc' => 'line_prescription', 'paDoc' => 'Prescription',
            'titles' => ['prescription', 'ordonnance', 'وصفة', 'وصفة طبية', 'rx', 'medical prescription'],
            'words' => ['posology', 'posologie', 'qsp', 'times a day', 'fois par jour', 'matin et soir', 'every morning', 'pendant', 'for 5 days', 'في اليوم', 'لمدة']],
        'lab_result' => ['label' => 'Laboratory result', 'claimDoc' => 'lab_results', 'lineDoc' => 'line_report', 'paDoc' => 'Laboratory result',
            'titles' => ['laboratory report', 'laboratory result', 'lab report', 'lab results', "compte rendu d'analyses", 'compte rendu d analyses', "resultats d'analyses", 'bilan biologique', 'analyses medicales', 'تحاليل', 'نتائج التحاليل',
                'pathology report', 'anatomic pathology', 'anatomie pathologique', 'compte rendu anatomopathologique'],
            'words' => ['reference range', 'valeurs de reference', 'normal range', 'hemoglobin', 'haemoglobin', 'hemoglobine', 'glycemia', 'glycemie', 'glucose', 'hba1c', 'creatinine', 'cholesterol', 'biologist', 'biologiste', 'prelevement', 'sample collected', 'laboratoire', 'laboratory']],
        'imaging_report' => ['label' => 'Imaging report', 'claimDoc' => 'imaging_report', 'lineDoc' => 'line_report', 'paDoc' => 'MRI report',
            'titles' => ['radiology report', 'imaging report', "compte rendu d'echographie", 'compte rendu de radiologie', 'mri', 'irm', 'ct scan', 'scanner', 'x-ray', 'radiographie', 'ultrasound', 'echographie', 'mammography', 'mammographie', 'doppler', 'أشعة'],
            'words' => ['radiologist', 'radiologue', 'technique', 'findings', 'impression', 'sequences', 'coupes', 'incidence', 'sagittal', 'axial', 'contrast', 'injection de produit']],
        'operative_note' => ['label' => 'Operative note', 'claimDoc' => null, 'lineDoc' => 'line_operative', 'paDoc' => 'Medical report',
            'titles' => ['operative note', 'operative report', 'operation note', 'compte rendu operatoire', 'تقرير العملية'],
            'words' => ['procedure performed', 'anesthesia', 'anaesthesia', 'anesthesie', 'incision', 'surgeon', 'chirurgien', 'bloc operatoire', 'intervention', 'suites operatoires']],
        'discharge_summary' => ['label' => 'Discharge summary', 'claimDoc' => 'discharge_summary', 'lineDoc' => null, 'paDoc' => 'Discharge summary',
            'titles' => ['discharge summary', 'discharge report', "compte rendu d'hospitalisation", 'compte rendu d hospitalisation', 'lettre de sortie', 'ملخص الخروج'],
            'words' => ['date of discharge', 'date de sortie', 'date of admission', "date d'entree", 'length of stay', 'duree de sejour', 'follow-up', 'hospitalised', 'hospitalise']],
        'referral_letter' => ['label' => 'Referral letter', 'claimDoc' => null, 'lineDoc' => 'line_referral', 'paDoc' => 'Physician letter',
            'titles' => ['referral letter', 'referral', "lettre d'orientation", 'lettre de liaison', 'رسالة إحالة'],
            'words' => ['dear colleague', 'cher confrere', 'je vous adresse', 'kindly see', 'i refer', 'whom i refer', 'refer this patient', 'merci de bien vouloir']],
        'admission_request' => ['label' => 'Admission request', 'claimDoc' => null, 'lineDoc' => null, 'paDoc' => 'Admission request',
            'titles' => ['admission request', "demande d'admission", 'request for admission', "demande d'hospitalisation", 'طلب دخول'],
            'words' => ['planned admission', 'admission prevue', 'expected length of stay']],
        'medical_report' => ['label' => 'Medical report', 'claimDoc' => 'medical_report', 'lineDoc' => 'line_report', 'paDoc' => 'Medical report',
            'titles' => ['medical report', 'rapport medical', 'certificat medical', 'medical certificate', 'consultation report', 'consultation note', 'compte rendu de consultation', 'تقرير طبي',
                "certificat d'arret de travail", 'arret de travail', 'sick leave', 'sick note', 'sick leave certificate', 'ecg report', 'electrocardiogram', 'electrocardiogramme', 'ecg', 'شهادة طبية'],
            /* any other "report" or "certificate" with nothing more specific */
            'weak' => ['report', 'rapport', 'compte rendu', 'certificat', 'certificate'],
            'words' => ['clinical findings', 'history of present illness', 'examen clinique', 'diagnosis', 'diagnostic', 'التشخيص', 'history', 'antecedents', 'complaint', 'motif de consultation']],
        'member_id' => ['label' => 'Member card', 'claimDoc' => 'member_id', 'lineDoc' => null, 'paDoc' => 'Other',
            'titles' => ['member card', 'insurance card', "carte d'adherent", 'carte adherent', "carte d'assure", 'بطاقة'],
            'words' => ['valid until', "valable jusqu'au", 'policy no', 'n° de police', 'member since']],
    ];

    private const PATIENT_LABELS = ['patient name', 'name of patient', 'nom du patient', 'nom et prenom', 'nom & prenom', 'beneficiaire', 'assure', 'received from', 'recu de', 'اسم المريض', 'المريض', 'patient', 'bill to', 'billed to', 'name'];

    private const DOB_LABELS = ['date of birth', 'dob', 'd.o.b', 'ne le', 'nee le', 'ne(e) le', 'date de naissance', 'تاريخ الميلاد'];

    private const INVOICE_LABELS = ['invoice no', 'invoice number', 'invoice #', 'invoice n°', 'facture n°', 'facture no', 'n° facture', 'no facture', 'n° de facture', 'رقم الفاتورة', 'receipt no', 'receipt number', 'recu n°', 'devis n°', 'quotation no', 'quote no'];

    private const DATE_LABELS = ['date of service', 'service date', 'treatment date', 'date des soins', 'date de soins', "date de l'acte", 'date of visit', 'date de consultation', 'exam date', 'date of examination', "date de l'examen", "date d'examen", 'date of procedure', 'date of surgery', "date de l'intervention", "date d'intervention",
        'sample collected', 'collection date', 'date of collection', 'date de prelevement', 'prelevement le', 'invoice date', 'date de facture', 'التاريخ', 'تاريخ', 'date'];

    private const DX_LABELS = ['final diagnosis', 'diagnosis', 'diagnostic retenu', 'diagnostic', 'impression', 'التشخيص', 'dx'];

    private const PHYSICIAN_LABELS = ['treating physician', 'attending physician', 'referring physician', 'physician', 'doctor', 'medecin traitant', 'medecin', 'radiologist', 'radiologue', 'surgeon', 'chirurgien', 'validated by', 'valide par', 'biologist', 'biologiste', 'الطبيب'];

    private const TOTAL_LABELS = ['total amount due', 'amount due', 'net a payer', 'total ttc', 'grand total', 'total due', 'amount paid', 'montant paye', 'montant recu', 'montant total', 'total', 'المجموع', 'montant', 'amount'];

    private const ADMISSION_LABELS = ['date of admission', 'admission date', "date d'entree", "date d'admission", 'admitted on'];

    private const DISCHARGE_LABELS = ['date of discharge', 'discharge date', 'date de sortie', 'discharged on'];

    private const TAX_LABELS = ['tax number', 'tax id', 'matricule fiscal', 'm.f', 'vat no', 'الرقم الجبائي'];

    private const PAYMENT_LABELS = ['payment method', 'paid by', 'mode de paiement', 'mode de reglement', 'طريقة الدفع'];

    private const CONCLUSION_LABELS = ['impression', 'conclusion', 'conclusions', 'الخلاصة', 'summary'];

    private const PROCEDURE_LABELS = ['procedure performed', 'procedure', 'operation', 'intervention', 'acte realise', 'العملية'];

    private const VALID_LABELS = ['valid until', 'expiry date', 'expires', "valable jusqu'au", "date d'expiration", 'date de validite'];

    private const POLICY_LABELS = ['policy no', 'policy number', 'n° de police', 'police n°', 'contrat n°'];

    private const CURRENCIES = ['USD', 'EUR', 'CHF', 'TND', 'MAD', 'GBP', 'AED', 'DZD', 'LYD', 'EGP', 'SAR', 'QAR', 'KWD'];

    private const THREE_DECIMALS = ['TND', 'LYD', 'KWD', 'BHD', 'OMR', 'JOD', 'IQD'];

    private const DATE_RE = '/\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4}|\d{4}-\d{2}-\d{2}|\d{1,2}(?:er|st|nd|rd|th)?\s+[A-Za-zéûè]+\.?,?\s+\d{4}|[A-Za-zéûè]+\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+\d{4}/u';

    private const DR_RE = "/\b(?:Dr|Pr|Prof)\.?[ \t]+[A-ZÀ-Ý][\wÀ-ÿ'’-]+(?:[ \t]+(?:[A-ZÀ-Ý][\wÀ-ÿ'’-]+|ben|bin|el|de|la|du)){0,3}/u";

    private const FINANCIAL = ['invoice', 'detailed_bill', 'receipt', 'quotation'];

    private const HEADER_WORDS = '/^(invoice|facture|receipt|recu|prescription|ordonnance|devis|quotation|medical report|rapport|laboratory|compte rendu|discharge|referr|mri|irm|ct|x-ray|scanner|ultrasound|echographie|radiographie|date|patient|page|tel|phone|fax|email|avenue|rue|street|signature)/iu';

    private const STRENGTH = '/(\d+(?:[.,]\d+)?\s?(?:mg|g|ml|mcg|µg|ui|iu|%)(?:\s*\/\s*\d*\s?(?:ml|g|dose))?)\b/iu';

    private const FREQUENCY = '/(\d+\s*(?:x|fois)\s*(?:\/|par)\s*(?:j(?:our)?|day|d)|(?:once|twice|three times|four times)\s+(?:a|per)\s+day|\d+\s*times?\s+(?:a|per)\s+day|every\s+\d+\s*(?:hours?|h)|toutes les\s+\d+\s*h(?:eures)?|matin(?:,)?\s*(?:midi\s*)?et\s+soir|every morning|every evening|at bedtime|au coucher|le matin|le soir|\d+\s*fois par jour|si (?:fi[eè]vre|douleur|besoin)|if (?:pain|fever|needed)|as needed|عند الحاجة|مرتين في اليوم|ثلاث مرات في اليوم|مرة في اليوم)/iu';

    private const DURATION = '/((?:for|pendant|durant|during)\s+\d+\s*(?:days?|jours?|weeks?|semaines?|months?|mois)|لمدة\s+\d+\s*(?:أيام|يوم|أسابيع|أشهر)|qsp\s+\d+\s*(?:jours?|mois|semaines?))/iu';

    private const NOT_A_DRUG = '/^(patient|date|dr\.?|pr\.?|nom|name|age|signature|stamp|cachet|qsp|tel|phone|adresse|address|rx|ordonnance|prescription|médecin|medecin|doctor|التاريخ|اسم|الطبيب|وصفة|tunis)\b/iu';

    private const LAB_HEADER = '/^(test|analyse|analysis|examen|parametre|parameter|resultat|result|التحليل)\b/iu';

    private const FLAG = '/^(h|l|high|low|haut|bas|↑|↓|\*)$/iu';

    private const MODALITIES = [
        ['MRI', '/\b(mri|irm|magnetic resonance|resonance magnetique)\b/u'],
        ['CT scan', '/\b(ct scan|ct|scanner|tomodensitometrie|tdm|computed tomography)\b/u'],
        ['Mammography', '/\b(mammograph(?:y|ie))\b/u'],
        ['Ultrasound', '/\b(ultrasound|echographie|echography|doppler|sonography)\b/u'],
        ['X-ray', '/\b(x-ray|xray|radiograph(?:y|ie)|radiographie|rx thorax)\b/u'],
        ['PET scan', '/\b(pet scan|pet-ct|tep)\b/u'],
    ];

    /* what a document of each kind should show; absent ones are listed so a person checks them */
    private const EXPECTED = ['invoice' => ['patientName', 'serviceDate', 'invoiceNo', 'total', 'currency'], 'detailed_bill' => ['patientName', 'serviceDate', 'total', 'currency'],
        'receipt' => ['patientName', 'serviceDate', 'total', 'currency'], 'quotation' => ['patientName', 'total', 'currency'],
        'prescription' => ['patientName', 'serviceDate', 'physician'], 'lab_result' => ['patientName', 'serviceDate'], 'imaging_report' => ['patientName', 'serviceDate', 'modality', 'conclusion'],
        'operative_note' => ['patientName', 'serviceDate', 'procedure'], 'discharge_summary' => ['patientName', 'admissionDate', 'dischargeDate'],
        'medical_report' => ['patientName', 'serviceDate'], 'referral_letter' => ['patientName'], 'admission_request' => ['patientName'], 'member_id' => ['patientName']];

    private const FIELD_NAMES = ['patientName' => 'patient name', 'serviceDate' => 'service date', 'invoiceNo' => 'document number', 'total' => 'total', 'currency' => 'currency', 'physician' => 'physician',
        'modality' => 'kind of imaging', 'conclusion' => 'conclusion', 'procedure' => 'procedure', 'admissionDate' => 'admission date', 'dischargeDate' => 'discharge date'];

    private const FROM_FILE = ['memberId' => 'id', 'patientName' => 'name', 'dob' => 'dob', 'cardNo' => 'cardNo'];

    private static ?array $allLabels = null;

    public static function docType(?string $id): array
    {
        return self::DOC_TYPES[$id ?? ''] ?? ['label' => 'Other document', 'claimDoc' => null, 'lineDoc' => 'line_other', 'paDoc' => 'Other'];
    }

    /** lower case, accents removed: "Échographie" and "ECHOGRAPHIE" match the same word */
    private static function fold($s): string
    {
        return mb_strtolower(Matching::deaccent((string) \Normalizer::normalize((string) $s, \Normalizer::FORM_C)));
    }

    private static function trim($s): string
    {
        return (string) preg_replace('/^\s+|\s+$/u', '', (string) $s);
    }

    private static function lines(string $text): array
    {
        return preg_split('/\r?\n/', $text) ?: [];
    }

    private static function hasWord(string $t, string $w): int
    {
        if (! str_contains($t, $w)) {
            return 0;
        }

        return (int) preg_match_all('/(^|[^a-z0-9\x{0600}-\x{06ff}])'.preg_quote($w, '/').'($|[^a-z0-9\x{0600}-\x{06ff}])/u', $t);
    }

    public static function classify($text): array
    {
        $raw = (string) $text;
        $t = self::fold($raw);
        if (mb_strlen((string) preg_replace('/\s+/u', '', $t)) < 20) {
            return ['id' => 'unreadable', 'label' => 'Unreadable', 'confidence' => 0, 'scores' => [], 'why' => null];
        }
        /* the title: the first lines, where a document says what it is */
        $headLines = array_slice(array_values(array_filter(array_map([self::class, 'trim'], self::lines($raw)), fn ($l) => $l !== '')), 0, 6);
        $head = self::fold(implode("\n", $headLines));
        $scores = [];
        foreach (self::DOC_TYPES as $id => $d) {
            $s = 0;
            $why = null;
            foreach ($d['titles'] as $w) {
                /* a line that IS the title ("INVOICE", "ORDONNANCE") is the strongest sign */
                $own = -1;
                foreach ($headLines as $i => $l) {
                    $f = self::trim(preg_replace("/[^a-z0-9\x{0600}-\x{06ff}' -]/u", '', self::fold($l)));
                    if ($f === $w || str_starts_with($f, "{$w} ") || str_ends_with($f, " {$w}")) {
                        $own = $i;
                        break;
                    }
                }
                if ($own >= 0) {
                    $add = 14 - $own;
                    if ($add > $s) {
                        $why = $why ?: 'Titled “'.mb_substr($headLines[$own], 0, 60).'”';
                    }
                    $s += $add;
                } elseif (self::hasWord($head, $w)) {
                    $s += 5;
                    $why = $why ?: "“{$w}” at the top";
                } elseif (mb_strlen($w) > 4 && self::hasWord($t, $w)) {
                    $s += 2;
                }
            }
            foreach ($d['words'] as $w) {
                if ($n = self::hasWord($t, $w)) {
                    $s += min($n, 3) * (mb_strlen($w) > 8 ? 2 : 1);
                }
            }
            foreach ($d['weak'] ?? [] as $w) {
                if (self::hasWord($head, $w)) {
                    $s += 3;
                }
            }
            $scores[$id] = ['id' => $id, 'score' => $s, 'why' => $why];
        }
        $moneyLines = count(array_filter(self::lines($raw), fn ($l) => preg_match('/\d[\d\s.,]*[.,]\d{2,3}\s*(usd|eur|chf|tnd|dt|mad|gbp|aed|€|\$)?\s*$/iu', $l)));
        if ($moneyLines >= 2) {
            $scores['invoice']['score'] += 3;
            $scores['quotation']['score'] += 1;
            $scores['detailed_bill']['score'] += 1;
        }
        if (preg_match("/^\s*(?:\d+[.)]|[-•*–])?\s*[a-zà-ÿ][\w'’\-]+(?:\s+[\w'’\-]+){0,3}\s+\d+(?:[.,]\d+)?\s?(?:mg|g|ml|mcg|µg|ui|iu)\b/imu", $raw) && ! $moneyLines) {
            $scores['prescription']['score'] += 4;
        }
        if (preg_match_all('/^.{2,40}\s{2,}\d+(?:[.,]\d+)?\s{2,}\S{1,12}\s{2,}\d+(?:[.,]\d+)?\s*[-–]\s*\d/imu', $raw) >= 2) {
            $scores['lab_result']['score'] += 6;
        }
        $list = array_values($scores);
        usort($list, fn ($a, $b) => $b['score'] <=> $a['score']);
        [$top, $second] = $list;
        if (! $top['score']) {
            return ['id' => 'other', 'label' => 'Other document', 'confidence' => 0.3, 'scores' => $list, 'why' => null];
        }
        $confidence = min(0.98, 0.5 + ($top['score'] - $second['score']) / ($top['score'] + 4) * 0.5);

        return ['id' => $top['id'], 'label' => self::docType($top['id'])['label'], 'confidence' => round($confidence, 2), 'scores' => array_slice($list, 0, 3), 'why' => $top['why'] ?: 'Words typical of this kind of document'];
    }

    /** one file, several documents: a new one starts when the type changes, when another patient is named, or with its own invoice number; "page 2 of 3" stays */
    public static function splitPages(array $pages): array
    {
        $docs = [];
        $cur = null;
        foreach ($pages as $i => $text) {
            $text = (string) $text;
            $kind = self::classify($text);
            $card = null;
            if (preg_match_all(Matching::CARD_RE, $text, $m)) {
                foreach ($m[0] as $x) {
                    if ($card = Matching::normCard($x)) {
                        break;
                    }
                }
            }
            $name = self::labelled($text, self::PATIENT_LABELS)['value'] ?? null;
            $inv = self::labelled($text, self::INVOICE_LABELS)['value'] ?? null;
            $continuation = preg_match('/\b(page|p\.)\s*[2-9]\s*(\/|of|sur)\s*\d/i', $text) || ($kind['id'] === 'unreadable' && ($docs[$cur]['unreadable'] ?? false));
            $has = $cur !== null;
            $differentPatient = $has && (($card && $docs[$cur]['card'] && $card !== $docs[$cur]['card']) || ($name && $docs[$cur]['name'] && ! self::sameName($name, $docs[$cur]['name'])));
            /* a page with no text after a readable one is a scan or handwriting: its own document */
            $scanAfterText = $has && $kind['id'] === 'unreadable' && ! $docs[$cur]['unreadable'];
            $newDoc = ! $has || $differentPatient || $scanAfterText
                || (! $continuation && ! in_array($kind['id'], ['other', 'unreadable'], true) && $kind['id'] !== $docs[$cur]['type'])
                || (! $continuation && $inv && $docs[$cur]['invoice'] && $inv !== $docs[$cur]['invoice']);
            if ($newDoc) {
                $docs[] = ['pages' => [$i + 1], 'type' => $kind['id'], 'card' => $card, 'name' => $name, 'invoice' => $inv, 'unreadable' => $kind['id'] === 'unreadable'];
                $cur = count($docs) - 1;
            } else {
                $docs[$cur]['pages'][] = $i + 1;
                $docs[$cur]['card'] = $docs[$cur]['card'] ?: $card;
                $docs[$cur]['name'] = $docs[$cur]['name'] ?: $name;
                $docs[$cur]['invoice'] = $docs[$cur]['invoice'] ?: $inv;
                if ($docs[$cur]['type'] === 'unreadable' && $kind['id'] !== 'unreadable') {
                    $docs[$cur]['type'] = $kind['id'];
                    $docs[$cur]['unreadable'] = false;
                }
            }
        }

        return array_map(fn ($d) => ['pages' => $d['pages'], 'text' => implode("\n\f\n", array_map(fn ($p) => (string) $pages[$p - 1], $d['pages']))], $docs);
    }

    private static function sameName($a, $b): bool
    {
        $f = fn ($x) => preg_replace('/[^a-z\x{0600}-\x{06ff}]/u', '', mb_strtolower((string) $x));

        return $f($a) === $f($b);
    }

    private static function allLabels(): array
    {
        if (self::$allLabels === null) {
            self::$allLabels = [];
            foreach ([self::PATIENT_LABELS, self::DOB_LABELS, self::INVOICE_LABELS, self::DATE_LABELS, self::DX_LABELS, self::PHYSICIAN_LABELS, self::TOTAL_LABELS, self::ADMISSION_LABELS, self::DISCHARGE_LABELS, self::TAX_LABELS, self::PAYMENT_LABELS, self::CONCLUSION_LABELS, self::PROCEDURE_LABELS, self::VALID_LABELS, self::POLICY_LABELS] as $group) {
                foreach ($group as $l) {
                    self::$allLabels[self::fold($l)] = true;
                }
            }
        }

        return self::$allLabels;
    }

    /** the value written after a label: "Patient: Leila Mansour"; labels match without accents or case, the value comes back as written */
    private static function labelled($text, array $labels, array $exclude = []): ?array
    {
        $lines = self::lines((string) $text);
        $folded = array_map([self::class, 'fold'], $lines);
        $bare = fn ($s) => self::trim(preg_replace('/[:：#.\-–\s]+$/u', '', (string) $s));
        $all = self::allLabels();
        foreach ($labels as $label) {
            $fl = self::fold($label);
            $re = '/(^|[^a-z\x{0600}-\x{06ff}])'.preg_quote($fl, '/').'\s*[:：#.\-–]?\s*(.+)$/iu';
            foreach ($lines as $i => $line) {
                foreach ($exclude as $x) {
                    if (str_contains($folded[$i], self::fold($x))) {
                        continue 2;
                    }
                }
                /* the label alone on its line: the value is on the next line */
                if ($bare($folded[$i]) === $fl) {
                    $j = $i + 1;
                    while ($j < count($lines) && self::trim($lines[$j]) === '') {
                        $j++;
                    }
                    if ($j < count($lines) && ! isset($all[$bare($folded[$j])])) {
                        $value = preg_replace('/\s{3,}.*$/u', '', self::trim($lines[$j]));
                        if (mb_strlen($value) > 1) {
                            return ['value' => $value, 'line' => self::trim($lines[$j]), 'lineNo' => $j, 'label' => $label];
                        }
                    }

                    continue;
                }
                if (! preg_match($re, $folded[$i], $m) || ! isset($m[2]) || mb_strlen(self::trim($m[2])) < 2) {
                    continue;
                }
                /* "Patient name" is a label, not a patient called "name" */
                if (isset($all[$bare($m[2])])) {
                    continue;
                }
                $at = mb_strlen($folded[$i]) - mb_strlen($m[2]);
                $value = preg_replace('/\s{3,}.*$/u', '', self::trim(mb_strlen($folded[$i]) === mb_strlen($line) ? mb_substr($line, $at) : $m[2]));
                if (mb_strlen($value) > 1) {
                    return ['value' => $value, 'line' => self::trim($line), 'lineNo' => $i, 'label' => $label];
                }
            }
        }

        return null;
    }

    public static function currencyOf($s): ?string
    {
        $t = (string) $s;
        foreach (self::CURRENCIES as $c) {
            if (preg_match("/\b{$c}\b/i", $t)) {
                return $c;
            }
        }
        if (preg_match('/\bDT\b|\bdinars?\b|د\.ت|دينار/iu', $t)) {
            return 'TND';
        }
        if (preg_match('/\bDH\b|\bdirhams?\b/i', $t)) {
            return 'MAD';
        }
        if (preg_match('/€|\beuros?\b/iu', $t)) {
            return 'EUR';
        }
        if (str_contains($t, '£')) {
            return 'GBP';
        }

        return preg_match('/US\s?\$|\$/', $t) ? 'USD' : null;
    }

    /** 1 250,000 DT · 1.234,50 EUR · 1,234.50 USD · 9,000 (dinars, three decimals) */
    public static function parseAmount($raw, ?string $hint = null): ?array
    {
        $s = (string) $raw;
        $cur = self::currencyOf($s);
        if (! preg_match("/-?\d{1,3}(?:[ \x{00A0}\x{202F}'.,]\d{3})+(?:[.,]\d{1,3})?|-?\d+(?:[.,]\d{1,3})?/u", $s, $m)) {
            return null;
        }
        $token = $m[0];
        /* spaces and apostrophes are always thousands */
        $spaced = (bool) preg_match("/[ \x{00A0}\x{202F}']/u", $token);
        $n = preg_replace("/[ \x{00A0}\x{202F}']/u", '', $token);
        $dots = substr_count($n, '.');
        $commas = substr_count($n, ',');
        $decimal = null;
        if ($dots && $commas) {
            $decimal = strrpos($n, '.') > strrpos($n, ',') ? '.' : ',';
        } elseif ($dots + $commas === 1) {
            $sep = $dots ? '.' : ',';
            $after = strlen($n) - strrpos($n, $sep) - 1;
            /* "9,000" is nine dinars and "1 250,000" is 1250; "1,234" is 1234 dollars */
            if ($after !== 3 || $spaced || in_array($cur ?: $hint, self::THREE_DECIMALS, true)) {
                $decimal = $sep;
            }
        }
        if ($decimal) {
            $n = str_replace($decimal === '.' ? ',' : '.', '', $n);
            $n = str_replace($decimal, '.', $n);
        } else {
            $n = str_replace(['.', ','], '', $n);
        }

        return is_numeric($n) ? ['value' => round((float) $n, 3), 'currency' => $cur] : null;
    }

    private static function field($value, float $confidence, $page, $snippet, string $source = 'rules'): ?array
    {
        return $value === null || $value === '' ? null : ['value' => $value, 'confidence' => round($confidence, 2), 'page' => $page, 'snippet' => mb_substr((string) $snippet, 0, 160), 'source' => $source];
    }

    /** which page of the document a snippet came from */
    private static function pageOf(array $doc, $snippet): int
    {
        $found = 0;
        foreach (explode("\n\f\n", (string) $doc['text']) as $i => $p) {
            if (str_contains($p, mb_substr((string) $snippet, 0, 40))) {
                $found = $i;
                break;
            }
        }

        return $doc['pages'][$found] ?? ($doc['pages'][0] ?? 1);
    }

    private static function dateIn($s): ?string
    {
        return preg_match(self::DATE_RE, (string) $s, $m) ? Matching::normDate($m[0]) : null;
    }

    private static function nice(string $s): string
    {
        return $s === mb_strtoupper($s) && preg_match('/[A-Z]/', $s) ? mb_convert_case(mb_strtolower($s), MB_CASE_TITLE) : $s;
    }

    public static function extractFields(array $doc): array
    {
        $text = (string) ($doc['text'] ?? '');
        $type = $doc['type'] ?? self::classify($text)['id'];
        $doc += ['pages' => [1]];
        $out = [];
        $put = function (string $k, $v, float $c, $snippet = null) use (&$out, $doc) {
            $snip = $snippet ?: $v;
            if ($f = self::field($v, $c, self::pageOf($doc, $snip), $snip)) {
                $out[$k] = $f;
            }
        };

        /* who */
        if (preg_match(Matching::CARD_RE, $text, $m) && ($c = Matching::normCard($m[0]))) {
            $put('cardNo', $c, 0.98, $m[0]);
        }
        if (preg_match(Matching::MEMBER_ID_RE, $text, $m)) {
            $put('memberId', mb_strtoupper($m[0]), 0.97, $m[0]);
        }
        $name = self::labelled($text, self::PATIENT_LABELS, ['nom du medecin', 'doctor name']);
        if ($name) {
            $clean = preg_replace('/\b(dob|date of birth|né|née|ne le|card|carte|id|age)\b.*$/iu', '', $name['value']);
            $clean = self::trim(preg_replace('/[,;|].*$/u', '', preg_replace('/\s*\(.*$/u', '', $clean)));
            /* "LEILA MANSOUR" from an OCR'd page reads as "Leila Mansour" */
            $nice = self::nice($clean);
            /* "Bill to" may name a company paying the bill: a weaker sign than "Patient" */
            if (mb_strlen($nice) > 2 && ! preg_match('/^\d/', $nice)) {
                $put('patientName', $nice, in_array($name['label'], ['name', 'bill to', 'billed to'], true) ? 0.7 : 0.88, $name['line']);
            }
        }
        $dob = self::labelled($text, self::DOB_LABELS);
        if (! $dob && preg_match('/\b(?:DOB|born)\s+(\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4})/i', $text, $m)) {
            $dob = ['value' => $m[1], 'line' => $m[0]];
        }
        if ($dob && ($d = self::dateIn($dob['value']))) {
            $put('dob', $d, 0.9, $dob['line']);
        }

        /* when */
        $notService = array_merge(self::DOB_LABELS, self::ADMISSION_LABELS, self::DISCHARGE_LABELS, self::VALID_LABELS, ['born', 'valable']);
        $sd = self::labelled($text, self::DATE_LABELS, $notService);
        $service = $sd ? self::dateIn($sd['value']) : null;
        if ($service) {
            $put('serviceDate', $service, $sd['label'] === 'date' ? 0.78 : 0.86, $sd['line']);
        } elseif (preg_match('/\b(?:le|on|في)\s+(\d{1,2}[\/.-]\d{1,2}[\/.-]\d{2,4})/iu', $text, $m) && Matching::normDate($m[1])) {
            /* "Tunis, le 18/10/2026": the date a letter or a prescription is written */
            $put('serviceDate', Matching::normDate($m[1]), 0.75, $m[0]);
        } else {
            /* the first date that is not a birth date: shown as a lower-confidence guess */
            foreach (self::lines($text) as $l) {
                foreach ($notService as $x) {
                    if (str_contains(self::fold($l), self::fold($x))) {
                        continue 2;
                    }
                }
                if ($d = self::dateIn($l)) {
                    $put('serviceDate', $d, 0.6, self::trim($l));
                    break;
                }
            }
        }
        if (($adm = self::labelled($text, self::ADMISSION_LABELS)) && ($d = self::dateIn($adm['value']))) {
            $put('admissionDate', $d, 0.86, $adm['line']);
        }
        if (($dis = self::labelled($text, self::DISCHARGE_LABELS)) && ($d = self::dateIn($dis['value']))) {
            $put('dischargeDate', $d, 0.86, $dis['line']);
        }

        /* by whom */
        $phys = self::labelled($text, self::PHYSICIAN_LABELS, ['date', 'nom du patient']);
        if ($phys && preg_match(self::DR_RE, $phys['value'], $m)) {
            $put('physician', self::trim($m[0]), 0.85, $phys['line']);
        } elseif (preg_match(self::DR_RE, $text, $m)) {
            $put('physician', self::trim($m[0]), 0.75, $m[0]);
        } elseif ($phys && preg_match('/[:：]/u', $phys['line']) && count(preg_split('/\s+/u', $phys['value'])) <= 5) {
            $put('physician', self::trim(preg_replace('/[,;].*$/u', '', $phys['value'])), 0.7, $phys['line']);
        }
        /* who issued it: the first line, when it names a place or a doctor */
        $firstLine = '';
        foreach (self::lines($text) as $l) {
            if (mb_strlen(self::trim($l)) > 2) {
                $firstLine = self::trim($l);
                break;
            }
        }
        $issuer = self::trim(str_replace(['|', "'"], '', preg_replace('/\s*[|—–-]\s*$/u', '', $firstLine)));
        if ($issuer !== '' && ! preg_match(self::HEADER_WORDS, self::fold($issuer)) && ! preg_match(self::DATE_RE, $issuer)) {
            $put('issuer', preg_replace('/\s+—.*$/u', '', self::nice($issuer)), 0.65, $firstLine);
        }
        if ($tax = self::labelled($text, self::TAX_LABELS)) {
            $put('taxNumber', self::trim(preg_replace('/^[:\s]+/u', '', preg_split('/\s{2,}/u', $tax['value'])[0])), 0.82, $tax['line']);
        }

        /* money */
        $currency = self::currencyOf($text);
        $financial = in_array($type, self::FINANCIAL, true) || $type === 'other';
        if ($financial) {
            $inv = self::labelled($text, self::INVOICE_LABELS);
            if (! $inv && preg_match('/\bINV[-\s]?\d[\w-]*/i', $text, $m)) {
                $inv = ['value' => $m[0], 'line' => $m[0]];
            }
            if ($inv) {
                $v = preg_split('/\s{2,}|\s+(?:date|du|of|le)\b/iu', $inv['value'])[0];
                $v = self::trim(preg_replace('/[^\w\/-]/u', '', preg_replace('/^[:\s#]+/u', '', $v)));
                if (mb_strlen($v) >= 3) {
                    $put('invoiceNo', $v, 0.9, $inv['line']);
                }
            }
            if (($total = self::labelled($text, self::TOTAL_LABELS, ['sous-total', 'subtotal', 'sub-total'])) && ($a = self::parseAmount($total['value'], $currency))) {
                $put('total', $a['value'], 0.88, $total['line']);
            }
            if ($type === 'receipt' && ($pm = self::labelled($text, self::PAYMENT_LABELS))) {
                $put('paymentMethod', preg_split('/\s{2,}/u', $pm['value'])[0], 0.8, $pm['line']);
            }
            if ($type === 'quotation' && preg_match('/(?:valid(?:e|able)?\s+(?:for\s+)?|valable\s+)(\d+\s*(?:days|jours|months|mois))/i', $text, $m)) {
                $put('validity', $m[1], 0.8, $m[0]);
            }
        }
        if ($currency) {
            $put('currency', $currency, 0.88, preg_match('/\b(USD|EUR|CHF|TND|DT|MAD|GBP|AED|LYD|DZD|EGP)\b|€|\$/u', $text, $m) ? $m[0] : $currency);
        }

        /* line items: "<description>   <qty>   <amount>" between the header and the total */
        $lines = [];
        if ($financial) {
            foreach (self::lines($text) as $l) {
                $t = self::trim($l);
                if ($t === '' || preg_match('/total|net a payer|net à payer|amount due|amount paid|montant pay|tva|vat|tax|subtotal|sous-total|المجموع|matricule|valable|valid/iu', $t)) {
                    continue;
                }
                /* columns apart, as a PDF's text layer gives them; or single spaces, as OCR gives them, where only a price with its decimals counts as a price */
                $cur = '(USD|EUR|CHF|TND|DT|MAD|GBP|AED|LYD|€|\$)?';
                if (! preg_match('/^(.{3,}?)\s{2,}(?:(\d{1,3})\s{2,})?(?:[\d.,\s]+\s{2,})?(-?\d[\d\s.,]*\d|\d)\s*'.$cur.'$/iu', $t, $m)
                    && (preg_match('/[:：]/u', $t) || ! preg_match('/^(.{3,}?)\s+(?:(\d{1,3})\s+)?(?:\d[\d\s]*[.,]\d{2,3}\s+)?(\d[\d\s]*[.,]\d{2,3})\s*'.$cur.'$/iu', $t, $m))) {
                    continue;
                }
                $desc = self::trim(preg_replace('/\s{2,}.*$/u', '', $m[1]));
                if (preg_match('/^(date|patient|invoice|facture|n°|no\.?|dob|card|carte|page|description|désignation|designation|produit|qty|qté)\b/iu', $desc) || mb_strlen($desc) < 3 || preg_match('/^\d+$/', $desc) || preg_match('/[:：]\s*$/u', $desc)) {
                    continue;
                }
                $a = self::parseAmount($m[3], $currency);
                if (! $a || $a['value'] <= 0) {
                    continue;
                }
                $code = preg_match('/\b(\d{5}|[A-Z]\d{4}|SVC-\d{4})\b/u', $desc, $cm) ? $cm[1] : null;
                $lines[] = ['description' => self::trim(preg_replace('/\s{2,}/u', ' ', preg_replace('/\b(\d{5}|SVC-\d{4})\b/u', '', $desc, 1))), 'code' => $code,
                    'quantity' => ($m[2] ?? '') !== '' ? (int) $m[2] : 1, 'amount' => $a['value'], 'confidence' => 0.78, 'page' => self::pageOf($doc, $t), 'snippet' => mb_substr($t, 0, 160), 'source' => 'rules'];
            }
        }
        $out['lines'] = $lines;

        if ($type === 'prescription') {
            $out['medications'] = self::readMedications($doc, $text);
        }
        if ($type === 'lab_result') {
            $out['tests'] = self::readTests($doc, $text);
        }
        if ($type === 'imaging_report') {
            $mod = null;
            $head = self::fold(implode(' ', array_slice(self::lines($text), 0, 8)));
            foreach ([$head, self::fold($text)] as $where) {
                foreach (self::MODALITIES as $x) {
                    if (preg_match($x[1], $where)) {
                        $mod = $x;
                        break 2;
                    }
                }
            }
            if ($mod) {
                $titleLine = $mod[0];
                foreach (self::lines($text) as $l) {
                    if (preg_match($mod[1], self::fold($l))) {
                        $titleLine = $l;
                        break;
                    }
                }
                $put('modality', $mod[0], 0.88, $titleLine);
                $part = preg_replace($mod[1], '', self::fold($titleLine), 1);
                $part = preg_replace("/compte rendu( d[e'])?|report|de l'|du|de la|des|d'/u", ' ', $part);
                $part = self::trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z\s-]/', ' ', $part)));
                if ($part !== '' && strlen($part) > 2 && strlen($part) < 40) {
                    $put('bodyPart', ucfirst($part), 0.7, $titleLine);
                }
            }
        }
        if (in_array($type, ['imaging_report', 'lab_result', 'medical_report', 'discharge_summary', 'operative_note'], true) && ($c = self::labelled($text, self::CONCLUSION_LABELS))) {
            $put('conclusion', $c['value'], 0.82, $c['line']);
        }
        if ($type === 'operative_note' && ($p = self::labelled($text, self::PROCEDURE_LABELS, ['date']))) {
            $put('procedure', $p['value'], 0.82, $p['line']);
        }
        if ($type === 'member_id') {
            if (($v = self::labelled($text, self::VALID_LABELS)) && ($d = self::dateIn($v['value']))) {
                $put('validUntil', $d, 0.85, $v['line']);
            }
            if ($pol = self::labelled($text, self::POLICY_LABELS)) {
                $put('policyNo', preg_split('/\s{2,}/u', $pol['value'])[0], 0.8, $pol['line']);
            }
        }

        /* diagnosis: printed ICD-10 codes, and the diagnosis as written */
        $icds = [];
        if (preg_match_all('/\b[A-TV-Z]\d{2}(?:\.\d{1,4})?\b/', $text, $m)) {
            $labelledCode = (bool) preg_match('/icd|cim|code/i', $text);
            foreach ($m[0] as $x) {
                $c = Vocab::normIcd($x);
                if (Vocab::isIcd($c) && (! preg_match('/^[A-Z]\d{2}$/', $c) || $labelledCode)
                    /* "L4-L5" and "L5" are vertebrae, not codes */
                    && ! preg_match('/^[CTLS]\d$/', $c) && ! preg_match('/'.preg_quote($c, '/').'\s*-\s*[CTLS]\d/', $text)) {
                    $icds[$c] = true;
                }
            }
        }
        $out['icdPrinted'] = array_map(function ($c) use ($text, $doc) {
            $at = (int) strpos($text, $c);

            return self::field($c, preg_match('/icd|cim|code/i', substr($text, max(0, $at - 30), min(30, $at))) ? 0.95 : 0.85, self::pageOf($doc, $c), mb_scrub(substr($text, max(0, $at - 40), min(40, $at) + 20)));
        }, array_keys($icds));
        if ($dx = self::labelled($text, self::DX_LABELS)) {
            /* "Low back pain (ICD-10 M54.5)" → "Low back pain": the code is read separately */
            $v = preg_replace('/\(?\s*(?:(?:ICD|CIM)-?10\s*:?\s*)?\b[A-TV-Z]\d{2}(?:\.\d{1,4})?\b\s*\)?/u', '', $dx['value']);
            $v = self::trim(preg_replace('/[:;,.(]+\s*$/u', '', str_replace('()', '', preg_replace('/\(\s*\)/u', '', $v))));
            if (mb_strlen($v) > 2) {
                $out['diagnosisText'] = self::field($v, 0.8, self::pageOf($doc, $dx['line']), $dx['line']);
            }
        }
        $cpts = [];
        if (preg_match_all('/\bCPT[:\s]*(\d{5})\b/i', $text, $m)) {
            $cpts = array_values(array_unique($m[1]));
        }
        $out['cpt'] = array_map(fn ($c) => self::field($c, 0.9, self::pageOf($doc, $c), $c), $cpts);

        return $out;
    }

    /** one medicine per line: "1. Ibuprofen 400 mg tablet — 1 tablet three times a day for 5 days" */
    private static function readMedications(array $doc, string $text): array
    {
        $forms = [['tablet', '/\b(tablets?|tabs?|comprim[eé]s?|cp|أقراص|قرص)\b/iu'], ['capsule', '/\b(capsules?|caps|g[eé]lules?)\b/iu'], ['syrup', '/\b(syrup|sirop|شراب)\b/iu'],
            ['injection', '/\b(injections?|injectable|ampoules?|amp|حقن)\b/iu'], ['sachet', '/\b(sachets?)\b/iu'], ['cream', '/\b(cream|cr[eè]me|pommade|ointment)\b/iu'],
            ['drops', '/\b(drops|gouttes|قطرات)\b/iu'], ['spray', '/\b(spray|nasal spray|pulv[eé]risation)\b/iu'], ['suppository', '/\b(suppositor(?:y|ies)|suppositoires?)\b/iu'],
            ['inhaler', '/\b(inhaler|inhalateur)\b/iu'], ['solution', '/\b(solution|lavage)\b/iu']];
        $meds = [];
        foreach (self::lines($text) as $raw) {
            $line = self::trim($raw);
            if ($line === '' || mb_strlen($line) < 4) {
                continue;
            }
            $bullet = (bool) preg_match('/^(\d+[.)]|[-•*–])\s*/u', $line);
            $body = preg_replace('/^(\d+[.)]|[-•*–])\s*/u', '', $line);
            if (preg_match(self::NOT_A_DRUG, self::fold($body)) || preg_match(self::DR_RE, mb_substr($body, 0, 6))) {
                continue;
            }
            $hasStrength = preg_match(self::STRENGTH, $body, $strength, PREG_OFFSET_CAPTURE);
            $hasSep = preg_match('/\s[—–:-]\s|\s*:\s/u', $body, $sep, PREG_OFFSET_CAPTURE);
            $instructionWords = preg_match(self::FREQUENCY, $body) || preg_match(self::DURATION, $body);
            /* no strength written: "Doliprane 1 tablet 3 times a day" */
            $hasDose = ! $hasStrength && preg_match("/^([A-ZÀ-Ý][\wÀ-ÿ'’-]+(?:\s+[A-Za-zÀ-ÿ'’-]+){0,2}?)\s+(\d+(?:[.,]\d+)?\s*(?:tablets?|tabs?|capsules?|caps?|sachets?|comprim[eé]s?|g[eé]lules?|ampoules?|drops|gouttes|puffs?|bouff[eé]es|cuill[eè]res?|spoons?))\b/iu", $body, $dose);
            if (! $hasStrength && ! $hasDose && ! ($bullet && $hasSep && $instructionWords)) {
                continue;
            }
            /* the name: the words before the strength, the dose or the separator (byte offsets throughout) */
            $strengthAt = $hasStrength ? $strength[0][1] : PHP_INT_MAX;
            $cut = $hasDose ? strlen($dose[1]) : min($strengthAt, $hasSep ? $sep[0][1] : PHP_INT_MAX);
            $name = self::trim(preg_replace('/[—–:,-]+\s*$/u', '', substr($body, 0, $cut)));
            if ($name === '' || mb_strlen($name) < 3 || preg_match('/^\d/', $name) || count(preg_split('/\s+/u', $name)) > 5) {
                continue;
            }
            $rest = substr($body, $cut + ($hasStrength && $cut === $strengthAt ? strlen($strength[0][0]) : 0));
            $form = null;
            foreach ($forms as $x) {
                if (preg_match($x[1], mb_substr($rest, 0, 40)) || preg_match($x[1], $name)) {
                    $form = $x;
                    break;
                }
            }
            $parts = preg_split('/\s[—–]\s|\s*:\s/u', $rest);
            $instructions = self::trim(implode(' ', array_slice($parts, 1)));
            if ($instructions === '') {
                $instructions = preg_replace('/^[\s—–:-]+/u', '', $rest);
                if ($form) {
                    $instructions = preg_replace($form[1], '', $instructions, 1);
                }
                $instructions = self::trim($instructions);
            }
            $instructions = preg_replace('/^[\s—–:-]+/u', '', $instructions);
            $meds[] = ['name' => preg_replace('/\s+(tablets?|capsules?|comprim[eé]s?|g[eé]lules?)$/iu', '', $name), 'strength' => $hasStrength ? preg_replace('/\s+/u', ' ', $strength[1][0], 1) : null, 'form' => $form[0] ?? null,
                'instructions' => $instructions ?: null, 'frequency' => preg_match(self::FREQUENCY, $body, $m) ? $m[0] : null, 'duration' => preg_match(self::DURATION, $body, $m) ? $m[0] : null,
                'confidence' => $hasStrength ? 0.85 : 0.72, 'page' => self::pageOf($doc, $line), 'snippet' => mb_substr($line, 0, 160), 'source' => 'rules'];
        }

        return $meds;
    }

    /** one test per row: "Fasting glucose   1.32   g/L   0.70 - 1.10   H" */
    private static function readTests(array $doc, string $text): array
    {
        $num = fn ($s) => (float) str_replace(',', '.', (string) $s);
        $tests = [];
        foreach (self::lines($text) as $raw) {
            $line = self::trim($raw);
            $cells = array_values(array_filter(array_map([self::class, 'trim'], preg_split('/\s{2,}/u', $line)), fn ($c) => $c !== ''));
            /* OCR keeps single spaces: "Ferritin 9 ng/mL 15 - 150 L" */
            if (count($cells) < 2 && preg_match('/^(.+?)\s+([<>≤≥]?\s*-?\d+(?:[.,]\d+)?)\s+(\S+)\s+(\d+(?:[.,]\d+)?\s*[-–]\s*\d+(?:[.,]\d+)?)(?:\s+(H|L|High|Low|Haut|Bas|↑|↓))?$/iu', $line, $m)) {
                $cells = array_merge([$m[1], $m[2], $m[3], $m[4]], ! empty($m[5]) ? [$m[5]] : []);
            }
            if (count($cells) < 2 || preg_match(self::LAB_HEADER, self::fold($cells[0]))) {
                continue;
            }
            if (! preg_match('/^[<>≤≥]?\s*-?\d+(?:[.,]\d+)?$/u', $cells[1])) {
                continue;
            }
            $name = preg_replace('/[:：]\s*$/u', '', $cells[0]);
            if (mb_strlen($name) < 2 || preg_match('/^\d/', $name) || preg_match('/date|patient|sample|prelevement/iu', self::fold($name))) {
                continue;
            }
            $tail = array_slice($cells, 2);
            $range = null;
            foreach ($tail as $c) {
                if (preg_match('/\d+(?:[.,]\d+)?\s*[-–]\s*\d+(?:[.,]\d+)?|^[<>≤≥]\s*\d/u', $c)) {
                    $range = $c;
                    break;
                }
            }
            $unit = null;
            foreach ($tail as $c) {
                if ($c !== $range && ! preg_match(self::FLAG, $c) && preg_match('/[a-zµ%\/^0-9]/iu', $c) && ! preg_match('/^\d+(?:[.,]\d+)?$/', $c)) {
                    $unit = $c;
                    break;
                }
            }
            $flag = null;
            foreach ($tail as $c) {
                if (preg_match(self::FLAG, $c)) {
                    $flag = mb_strtoupper($c);
                    break;
                }
            }
            $value = $cells[1];
            $clean = preg_replace('/[<>≤≥\s]/u', '', $value);
            $lim = $range && preg_match('/(\d+(?:[.,]\d+)?)\s*[-–]\s*(\d+(?:[.,]\d+)?)/u', $range, $rm) ? $rm : null;
            if (! $flag && $lim) {
                $flag = $num($clean) > $num($lim[2]) ? 'H' : ($num($clean) < $num($lim[1]) ? 'L' : null);
            }
            if ($flag) {
                $flag = preg_match('/^(h|high|haut|↑)$/iu', $flag) ? 'High' : (preg_match('/^(l|low|bas|↓)$/iu', $flag) ? 'Low' : $flag);
            }
            /* a result many times outside its range is more often a lost decimal point ("74" for 7.4) than a real value: a person looks at it */
            $doubt = null;
            if ($lim) {
                $v = $num($clean);
                $lo = $num($lim[1]);
                $hi = $num($lim[2]);
                if ($hi > 0 && $v > $hi * 5) {
                    $doubt = "{$name}: {$value} is far above the range {$range}. Check the decimal point.";
                } elseif ($lo > 0 && $v > 0 && $v < $lo / 5) {
                    $doubt = "{$name}: {$value} is far below the range {$range}. Check the decimal point.";
                } elseif (($flag === 'High' && $v <= $hi) || ($flag === 'Low' && $v >= $lo)) {
                    $doubt = "{$name}: marked {$flag} but {$value} is inside the range {$range}. Check the result.";
                }
            }
            $tests[] = ['name' => $name, 'value' => $value, 'unit' => $unit, 'range' => $range, 'flag' => $flag, 'doubt' => $doubt, 'confidence' => $doubt ? 0.5 : 0.84, 'page' => self::pageOf($doc, $line), 'snippet' => mb_substr($line, 0, 160), 'source' => 'rules'];
        }

        return $tests;
    }

    /** the member found fills the fields; what the page said stays beside them; a value a person typed is never overwritten; null takes the file's values away again */
    public static function linkMember(array $fields = [], ?array $member = null): array
    {
        $out = $fields;
        foreach (self::FROM_FILE as $k => $from) {
            $had = $out[$k] ?? null;
            if (($had['source'] ?? null) === 'person') {
                continue;
            }
            if (! $member) {
                if (($had['source'] ?? null) === 'crm') {
                    if (! empty($had['read'])) {
                        $out[$k] = $had['read'];
                    } else {
                        unset($out[$k]);
                    }
                }

                continue;
            }
            if (($member[$from] ?? '') === '' || ($member[$from] ?? null) === null) {
                continue;
            }
            $read = ($had['source'] ?? null) === 'crm' ? ($had['read'] ?? null) : $had;
            $out[$k] = ['value' => $member[$from], 'confidence' => 1, 'page' => $read['page'] ?? null, 'snippet' => ($read['snippet'] ?? null) ?: 'VSahha member file', 'source' => 'crm', 'read' => $read ?: null];
        }

        return $out;
    }

    /** the claim type a group of documents points to; only where the draft starts */
    public static function claimTypeFor(array $docs): string
    {
        $has = fn ($t) => (bool) array_filter($docs, fn ($d) => $d['type'] === $t);
        $issuer = mb_strtolower(implode(' ', array_map(fn ($d) => ($d['fields']['issuer']['value'] ?? '').' '.mb_substr((string) ($d['textPreview'] ?? ''), 0, 200), $docs)));
        if ($has('discharge_summary') || array_filter($docs, fn ($d) => ! empty($d['fields']['admissionDate']) || ! empty($d['fields']['dischargeDate'])) || $has('admission_request')) {
            return 'Inpatient / Hospitalization';
        }
        if ($has('operative_note')) {
            return 'Surgery';
        }
        $drugLines = function ($d) {
            $lines = $d['fields']['lines'] ?? [];
            foreach ($lines as $l) {
                if (! preg_match('/\d\s?(mg|g|ml)\b|comprim|tablet|capsule|g[eé]lule|sirop|syrup/iu', (string) $l['description'])) {
                    return false;
                }
            }

            return (bool) $lines;
        };
        $invoices = array_filter($docs, fn ($d) => $d['type'] === 'invoice');
        if (preg_match('/pharmac|صيدلي/u', $issuer) || ($has('prescription') && ! $has('medical_report') && ! $has('lab_result') && ! $has('imaging_report') && count(array_filter($invoices, $drugLines)) === count($invoices))) {
            return 'Pharmacy / Prescription Drugs';
        }
        if ($has('lab_result') && ! $has('medical_report') && ! $has('imaging_report')) {
            return 'Laboratory Services';
        }
        if ($has('imaging_report') && ! $has('medical_report') && ! $has('lab_result')) {
            return 'Diagnostic Services';
        }

        return 'Outpatient Care';
    }

    public static function lineTotalsSignal(array $fields): ?array
    {
        if (empty($fields['total']) || empty($fields['lines'])) {
            return null;
        }
        /* a line's amount is its line total, as invoices print it */
        $sum = round(array_sum(array_column($fields['lines'], 'amount')), 3);
        if (abs($sum - (float) $fields['total']['value']) <= 0.05) {
            return null;
        }

        return ['kind' => 'totals', 'severity' => 'warn', 'text' => "The lines add up to {$sum}, the invoice total says {$fields['total']['value']}."];
    }

    /** nothing here changes a value; it says which values do not hold up, because a misread number looks exactly like a correct one */
    public static function verifyFields(?string $type, array $f = [], string $text = '', ?string $today = null): array
    {
        $today = $today ?: portal_today();
        $out = [];
        $warn = function (string $field, string $say) use (&$out) {
            $out[] = ['kind' => 'check', 'severity' => 'warn', 'field' => $field, 'text' => $say];
        };
        $d = fn ($k) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($f[$k]['value'] ?? '')) ? $f[$k]['value'] : null;
        foreach (['serviceDate', 'admissionDate', 'dischargeDate'] as $k) {
            if ($d($k) && $d($k) > $today) {
                $warn($k, 'The '.self::FIELD_NAMES[$k].' '.$d($k).' is in the future. Check the date.');
            }
            if ($d($k) && $d('dob') && $d($k) < $d('dob')) {
                $warn($k, 'The '.self::FIELD_NAMES[$k].' '.$d($k).' is before the date of birth '.$d('dob').'. Check both dates.');
            }
        }
        if ($d('dob') && $d('dob') > $today) {
            $warn('dob', 'The date of birth '.$d('dob').' is in the future. Check the date.');
        }
        if ($d('admissionDate') && $d('dischargeDate') && $d('dischargeDate') < $d('admissionDate')) {
            $warn('dischargeDate', 'The discharge date '.$d('dischargeDate').' is before the admission date '.$d('admissionDate').'.');
        }
        if (! empty($f['total']) && ! ((float) $f['total']['value'] > 0)) {
            $warn('total', "The total reads {$f['total']['value']}. Check the amount.");
        }
        foreach ($f['tests'] ?? [] as $x) {
            if (! empty($x['doubt'])) {
                $warn('tests', $x['doubt']);
            }
        }
        if ($type === 'lab_result') {
            $read = array_flip(array_column($f['tests'] ?? [], 'snippet'));
            foreach (self::lines($text) as $l) {
                $l = self::trim($l);
                if ($l !== '' && ! preg_match('/^[^\d:：]{2,30}[:：]/u', $l) && ! preg_match('/\d{1,2}\/\d{1,2}\/\d{2,4}/', $l) && preg_match('/\d(?:[.,]\d+)?\s*[-–]\s*\d/u', $l) && ! isset($read[mb_substr($l, 0, 160)])) {
                    $warn('tests', 'This result line could not be read: “'.mb_substr($l, 0, 80).'”. Add it by hand if it is needed.');
                }
            }
            if (empty($f['tests']) && empty($f['conclusion'])) {
                $warn('tests', 'No test results were read from this laboratory report.');
            }
        }
        if ($type === 'prescription' && empty($f['medications']) && ! preg_match('/session|lunettes|glasses|verres|seance|séance|\bOD\b/iu', $text)) {
            $warn('medications', 'No medicine was read from this prescription. Check it.');
        }
        $absent = array_values(array_filter(self::EXPECTED[$type ?? ''] ?? [], fn ($k) => empty($f[$k])));
        if ($absent) {
            $out[] = ['kind' => 'absent', 'severity' => 'info', 'fields' => $absent, 'text' => 'Not found on this document: '.implode(', ', array_map(fn ($k) => self::FIELD_NAMES[$k] ?? $k, $absent)).'.'];
        }

        return $out;
    }

    /** a PDF saved again after it was created keeps both versions: more than one end-of-file marker, or a modification date different from the creation date */
    public static function pdfEditSignal(string $bytes): ?array
    {
        if (strlen($bytes) < 8 || ! str_starts_with($bytes, '%PDF-')) {
            return null;
        }
        $eofs = substr_count($bytes, '%%EOF');
        $created = preg_match('/\/CreationDate\s*\(D:(\d{8,14})/', $bytes, $m) ? $m[1] : null;
        $modified = preg_match('/\/ModDate\s*\(D:(\d{8,14})/', $bytes, $m) ? $m[1] : null;
        $producer = preg_match('/\/Producer\s*\(([^)]{1,80})\)/', $bytes, $m) ? $m[1] : '';
        $editors = (bool) preg_match('/photoshop|gimp|illustrator|pdf ?edit|smallpdf|ilovepdf|sejda|foxit phantom/i', $producer);
        if ($eofs > 1 || ($created && $modified && substr($created, 0, 12) !== substr($modified, 0, 12)) || $editors) {
            return ['kind' => 'edited', 'severity' => 'warn', 'text' => 'This PDF was modified after it was created'.($editors ? " (with {$producer})" : '').'. Check that the amounts are the original ones.'];
        }

        return null;
    }

    /** images compared by an 8×8 average hash: a small distance means the same picture, re-saved or re-photographed */
    public static function hamming(?string $a, ?string $b): int
    {
        if (! $a || ! $b || strlen($a) !== strlen($b)) {
            return 64;
        }
        $d = 0;
        for ($i = 0; $i < strlen($a); $i++) {
            $x = hexdec($a[$i]) ^ hexdec($b[$i]);
            while ($x) {
                $d += $x & 1;
                $x >>= 1;
            }
        }

        return $d;
    }
}
