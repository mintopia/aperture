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
            $this->assertArrayHasKey('fields', $integration, "Integration '{$key}' is missing 'fields' array.");
            $this->assertNotEmpty($integration['fields'], "Integration '{$key}' has empty 'fields' array.");
        }
    }

    public function test_each_field_has_required_attributes(): void
    {
        $integrations = config('integrations');
        foreach ($integrations as $service => $integration) {
            foreach ($integration['fields'] as $fieldKey => $field) {
                $this->assertArrayHasKey('type', $field, "Field '{$fieldKey}' in '{$service}' missing 'type'.");
                $this->assertArrayHasKey('label', $field, "Field '{$fieldKey}' in '{$service}' missing 'label'.");
                $this->assertContains($field['type'], ['text', 'url', 'password', 'toggle', 'number', 'select'], "Field '{$fieldKey}' in '{$service}' has invalid type '{$field['type']}'.");
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
                $this->assertContains($vKey, $fieldKeys, "Validation key '{$vKey}' in '{$service}' has no corresponding field definition.");
            }
        }
    }
}
