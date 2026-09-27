<?php

namespace App\Models\Tan90\MasterData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class ModuleSetting extends Model
{
    protected $table = 'tan90_module_settings';

    protected $fillable = ['group', 'key', 'value', 'is_encrypted', 'updated_by'];

    protected $casts = ['is_encrypted' => 'boolean'];

    /**
     * Plain value, transparently decrypted if the row is marked encrypted.
     * Never logged - callers must not pass this to AuditLogger.
     */
    public function plainValue(): ?string
    {
        if (! $this->value) {
            return $this->value;
        }

        return $this->is_encrypted ? Crypt::decryptString($this->value) : $this->value;
    }

    public static function put(string $group, string $key, ?string $value, bool $encrypt, ?int $updatedBy): void
    {
        static::updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'value' => $encrypt && $value !== null && $value !== '' ? Crypt::encryptString($value) : $value,
                'is_encrypted' => $encrypt,
                'updated_by' => $updatedBy,
            ]
        );
    }
}
