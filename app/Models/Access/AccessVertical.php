<?php

namespace App\Models\Access;

use Illuminate\Database\Eloquent\Model;

class AccessVertical extends Model
{
    protected $fillable = ['code', 'name', 'description', 'status', 'created_by'];
}
