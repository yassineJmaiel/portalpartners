<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SecurityHeaders;
use App\Portal\Crm;
use App\Portal\Csv;
use App\Portal\Docs;
use App\Portal\PortalError;
use App\Portal\Vocab;
use Illuminate\Http\Request;

/* What VSahha paid you, with the remittance per payment, the EOB of every claim, the proof of
   payment; what is still owed and how old it is; and a check of your own bank statement against it. */
class PaymentsController extends Controller
{
    private const REMIT_HEAD = ['Payment', 'Date', 'Method', 'Claim', 'Patient', 'Invoice', 'Claimed', 'Approved', 'Not approved', 'Member share', 'Paid', 'Currency', 'Reasons'];

    public function index(Request $r)
    {
        $ctx = me();
        $ctx->need('payment.view');
        $tab = in_array($r->query('tab'), ['payments', 'receivables', 'reconcile'], true) ? $r->query('tab') : 'payments';
        $data = ['tab' => $tab, 'payments' => [], 'recv' => null, 'res' => session('reconcile')];
        if ($tab === 'payments') {
            $data['payments'] = Crm::get('/v1/payments', $ctx->crmOpts());
        } elseif ($tab === 'receivables') {
            $data['recv'] = $this->receivables(Crm::get('/v1/claims', $ctx->crmOpts()));
        }

        return view('payments.index', $data);
    }

    private function receivables(array $claims): array
    {
        $buckets = array_map(fn ($b) => ['label' => $b[0], 'from' => $b[1], 'to' => $b[2], 'count' => 0, 'amount' => 0.0], [['0–30 days', 0, 30], ['31–60 days', 31, 60], ['61–90 days', 61, 90], ['Over 90 days', 91, PHP_INT_MAX]]);
        $rows = [];
        foreach ($claims as $c) {
            $stage = Vocab::claimStage($c['workflowStatus'] ?? null);
            if (in_array($stage['id'], ['paid', 'closed', 'decided', 'draft'], true) || ! empty($c['memberReimbursement'])) {
                continue;
            }
            $age = days_between(($c['receivedAt'] ?? null) ?: (($c['treatmentDate'] ?? null) ?: portal_today()), portal_today());
            $decided = in_array($c['decisionStatus'] ?? null, Vocab::DECIDED, true);
            $amount = (float) ($decided ? ($c['money']['insurerPayable'] ?? 0) : ($c['claimed'] ?? 0));
            foreach ($buckets as &$b) {
                if ($age >= $b['from'] && $age <= $b['to']) {
                    $b['count']++;
                    $b['amount'] += $amount;
                }
            }
            unset($b);
            $rows[] = ['id' => $c['id'], 'memberName' => $c['memberName'] ?? '', 'receivedAt' => $c['receivedAt'] ?? null, 'age' => $age, 'stage' => $stage['label'], 'decided' => $decided, 'amount' => $amount, 'currency' => $c['currency'] ?? null,
                'basis' => $decided ? 'payable (decided)' : 'claimed (not yet decided)', 'expected' => $decided && ! empty($c['statusSince']) ? add_days($c['statusSince'], 10) : null];
        }
        usort($rows, fn ($a, $b) => $b['age'] <=> $a['age']);

        return ['buckets' => $buckets, 'rows' => $rows, 'note' => "Expected dates add VSahha's 10-day payment target to the decision date. They are expectations, not commitments."];
    }

    public function reconcile(Request $r)
    {
        $ctx = me();
        $ctx->need('payment.view');
        $payments = Crm::get('/v1/payments', $ctx->crmOpts());
        $csv = $r->file('file') ? (string) file_get_contents($r->file('file')->getRealPath()) : (string) $r->input('csv', '');
        $rows = Csv::parseStatement($csv);
        if (! $rows) {
            throw new PortalError(400, 'EMPTY', 'No lines with a date and an amount were found. Use a CSV with date, description and amount columns.');
        }
        $used = [];
        $matched = [];
        $unmatchedBank = [];
        foreach ($rows as $row) {
            $hit = null;
            $how = 'reference';
            foreach ($payments as $p) {
                if (! isset($used[$p['id']]) && str_contains(strtoupper($row['text']), (string) $p['reference'])) {
                    $hit = $p;
                    break;
                }
            }
            if (! $hit) {
                $how = 'amount and date';
                foreach ($payments as $p) {
                    if (! isset($used[$p['id']]) && abs((float) $p['amount'] - $row['amount']) < 0.01 && $row['date'] && abs(days_between($p['paymentDate'], $row['date'])) <= 5) {
                        $hit = $p;
                        break;
                    }
                }
            }
            if ($hit) {
                $used[$hit['id']] = true;
                $matched[] = ['bank' => $row, 'payment' => $hit, 'how' => $how];
            } else {
                $unmatchedBank[] = $row;
            }
        }
        $ctx->audit('bank.reconciled', null, ['lines' => count($rows), 'matched' => count($matched)]);

        return redirect('/payments?tab=reconcile')->with('reconcile', ['matched' => $matched, 'unmatchedBank' => $unmatchedBank, 'unmatchedPayments' => array_values(array_filter($payments, fn ($p) => ! isset($used[$p['id']])))]);
    }

    public function show(string $id)
    {
        $ctx = me();
        $ctx->need('payment.view');
        $p = Crm::get('/v1/payments/'.rawurlencode($id), $ctx->crmOpts());

        return view('payments.show', ['p' => $p, 'notes' => NotesController::panel($ctx, 'payment', $p['id'])]);
    }

    public function remittance(string $id)
    {
        $ctx = me();
        $ctx->need('payment.view');
        $p = Crm::get('/v1/payments/'.rawurlencode($id), $ctx->crmOpts());
        $provider = Crm::get('/v1/providers/'.(($p['payeeId'] ?? null) ?: $ctx->providerId), $ctx->crmOpts());
        $ctx->audit('document.viewed', "remittance:{$p['reference']}");

        return response(Docs::remittance($p, $provider))->header('Content-Security-Policy', SecurityHeaders::DOC_CSP);
    }

    public function document(Request $r, string $kind, string $id)
    {
        $ctx = me();
        abort_unless(in_array($kind, ['eob', 'proof', 'gop'], true), 404);
        $ctx->need($kind === 'gop' ? 'preauth.view' : 'payment.view');
        $ctx->audit('document.viewed', "{$kind}:{$id}");
        $v = $r->query('version');
        $html = Crm::get("/v1/documents/{$kind}/".rawurlencode($id).($v ? '?version='.rawurlencode($v) : ''), $ctx->crmOpts());

        return response($html)->header('Content-Type', 'text/html; charset=utf-8')->header('Content-Security-Policy', SecurityHeaders::DOC_CSP);
    }

    private function remitRows(array $p): array
    {
        return array_map(fn ($c) => [$p['reference'], $p['paymentDate'], $p['method'], $c['id'], $c['memberName'] ?? '', $c['invoiceNo'] ?? '', $c['billed'] ?? '', $c['approved'] ?? '', $c['rejected'] ?? '', $c['memberShare'] ?? '', $c['payable'] ?? '', $p['currency'],
            implode(' | ', array_map(fn ($x) => trim(($x['code'] ?? '').' '.($x['text'] ?? '')), $c['reasons'] ?? []))], $p['claims'] ?? []);
    }

    /** one row per claim: the five amounts and the reasons, as CSV or Excel; $id is one payment, or all */
    public function export(Request $r, string $format, ?string $id = null)
    {
        $ctx = me();
        $ctx->need('payment.view');
        $payments = $id ? [Crm::get('/v1/payments/'.rawurlencode($id), $ctx->crmOpts())] : Crm::get('/v1/payments', $ctx->crmOpts());
        $rows = array_merge([], ...array_map(fn ($p) => $this->remitRows($p), $payments));
        $mark = "Exported by {$ctx->user->name} (".$ctx->organisation()->name.') on '.iso_now().' — confidential';
        $name = 'remittance'.($id ? "-{$payments[0]['reference']}" : '');
        $ctx->audit('remittance.exported', $id, ['format' => $format, 'rows' => count($rows)]);
        if ($format === 'xlsx') {
            return response(self::xlsx('Remittance', [[$mark], self::REMIT_HEAD, ...$rows]), 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => "attachment; filename=\"{$name}.xlsx\""]);
        }
        $esc = fn ($v) => '"'.str_replace('"', '""', (string) ($v ?? '')).'"';
        $lines = [$esc($mark), implode(',', array_map($esc, self::REMIT_HEAD))];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map($esc, $row));
        }

        return response(implode("\n", $lines), 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => "attachment; filename=\"{$name}.csv\""]);
    }

    /** a minimal workbook: one sheet, inline strings and numbers */
    public static function xlsx(string $sheet, array $rows): string
    {
        $xml = '';
        foreach ($rows as $row) {
            $cells = '';
            foreach ($row as $v) {
                $cells .= is_int($v) || is_float($v) ? "<c><v>{$v}</v></c>" : '<c t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars((string) $v, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xml .= "<row>{$cells}</row>";
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.htmlspecialchars($sheet, ENT_XML1).'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }
}
