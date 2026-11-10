<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/* Demo organisations and accounts, portal-owned data only. Each maps to a provider in the CRM's
   seed, chosen to show the situations that matter: an active provider with activity, a second one
   to prove isolation, a suspended one that can read but not submit, and a group with two branches.
   Accounts are named after their desk. They exist only in demo mode. */
class DatabaseSeeder extends Seeder
{
    private const ORGS = [
        ['ORG-CMC', 'Carthage Medical Centre', [['PRV-001', 'Main branch · Tunis']], [
            ['admin@carthagemedical.tn', 'Clinic administrator', 'Provider Admin'], ['billing@carthagemedical.tn', 'Billing desk', 'Billing Officer'], ['frontdesk@carthagemedical.tn', 'Front desk', 'Front Desk'],
            ['medical@carthagemedical.tn', 'Medical coordinator', 'Medical Coordinator'], ['finance@carthagemedical.tn', 'Finance office', 'Finance Viewer'], ['audit@carthagemedical.tn', 'Internal audit', 'Read-only Auditor']]],
        ['ORG-GII', 'Geneva Imaging Institute', [['PRV-002', 'Geneva']], [['billing@genevaimaging.ch', 'Billing desk', 'Billing Officer']]],
        ['ORG-CAS', 'Casablanca Polyclinic', [['PRV-008', 'Casablanca']], [['billing@casablanca-polyclinic.ma', 'Billing desk', 'Billing Officer']]],
        ['ORG-DGB', 'Demo group — Brussels & Antwerp', [['PRV-005', 'Brussels · Skin Clinic'], ['PRV-006', 'Antwerp · Rehab Centre']], [['admin@demo-group.test', 'Group administrator', 'Provider Admin'], ['finance@demo-group.test', 'Group finance', 'Finance Viewer']]],
    ];

    public function run(): void
    {
        if (! config('portal.demo') || DB::table('users')->exists()) {
            return;
        }
        $hash = Hash::make((string) config('portal.demo_password'));
        foreach (self::ORGS as $i => [$id, $name, $providers, $users]) {
            DB::table('organisations')->insert(['id' => $id, 'name' => $name, 'created_at' => iso_now()]);
            foreach ($providers as [$pid, $label]) {
                DB::table('org_providers')->insert(['org_id' => $id, 'provider_id' => $pid, 'label' => $label]);
            }
            foreach ($users as $j => [$email, $person, $role]) {
                DB::table('users')->insert(['id' => sprintf('USR-%02d%02d', $i + 1, $j + 1), 'org_id' => $id, 'email' => $email, 'name' => $person, 'role' => $role, 'status' => 'Active', 'password_hash' => $hash, 'mfa' => false, 'created_at' => iso_now()]);
            }
        }
        \App\Portal\Retention::setConfig('intake', ['patientAutoMatch' => 0.95, 'diagnosisConfidence' => 0.70, 'unknownIllnessDefault' => 'R69']);
        \App\Portal\Retention::setConfig('eligibilityRateLimitPerHour', 120);
    }
}
