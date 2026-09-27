<?php

namespace App\Services\Access\Widgets;

use App\Models\User;

interface WidgetProvider
{
    /** @return array<string, mixed> */
    public function data(User $user): array;
}
