<?php

namespace App\Portal;

use Illuminate\Support\Facades\DB;

/* Work that should not keep a person waiting, or must be retried. A job is a row, so it
   survives a restart; a job that throws is retried with back-off (30 s, 2 min, 10 min, 1 h)
   and then marked failed with its error. Jobs run when the request that raised them has
   answered, and `portal:work` (every minute) picks up what is due.
     intake.read   read a Smart Intake batch (the upload returns at once)
     deliver       an e-mail, SMS or outbound webhook whose transport failed is tried again */
class Jobs
{
    private const BACKOFF = [30, 120, 600, 3600];

    private static bool $queued = false;

    public static function enqueue(string $kind, array $payload = [], int $delay = 0, ?int $maxAttempts = null): string
    {
        $id = portal_id('JOB', 6);
        DB::table('portal_jobs')->insert(['id' => $id, 'kind' => $kind, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'status' => 'queued', 'attempts' => 0,
            'max_attempts' => $maxAttempts ?? count(self::BACKOFF) + 1, 'run_after' => iso_later($delay), 'created_at' => iso_now(), 'updated_at' => iso_now()]);
        if (! self::$queued && ! app()->runningUnitTests()) {
            self::$queued = true;
            app()->terminating(fn () => self::drain());
        }

        return $id;
    }

    private static function handle(string $kind, array $payload, array $job)
    {
        return match ($kind) {
            'intake.read' => Intake\Intake::readBatchJob($payload, $job),
            'deliver' => Alerts::deliverJob($payload),
            default => throw new \RuntimeException("No handler for {$kind}."),
        };
    }

    public static function runOne(): bool
    {
        $job = DB::table('portal_jobs')->where('status', 'queued')->where('run_after', '<=', iso_now())->orderBy('run_after')->orderBy('created_at')->first();
        if (! $job) {
            return false;
        }
        DB::table('portal_jobs')->where('id', $job->id)->update(['status' => 'running', 'attempts' => $job->attempts + 1, 'updated_at' => iso_now()]);
        try {
            $result = self::handle($job->kind, json_of($job->payload, []), ['id' => $job->id, 'attempt' => $job->attempts + 1]);
            DB::table('portal_jobs')->where('id', $job->id)->update(['status' => 'done', 'result' => $result === null ? null : json_encode($result, JSON_UNESCAPED_UNICODE), 'last_error' => null, 'updated_at' => iso_now()]);
        } catch (\Throwable $e) {
            $attempts = $job->attempts + 1;
            $again = $attempts < $job->max_attempts && ! ($e instanceof JobGaveUp);
            DB::table('portal_jobs')->where('id', $job->id)->update(['status' => $again ? 'queued' : 'failed', 'last_error' => mb_substr($e->getMessage(), 0, 500),
                'run_after' => iso_later(self::BACKOFF[$attempts - 1] ?? 3600), 'updated_at' => iso_now()]);
        }

        return true;
    }

    public static function drain(int $limit = 50): int
    {
        /* anything left running by a stopped server goes back to the queue */
        DB::table('portal_jobs')->where('status', 'running')->where('updated_at', '<', gmdate('Y-m-d\TH:i:s.v\Z', time() - 600))->update(['status' => 'queued', 'updated_at' => iso_now()]);
        $n = 0;
        while ($n < $limit && self::runOne()) {
            $n++;
        }

        return $n;
    }

    public static function stats(): array
    {
        return DB::table('portal_jobs')->select('kind', 'status', DB::raw('COUNT(*) AS n'))->groupBy('kind', 'status')->get()->map(fn ($r) => (array) $r)->all();
    }

    public static function recentFailures(int $limit = 20): array
    {
        return DB::table('portal_jobs')->where('status', 'failed')->orderByDesc('updated_at')->limit($limit)->get(['id', 'kind', 'attempts', 'last_error', 'updated_at'])->map(fn ($r) => (array) $r)->all();
    }
}
