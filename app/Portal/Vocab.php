<?php

namespace App\Portal;

/* The CRM's words, and the provider-facing words they become. Nothing here decides
   anything about a claim: the CRM decides, the portal translates. Two rules hold by
   construction: an investigation is never revealed ("Pending Investigation" maps to
   the same stage as ordinary review), and a stage never claims money was decided. */
class Vocab
{
    public const CLAIM_STAGES = [
        'draft' => ['Draft', 'muted', 'Saved in the portal. VSahha has not received it.'],
        'received' => ['Received', 'blue', 'VSahha has the claim. Its review has not started yet.'],
        'review' => ['In review', 'blue', 'VSahha is checking the claim.'],
        'action' => ['Action needed from you', 'amber', 'VSahha needs something from you. The service clock is paused.'],
        'medical' => ['With medical team', 'violet', 'A clinician is reviewing part of the claim.'],
        'decided' => ['Decided', 'red', 'VSahha has decided, and nothing is payable. See the reason on each line.'],
        'scheduled' => ['Payment scheduled', 'teal', 'Decided and payable. Waiting for the next payment.'],
        'paid' => ['Paid', 'green', 'Paid, with a payment reference.'],
        'closed' => ['Closed', 'muted', 'Nothing further is expected.'],
    ];

    public const CLAIM_STAGE_OF = ['Draft' => 'draft', 'Submitted for Validation' => 'received', 'Assigned to Validator' => 'review', 'Under Validation' => 'review',
        'Pending Additional Information' => 'action', 'Pending Medical Review' => 'medical', 'Pending Investigation' => 'review', 'Pending Supervisor Approval' => 'review',
        'Rejected' => 'decided', 'Pending Payment' => 'scheduled', 'Ready for Payment' => 'scheduled', 'Payment Processing' => 'scheduled', 'Returned by Finance' => 'review',
        'Paid' => 'paid', 'Closed' => 'closed', 'Reopened' => 'review', 'Cancelled' => 'closed'];

    /** the CRM's status words that may be shown under the stage; the two the provider must never read become the stage itself */
    private const HIDDEN_CLAIM_STATUSES = ['Pending Investigation', 'Returned by Finance'];

    public const DECIDED = ['Approved', 'Partially Approved', 'Rejected'];

    public const PA_STAGES = ['draft' => ['Draft', 'muted'], 'checking' => ['Checking cover', 'blue'], 'medical' => ['With medical team', 'violet'], 'action' => ['Action needed from you', 'amber'],
        'insurer' => ['Awaiting the insurer', 'violet'], 'authorised' => ['Authorised', 'green'], 'partly' => ['Partly authorised', 'teal'], 'declined' => ['Not authorised', 'red'],
        'cancelled' => ['Cancelled', 'muted'], 'expired' => ['Expired', 'muted'], 'closed' => ['Closed', 'muted']];

    public const PA_STAGE_OF = ['Draft' => 'draft', 'Submitted' => 'checking', 'Eligibility Validation' => 'checking', 'Benefit Validation' => 'checking', 'Authority Validation' => 'checking',
        'Medical Queue' => 'medical', 'Medical Review' => 'medical', 'Additional Information Required' => 'action', 'Administrator Action Required' => 'medical', 'Returned to Medical Review' => 'medical',
        'External Approval Required' => 'insurer', 'External Approval Pending' => 'insurer', 'External Approval Received' => 'insurer', 'Final Decision Pending' => 'medical',
        'Approved' => 'authorised', 'Partially Approved' => 'partly', 'Rejected' => 'declined', 'GOP Pending' => 'authorised', 'GOP Generated' => 'authorised', 'GOP Sent' => 'authorised', 'GOP Revised' => 'authorised',
        'Cancelled' => 'cancelled', 'Expired' => 'expired', 'Closed' => 'closed'];

    public const PA_JOURNEY = ['Submitted', 'Cover checked', 'Medical review', 'Decision', 'GOP issued'];

    public const PA_URGENCY = ['Routine' => 72, 'High' => 24, 'Urgent' => 4];

    public const REJECTION_REASONS = ['RJ-01' => 'Benefit limit exceeded', 'RJ-02' => 'GOP amount exceeded', 'RJ-03' => 'Missing supporting document', 'RJ-04' => 'Service not covered',
        'RJ-05' => 'Duplicate claim', 'RJ-06' => 'Invalid eligibility', 'RJ-07' => 'Medical review required', 'RJ-08' => 'No prior authorisation', 'RJ-09' => 'Waiting period not served',
        'RJ-10' => 'Pre-existing condition excluded', 'RJ-11' => 'Tariff exceeded', 'RJ-12' => 'Late submission', 'RJ-13' => 'Fraud or misrepresentation confirmed',
        'RJ-14' => 'Treatment outside the policy territory', 'RJ-15' => 'Other — see reason'];

    public const CLAIM_TYPES = ['IP' => 'Inpatient / Hospitalization', 'DC' => 'Day Care / Day Surgery', 'SU' => 'Surgery', 'OT' => 'Organ Transplantation', 'HP' => 'Hospice Care', 'OP' => 'Outpatient Care',
        'ER' => 'Emergency Care', 'AM' => 'Ambulance Services', 'EV' => 'Medical Evacuation & Repatriation', 'DI' => 'Diagnostic Services', 'LB' => 'Laboratory Services', 'PH' => 'Pharmacy / Prescription Drugs',
        'MT' => 'Maternity & Newborn Care', 'PD' => 'Pediatric Care', 'MH' => 'Mental Health & Psychological Care', 'DE' => 'Dental Care', 'OV' => 'Optical / Vision Care', 'MD' => 'Medical Devices & Prosthetics',
        'ON' => 'Cancer / Oncology', 'RD' => 'Renal / Dialysis Treatment', 'FI' => 'Fertility / Infertility', 'RH' => 'Rehabilitation & Physiotherapy', 'CD' => 'Chronic Disease Management', 'PW' => 'Preventive & Wellness Care',
        'VI' => 'Vaccination & Immunization', 'HS' => 'Health Screening', 'GT' => 'Genetic Testing', 'HC' => 'Hearing Care', 'HH' => 'Home Healthcare', 'TM' => 'Telemedicine',
        'AC' => 'Alternative / Complementary Medicine', 'IO' => 'International / Overseas Treatment', 'PC' => 'Palliative Care', 'ST' => 'Special / High-Cost Treatments', 'OM' => 'Other Medical Services'];

    public const INPATIENT_ROOTS = ['IP', 'DC', 'SU', 'OT', 'HP', 'MT', 'ON', 'IO', 'ST'];

    public const EMERGENCY_ROOTS = ['ER', 'AM', 'EV'];

    public const CLAIM_DOCUMENTS = ['invoice' => 'Provider invoice', 'detailed_bill' => 'Detailed bill', 'medical_report' => 'Medical report', 'discharge_summary' => 'Discharge summary',
        'lab_results' => 'Laboratory results', 'imaging_report' => 'Imaging report', 'prescription' => 'Prescription', 'member_id' => 'Member identification'];

    public const LINE_DOCUMENTS = ['line_invoice' => 'Line invoice or bill extract', 'line_prescription' => 'Prescription', 'line_report' => 'Clinical or diagnostic report',
        'line_operative' => 'Operative note', 'line_referral' => 'Referral letter', 'line_other' => 'Other supporting document'];

    public const PA_DOC_TYPES = ['Medical report', 'Prescription', 'MRI report', 'X-ray report', 'Laboratory result', 'Physician letter', 'Admission request', 'Discharge summary', 'Quotation / estimate', 'Other'];

    public const CAPABILITIES = ['eligibility.check' => "Check a patient's cover", 'claim.view' => 'See claims', 'claim.submit' => 'Submit claims and reply to requests', 'preauth.view' => 'See pre-authorizations',
        'preauth.submit' => 'Request pre-authorizations and reply to medical queries', 'intake.use' => 'Use Smart Intake', 'payment.view' => 'See payments, EOBs and proofs of payment', 'appeal.submit' => 'Appeal a decision',
        'message.send' => 'Message VSahha', 'insights.view' => 'See insights', 'team.manage' => 'Invite and manage team members', 'bank.request' => 'Request a change of bank details',
        'settings.view' => 'See organisation settings', 'note.write' => 'Write team notes and hand off records'];

    public const ROLES = [
        'Provider Admin' => ['Manages the team, the organisation and bank-detail requests.', null],
        'Front Desk' => ['Checks cover at arrival and prepares pre-authorization requests.', ['eligibility.check', 'claim.view', 'preauth.view', 'preauth.submit', 'message.send', 'note.write']],
        'Medical Coordinator' => ['Writes the clinical content of pre-authorizations and answers medical queries.', ['eligibility.check', 'claim.view', 'preauth.view', 'preauth.submit', 'message.send', 'appeal.submit', 'note.write']],
        'Billing Officer' => ['Submits claims, uses Smart Intake and handles appeals.', ['eligibility.check', 'claim.view', 'claim.submit', 'preauth.view', 'intake.use', 'payment.view', 'appeal.submit', 'message.send', 'insights.view', 'note.write']],
        'Finance Viewer' => ['Sees payments, EOBs, proofs of payment and receivables.', ['claim.view', 'payment.view', 'insights.view', 'settings.view', 'note.write']],
        'Read-only Auditor' => ['Sees everything, changes nothing.', ['claim.view', 'preauth.view', 'payment.view', 'insights.view', 'settings.view']],
    ];

    public const MFA_REQUIRED = ['Provider Admin', 'Billing Officer'];

    public const INTAKE_DEFAULTS = ['patientAutoMatch' => 0.95, 'diagnosisConfidence' => 0.70, 'unknownIllnessDefault' => 'R69'];

    public const UNKNOWN_ILLNESS = 'Unknown Illness';

    public const UNKNOWN_REASONS = ['handwritten-illegible' => 'The medical report is handwritten and could not be read.', 'low-confidence' => 'The diagnosis could not be read with enough confidence.',
        'contradictory' => 'The report gives diagnoses that contradict each other.', 'missing' => 'No diagnosis is written on the documents.'];

    /** "All branches" is for reading: the actions that need one branch are hidden */
    public const NEEDS_ONE_BRANCH = ['eligibility.check', 'claim.submit', 'preauth.submit', 'intake.use', 'appeal.submit', 'message.send', 'bank.request'];

    public static function claimStageId(?string $status): string
    {
        return self::CLAIM_STAGE_OF[$status ?? ''] ?? 'received';
    }

    public static function claimStage(?string $status): array
    {
        $id = self::claimStageId($status);
        [$label, $tone, $desc] = self::CLAIM_STAGES[$id];

        return compact('id', 'label', 'tone', 'desc');
    }

    public static function claimStatusLine(?string $status): string
    {
        return in_array($status, self::HIDDEN_CLAIM_STATUSES, true) ? 'Under validation' : (string) $status;
    }

    public static function paStage(?string $status, bool $partly = false): array
    {
        $id = self::PA_STAGE_OF[$status ?? ''] ?? 'checking';
        /* a GOP state after a partial decision is still a partial authorisation */
        $id = $id === 'authorised' && $partly ? 'partly' : $id;
        [$label, $tone] = self::PA_STAGES[$id];

        return compact('id', 'label', 'tone');
    }

    public static function paJourneyIndex(?string $status, bool $hasGop): int
    {
        if ($hasGop) {
            return 4;
        }
        $stage = self::PA_STAGE_OF[$status ?? ''] ?? null;
        if (in_array($stage, ['authorised', 'partly', 'declined'], true)) {
            return 3;
        }
        if (in_array($stage, ['medical', 'action', 'insurer'], true)) {
            return 2;
        }

        return $stage === 'checking' ? ($status === 'Submitted' ? 0 : 1) : -1;
    }

    public static function isAppealable(?string $code): bool
    {
        return (bool) $code && isset(self::REJECTION_REASONS[$code]) && $code !== 'RJ-13';
    }

    public static function documentLabel(?string $id): string
    {
        return self::CLAIM_DOCUMENTS[$id ?? ''] ?? self::LINE_DOCUMENTS[$id ?? ''] ?? (string) $id;
    }

    public static function roleCan(?string $role, string $capability): bool
    {
        if (! isset(self::ROLES[$role ?? ''])) {
            return false;
        }
        $can = self::ROLES[$role][1];

        return $can === null ? isset(self::CAPABILITIES[$capability]) : in_array($capability, $can, true);
    }

    public static function normIcd($c): string
    {
        return preg_replace('/\s+/', '', strtoupper(trim((string) ($c ?? ''))));
    }

    public static function isIcd($c): bool
    {
        return (bool) preg_match('/^[A-Z][0-9][0-9A-Z](\.[0-9A-Z]{1,4})?$/', self::normIcd($c));
    }
}
