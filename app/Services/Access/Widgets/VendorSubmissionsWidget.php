<?php

namespace App\Services\Access\Widgets;

use App\Enums\Role;
use App\Models\User;
use App\Models\VendorSubmission;
use App\Services\Access\AccessControlService;

/** Supports dashboard catalogs that still contain the legacy vendor widget. */
class VendorSubmissionsWidget implements WidgetProvider
{
    public function data(User $user): array
    {
        $scope = app(AccessControlService::class)->teamScopedUserIds($user);
        $submissions = VendorSubmission::query();

        if ($user->role === Role::Vendor) {
            $submissions->where('vendor_name', $user->name);
        } elseif ($scope !== null) {
            $ownerNames = User::query()->whereIn('id', $scope)->pluck('name');
            $submissions->whereHas('purchaseOrder', fn ($query) => $query->whereIn('po_owner', $ownerNames));
        }

        return [
            'metric' => $submissions->count(),
            'caption' => 'Vendor PO submissions',
            'route' => $user->role === Role::Vendor ? 'vendor.submissions' : 'admin.vendor-scorecard',
        ];
    }
}
