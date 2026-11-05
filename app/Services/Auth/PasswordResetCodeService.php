<?php

namespace App\Services\Auth;

use App\Mail\PasswordResetCodeMail;
use App\Models\PasswordResetCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PasswordResetCodeService
{
    private const CODE_TTL_MINUTES = 10;

    public function send(string $email): void
    {
        $email = $this->normalizeEmail($email);
        $this->ensureCanRequest($email);

        $user = User::query()->where('email', $email)->where('is_active', true)->first();
        if (! $user) {
            return;
        }

        $code = (string) random_int(100000, 999999);
        PasswordResetCode::query()->updateOrCreate(
            ['email' => $email],
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES)],
        );

        try {
            Mail::to($user->email)->send(new PasswordResetCodeMail($user->name, $code, self::CODE_TTL_MINUTES));
        } catch (Throwable $exception) {
            PasswordResetCode::query()->where('email', $email)->delete();
            Log::warning('Tan90 password reset code delivery failed.', [
                'user_id' => $user->getKey(),
                'exception' => $exception::class,
            ]);
            throw ValidationException::withMessages(['email' => 'We could not deliver a reset code right now. Please try again shortly.']);
        }
    }

    public function reset(string $email, string $code, string $password): bool
    {
        $email = $this->normalizeEmail($email);
        $key = 'password-reset:verify:'.hash('sha256', $email.'|'.request()->ip());
        if (RateLimiter::tooManyAttempts($key, 10)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Request a new reset code and try again.']);
        }
        RateLimiter::hit($key, 15 * 60);

        $user = DB::transaction(function () use ($email, $code, $password): ?User {
            $challenge = PasswordResetCode::query()->where('email', $email)->lockForUpdate()->first();
            if (! $challenge || $challenge->expires_at->isPast() || $challenge->attempts >= 5) {
                $challenge?->delete();

                return null;
            }
            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->attempts++;
                $challenge->attempts >= 5 ? $challenge->delete() : $challenge->save();

                return null;
            }

            $user = User::query()->where('email', $email)->where('is_active', true)->first();
            if (! $user) {
                $challenge->delete();

                return null;
            }

            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $challenge->delete();

            return $user;
        });

        if (! $user) {
            throw ValidationException::withMessages(['code' => 'That code is invalid or has expired. Request a new reset code and try again.']);
        }

        RateLimiter::clear($key);

        return true;
    }

    private function ensureCanRequest(string $email): void
    {
        $key = $this->key($email);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages(['email' => 'Please wait '.$seconds.' seconds before requesting another code.']);
        }
        RateLimiter::hit($key, 60 * 60);
    }

    private function key(string $email): string
    {
        return 'password-reset:send:'.hash('sha256', $email.'|'.request()->ip());
    }

    private function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
