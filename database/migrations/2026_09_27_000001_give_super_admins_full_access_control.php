<?php

use App\Services\Access\SuperAdminAccessSync;
use Illuminate\Database\Migrations\Migration;

// Existing databases: move GRN Super Admin accounts (e.g. admin@tan90.test)
// off the 'legacy' access mode so Access Control, Forge, Flow and Workspace
// stop returning 403 for them. Fresh installs get the same via the seeder.
return new class extends Migration
{
    public function up(): void
    {
        app(SuperAdminAccessSync::class)->syncAll();
    }

    public function down(): void
    {
        // Intentionally not reversed: reverting would lock the Super Admin
        // out of Access Control again.
    }
};
