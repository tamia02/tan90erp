<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasswordResetCode extends Model
{
    protected $fillable = ['email', 'code_hash', 'attempts', 'expires_at'];

    protected function casts(): array
    {
        return ['attempts' => 'integer', 'expires_at' => 'datetime'];
    }
}
