<?php

namespace App\Support;

class DeploymentIdentity
{
    public static function isStaging(): bool
    {
        return config('app.env') === 'staging';
    }

    public static function mailSubject(string $subject): string
    {
        return self::isStaging() ? '[STAGING] '.$subject : $subject;
    }
}
