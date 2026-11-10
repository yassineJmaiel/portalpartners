<?php

namespace App\Http\Controllers;

use App\Portal\Crm;
use App\Portal\Ctx;
use App\Portal\PortalError;
use App\Portal\Vocab;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/* Team notes, @mentions and hand-offs on a claim, a pre-authorization or a payment.
   Internal to the organisation: never sent to VSahha. A note is only possible on a
   record the CRM returns for this provider and this role. */
class NotesController extends Controller
{
    private const ON = ['claim' => ['claim.view', '/v1/claims/', '/claims/'], 'preauth' => ['preauth.view', '/v1/preauths/', '/preauth/'], 'payment' => ['payment.view', '/v1/payments/', '/payments/']];

    private static function subject(Ctx $ctx, string $type, string $id): array
    {
        $s = self::ON[$type] ?? throw new PortalError(404, 'NOT_FOUND', 'Notes are kept on claims, pre-authorizations and payments.');
        $ctx->need($s[0]);
        $rec = Crm::get($s[1].rawurlencode($id), $ctx->crmOpts()); // the CRM refuses another provider's record
        $label = $type === 'preauth' ? ($rec['ref'] ?? $rec['id']) : ($type === 'payment' ? (($rec['reference'] ?? null) ?: $rec['id']) : $rec['id']);

        return ['cap' => $s[0], 'rec' => $rec, 'label' => $label, 'link' => $s[2].$rec['id']];
    }

    private static function team(Ctx $ctx): array
    {
        return DB::table('users')->where('org_id', $ctx->user->org_id)->where('status', 'Active')->orderBy('name')->get(['id', 'name', 'role'])
            ->map(fn ($u) => (array) $u + ['canNote' => Vocab::roleCan($u->role, 'note.write')])->all();
    }

    private static function notes(Ctx $ctx, string $type, string $id): array
    {
        return DB::table('record_notes as n')->join('users as a', 'a.id', '=', 'n.author_id')->leftJoin('users as s', 's.id', '=', 'n.assignee_id')->leftJoin('users as d', 'd.id', '=', 'n.done_by')
            ->where('n.org_id', $ctx->user->org_id)->where('n.subject_type', $type)->where('n.subject_id', $id)->orderBy('n.created_at')
            ->get(['n.*', 'a.name as author', 's.name as assignee', 'd.name as done_by_name'])
            ->map(fn ($n) => ['id' => $n->id, 'kind' => $n->kind, 'body' => $n->body, 'mentions' => json_of($n->mentions, []), 'author' => ['id' => $n->author_id, 'name' => $n->author],
                'assignee' => $n->assignee_id ? ['id' => $n->assignee_id, 'name' => $n->assignee] : null, 'doneAt' => $n->done_at, 'doneBy' => $n->done_by_name, 'at' => $n->created_at])->all();
    }

    /** what the record's page needs to show its notes; the record was already read by that page */
    public static function panel(Ctx $ctx, string $type, string $id): array
    {
        $canWrite = $ctx->roleCan('note.write');

        return ['type' => $type, 'id' => $id, 'notes' => self::notes($ctx, $type, $id), 'team' => self::team($ctx), 'canWrite' => $canWrite];
    }

    private static function notify(Ctx $ctx, string $userId, string $type, string $title, ?string $body, string $link): void
    {
        DB::table('notifications')->insert(['org_id' => $ctx->user->org_id, 'provider_id' => $ctx->providerId, 'user_id' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 250), 'body' => $body ?: null, 'link' => $link, 'at' => iso_now()]);
    }

    public function store(Request $r, string $type, string $id)
    {
        $ctx = me();
        $ctx->need('note.write');
        $s = self::subject($ctx, $type, $id);
        $rec = $s['rec'];
        $body = mb_substr(trim((string) $r->input('body')), 0, 2000);
        $team = self::team($ctx);
        $assignee = $r->filled('assignTo') ? collect($team)->firstWhere('id', $r->input('assignTo')) : null;
        if ($r->filled('assignTo') && ! $assignee) {
            throw new PortalError(400, 'NOT_IN_TEAM', 'Hand-offs go to an active member of your team.');
        }
        if ($assignee && ! Vocab::roleCan($assignee['role'], $s['cap'])) {
            throw new PortalError(400, 'CANNOT_SEE', "{$assignee['name']} ({$assignee['role']}) cannot see this kind of record.");
        }
        if ($assignee && ! $assignee['canNote']) {
            throw new PortalError(400, 'READ_ONLY', "{$assignee['name']} ({$assignee['role']}) can read records but not act on a hand-off.");
        }
        if ($body === '' && ! $assignee) {
            throw new PortalError(400, 'EMPTY', 'Write a note, or choose who to hand this record to.');
        }
        /* "@Name" in the note reaches that person */
        $mentions = [];
        foreach ($team as $u) {
            if ($u['id'] !== $ctx->user->id && str_contains($body, "@{$u['name']}")) {
                $mentions[] = $u['id'];
            }
        }
        $noteId = portal_id('NTE');
        DB::transaction(function () use ($ctx, $type, $rec, $assignee, $noteId, $body, $mentions) {
            if ($assignee) { // one open hand-off per record: a new one replaces it
                DB::table('record_notes')->where('org_id', $ctx->user->org_id)->where('subject_type', $type)->where('subject_id', $rec['id'])->where('kind', 'handoff')->whereNull('done_at')->update(['done_at' => iso_now(), 'done_by' => $ctx->user->id]);
            }
            DB::table('record_notes')->insert(['id' => $noteId, 'org_id' => $ctx->user->org_id, 'provider_id' => ($rec['providerId'] ?? null) ?: (($rec['payeeId'] ?? null) ?: $ctx->providerId), 'subject_type' => $type, 'subject_id' => $rec['id'],
                'author_id' => $ctx->user->id, 'kind' => $assignee ? 'handoff' : 'note', 'body' => $body ?: null, 'mentions' => json_encode($mentions), 'assignee_id' => $assignee['id'] ?? null, 'created_at' => iso_now()]);
        });
        $snippet = mb_strlen($body) > 140 ? mb_substr($body, 0, 140).'…' : $body;
        foreach ($mentions as $u) {
            if ($u !== ($assignee['id'] ?? null)) {
                self::notify($ctx, $u, 'team.mention', "{$ctx->user->name} mentioned you on {$s['label']}", $snippet, $s['link']);
            }
        }
        if ($assignee && $assignee['id'] !== $ctx->user->id) {
            self::notify($ctx, $assignee['id'], 'team.handoff', "{$ctx->user->name} handed {$s['label']} to you", $snippet, $s['link']);
        }
        $ctx->audit($assignee ? 'note.handoff' : 'note.written', "{$type}:{$rec['id']}", ['note' => $noteId, 'mentions' => count($mentions), 'assignee' => $assignee['id'] ?? null]);
        toast($assignee ? t('Handed off. They will see it on their Home page.') : t('Note added for your team.'));

        return redirect()->back();
    }

    public function done(string $id)
    {
        $ctx = me();
        $ctx->need('note.write');
        $n = DB::table('record_notes')->where('id', $id)->where('org_id', $ctx->user->org_id)->where('kind', 'handoff')->first() ?? throw new PortalError(404, 'NOT_FOUND', 'No such hand-off.');
        if ($n->done_at) {
            throw new PortalError(409, 'DONE', 'This hand-off is already done.');
        }
        if ($n->assignee_id !== $ctx->user->id && $n->author_id !== $ctx->user->id) {
            throw new PortalError(403, 'NOT_YOURS', 'Only the person it was handed to, or who handed it, can mark it done.');
        }
        $s = self::subject($ctx, $n->subject_type, $n->subject_id);
        DB::table('record_notes')->where('id', $n->id)->update(['done_at' => iso_now(), 'done_by' => $ctx->user->id]);
        if ($n->author_id !== $ctx->user->id) {
            self::notify($ctx, $n->author_id, 'team.handoff', "{$ctx->user->name} marked {$s['label']} done", null, $s['link']);
        }
        $ctx->audit('note.handoff.done', "{$n->subject_type}:{$n->subject_id}", ['note' => $n->id]);
        toast(t('Marked done.'));

        return redirect()->back();
    }
}
