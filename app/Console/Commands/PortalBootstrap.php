<?php

namespace App\Console\Commands;

use App\Portal\Retention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/* Production start: no demo account ever exists outside demo mode. The first organisation and its
   administrator come from the environment; the administrator is Invited, with a one-time link (7 days)
   printed once. Nothing happens if users already exist. */
class PortalBootstrap extends Command
{
    protected $signature = 'portal:bootstrap';

    protected $description = 'Create the first organisation and administrator from the environment';

    public function handle(): int
    {
        if (DB::table('users')->exists()) {
            $this->info('Users already exist. Nothing to do.');

            return self::SUCCESS;
        }
        $email = mb_strtolower(trim((string) config('portal.admin_email')));
        $providers = array_filter(array_map('trim', explode(',', (string) config('portal.provider_ids'))));
        if ($email === '' || ! config('portal.org_name') || ! $providers) {
            $this->error('Set PORTAL_ORG_NAME, PORTAL_PROVIDER_IDS and PORTAL_ADMIN_EMAIL first.');

            return self::FAILURE;
        }
        $org = portal_id('ORG', 3);
        $user = portal_id('USR', 4);
        $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
        DB::transaction(function () use ($org, $user, $email, $providers, $token) {
            DB::table('organisations')->insert(['id' => $org, 'name' => mb_substr((string) config('portal.org_name'), 0, 120), 'created_at' => iso_now()]);
            foreach ($providers as $p) {
                [$pid, $label] = array_pad(explode(':', $p, 2), 2, null);
                DB::table('org_providers')->insert(['org_id' => $org, 'provider_id' => $pid, 'label' => $label ?: $pid]);
            }
            DB::table('users')->insert(['id' => $user, 'org_id' => $org, 'email' => $email, 'name' => explode('@', $email)[0], 'role' => 'Provider Admin', 'status' => 'Invited', 'mfa' => true, 'created_at' => iso_now()]);
            DB::table('invites')->insert(['id' => portal_sha($token), 'user_id' => $user, 'kind' => 'invite', 'expires_at' => iso_later(7 * 86400), 'created_at' => iso_now()]);
            Retention::setConfig('intake', ['patientAutoMatch' => 0.95, 'diagnosisConfidence' => 0.70, 'unknownIllnessDefault' => 'R69']);
            Retention::setConfig('eligibilityRateLimitPerHour', 120);
        });
        $this->info("First administrator {$email} created. One-time link (7 days): ".rtrim((string) config('app.url'), '/')."/accept/{$token}");

        return self::SUCCESS;
    }
}
