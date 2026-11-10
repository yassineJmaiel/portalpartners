<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* What the portal owns, and nothing the CRM owns: users, sessions, drafts, uploads,
   Smart Intake results, notifications, the webhook inbox and the audit trail. Claims,
   pre-authorizations, decisions, payments and benefit balances are never stored here;
   they are read from the CRM on every request. Times are ISO strings in UTC, so
   they compare as text and mean the same on every server. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organisations', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('name');
            $t->string('created_at', 30);
        });
        Schema::create('org_providers', function (Blueprint $t) {
            $t->string('org_id', 40);
            $t->string('provider_id', 40)->index();
            $t->string('label')->nullable();
            $t->primary(['org_id', 'provider_id']);
        });
        Schema::create('users', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('org_id', 40)->index();
            $t->string('email')->unique();
            $t->string('name');
            $t->string('role', 40);
            $t->string('status', 20)->default('Active');
            $t->string('password_hash')->nullable();
            $t->boolean('mfa')->default(false);
            $t->string('language', 4)->default('en');
            $t->string('created_at', 30);
            $t->string('last_login_at', 30)->nullable();
            $t->unsignedInteger('failed_count')->default(0);
            $t->string('locked_until', 30)->nullable();
            $t->string('invited_by', 40)->nullable();
        });
        Schema::create('portal_sessions', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->string('user_id', 40)->index();
            $t->string('provider_id', 40)->nullable();
            $t->string('created_at', 30);
            $t->string('expires_at', 30);
            $t->string('last_seen_at', 30);
            $t->string('ip', 60)->nullable();
            $t->string('ua')->nullable();
            $t->boolean('remember')->default(false);
        });
        Schema::create('challenges', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('user_id', 40);
            $t->string('code_hash', 64);
            $t->string('expires_at', 30);
            $t->unsignedInteger('tries')->default(0);
            $t->string('created_at', 30);
            $t->string('resent_at', 30)->nullable();
            $t->boolean('remember')->default(false);
            $t->boolean('used')->default(false);
        });
        Schema::create('trusted_devices', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->string('user_id', 40);
            $t->string('expires_at', 30);
            $t->string('created_at', 30);
        });
        Schema::create('signins', function (Blueprint $t) {
            $t->id();
            $t->string('user_id', 40)->nullable()->index();
            $t->string('email')->nullable();
            $t->string('at', 30);
            $t->string('ip', 60)->nullable();
            $t->string('ua')->nullable();
            $t->string('outcome', 80);
        });
        Schema::create('audit', function (Blueprint $t) {
            $t->id();
            $t->string('at', 30);
            $t->string('user_id', 40)->nullable();
            $t->string('org_id', 40)->nullable();
            $t->string('provider_id', 40)->nullable();
            $t->string('action', 60);
            $t->string('subject')->nullable();
            $t->text('detail')->nullable();
            $t->string('ip', 60)->nullable();
            $t->string('device')->nullable();
            $t->text('before_state')->nullable();
            $t->text('after_state')->nullable();
            $t->index(['org_id', 'at']);
        });
        Schema::create('eligibility_checks', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('at', 30);
            $t->string('user_id', 40);
            $t->string('provider_id', 40);
            $t->string('member_id', 40)->nullable();
            $t->string('member_name')->nullable();
            $t->string('service_date', 10)->nullable();
            $t->boolean('eligible')->default(false);
            $t->text('summary')->nullable();
            $t->longText('snapshot')->nullable();
        });
        Schema::create('drafts', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('org_id', 40)->index();
            $t->string('provider_id', 40);
            $t->string('kind', 20);
            $t->string('title')->nullable();
            $t->longText('data');
            $t->string('source', 20)->default('manual');
            $t->string('created_by', 40)->nullable();
            $t->string('created_at', 30);
            $t->string('updated_at', 30);
        });
        Schema::create('files', function (Blueprint $t) {
            $t->string('ref', 40)->primary();
            $t->string('org_id', 40)->index();
            $t->string('provider_id', 40);
            $t->string('name');
            $t->string('mime', 80)->nullable();
            $t->unsignedBigInteger('size')->default(0);
            $t->string('sha256', 64)->index();
            $t->string('path');
            $t->string('crm_ref', 60)->nullable();
            $t->string('created_by', 40)->nullable();
            $t->string('created_at', 30);
            $t->string('scan_status', 20)->nullable();
            $t->string('scanned_by', 80)->nullable();
            $t->string('scanned_at', 30)->nullable();
            $t->string('removed_at', 30)->nullable();
            $t->string('removed_why')->nullable();
        });
        Schema::create('intake_batches', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('org_id', 40);
            $t->string('provider_id', 40)->index();
            $t->string('created_by', 40)->nullable();
            $t->string('created_at', 30);
            $t->string('engine', 80)->nullable();
            $t->string('status', 20)->default('Read');
            $t->text('error')->nullable();
            $t->text('notes')->nullable();
        });
        Schema::create('intake_documents', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('batch_id', 40)->index();
            $t->string('provider_id', 40);
            $t->string('file_ref', 40);
            $t->string('name')->nullable();
            $t->longText('pages')->nullable();
            $t->string('doc_type', 40)->nullable();
            $t->double('type_confidence')->nullable();
            $t->longText('text')->nullable();
            $t->longText('fields')->nullable();
            $t->longText('candidates')->nullable();
            $t->longText('patient')->nullable();
            $t->longText('diagnosis')->nullable();
            $t->string('duplicate_of', 60)->nullable();
            $t->longText('signals')->nullable();
            $t->string('status', 30);
            $t->string('draft_id', 40)->nullable();
            $t->string('created_at', 30);
            $t->string('updated_at', 30);
        });
        Schema::create('intake_feedback', function (Blueprint $t) {
            $t->id();
            $t->string('document_id', 40);
            $t->string('provider_id', 40)->index();
            $t->string('doc_type', 40)->nullable();
            $t->string('field', 60);
            $t->text('proposed')->nullable();
            $t->text('final')->nullable();
            $t->boolean('accepted');
            $t->string('at', 30);
            $t->string('user_id', 40)->nullable();
        });
        Schema::create('notifications', function (Blueprint $t) {
            $t->id();
            $t->string('org_id', 40);
            $t->string('provider_id', 40)->nullable();
            $t->string('user_id', 40)->nullable();
            $t->string('type', 60);
            $t->string('title');
            $t->text('body')->nullable();
            $t->string('link')->nullable();
            $t->string('at', 30);
            $t->string('read_at', 30)->nullable();
            $t->index(['org_id', 'at']);
        });
        Schema::create('webhook_inbox', function (Blueprint $t) {
            $t->string('event_id', 80)->primary();
            $t->string('type', 60);
            $t->string('provider_id', 40)->nullable();
            $t->string('received_at', 30);
            $t->longText('payload');
        });
        Schema::create('outbox', function (Blueprint $t) {
            $t->id();
            $t->string('channel', 20);
            $t->string('recipient')->nullable();
            $t->string('subject')->nullable();
            $t->longText('body')->nullable();
            $t->boolean('transmitted')->default(false);
            $t->string('reason')->nullable();
            $t->string('at', 30);
        });
        Schema::create('rate_limits', function (Blueprint $t) {
            $t->string('key', 120);
            $t->unsignedBigInteger('at');
            $t->index(['key', 'at']);
        });
        Schema::create('invites', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->string('user_id', 40);
            $t->string('kind', 20);
            $t->string('expires_at', 30);
            $t->boolean('used')->default(false);
            $t->string('created_at', 30);
        });
        Schema::create('config', function (Blueprint $t) {
            $t->string('key', 80)->primary();
            $t->text('value');
        });
        Schema::create('record_notes', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('org_id', 40);
            $t->string('provider_id', 40);
            $t->string('subject_type', 20);
            $t->string('subject_id', 60);
            $t->string('author_id', 40);
            $t->string('kind', 20);
            $t->text('body')->nullable();
            $t->text('mentions')->nullable();
            $t->string('assignee_id', 40)->nullable();
            $t->string('done_at', 30)->nullable();
            $t->string('done_by', 40)->nullable();
            $t->string('created_at', 30);
            $t->index(['org_id', 'subject_type', 'subject_id']);
            $t->index(['assignee_id', 'done_at']);
        });
        Schema::create('notify_prefs', function (Blueprint $t) {
            $t->string('user_id', 40);
            $t->string('type', 60);
            $t->boolean('email');
            $t->primary(['user_id', 'type']);
        });
        Schema::create('raised_alerts', function (Blueprint $t) {
            $t->string('key', 160)->primary();
            $t->string('at', 30);
        });
        Schema::create('check_ins', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('org_id', 40);
            $t->string('provider_id', 40);
            $t->string('member_id', 40);
            $t->string('member_name')->nullable();
            $t->string('day', 10);
            $t->string('at', 30);
            $t->string('method', 30);
            $t->string('check_id', 40)->nullable();
            $t->string('user_id', 40);
            $t->string('consent_hash', 64)->nullable();
            $t->string('consent_expires', 30)->nullable();
            $t->unsignedInteger('consent_tries')->default(0);
            $t->string('consent_at', 30)->nullable();
            $t->string('consent_sent_to', 40)->nullable();
            $t->index(['provider_id', 'day']);
        });
        Schema::create('endpoints', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('org_id', 40)->index();
            $t->string('url');
            $t->text('secret');
            $t->text('events');
            $t->boolean('active')->default(true);
            $t->string('created_by', 40)->nullable();
            $t->string('created_at', 30);
        });
        Schema::create('feedback', function (Blueprint $t) {
            $t->id();
            $t->string('org_id', 40)->index();
            $t->string('user_id', 40);
            $t->string('role', 40)->nullable();
            $t->string('page', 120)->nullable();
            $t->string('rating', 10)->nullable();
            $t->text('body')->nullable();
            $t->string('language', 4)->nullable();
            $t->unsignedInteger('width')->nullable();
            $t->string('at', 30);
            $t->string('status', 10)->default('New');
        });
        Schema::create('portal_jobs', function (Blueprint $t) {
            $t->string('id', 40)->primary();
            $t->string('kind', 40);
            $t->longText('payload');
            $t->string('status', 20);
            $t->unsignedInteger('attempts')->default(0);
            $t->unsignedInteger('max_attempts')->default(5);
            $t->string('run_after', 30);
            $t->text('last_error')->nullable();
            $t->longText('result')->nullable();
            $t->string('created_at', 30);
            $t->string('updated_at', 30);
            $t->index(['status', 'run_after']);
        });
        /* Laravel's own session store, for CSRF tokens and flash messages */
        Schema::create('sessions', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('user_id', 40)->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->text('user_agent')->nullable();
            $t->longText('payload');
            $t->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        foreach (['organisations', 'org_providers', 'users', 'portal_sessions', 'challenges', 'trusted_devices', 'signins', 'audit', 'eligibility_checks', 'drafts', 'files',
            'intake_batches', 'intake_documents', 'intake_feedback', 'notifications', 'webhook_inbox', 'outbox', 'rate_limits', 'invites', 'config', 'record_notes',
            'notify_prefs', 'raised_alerts', 'check_ins', 'endpoints', 'feedback', 'portal_jobs', 'sessions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
