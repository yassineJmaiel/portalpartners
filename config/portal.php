<?php

/* The portal's link to the CRM, and what is connected. No secret has a default. */
return [
    'crm_base_url' => rtrim((string) env('CRM_BASE_URL', 'http://localhost:8000'), '/'),
    'crm_client_id' => env('CRM_CLIENT_ID', 'vsahha-provider-portal'),
    'crm_client_secret' => env('CRM_CLIENT_SECRET'),
    'webhook_secret' => env('WEBHOOK_SECRET'),
    'demo' => (bool) env('PORTAL_DEMO', false),
    'demo_password' => 'vsahha2026',
    'files_key' => env('FILES_KEY'),
    'clamd_host' => env('CLAMD_HOST'),
    'clamd_port' => (int) env('CLAMD_PORT', 3310),
    'mail_enabled' => (bool) env('PORTAL_MAIL_ENABLED', false),
    'sms_url' => env('SMS_URL'),
    'sms_token' => env('SMS_TOKEN'),
    'sms_from' => env('SMS_FROM', 'VSahha'),
    'org_name' => env('PORTAL_ORG_NAME'),
    'provider_ids' => env('PORTAL_PROVIDER_IDS'),
    'admin_email' => env('PORTAL_ADMIN_EMAIL'),
];
