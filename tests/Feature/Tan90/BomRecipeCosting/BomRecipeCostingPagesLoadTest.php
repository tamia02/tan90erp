<?php

namespace Tests\Feature\Tan90\BomRecipeCosting;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

/**
 * The BOM/Recipe/Costing module (Space 03 — Product & Engineering Masters)
 * had zero automated coverage of its own named routes before this — the
 * existing Tan90\MasterData tests exercise a different route prefix
 * (tan90/master-data), not tan90/brc. This exercises every named index page
 * for a user holding full house-wide permissions, the same way the golden
 * path tests do for Forge and Flow.
 */
class BomRecipeCostingPagesLoadTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    public function test_all_brc_named_pages_load_for_a_fully_permissioned_user(): void
    {
        $user = $this->superAdmin();

        foreach ([
            'tan90.brc.dashboard',
            'tan90.brc.audit-logs',
            'tan90.brc.mrp-readiness.index',
            'tan90.brc.recipes.index',
            'tan90.brc.recipes.create',
            'tan90.brc.boms.index',
            'tan90.brc.boms.create',
            'tan90.brc.routings.index',
            'tan90.brc.routings.create',
            'tan90.brc.costing.index',
            'tan90.brc.eco.index',
        ] as $route) {
            $this->actingAs($user)->get(route($route))->assertOk();
        }
    }

    public function test_a_user_without_view_permission_is_forbidden(): void
    {
        $user = $this->userWithRole('ROLE-NO-ACCESS-TEST', []);

        $this->actingAs($user)->get(route('tan90.brc.boms.index'))->assertForbidden();
    }
}
