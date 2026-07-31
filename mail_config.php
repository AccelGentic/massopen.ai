<?php
/**
 * Mailgun and opt-in settings for Mass Open.
 *
 * Like db_config.php, every secret comes from the environment so nothing
 * sensitive lives in source control. Set these in your web server / PHP-FPM
 * pool config:
 *
 *   MASSOPEN_MAILGUN_API_KEY   required — Mailgun private API key
 *   MASSOPEN_MAILGUN_DOMAIN    required — sending domain, e.g. mg.massopen.ai
 *   MASSOPEN_MAILGUN_BASE      optional — https://api.eu.mailgun.net for EU
 *   MASSOPEN_MAIL_FROM         optional — defaults to Mass Open <hello@DOMAIN>
 *   MASSOPEN_MAIL_REPLY_TO     optional
 *   MASSOPEN_SITE_URL          optional — public origin, no trailing slash
 *   MASSOPEN_CONFIRM_TTL_HOURS optional — link lifetime, default 48
 *   MASSOPEN_VALIDATE_EMAILS   optional — "1" enables the Mailgun validation
 *                                          pre-check (metered, off by default)
 *   MASSOPEN_RESEND_SECONDS    optional — min gap between resends, default 90
 *   MASSOPEN_IP_HOURLY_LIMIT   optional — signups per IP per hour, default 10
 */

declare(strict_types=1);

$domain = getenv('MASSOPEN_MAILGUN_DOMAIN') ?: '';

return [
    'api_key'         => getenv('MASSOPEN_MAILGUN_API_KEY') ?: '',
    'domain'          => $domain,
    // US region by default; EU accounts must use https://api.eu.mailgun.net.
    'api_base'        => rtrim(getenv('MASSOPEN_MAILGUN_BASE') ?: 'https://api.mailgun.net', '/'),

    'from'            => getenv('MASSOPEN_MAIL_FROM') ?: ('Mass Open <hello@' . $domain . '>'),
    'reply_to'        => getenv('MASSOPEN_MAIL_REPLY_TO') ?: '',

    'site_url'        => rtrim(getenv('MASSOPEN_SITE_URL') ?: 'https://massopen.ai', '/'),

    'confirm_ttl'     => (int) (getenv('MASSOPEN_CONFIRM_TTL_HOURS') ?: 48),

    // Call for papers. cfp_notify is an optional address that receives a copy
    // of every verified proposal; leave unset to disable.
    'cfp_notify'      => getenv('MASSOPEN_CFP_NOTIFY') ?: '',
    'cfp_min_seconds' => (int) (getenv('MASSOPEN_CFP_MIN_SECONDS') ?: 5),
    'cfp_max_hours'   => (int) (getenv('MASSOPEN_CFP_MAX_HOURS') ?: 3),
    'cfp_daily_ip'    => (int) (getenv('MASSOPEN_CFP_DAILY_IP') ?: 5),
    'cfp_daily_email' => (int) (getenv('MASSOPEN_CFP_DAILY_EMAIL') ?: 3),
    'validate_emails' => (getenv('MASSOPEN_VALIDATE_EMAILS') ?: '0') === '1',
    'resend_seconds'  => (int) (getenv('MASSOPEN_RESEND_SECONDS') ?: 90),
    'ip_hourly_limit' => (int) (getenv('MASSOPEN_IP_HOURLY_LIMIT') ?: 10),
];
