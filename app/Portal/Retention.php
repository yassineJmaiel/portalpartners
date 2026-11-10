<?php

namespace App\Portal;

use Illuminate\Support\Facades\DB;

/* How long the portal keeps what it owns, enforced daily. The CRM keeps claims, decisions and
   payments; the portal keeps its own working data no longer than it is useful. Periods are
   days, in the config table under "retention"; 0 means "keep". Each run is audited. */
class Retention
{
    public const DEFAULTS = ['filesSentToCrmDays' => 30, 'filesUnusedDays' => 90, 'intakeDays' => 180, 'draftsDays' => 180, 'notificationsDays' => 365, 'checkInsDays' => 365,
        'eligibilityChecksDays' => 730, 'outboxBodiesDays' => 30, 'jobsDays' => 30, 'webhooksDays' => 90, 'signinsDays' => 730, 'auditDays' => 2555];

    public const LABELS = ['filesSentToCrmDays' => 'Uploaded documents, after VSahha received them', 'filesUnusedDays' => 'Uploaded documents never submitted', 'intakeDays' => 'Smart Intake reading results',
        'draftsDays' => 'Drafts not opened since', 'notificationsDays' => 'Notifications', 'checkInsDays' => 'Front-desk check-ins', 'eligibilityChecksDays' => 'Eligibility checks and certificates',
        'outboxBodiesDays' => 'Text of e-mails and SMS sent (codes, links)', 'jobsDays' => 'Finished background jobs', 'webhooksDays' => 'Events received from VSahha', 'signinsDays' => 'Sign-in history', 'auditDays' => 'Audit trail'];

    public static function config(string $key, $fallback = null)
    {
        $row = DB::table('config')->where('key', $key)->value('value');

        return $row === null ? $fallback : json_decode($row, true);
    }

    public static function setConfig(string $key, $value): void
    {
        DB::table('config')->updateOrInsert(['key' => $key], ['value' => json_encode($value, JSON_UNESCAPED_UNICODE)]);
    }

    public static function policy(): array
    {
        return array_merge(self::DEFAULTS, (array) self::config('retention', []));
    }

    public static function lastRun(): ?array
    {
        return self::config('retentionLastRun');
    }

    private static function before(int $days): string
    {
        return gmdate('Y-m-d\TH:i:s.v\Z', time() - $days * 86400);
    }

    public static function run(bool $dryRun = false): array
    {
        $p = self::policy();
        $counts = ['files' => 0];
        /* files: content only; drafts and intake documents still waiting for a person keep theirs */
        $candidates = [];
        if ($p['filesSentToCrmDays']) {
            $candidates = DB::table('files')->whereNull('removed_at')->whereNotNull('crm_ref')->where('created_at', '<', self::before($p['filesSentToCrmDays']))->pluck('ref')->all();
        }
        if ($p['filesUnusedDays']) {
            $candidates = [...$candidates, ...DB::table('files')->whereNull('removed_at')->whereNull('crm_ref')->where('created_at', '<', self::before($p['filesUnusedDays']))->pluck('ref')->all()];
        }
        foreach ($candidates as $ref) {
            $inUse = DB::table('drafts')->where('data', 'like', "%{$ref}%")->exists() || DB::table('intake_documents')->where('file_ref', $ref)->where('status', '!=', 'Filed')->exists();
            if ($inUse) {
                continue;
            }
            if (! $dryRun) {
                Files::removeContent($ref, 'Removed under the retention policy');
            }
            $counts['files']++;
        }
        if ($p['intakeDays']) {
            $old = DB::table('intake_batches')->where('created_at', '<', self::before($p['intakeDays']))->pluck('id')->all();
            $counts['intakeDays'] = count($old);
            if (! $dryRun && $old) {
                DB::table('intake_documents')->whereIn('batch_id', $old)->delete();
                DB::table('intake_batches')->whereIn('id', $old)->delete();
            }
        }
        $del = function (string $key, $query, ?array $update = null) use ($p, $dryRun, &$counts) {
            if (! $p[$key]) {
                return;
            }
            $counts[$key] = $dryRun ? $query->count() : ($update ? $query->update($update) : $query->delete());
        };
        $del('draftsDays', DB::table('drafts')->where('updated_at', '<', self::before($p['draftsDays'])));
        $del('notificationsDays', DB::table('notifications')->where('at', '<', self::before($p['notificationsDays'])));
        $del('checkInsDays', DB::table('check_ins')->where('at', '<', self::before($p['checkInsDays'])));
        $del('eligibilityChecksDays', DB::table('eligibility_checks')->where('at', '<', self::before($p['eligibilityChecksDays'])));
        $del('outboxBodiesDays', DB::table('outbox')->whereNotNull('body')->where('at', '<', self::before($p['outboxBodiesDays'])), ['body' => null]);
        $del('jobsDays', DB::table('portal_jobs')->whereIn('status', ['done', 'failed'])->where('updated_at', '<', self::before($p['jobsDays'])));
        $del('webhooksDays', DB::table('webhook_inbox')->where('received_at', '<', self::before($p['webhooksDays'])));
        $del('signinsDays', DB::table('signins')->where('at', '<', self::before($p['signinsDays'])));
        $del('auditDays', DB::table('audit')->where('at', '<', self::before($p['auditDays'])));
        if (! $dryRun) {
            DB::table('portal_sessions')->where('expires_at', '<', iso_now())->delete();
            DB::table('challenges')->where('expires_at', '<', self::before(1))->delete();
            DB::table('trusted_devices')->where('expires_at', '<', iso_now())->delete();
            DB::table('audit')->insert(['at' => iso_now(), 'action' => 'retention.applied', 'detail' => json_encode($counts)]);
            self::setConfig('retentionLastRun', ['at' => iso_now(), 'counts' => $counts]);
        }

        return $counts;
    }
}
