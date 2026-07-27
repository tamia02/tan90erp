<?php

namespace App\Services\Access\Widgets;

use App\Models\QcResult;
use App\Models\User;
use App\Models\ValidationIssue;

class QcQueueWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        return [
            'metric' => ValidationIssue::where('status', 'open')->count() + QcResult::count(),
            'caption' => 'QC checks and open validation issues',
            'route' => 'qc.queue',
        ];
    }
}
