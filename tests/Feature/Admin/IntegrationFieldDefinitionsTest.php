<?php

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IntegrationFieldDefinitionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    protected function createAdminUser(): User
    {
        $user = User::factory()->create();
        $role = new Role;
        $role->code = 'admin';
        $role->name = 'Admin';
        $role->save();
        $user->roles()->attach($role);

        return $user;
    }

    public function test_integration_config_has_fields_for_all_integrations(): void
    {
        $integrations = config('integrations');
        foreach ($integrations as $key => $integration) {
            $this->assertArrayHasKey('fields', $integration, sprintf("Integration '%s' is missing 'fields' array.", $key));
            $this->assertNotEmpty($integration['fields'], sprintf("Integration '%s' has empty 'fields' array.", $key));
        }
    }

    public function test_each_field_has_required_attributes(): void
    {
        $integrations = config('integrations');
        foreach ($integrations as $service => $integration) {
            foreach ($integration['fields'] as $fieldKey => $field) {
                $this->assertArrayHasKey('type', $field, sprintf("Field '%s' in '%s' missing 'type'.", $fieldKey, $service));
                $this->assertArrayHasKey('label', $field, sprintf("Field '%s' in '%s' missing 'label'.", $fieldKey, $service));
                $this->assertContains($field['type'], ['text', 'url', 'password', 'toggle', 'number', 'select', 'select-remote'], sprintf("Field '%s' in '%s' has invalid type '%s'.", $fieldKey, $service, $field['type']));
            }
        }
    }

    public function test_show_page_includes_field_definitions(): void
    {
        $admin = $this->createAdminUser();

        $response = $this->actingAs($admin)->get('/admin/settings/integrations/opnsense');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Settings/IntegrationShow')
            ->has('service.fields')
            ->where('service.fields.0.type', 'url')
        );
    }

    public function test_field_definitions_match_validation_keys(): void
    {
        $integrations = config('integrations');
        foreach ($integrations as $service => $integration) {
            $fieldKeys = array_keys($integration['fields']);
            $validationKeys = array_keys($integration['validation'] ?? []);
            foreach ($validationKeys as $vKey) {
                $this->assertContains($vKey, $fieldKeys, sprintf("Validation key '%s' in '%s' has no corresponding field definition.", $vKey, $service));
            }
        }
    }
}
