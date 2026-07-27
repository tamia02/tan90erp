<?php

namespace App\Services\Access\Widgets;

use App\Models\Tan90\MasterData\Vendor;
use App\Models\User;

class MasterVendorWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        return [
            'metric' => Vendor::count(),
            'caption' => 'Master-data vendors',
            'route' => 'tan90.master-data.index',
            'route_params' => ['vendors'],
        ];
    }
}
