<?php

namespace App\Services\Access\Widgets;

use App\Models\FinanceRecord;
use App\Models\User;

class FinanceClaimsWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        return [
            'metric' => FinanceRecord::sum('final_payable'),
            'caption' => 'Final payable value',
            'route' => 'finance.claims',
            'format' => 'currency',
        ];
    }
}
