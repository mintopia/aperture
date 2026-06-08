<?php

declare(strict_types=1);

namespace Tests\Unit\Services\NetworkSwitch;

use App\Services\NetworkSwitch\IosOutputParser;
use PHPUnit\Framework\TestCase;

class IosOutputParserDhcpTest extends TestCase
{
    private IosOutputParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new IosOutputParser;
    }

    public function test_parse_dhcp_binding_table(): void
    {
        $output = implode("\r\n", [
            'Bindings from all pools not associated with VRF:',
            'IP address          Client-ID/              Lease expiration        Type       State      Interface',
            '                    Hardware address/',
            '                    User name',
            '10.0.0.50           0100.1122.3344.55       Jun 08 2026 12:00 AM    Automatic  Active     Vlan100',
            '10.0.0.51           0100.aabb.ccdd.ee       Jun 08 2026 01:00 AM    Automatic  Active     Vlan100',
        ]);

        $result = $this->parser->parseDhcpBindingTable($output);

        $this->assertCount(2, $result);

        $this->assertSame('10.0.0.50', $result[0]['ip']);
        $this->assertSame('00:11:22:33:44:55', $result[0]['mac']);
        $this->assertSame('Jun 08 2026 12:00 AM', $result[0]['expires']);
        $this->assertSame('Automatic', $result[0]['type']);
        $this->assertSame('Active', $result[0]['state']);
        $this->assertSame('Vlan100', $result[0]['interface']);

        $this->assertSame('10.0.0.51', $result[1]['ip']);
        $this->assertSame('00:AA:BB:CC:DD:EE', $result[1]['mac']);
        $this->assertSame('Jun 08 2026 01:00 AM', $result[1]['expires']);
        $this->assertSame('Automatic', $result[1]['type']);
        $this->assertSame('Active', $result[1]['state']);
        $this->assertSame('Vlan100', $result[1]['interface']);
    }

    public function test_parse_dhcp_binding_with_raw_mac(): void
    {
        $output = implode("\n", [
            'Bindings from all pools not associated with VRF:',
            'IP address          Client-ID/              Lease expiration        Type       State      Interface',
            '                    Hardware address/',
            '                    User name',
            '10.0.0.52           aabb.ccdd.eeff          Jun 08 2026 02:00 AM    Automatic  Active     Vlan200',
        ]);

        $result = $this->parser->parseDhcpBindingTable($output);

        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.52', $result[0]['ip']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $result[0]['mac']);
        $this->assertSame('Vlan200', $result[0]['interface']);
    }

    public function test_parse_dhcp_binding_with_infinite_lease(): void
    {
        $output = implode("\r\n", [
            'Bindings from all pools not associated with VRF:',
            'IP address          Client-ID/              Lease expiration        Type       State      Interface',
            '                    Hardware address/',
            '                    User name',
            '10.0.0.52           aabb.ccdd.eeff          Infinite                Manual     Active     Vlan200',
        ]);

        $result = $this->parser->parseDhcpBindingTable($output);

        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.52', $result[0]['ip']);
        $this->assertSame('Infinite', $result[0]['expires']);
        $this->assertSame('Manual', $result[0]['type']);
        $this->assertSame('Active', $result[0]['state']);
    }

    public function test_parse_dhcp_binding_table_rejects_error_output(): void
    {
        $output = "% Invalid input detected at '^' marker.";
        $result = $this->parser->parseDhcpBindingTable($output);
        $this->assertSame([], $result);
    }

    public function test_parse_dhcp_binding_table_rejects_incomplete_command_error(): void
    {
        $output = '% Incomplete command.';
        $result = $this->parser->parseDhcpBindingTable($output);
        $this->assertSame([], $result);
    }

    public function test_parse_dhcp_binding_table_rejects_ambiguous_command_error(): void
    {
        $output = '% Ambiguous command:  "show ip dhcp"';
        $result = $this->parser->parseDhcpBindingTable($output);
        $this->assertSame([], $result);
    }

    public function test_parse_dhcp_binding_table_empty_output(): void
    {
        $result = $this->parser->parseDhcpBindingTable('');
        $this->assertSame([], $result);
    }

    public function test_parse_dhcp_binding_table_only_header(): void
    {
        $output = implode("\r\n", [
            'Bindings from all pools not associated with VRF:',
            'IP address          Client-ID/              Lease expiration        Type       State      Interface',
            '                    Hardware address/',
            '                    User name',
        ]);

        $result = $this->parser->parseDhcpBindingTable($output);
        $this->assertSame([], $result);
    }

    public function test_parse_dhcp_binding_table_multiple_entries(): void
    {
        $output = implode("\r\n", [
            'Bindings from all pools not associated with VRF:',
            'IP address          Client-ID/              Lease expiration        Type       State      Interface',
            '                    Hardware address/',
            '                    User name',
            '10.0.0.50           0100.1122.3344.55       Jun 08 2026 12:00 AM    Automatic  Active     Vlan100',
            '10.0.0.51           0100.aabb.ccdd.ee       Jun 08 2026 01:00 AM    Automatic  Active     Vlan100',
            '10.0.0.52           aabb.ccdd.eeff          Infinite                Manual     Active     Vlan200',
        ]);

        $result = $this->parser->parseDhcpBindingTable($output);

        $this->assertCount(3, $result);

        $this->assertSame('10.0.0.50', $result[0]['ip']);
        $this->assertSame('00:11:22:33:44:55', $result[0]['mac']);

        $this->assertSame('10.0.0.51', $result[1]['ip']);
        $this->assertSame('00:AA:BB:CC:DD:EE', $result[1]['mac']);

        $this->assertSame('10.0.0.52', $result[2]['ip']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $result[2]['mac']);
        $this->assertSame('Infinite', $result[2]['expires']);
        $this->assertSame('Manual', $result[2]['type']);
    }

    public function test_extract_mac_from_client_id_with_hardware_type(): void
    {
        // 0100.1122.3344.55 → 00:11:22:33:44:55
        $result = $this->parser->extractMacFromClientId('0100.1122.3344.55');
        $this->assertSame('00:11:22:33:44:55', $result);
    }

    public function test_extract_mac_from_client_id_with_hardware_type_lowercase(): void
    {
        // 0100.aabb.ccdd.ee → strip 01 prefix → 00:AA:BB:CC:DD:EE
        $result = $this->parser->extractMacFromClientId('0100.aabb.ccdd.ee');
        $this->assertSame('00:AA:BB:CC:DD:EE', $result);
    }

    public function test_extract_mac_from_raw_dotted_mac(): void
    {
        // aabb.ccdd.eeff → AA:BB:CC:DD:EE:FF
        $result = $this->parser->extractMacFromClientId('aabb.ccdd.eeff');
        $this->assertSame('AA:BB:CC:DD:EE:FF', $result);
    }

    public function test_extract_mac_from_raw_dotted_mac_with_numeric(): void
    {
        // 0011.2233.4455 → 00:11:22:33:44:55
        $result = $this->parser->extractMacFromClientId('0011.2233.4455');
        $this->assertSame('00:11:22:33:44:55', $result);
    }

    public function test_extract_mac_returns_null_for_unrecognized(): void
    {
        $result = $this->parser->extractMacFromClientId('not-a-mac-address');
        $this->assertNull($result);
    }

    public function test_extract_mac_returns_null_for_empty_string(): void
    {
        $result = $this->parser->extractMacFromClientId('');
        $this->assertNull($result);
    }

    public function test_is_error_output_detects_invalid_input(): void
    {
        $this->assertTrue($this->parser->isErrorOutput("% Invalid input detected at '^' marker."));
    }

    public function test_is_error_output_detects_incomplete_command(): void
    {
        $this->assertTrue($this->parser->isErrorOutput('% Incomplete command.'));
    }

    public function test_is_error_output_detects_ambiguous_command(): void
    {
        $this->assertTrue($this->parser->isErrorOutput('% Ambiguous command:  "show ip dhcp"'));
    }

    public function test_is_error_output_returns_false_for_normal_output(): void
    {
        $output = implode("\n", [
            'Bindings from all pools not associated with VRF:',
            'IP address          Client-ID/',
        ]);
        $this->assertFalse($this->parser->isErrorOutput($output));
    }

    public function test_is_error_output_returns_false_for_empty_string(): void
    {
        $this->assertFalse($this->parser->isErrorOutput(''));
    }
}
