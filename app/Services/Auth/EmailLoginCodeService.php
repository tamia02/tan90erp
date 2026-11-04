<?php

namespace App\Services\Auth;

use App\Mail\EmailLoginCodeMail;
use App\Models\EmailLoginCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Throwable;

class EmailLoginCodeService
{
    private const CODE_TTL_MINUTES = 10;

    private const MAX_CODE_ATTEMPTS = 5;

    public function send(string $email): void
    {
        $email = $this->normalizeEmail($email);
        $this->ensureCanRequest($email);

        $user = $this->activeUserForEmail($email);

        // Use the same response for unknown/inactive accounts to avoid exposing
        // whether an address belongs to a user in any of the role systems.
        if (! $user) {
            throw ValidationException::withMessages([
                'form.email' => 'You do not have a Tan90 account with this email address.',
            ]);
        }

        $code = (string) random_int(100000, 999999);

        EmailLoginCode::query()->updateOrCreate(
            ['email' => $email],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES),
            ],
        );

        try {
            Mail::to($user->email)->send(new EmailLoginCodeMail(
                recipientName: $user->name,
                code: $code,
                expiresInMinutes: self::CODE_TTL_MINUTES,
            ));
        } catch (Throwable $exception) {
            EmailLoginCode::query()->where('email', $email)->delete();

            Log::warning('Tan90 email login code delivery failed.', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);

            throw ValidationException::withMessages([
                'form.email' => 'We could not deliver a sign-in code right now. Please try again shortly.',
            ]);
        }
    }

    public function verify(string $email, string $code): User
    {
        $email = $this->normalizeEmail($email);
        $key = $this->limiterKey('verify', $email);

        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->invalidCode('Too many attempts. Request a new sign-in code and try again.');
        }

        RateLimiter::hit($key, 15 * 60);

        $user = DB::transaction(function () use ($email, $code): ?User {
            $challenge = EmailLoginCode::query()
                ->where('email', $email)
                ->lockForUpdate()
                ->first();

            if (! $challenge || $challenge->expires_at->isPast()) {
                $challenge?->delete();

                return null;
            }

            if ($challenge->attempts >= self::MAX_CODE_ATTEMPTS) {
                $challenge->delete();

                return null;
            }

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->attempts++;

                if ($challenge->attempts >= self::MAX_CODE_ATTEMPTS) {
                    $challenge->delete();
                } else {
                    $challenge->save();
                }

                return null;
            }

            $user = $this->activeUserForEmail($email);

            $challenge->delete();

            return $user;
        });

        if (! $user) {
            $this->invalidCode();
        }

        RateLimiter::clear($key);

        return $user;
    }

    private function ensureCanRequest(string $email): void
    {
        $hourlyKey = $this->limiterKey('send-hourly', $email);

        if (RateLimiter::tooManyAttempts($hourlyKey, 5)) {
            $this->requestLimited(RateLimiter::availableIn($hourlyKey));
        }

        RateLimiter::hit($hourlyKey, 60 * 60);
    }

    private function limiterKey(string $action, string $email): string
    {
        return 'email-login:'.$action.':'.hash('sha256', $email.'|'.request()->ip());
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function activeUserForEmail(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_active', true)
            ->first();
    }

    private function requestLimited(int $seconds): never
    {
        throw ValidationException::withMessages([
            'form.email' => 'Please wait '.$seconds.' seconds before requesting another code.',
        ]);
    }

    private function invalidCode(string $message = 'That code is invalid or has expired. Request a new code and try again.'): never
    {
        throw ValidationException::withMessages(['emailCode' => $message]);
    }
}
