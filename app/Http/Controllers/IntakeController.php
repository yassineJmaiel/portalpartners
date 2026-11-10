<?php

namespace App\Http\Controllers;

use App\Portal\Crm;
use App\Portal\Files;
use App\Portal\Intake\Intake;
use App\Portal\Intake\Matching;
use App\Portal\PortalError;
use Illuminate\Http\Request;

/* Smart Intake: drop a pile of documents; each one is read, filed under the right patient and
   diagnosis, and checked; a person reviews, then files them into claim drafts. */
class IntakeController extends Controller
{
    public function index()
    {
        $ctx = me();
        $ctx->need('intake.use');

        return view('intake.index', ['batches' => Intake::listBatches($ctx)]);
    }

    public function upload(Request $r)
    {
        $ctx = me();
        $ctx->need('intake.use');
        $ctx->single();
        $files = ClaimsController::uploaded($ctx, $r, 'files', 'intake');
        if (! $files) {
            throw new PortalError(400, 'NO_FILES', 'Choose at least one file.');
        }
        /* what the browser read on scans and photos, by the file's place in the upload */
        $ocr = json_of($r->input('ocr'), []);
        $items = [];
        foreach ($files as $i => $f) {
            $items[] = ['ref' => $f['ref'], 'ocr' => is_array($ocr[$i] ?? null) ? $ocr[$i] : null];
        }
        $batchId = Intake::startBatch($ctx, $items); // read in the background; the page follows the batch status
        $ctx->audit('intake.read', $batchId, ['files' => count($items)]);

        return redirect("/intake/{$batchId}");
    }

    public function show(string $id)
    {
        $ctx = me();
        $ctx->need('intake.use');
        $data = Intake::getBatch($ctx, $id) ?? throw new PortalError(404, 'NOT_FOUND', 'No such upload.');

        return view('intake.batch', $data + ['filed' => session('intake_filed')]);
    }

    public function status(string $id)
    {
        $ctx = me();
        $ctx->need('intake.use');
        $data = Intake::getBatch($ctx, $id) ?? throw new PortalError(404, 'NOT_FOUND', 'No such upload.');

        return response()->json(['status' => $data['batch']['status']]);
    }

    /** one review form per document; the button pressed says what the person decided */
    public function review(Request $r, string $id)
    {
        $ctx = me();
        $ctx->need('intake.use');
        $batch = Intake::batchOfDoc($ctx, $id) ?? throw new PortalError(404, 'NOT_FOUND', 'No such document.');
        $do = (string) $r->input('do');
        $change = [];
        if ($do === 'type') {
            $change['type'] = (string) $r->input('type');
        } elseif ($do === 'fields') {
            $change['fields'] = array_map(fn ($v) => is_string($v) ? trim($v) : $v, (array) $r->input('fields', []));
        } elseif (str_starts_with($do, 'checked:')) {
            $change['checked'] = (string) $r->input('checks.'.substr($do, 8));
        } elseif (str_starts_with($do, 'member:')) {
            $change['memberId'] = substr($do, 7);
        } elseif ($do === 'unlink') {
            $change['memberId'] = null;
        } elseif ($do === 'find') {
            $q = trim((string) $r->input('manual'));
            $card = Matching::normCard($q);
            $found = Crm::post('/v1/members/candidates', $card ? ['cardNo' => $card] : ['memberId' => mb_strtoupper($q)], $ctx->crmOpts())[0] ?? null;
            if (! $found) {
                throw new PortalError(404, 'NOT_FOUND', 'No VSahha member matches this card number or ID.');
            }
            $change['memberId'] = $found['id'];
            $change['memberName'] = $found['name'];
        } elseif (str_starts_with($do, 'code:')) {
            [, $code, $certainty] = array_pad(explode(':', $do, 3), 3, 'Confirmed');
            $change['diagnosis'] = ['code' => $code, 'title' => (string) $r->input("titles.{$code}"), 'certainty' => $certainty];
        } elseif ($do === 'diagnosis') {
            $change['diagnosis'] = ['code' => (string) $r->input('icd'), 'title' => (string) $r->input('icdTitle'), 'certainty' => (string) $r->input('icdCertainty', 'Confirmed')];
        } else {
            throw new PortalError(400, 'BAD_ACTION', 'Unknown action.');
        }
        $before = Intake::reviewState($ctx, $id);
        Intake::reviewDocument($ctx, $id, $change);
        $ctx->audit('intake.reviewed', $id, array_filter(['type' => $change['type'] ?? null, 'memberId' => $change['memberId'] ?? null, 'icd' => $change['diagnosis']['code'] ?? null, 'fields' => array_keys($change['fields'] ?? []) ?: null]), ['before' => $before, 'after' => Intake::reviewState($ctx, $id)]);

        return redirect("/intake/{$batch}#doc-{$id}");
    }

    public function file(string $id)
    {
        $ctx = me();
        $ctx->need('intake.use');
        $ctx->single();
        if (! Intake::getBatch($ctx, $id)) {
            throw new PortalError(404, 'NOT_FOUND', 'No such upload.');
        }
        $out = Intake::fileBatch($ctx, $id);
        $ctx->audit('intake.filed', $id, ['drafts' => count($out['drafts'])]);
        toast(t('{n} claim draft(s) ready', ['n' => count($out['drafts'])]));

        return redirect("/intake/{$id}")->with('intake_filed', $out);
    }
}
