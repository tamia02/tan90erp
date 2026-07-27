<?php

namespace App\Services\Access\Widgets;

use App\Models\GrnRecord;
use App\Models\User;

class GrnRecordsWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        return [
            'metric' => GrnRecord::count(),
            'caption' => 'GRN rows in register',
            'route' => 'grn.register',
        ];
    }
}
