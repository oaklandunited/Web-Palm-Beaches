<?php
/**
 * Web Palm Beaches — Server Configuration Template
 *
 * SETUP INSTRUCTIONS:
 * 1. Copy this file to ONE of the following locations (never commit real secrets to git):
 *      - RECOMMENDED (above webroot):  ../wpb-config.php
 *      - OR local (gitignored + blocked by .htaccess): ./config.local.php
 *    Alternatively, set the matching environment variables in your host control panel / Apache config.
 * 2. Fill in your LLM provider, API key, and Resend / reCAPTCHA keys.
 */

return [
    // Supported providers: 'openai', 'anthropic', 'gemini'
    'LLM_PROVIDER' => 'openai',

    // API key for your chosen LLM provider (kept strictly server-side)
    'LLM_API_KEY'  => '',

    // Optional model override. Defaults:
    //   openai    => 'gpt-4o-mini'
    //   anthropic => 'claude-3-5-haiku-latest'
    //   gemini    => 'gemini-2.5-flash'
    'LLM_MODEL'    => '',

    // Resend API key for lead notifications (contact form, homepage CTA, Project Brief email capture)
    'RESEND_API_KEY' => '',

    // Google reCAPTCHA v3 secret key (used by contact_handler.php and send_email.php)
    'RECAPTCHA_SECRET_KEY' => '',

    // Lead notification email routing
    'LEAD_TO_EMAIL'   => 'hello@webpalmbeaches.com',
    'LEAD_FROM_EMAIL' => 'Web Palm Beaches <no-reply@webpalmbeaches.com>',

    // Rate limiting for /project-brief-api.php (per IP address)
    'BRIEF_RATE_LIMIT_MAX'    => 6,
    'BRIEF_RATE_LIMIT_WINDOW' => 3600, // seconds (1 hour)
];
