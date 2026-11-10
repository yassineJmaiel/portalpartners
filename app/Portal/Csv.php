<?php

namespace App\Portal;

/* The two files providers bring: a bank statement to reconcile, and a day's claims. */
class Csv
{
    private static function separator(string $head): string
    {
        return substr_count($head, ';') > substr_count($head, ',') ? ';' : ',';
    }

    private static function amount(string $v): ?float
    {
        $s = preg_replace('/\s/u', '', $v);
        $s = preg_match('/,\d{1,2}$/', $s) ? str_replace(',', '.', str_replace('.', '', $s)) : str_replace(',', '', $s);

        return is_numeric($s) ? (float) $s : null;
    }

    /** bank statement: date, description, amount (any order, ; or , separated) */
    public static function parseStatement(string $csv): array
    {
        $lines = array_values(array_filter(preg_split('/\r?\n/', $csv), fn ($l) => trim($l) !== ''));
        if (! $lines) {
            return [];
        }
        $sep = self::separator($lines[0]);
        $out = [];
        foreach ($lines as $l) {
            $cells = array_map(fn ($c) => trim(trim($c), '"'), explode($sep, $l));
            $date = null;
            foreach ($cells as $c) {
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $c) || preg_match('#^\d{1,2}[/.]\d{1,2}[/.]\d{4}$#', $c)) {
                    $date = $c;
                    break;
                }
            }
            $iso = $date;
            if ($date && preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $date, $m)) {
                $iso = sprintf('%s-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            $amountCell = null;
            foreach (array_reverse($cells) as $c) {
                if (preg_match('/^-?[\d\s.,]+$/u', $c) && preg_match('/\d/', $c) && $c !== $date) {
                    $amountCell = $c;
                    break;
                }
            }
            $amount = $amountCell === null ? null : self::amount($amountCell);
            if ($amount === null || $amount <= 0) {
                continue;
            }
            $out[] = ['date' => $iso, 'amount' => $amount, 'text' => implode(' ', array_filter($cells, fn ($c) => $c !== $date && $c !== $amountCell))];
        }

        return $out;
    }

    public const BULK_COLUMNS = ['invoice_no', 'member', 'treatment_date', 'type_of_care', 'icd10', 'physician', 'benefit_code', 'description', 'quantity', 'amount', 'service_date', 'tariff_code'];

    private static function split(string $l, string $sep): array
    {
        $out = [];
        $cur = '';
        $q = false;
        $n = strlen($l);
        for ($i = 0; $i < $n; $i++) {
            $ch = $l[$i];
            if ($ch === '"') {
                if ($q && ($l[$i + 1] ?? '') === '"') {
                    $cur .= '"';
                    $i++;
                } else {
                    $q = ! $q;
                }
            } elseif ($ch === $sep && ! $q) {
                $out[] = trim($cur);
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        $out[] = trim($cur);

        return $out;
    }

    private static function iso($v): ?string
    {
        $v = (string) $v;
        if (preg_match('#^(\d{1,2})[/.](\d{1,2})[/.](\d{4})$#', $v, $m)) {
            return sprintf('%s-%02d-%02d', $m[3], $m[2], $m[1]);
        }

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }

    /* bulk claims: one row per service line. Rows sharing an invoice number are one claim.
       A row that cannot be read is reported, never dropped silently. */
    public static function parseBulk(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = array_values(array_filter(preg_split('/\r?\n/', $csv), fn ($l) => trim($l) !== ''));
        if (! $lines) {
            return ['rows' => [], 'claims' => [], 'ignored' => []];
        }
        $sep = self::separator($lines[0]);
        $head = array_map(fn ($h) => trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($h)), '_'), self::split($lines[0], $sep));
        $ignored = [];
        $rows = [];
        foreach (array_slice($lines, 1) as $i => $l) {
            $vals = self::split($l, $sep);
            $cells = [];
            foreach ($head as $k => $h) {
                $cells[$h] = $vals[$k] ?? '';
            }
            $n = $i + 2;
            if (($cells['invoice_no'] ?? '') === '' && ($cells['member'] ?? '') === '') {
                $ignored[] = ['row' => $n, 'why' => 'No invoice number and no member'];
                continue;
            }
            $rows[] = ['n' => $n, 'cells' => $cells];
        }
        $byInvoice = [];
        foreach ($rows as $r) {
            $c = $r['cells'] + array_fill_keys(self::BULK_COLUMNS, '');
            $key = $c['invoice_no'] ?: "row {$r['n']}";
            if (! isset($byInvoice[$key])) {
                $type = null;
                foreach (Vocab::CLAIM_TYPES as $root => $label) {
                    if (mb_strtolower($label) === mb_strtolower($c['type_of_care']) || $root === strtoupper($c['type_of_care'])) {
                        $type = $label;
                    }
                }
                $byInvoice[$key] = ['invoiceNo' => $c['invoice_no'], 'memberRef' => $c['member'], 'rows' => [], 'problems' => [],
                    'draft' => ['invoiceNo' => $c['invoice_no'], 'treatmentDate' => self::iso($c['treatment_date']), 'type' => $type, 'icd' => Vocab::normIcd($c['icd10']), 'diagnosis' => Vocab::normIcd($c['icd10']),
                        'diagnosisCertainty' => 'Confirmed', 'physicianName' => $c['physician'] ?: '', 'secondaryIcd' => [], 'documents' => [], 'lines' => []]];
            }
            $g = &$byInvoice[$key];
            $g['rows'][] = $r['n'];
            $amount = self::amount($c['amount']) ?? NAN;
            $qty = $c['quantity'] !== '' ? (self::amount($c['quantity']) ?? NAN) : 1.0;
            $n = $r['n'];
            if ($c['invoice_no'] === '') {
                $g['problems'][] = "Row {$n}: no invoice number.";
            }
            if ($c['member'] === '') {
                $g['problems'][] = "Row {$n}: no card number or member ID.";
            }
            if ($c['member'] !== '' && $g['memberRef'] && $c['member'] !== $g['memberRef']) {
                $g['problems'][] = "Row {$n}: another member than the other lines of invoice {$c['invoice_no']}.";
            }
            if (! $g['draft']['treatmentDate']) {
                $g['problems'][] = "Row {$n}: the treatment date is missing or not a date (use YYYY-MM-DD).";
            }
            if (! $g['draft']['type']) {
                $g['problems'][] = "Row {$n}: \"{$c['type_of_care']}\" is not a VSahha type of care.";
            }
            if (! Vocab::isIcd($c['icd10'])) {
                $g['problems'][] = "Row {$n}: \"{$c['icd10']}\" is not a valid ICD-10 code.";
            }
            if ($c['benefit_code'] === '') {
                $g['problems'][] = "Row {$n}: no benefit code.";
            }
            if (! ($amount > 0)) {
                $g['problems'][] = "Row {$n}: the amount is missing or not a number.";
            }
            if (! ($qty > 0)) {
                $g['problems'][] = "Row {$n}: the quantity is not a number.";
            }
            $g['draft']['lines'][] = ['description' => $c['description'] ?: '', 'code' => $c['benefit_code'], 'tariffCode' => $c['tariff_code'] ?: null, 'quantity' => $qty > 0 ? $qty : 1,
                'claimedAmount' => $amount > 0 ? $amount : 0, 'serviceDate' => self::iso($c['service_date']) ?: $g['draft']['treatmentDate'], 'gopId' => null, 'icd' => null];
            unset($g);
        }

        return ['rows' => $rows, 'claims' => array_values($byInvoice), 'ignored' => $ignored];
    }
}
