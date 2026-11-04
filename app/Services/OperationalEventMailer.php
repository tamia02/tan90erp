<?php

namespace App\Services;

use App\Enums\Role;
use App\Mail\OperationalEventMail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Sends operational hand-off notices to active owners of the next module. */
class OperationalEventMailer
{
    /** @param array<Role> $roles */
    public function notify(string $subject, string $summary, ?int $locationId, array $roles, string $path): void
    {
        if (! config('notifications.operational_email_enabled', false)) {
            return;
        }

        $roleValues = collect($roles)->map(fn (Role $role) => $role->value)->unique()->values()->all();
        $recipients = User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where(function ($query) use ($roleValues) {
                $query->whereIn('role', $roleValues)
                    ->orWhereHas('extraRoleRecords', fn ($extra) => $extra->whereIn('role', $roleValues))
                    ->orWhere('super_admin', true)
                    ->orWhere('role', Role::Admin->value);
            })
            // If an event has no resolved location, treat its scope as unknown
            // and notify all owners of the target module. Restricting that
            // case to all-sites users silently drops every site-scoped owner.
            ->when($locationId !== null, fn ($query) => $query->where(fn ($scope) =>
                $scope->whereNull('location_id')->orWhere('location_id', $locationId)
            ))
            ->get(['email', 'preferences'])
            ->filter(fn (User $user) => data_get($user->preferences, 'email_notifications', true) !== false)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values();

        if ($recipients->isEmpty()) {
            Log::info('Operational event email has no active role recipients', [
                'subject' => $subject,
                'roles' => $roleValues,
                'location_id' => $locationId,
            ]);

            return;
        }

        $send = function () use ($recipients, $subject, $summary, $path): void {
            foreach ($recipients as $email) {
                try {
                    Mail::to($email)->send(OperationalEventMail::forEvent($subject, $summary, url($path)));
                } catch (Throwable $exception) {
                    Log::warning('Operational event email could not be sent', [
                        'recipient' => $email,
                        'subject' => $subject,
                        'error' => $exception->getMessage(),
                    ]);
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($send);
        } else {
            $send();
        }

    }
}
