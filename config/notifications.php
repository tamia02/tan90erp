<?php

return [
    // Security and account emails (email-code login / password reset) use
    // their own auth flows. This flag controls business-event email only.
    'operational_email_enabled' => (bool) env('OPERATIONAL_EMAIL_NOTIFICATIONS_ENABLED', false),
];
