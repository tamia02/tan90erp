<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\Plant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

class PolicyScopeTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    public function test_auditor_can_view_but_not_create_or_edit(): void
    {
        $auditor = $this->auditor();

        $this->actingAs($auditor)
            ->get(route('tan90.master-data.index', 'legal-entities'))
            ->assertOk();

        $this->actingAs($auditor)
            ->get(route('tan90.master-data.create', 'legal-entities'))
            ->assertForbidden();

        $this->actingAs($auditor)
            ->post(route('tan90.master-data.store', 'legal-entities'), ['code' => 'X', 'name' => 'X'])
            ->assertForbidden();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('tan90.master-data.index', 'legal-entities'))->assertRedirect();
    }

    public function test_plant_user_only_sees_their_assigned_plant(): void
    {
        $myPlant = Plant::factory()->create();
        $otherPlant = Plant::factory()->create();
        $plantUser = $this->plantUser($myPlant->id);

        $this->actingAs($plantUser)
            ->get(route('tan90.master-data.show', ['plants', $myPlant->id]))
            ->assertOk();

        $this->actingAs($plantUser)
            ->get(route('tan90.master-data.show', ['plants', $otherPlant->id]))
            ->assertForbidden();
    }

    public function test_plant_user_cannot_edit_records(): void
    {
        $plant = Plant::factory()->create();
        $plantUser = $this->plantUser($plant->id);

        $this->actingAs($plantUser)
            ->get(route('tan90.master-data.edit', ['plants', $plant->id]))
            ->assertForbidden();
    }
}
