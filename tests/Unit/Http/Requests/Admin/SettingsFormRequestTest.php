<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Requests\Admin;

use App\Http\Requests\Admin\UpdateDnsDetectionSettingsRequest;
use App\Http\Requests\Admin\UpdateGeneralSettingsRequest;
use App\Http\Requests\Admin\UpdateIpv6DetectionSettingsRequest;
use App\Http\Requests\Admin\UpdateNetworkSettingsRequest;
use App\Http\Requests\Admin\UpdateThemeSettingsRequest;
use Tests\TestCase;

class SettingsFormRequestTest extends TestCase
{
    public function test_dns_detection_request_authorizes(): void
    {
        $request = new UpdateDnsDetectionSettingsRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_dns_detection_request_rules(): void
    {
        $request = new UpdateDnsDetectionSettingsRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('dns_check_url', $rules);
        $this->assertArrayHasKey('dns_warning_message', $rules);
        $this->assertIsArray($rules['dns_check_url']);
        $this->assertContains('nullable', $rules['dns_check_url']);
        $this->assertContains('string', $rules['dns_check_url']);
        $this->assertContains('max:500', $rules['dns_check_url']);
    }

    public function test_dns_detection_request_has_uuid_closure_rule(): void
    {
        $request = new UpdateDnsDetectionSettingsRequest;
        $rules = $request->rules();

        $closures = array_filter($rules['dns_check_url'], fn ($rule) => $rule instanceof \Closure);
        $this->assertCount(2, $closures);
    }

    public function test_ipv6_detection_request_authorizes(): void
    {
        $request = new UpdateIpv6DetectionSettingsRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_ipv6_detection_request_rules(): void
    {
        $request = new UpdateIpv6DetectionSettingsRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('detection_endpoint', $rules);
        $this->assertArrayHasKey('jwks_url', $rules);
        $this->assertIsArray($rules['detection_endpoint']);
        $this->assertContains('nullable', $rules['detection_endpoint']);
        $this->assertContains('string', $rules['detection_endpoint']);
        $this->assertContains('max:500', $rules['detection_endpoint']);
    }

    public function test_network_settings_request_authorizes(): void
    {
        $request = new UpdateNetworkSettingsRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_network_settings_request_rules(): void
    {
        $request = new UpdateNetworkSettingsRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('managed_ranges_v4', $rules);
        $this->assertArrayHasKey('managed_ranges_v6', $rules);
        $this->assertArrayHasKey('dns_filter_default', $rules);
        $this->assertArrayHasKey('oui_auto_allow', $rules);
        $this->assertIsArray($rules['managed_ranges_v4']);
        $this->assertIsArray($rules['managed_ranges_v6']);
        $this->assertIsArray($rules['oui_auto_allow']);
    }

    public function test_network_settings_request_has_cidr_closure_rules(): void
    {
        $request = new UpdateNetworkSettingsRequest;
        $rules = $request->rules();

        $v4Closures = array_filter($rules['managed_ranges_v4'], fn ($rule) => $rule instanceof \Closure);
        $v6Closures = array_filter($rules['managed_ranges_v6'], fn ($rule) => $rule instanceof \Closure);
        $ouiClosures = array_filter($rules['oui_auto_allow'], fn ($rule) => $rule instanceof \Closure);

        $this->assertCount(1, $v4Closures);
        $this->assertCount(1, $v6Closures);
        $this->assertCount(1, $ouiClosures);
    }

    public function test_general_settings_request_authorizes(): void
    {
        $request = new UpdateGeneralSettingsRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_general_settings_request_rules(): void
    {
        $request = new UpdateGeneralSettingsRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('site_title', $rules);
        $this->assertArrayHasKey('terms_type', $rules);
        $this->assertArrayHasKey('terms_value', $rules);
        $this->assertArrayHasKey('privacy_type', $rules);
        $this->assertArrayHasKey('privacy_value', $rules);
        $this->assertArrayHasKey('theme_mode', $rules);
        $this->assertArrayHasKey('accent_hue', $rules);
        $this->assertArrayHasKey('accent_chroma', $rules);
        $this->assertArrayHasKey('accent_lightness', $rules);
        $this->assertArrayHasKey('custom_css', $rules);
    }

    public function test_general_settings_request_has_custom_css_closure_rule(): void
    {
        $request = new UpdateGeneralSettingsRequest;
        $rules = $request->rules();

        $this->assertIsArray($rules['custom_css']);
        $closures = array_filter($rules['custom_css'], fn ($rule) => $rule instanceof \Closure);
        $this->assertCount(1, $closures);
    }

    public function test_theme_settings_request_authorizes(): void
    {
        $request = new UpdateThemeSettingsRequest;
        $this->assertTrue($request->authorize());
    }

    public function test_theme_settings_request_rules(): void
    {
        $request = new UpdateThemeSettingsRequest;
        $rules = $request->rules();

        $this->assertArrayHasKey('theme_mode', $rules);
        $this->assertArrayHasKey('accent_hue', $rules);
        $this->assertArrayHasKey('accent_chroma', $rules);
        $this->assertArrayHasKey('accent_lightness', $rules);
        $this->assertArrayHasKey('custom_css', $rules);
    }

    public function test_theme_settings_request_has_custom_css_closure_rule(): void
    {
        $request = new UpdateThemeSettingsRequest;
        $rules = $request->rules();

        $this->assertIsArray($rules['custom_css']);
        $closures = array_filter($rules['custom_css'], fn ($rule) => $rule instanceof \Closure);
        $this->assertCount(1, $closures);
    }
}
