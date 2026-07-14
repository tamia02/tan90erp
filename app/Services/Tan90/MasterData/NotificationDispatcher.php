<?php

namespace App\Services\Tan90\MasterData;

use App\Models\Tan90\MasterData\NotificationTemplate;
use App\Models\Tan90\MasterData\Role;
use App\Models\Tan90\MasterData\UserProfile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Renders a tan90_notification_templates row's subject against $context and
 * sends it. Templates only define a subject line (matching the demo's data
 * shape), so the body is a short generated summary, not a designed email -
 * this is the "notification contract" the module scope calls for, not a
 * full transactional-email system.
 *
 * Module Settings' `email.mailer` defaults to 'log' (seeded), so out of the
 * box this writes to the Laravel log instead of sending real mail until an
 * operator configures SMTP - see ModuleSettingsService.
 */
class NotificationDispatcher
{
    /**
     * @param  array<string, string>  $context  placeholders for the template subject
     */
    public function sendToApprovers(string $templateCode, array $context): int
    {
        $approverEmails = UserProfile::with(['user', 'role.permissions'])
            ->get()
            ->filter(function (UserProfile $profile) {
                $approvePermission = $profile->role?->permissions->firstWhere('key', 'approve');

                return (bool) ($approvePermission?->pivot->allowed);
            })
            ->pluck('user.email')
            ->filter()
            ->unique();

        return $this->dispatch($templateCode, $context, $approverEmails->all());
    }

    /**
     * Used by CheckSlaBreaches: sends to every user whose Tan90 role name
     * matches $roleName (case-insensitive) - i.e. an SLA policy's free-text
     * `escalation_role`. No matching role/user => 0 sent, not an error.
     */
    public function sendToRole(string $roleName, string $templateCode, array $context): int
    {
        $role = Role::whereRaw('LOWER(name) = ?', [strtolower(trim($roleName))])->first();
        if (! $role) {
            return 0;
        }

        $emails = UserProfile::with('user')
            ->where('tan90_role_id', $role->id)
            ->get()
            ->pluck('user.email')
            ->filter()
            ->unique()
            ->all();

        return $this->dispatch($templateCode, $context, $emails);
    }

    /** @param  string[]  $emails */
    private function dispatch(string $templateCode, array $context, array $emails): int
    {
        $template = NotificationTemplate::where('code', $templateCode)->where('status', 'active')->first();
        if (! $template || ! $emails) {
            return 0;
        }

        $subject = $template->renderSubject($context);
        $body = $context['summary'] ?? 'A master data record requires your review in the Tan90 Approval Queue.';

        $sent = 0;
        foreach (array_unique($emails) as $email) {
            if ($this->send($email, $subject, $body)) {
                $sent++;
            }
        }

        return $sent;
    }

    private function send(string $email, string $subject, string $body): bool
    {
        try {
            Mail::raw($body, function ($message) use ($email, $subject) {
                $message->to($email)->subject($subject);
            });

            return true;
        } catch (Throwable $e) {
            Log::warning('Tan90 notification dispatch failed', ['email' => $email, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
