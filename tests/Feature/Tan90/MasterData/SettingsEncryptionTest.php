<?php

namespace Tests\Feature\Tan90\MasterData;

use App\Models\Tan90\MasterData\ModuleSetting;
use App\Services\Tan90\MasterData\ModuleSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Tan90\MasterData\Concerns\SeedsTan90Access;
use Tests\TestCase;

class SettingsEncryptionTest extends TestCase
{
    use RefreshDatabase;
    use SeedsTan90Access;

    public function test_gst_api_key_is_encrypted_at_rest_and_decrypts_correctly(): void
    {
        $admin = $this->superAdmin();

        $this->actingAs($admin)->post(route('tan90.master-data.settings.update', 'gst'), [
            'enabled' => '1',
            'endpointTemplate' => 'https://example.test/{api_key}/{gstin}',
            'apiKey' => 'super-secret-key-123',
            'timeout' => '10',
            'cacheHours' => '24',
        ]);

        $row = ModuleSetting::where('group', 'gst')->where('key', 'apiKey')->firstOrFail();

        $this->assertTrue($row->is_encrypted);
        $this->assertNotSame('super-secret-key-123', $row->value, 'Secret must not be stored in plain text.');
        $this->assertSame('super-secret-key-123', $row->plainValue());
    }

    public function test_settings_screen_masks_secret_values_instead_of_echoing_them(): void
    {
        $admin = $this->superAdmin();
        ModuleSetting::put('gst', 'apiKey', 'super-secret-key-123', true, $admin->id);

        $values = app(ModuleSettingsService::class)->groupValues('gst');

        $this->assertStringNotContainsString('super-secret-key-123', $values['apiKey']);
    }

    public function test_only_settings_capable_role_can_update_settings(): void
    {
        $mdm = $this->masterDataManager(); // has view/create/edit/approve/export but not settings

        $this->actingAs($mdm)
            ->post(route('tan90.master-data.settings.update', 'gst'), ['apiKey' => 'x'])
            ->assertForbidden();
    }
}
