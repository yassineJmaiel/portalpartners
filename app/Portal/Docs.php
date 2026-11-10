<?php

namespace App\Portal;

/* The portal's own printable documents: what VSahha received, what a payment settled, and
   the eligibility certificate. Every value is the CRM's; a missing one says so. */
class Docs
{
    private const STYLE = 'body{font-family:"Plus Jakarta Sans",sans-serif;color:#0F2A2D;margin:0;background:#fff}.page{max-width:860px;margin:0 auto;padding:36px}
.band{background:linear-gradient(135deg,#0B4A4C,#0E7C86 55%,#0E9BB8);color:#fff;border-radius:18px;padding:22px 26px;display:flex;justify-content:space-between;align-items:center;gap:16px}
.band b{font-size:20px}.band small{opacity:.85;display:block;margin-top:4px}.box{margin:22px 0;padding:14px 18px;border-radius:14px;font-weight:600;background:#E7F5F5;color:#0B4A4C}
table{width:100%;border-collapse:collapse;margin-top:8px;font-size:13px}th{text-align:left;color:#64797B;font-weight:700;font-size:11px;text-transform:uppercase;letter-spacing:.06em;padding:8px 6px;border-bottom:2px solid #E2ECEC}
td{padding:9px 6px;border-bottom:1px solid #E2ECEC;vertical-align:top}.r{text-align:right;white-space:nowrap}.kv td:first-child{color:#64797B;width:38%}tfoot td{font-weight:800;border-bottom:0}
h3{margin:26px 0 6px;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:#0E7C86}.foot{margin-top:28px;padding-top:18px;border-top:1px solid #E2ECEC;font-size:12px;color:#64797B}
.reason{color:#64797B;font-size:12px}@media print{.page{padding:0}}';

    private const FONT = '<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">';

    private static function e($v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }

    private static function day($v): string
    {
        return $v ? gmdate('d M Y', strtotime(substr((string) $v, 0, 10).' 00:00:00 UTC')) : 'Not recorded';
    }

    private static function amt($n, $c): string
    {
        return $n === null ? '—' : trim(number_format((float) $n, 2).' '.$c);
    }

    private static function printable(string $title, string $band, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>'.self::e($title).'</title>'.self::FONT.'<style>'.self::STYLE.'</style></head>'
            .'<body><div class="page"><div class="band">'.$band.'</div>'.$body.'</div></body></html>';
    }

    public static function acknowledgement(array $c, array $provider): string
    {
        $e = [self::class, 'e'];
        $total = array_sum(array_map(fn ($l) => (float) ($l['claimed'] ?? 0), $c['lines'] ?? []));
        $sla = $c['sla'] ?? null;
        $target = ! empty($sla['due'])
            ? "VSahha's target for a decision is <b>".self::day($sla['due']).'</b> ('.$e($sla['target']).' days)'.(! empty($sla['paused']) ? ', paused while VSahha waits for information from you' : '').'.'
            : 'This claim is no longer waiting for a first decision. Its status is <b>'.$e(Vocab::claimStatusLine($c['workflowStatus'] ?? '')).'</b>.';
        $rows = '';
        foreach ($c['lines'] ?? [] as $l) {
            $rows .= '<tr><td>'.$e(($l['description'] ?? null) ?: ($l['benefit'] ?? '')).'</td><td>'.$e(self::day($l['serviceDate'] ?? null)).'</td><td class="r">'.$e($l['quantity'] ?? 1).'</td><td class="r">'.self::amt($l['claimed'] ?? null, $c['currency'] ?? '').'</td></tr>';
        }
        $diagnosis = $e(($c['icd'] ?? null) ?: 'Not recorded').(($c['diagnosisCertainty'] ?? null) === Vocab::UNKNOWN_ILLNESS ? ' · Unknown Illness — under medical review' : '');

        return self::printable("Acknowledgement {$c['id']}",
            '<div><b>Claim acknowledgement</b><small>Virtual Sahha Solutions · Provider Portal</small></div><div style="text-align:right"><b>'.$e($c['id']).'</b><small>Received '.$e(self::day($c['receivedAt'] ?? null)).'</small></div>',
            '<div class="box">VSahha received this claim from '.$e($provider['name'] ?? '').' through the provider portal. '.$target.'</div>'
            .'<table class="kv"><tr><td>Patient</td><td><b>'.$e($c['memberName'] ?? '').'</b> · '.$e($c['memberId'] ?? '').'</td></tr><tr><td>Invoice</td><td>'.$e(($c['invoiceNo'] ?? null) ?: 'Not recorded').'</td></tr>'
            .'<tr><td>Treatment date</td><td>'.$e(self::day($c['treatmentDate'] ?? null)).'</td></tr><tr><td>Type of care</td><td>'.$e($c['type'] ?? '').'</td></tr><tr><td>Diagnosis</td><td>'.$diagnosis.'</td></tr></table>'
            .'<h3>Services claimed</h3><table><thead><tr><th>Service</th><th>Date</th><th class="r">Qty</th><th class="r">Claimed</th></tr></thead><tbody>'.$rows.'</tbody>'
            .'<tfoot><tr><td colspan="3">Total claimed</td><td class="r">'.self::amt($total, $c['currency'] ?? '').'</td></tr></tfoot></table>'
            .'<div class="foot">This acknowledgement confirms receipt only. It is not a decision and not a guarantee of payment: VSahha\'s claims team assesses the claim. Follow it in the provider portal under '.$e($c['id']).'.</div>');
    }

    public static function remittance(array $p, array $provider): string
    {
        $e = [self::class, 'e'];
        $cur = $p['currency'] ?? '';
        $claims = $p['claims'] ?? [];
        $sum = fn ($k) => array_sum(array_map(fn ($c) => (float) ($c[$k] ?? 0), $claims));
        $rej = fn ($c) => $c['rejected'] ?? ((float) ($c['billed'] ?? 0) - (float) ($c['approved'] ?? 0));
        $rows = '';
        foreach ($claims as $c) {
            $reasons = '';
            foreach ($c['reasons'] ?? [] as $r) {
                $reasons .= '<br>'.$e($r['code'] ?? '').' '.$e($r['text'] ?? '');
            }
            $rows .= '<tr><td><b>'.$e($c['id'] ?? '').'</b><div class="reason">'.$e($c['invoiceNo'] ?? '').$reasons.'</div></td><td>'.$e($c['memberName'] ?? '').'</td><td class="r">'.self::amt($c['billed'] ?? null, $cur).'</td><td class="r">'.self::amt($c['approved'] ?? null, $cur).'</td><td class="r">'.self::amt($rej($c), $cur).'</td><td class="r">'.self::amt($c['memberShare'] ?? null, $cur).'</td><td class="r"><b>'.self::amt($c['payable'] ?? null, $cur).'</b></td></tr>';
        }

        return self::printable("Remittance {$p['reference']}",
            '<div><b>Remittance advice</b><small>Virtual Sahha Solutions · to '.$e($provider['name'] ?? '').'</small></div><div style="text-align:right"><b>'.$e($p['reference']).'</b><small>'.$e(self::day($p['paymentDate'] ?? null)).' · '.$e($p['method'] ?? '').(! empty($p['runId']) ? ' · '.$e($p['runId']) : '').'</small></div>',
            '<table><thead><tr><th>Claim</th><th>Patient</th><th class="r">Claimed</th><th class="r">Approved</th><th class="r">Not approved</th><th class="r">Member share</th><th class="r">Paid</th></tr></thead><tbody>'.$rows.'</tbody>'
            .'<tfoot><tr><td colspan="2">Total</td><td class="r">'.self::amt($sum('billed'), $cur).'</td><td class="r">'.self::amt($sum('approved'), $cur).'</td><td class="r">'.self::amt(array_sum(array_map($rej, $claims)), $cur).'</td><td class="r">'.self::amt($sum('memberShare'), $cur).'</td><td class="r">'.self::amt($p['amount'] ?? null, $cur).'</td></tr></tfoot></table>'
            .'<div class="foot">Amounts are VSahha\'s decisions as recorded in its claims system. The EOB of each claim and the proof of payment carry a QR code that confirms they were issued by Virtual Sahha Solutions.</div>');
    }

    public static function certificate(object $check, array $snap, array $provider, string $origin): string
    {
        $e = [self::class, 'e'];
        $verifyUrl = "{$origin}/verify/{$check->id}";
        $ok = (bool) $check->eligible;
        $m = $snap['member'] ?? [];
        $checks = '';
        foreach ($snap['checks'] ?? [] as $c) {
            $checks .= '<tr><td>'.$e($c['label'] ?? '').'</td><td>'.(! empty($c['ok']) ? '✓' : '✗').' '.$e($c['detail'] ?? '').'</td></tr>';
        }
        $ids = (! empty($m['memberPartnerId']) ? '<tr><td>Member Partner ID</td><td>'.$e($m['memberPartnerId']).'</td></tr>' : '').(! empty($m['mainPolicyHolderId']) ? '<tr><td>Main Policy Holder ID</td><td>'.$e($m['mainPolicyHolderId']).'</td></tr>' : '');
        $style = str_replace('.page{max-width:860px', '.page{max-width:780px', self::STYLE).'.status{margin:22px 0;padding:14px 18px;border-radius:14px;font-weight:700;background:'.($ok ? '#E5F5EE' : '#FDECEF').';color:'.($ok ? '#08724E' : '#B33348').'}'
            .'.foot{display:flex;gap:20px;align-items:center}.qr{width:110px;height:110px;border:1px solid #E2ECEC;border-radius:10px;display:grid;place-items:center;font-size:10px;text-align:center;padding:6px;flex:none;word-break:break-all}';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Eligibility certificate '.$e($check->id).'</title>'.self::FONT.'<style>'.$style.'</style></head>'
            .'<body><div class="page"><div class="band"><div><b>Eligibility certificate</b><small>Virtual Sahha Solutions · Provider Portal</small></div><div style="text-align:right"><b>'.$e($check->id).'</b><small>Checked '.$e(substr(str_replace('T', ' ', (string) $check->at), 0, 16)).' UTC</small></div></div>'
            .'<div class="status">'.($ok ? 'Covered' : 'Not covered').' on '.self::day($check->service_date).' — '.$e($check->summary).'</div>'
            .'<h3>Member</h3><table class="kv"><tr><td>Name</td><td><b>'.$e($m['name'] ?? '').'</b></td></tr><tr><td>Member ID</td><td>'.$e($m['id'] ?? '').'</td></tr><tr><td>Card number</td><td>'.$e(($m['cardNo'] ?? null) ?: 'Not recorded').'</td></tr>'.$ids
            .'<tr><td>Relation</td><td>'.$e(($m['relation'] ?? null) ?: 'Not recorded').'</td></tr><tr><td>Date of birth</td><td>'.self::day($m['dob'] ?? null).'</td></tr></table>'
            .'<h3>Cover</h3><table class="kv"><tr><td>Policy</td><td>'.$e($snap['policy']['id'] ?? '').' · '.$e($snap['policy']['name'] ?? '').'</td></tr><tr><td>Insurer</td><td>'.$e(($snap['insurer']['name'] ?? null) ?: 'Not recorded').'</td></tr>'
            .'<tr><td>Cover period</td><td>'.self::day($snap['policy']['start'] ?? null).' – '.self::day($snap['policy']['end'] ?? null).'</td></tr><tr><td>Network status of '.$e($provider['name'] ?? '').'</td><td>'.$e($snap['network'] ?? '').'</td></tr></table>'
            .'<h3>Checks</h3><table class="kv">'.$checks.'</table>'
            .'<div class="foot"><div class="qr" id="qr">'.$e($verifyUrl).'</div><div>Valid for the service date above only. Checked by '.$e($check->by ?? '').' at '.$e($provider['name'] ?? '').'.<br>Verify this certificate at <b>'.$e($verifyUrl).'</b>.<br>This certificate confirms cover on the date shown. It is not a guarantee of payment: claims are assessed when they are received.</div></div></div>'
            .'<script src="https://unpkg.com/qrcode-generator@1.4.4/qrcode.js" crossorigin></script><script>addEventListener("load",function(){if(!window.qrcode)return;var q=qrcode(0,"M");q.addData('.json_encode($verifyUrl).');q.make();document.getElementById("qr").innerHTML=q.createImgTag(3,4);});</script></body></html>';
    }

    /** the public page behind every QR code: no medical or personal information */
    public static function verifyPage(string $ref, array $out): string
    {
        $e = [self::class, 'e'];
        $ok = ! empty($out['valid']) && ! empty($out['kind']);
        $row = fn ($k, $v) => $v ? "<dt>{$k}</dt><dd>".$e($v).'</dd>' : '';
        $facts = $ok ? '<dl>'.$row('Type', $out['kind']).$row('Issued by', ($out['issuer'] ?? null) ?: 'Virtual Sahha Solutions').$row('Issued', substr((string) ($out['issuedAt'] ?? ''), 0, 10) ?: 'Not recorded')
            .$row('Valid until', $out['validUntil'] ?? null).$row('Version', $out['version'] ?? null).$row('Note', $out['note'] ?? null).'</dl>' : '';

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Document verification</title>'
            .'<style>body{font-family:system-ui,sans-serif;background:#F4F8F8;color:#0F2A2D;margin:0;display:grid;place-items:center;min-height:100vh;padding:16px}.c{background:#fff;border:1px solid #E2ECEC;border-radius:20px;padding:28px;max-width:440px;width:100%;box-shadow:0 10px 30px rgba(15,42,45,.08)}'
            .'.s{display:inline-block;border-radius:999px;padding:6px 12px;font-weight:700;background:'.($ok ? '#E5F5EE' : '#FDECEF').';color:'.($ok ? '#08724E' : '#B33348').'}dl{display:grid;grid-template-columns:auto 1fr;gap:8px 16px;margin-top:18px}dt{color:#64797B}dd{margin:0;font-weight:600}small{color:#64797B;display:block;margin-top:18px}</style></head>'
            .'<body><div class="c"><div style="font-weight:800;color:#0E7C86">VSahha · Document verification</div><h1 style="font-size:22px">'.$e($ref).'</h1><span class="s">'.($ok ? 'Valid document' : (! empty($out['unreachable']) ? 'Cannot be checked right now' : 'Not a valid VSahha document')).'</span>'.$facts
            .'<small>This page confirms whether a document was issued by Virtual Sahha Solutions. It shows no medical or personal information.</small></div></body></html>';
    }
}
