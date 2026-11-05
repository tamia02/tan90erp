<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class ZohoFieldDefinition extends Model
{
    // Zoho PO custom field that the ERP already has as a built-in PO field.
    public const PO_BUILT_IN = ['cf_po_valid_upto' => 'validUpto'];

    protected $fillable = ['entity', 'api_name', 'label', 'data_type', 'is_mandatory', 'options', 'sort_order', 'active', 'zoho_field_id'];

    protected function casts(): array
    {
        return ['is_mandatory' => 'boolean', 'options' => 'array', 'active' => 'boolean'];
    }

    public function scopeForEntity(Builder $query, string $entity): Builder
    {
        return $query->where('entity', $entity)->where('active', true)->orderBy('sort_order')->orderBy('label');
    }

    /** Laravel validation rules for a value of this field. */
    public function rules(): array
    {
        $rules = [$this->is_mandatory ? 'required' : 'nullable'];

        return [...$rules, ...match ($this->data_type) {
            'date' => ['date'],
            'date_time' => ['date'],
            'number', 'decimal', 'amount', 'percent' => ['numeric'],
            'email' => ['email'],
            'url' => ['url'],
            'dropdown' => $this->options ? [Rule::in($this->options)] : ['string'],
            'check_box' => ['boolean'],
            default => ['string', 'max:255'],
        }];
    }

    public function inputType(): string
    {
        return match ($this->data_type) {
            'date' => 'date',
            'date_time' => 'datetime-local',
            'number', 'decimal', 'amount', 'percent' => 'number',
            'email' => 'email',
            'url' => 'url',
            'phone' => 'tel',
            'dropdown' => 'select',
            'check_box' => 'checkbox',
            'multiline' => 'textarea',
            default => 'text',
        };
    }
}
