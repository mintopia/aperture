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

    // -------------------------------------------------------------------------
    // parseDhcpPoolStats
    // -------------------------------------------------------------------------

    public function test_parse_dhcp_pool_stats_single_pool(): void
    {
        $output = implode("\n", [
            'Pool LAN :',
            ' Utilization mark (high/low)    : 100 / 0',
            ' Subnet size (first/next)       : 0 / 0',
            ' Total addresses                : 254',
            ' Leased addresses               : 50',
            ' Pending event                  : none',
            ' 1 subnet is currently in the pool :',
            ' Current index        IP address range                    Leased addresses',
            ' 10.0.0.1             10.0.0.1     - 10.0.0.254           50',
        ]);

        $result = $this->parser->parseDhcpPoolStats($output);

        $this->assertCount(1, $result);
        $this->assertSame('LAN', $result[0]['name']);
        $this->assertSame('254', $result[0]['total']);
        $this->assertSame('50', $result[0]['leased']);
    }

    public function test_parse_dhcp_pool_stats_multiple_pools(): void
    {
        $output = implode("\n", [
            'Pool LAN :',
            ' Utilization mark (high/low)    : 100 / 0',
            ' Subnet size (first/next)       : 0 / 0',
            ' Total addresses                : 254',
            ' Leased addresses               : 50',
            ' Pending event                  : none',
            ' 1 subnet is currently in the pool :',
            ' Current index        IP address range                    Leased addresses',
            ' 10.0.0.1             10.0.0.1     - 10.0.0.254           50',
            '',
            'Pool GUESTS :',
            ' Utilization mark (high/low)    : 100 / 0',
            ' Subnet size (first/next)       : 0 / 0',
            ' Total addresses                : 126',
            ' Leased addresses               : 30',
            ' Pending event                  : none',
            ' 1 subnet is currently in the pool :',
            ' Current index        IP address range                    Leased addresses',
            ' 10.1.0.1             10.1.0.1     - 10.1.0.126           30',
        ]);

        $result = $this->parser->parseDhcpPoolStats($output);

        $this->assertCount(2, $result);
        $this->assertSame('LAN', $result[0]['name']);
        $this->assertSame('254', $result[0]['total']);
        $this->assertSame('50', $result[0]['leased']);
        $this->assertSame('GUESTS', $result[1]['name']);
        $this->assertSame('126', $result[1]['total']);
        $this->assertSame('30', $result[1]['leased']);
    }

    public function test_parse_dhcp_pool_stats_empty_output(): void
    {
        $result = $this->parser->parseDhcpPoolStats('');
        $this->assertSame([], $result);
    }

    public function test_parse_dhcp_pool_stats_error_output(): void
    {
        $output = "% Invalid input detected at '^' marker.";
        $result = $this->parser->parseDhcpPoolStats($output);
        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // parseDhcpPoolConfig
    // -------------------------------------------------------------------------

    public function test_parse_dhcp_pool_config_with_range_and_single_exclusions(): void
    {
        $output = implode("\n", [
            'ip dhcp excluded-address 10.0.0.1 10.0.0.9',
            'ip dhcp excluded-address 10.0.0.250 10.0.0.254',
            'ip dhcp excluded-address 10.1.0.1',
            '!',
            'ip dhcp pool LAN',
            ' network 10.0.0.0 255.255.255.0',
            ' default-router 10.0.0.1',
            ' dns-server 8.8.8.8',
            '!',
            'ip dhcp pool GUESTS',
            ' network 10.1.0.0 255.255.128.0',
            ' default-router 10.1.0.1',
        ]);

        $result = $this->parser->parseDhcpPoolConfig($output);

        $this->assertCount(2, $result['pools']);
        $this->assertSame('LAN', $result['pools'][0]['name']);
        $this->assertSame('10.0.0.0', $result['pools'][0]['network']);
        $this->assertSame('255.255.255.0', $result['pools'][0]['mask']);
        $this->assertSame('10.0.0.1', $result['pools'][0]['gateway']);
        $this->assertSame('GUESTS', $result['pools'][1]['name']);
        $this->assertSame('10.1.0.0', $result['pools'][1]['network']);
        $this->assertSame('255.255.128.0', $result['pools'][1]['mask']);
        $this->assertSame('10.1.0.1', $result['pools'][1]['gateway']);

        $this->assertCount(3, $result['excluded']);
        $this->assertSame('10.0.0.1', $result['excluded'][0]['start']);
        $this->assertSame('10.0.0.9', $result['excluded'][0]['end']);
        $this->assertSame('10.0.0.250', $result['excluded'][1]['start']);
        $this->assertSame('10.0.0.254', $result['excluded'][1]['end']);
        $this->assertSame('10.1.0.1', $result['excluded'][2]['start']);
        $this->assertSame('10.1.0.1', $result['excluded'][2]['end']);
    }

    public function test_parse_dhcp_pool_config_no_exclusions(): void
    {
        $output = implode("\n", [
            'ip dhcp pool LAN',
            ' network 10.0.0.0 255.255.255.0',
            ' default-router 10.0.0.1',
        ]);

        $result = $this->parser->parseDhcpPoolConfig($output);

        $this->assertCount(1, $result['pools']);
        $this->assertSame([], $result['excluded']);
    }

    public function test_parse_dhcp_pool_config_error_output(): void
    {
        $output = "% Invalid input detected at '^' marker.";
        $result = $this->parser->parseDhcpPoolConfig($output);
        $this->assertSame(['pools' => [], 'excluded' => []], $result);
    }

    public function test_parse_dhcp_pool_config_single_address_exclusion_has_same_start_and_end(): void
    {
        $output = implode("\n", [
            'ip dhcp excluded-address 192.168.1.1',
            'ip dhcp pool TEST',
            ' network 192.168.1.0 255.255.255.0',
            ' default-router 192.168.1.1',
        ]);

        $result = $this->parser->parseDhcpPoolConfig($output);

        $this->assertCount(1, $result['excluded']);
        $this->assertSame('192.168.1.1', $result['excluded'][0]['start']);
        $this->assertSame('192.168.1.1', $result['excluded'][0]['end']);
    }

    // -------------------------------------------------------------------------
    // computeEffectiveRanges
    // -------------------------------------------------------------------------

    public function test_compute_effective_ranges_exclusions_at_start_and_end(): void
    {
        $config = [
            'pools' => [
                ['name' => 'LAN', 'network' => '10.0.0.0', 'mask' => '255.255.255.0', 'gateway' => '10.0.0.1'],
            ],
            'excluded' => [
                ['start' => '10.0.0.1', 'end' => '10.0.0.9'],
                ['start' => '10.0.0.250', 'end' => '10.0.0.254'],
            ],
        ];

        $result = $this->parser->computeEffectiveRanges($config);

        $this->assertCount(1, $result);
        $this->assertSame('LAN', $result[0]['name']);
        $this->assertSame('10.0.0.0/24', $result[0]['subnet']);
        $this->assertSame('10.0.0.10', $result[0]['range_from']);
        $this->assertSame('10.0.0.249', $result[0]['range_to']);
        $this->assertSame('240', $result[0]['total_addresses']);
        $this->assertSame('10.0.0.1', $result[0]['gateway']);
    }

    public function test_compute_effective_ranges_no_exclusions(): void
    {
        $config = [
            'pools' => [
                ['name' => 'LAN', 'network' => '10.0.0.0', 'mask' => '255.255.255.0', 'gateway' => '10.0.0.1'],
            ],
            'excluded' => [],
        ];

        $result = $this->parser->computeEffectiveRanges($config);

        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.1', $result[0]['range_from']);
        $this->assertSame('10.0.0.254', $result[0]['range_to']);
        $this->assertSame('254', $result[0]['total_addresses']);
    }

    public function test_compute_effective_ranges_single_address_exclusion(): void
    {
        $config = [
            'pools' => [
                ['name' => 'LAN', 'network' => '10.0.0.0', 'mask' => '255.255.255.0', 'gateway' => '10.0.0.1'],
            ],
            'excluded' => [
                ['start' => '10.0.0.1', 'end' => '10.0.0.1'],
            ],
        ];

        $result = $this->parser->computeEffectiveRanges($config);

        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.2', $result[0]['range_from']);
        $this->assertSame('10.0.0.254', $result[0]['range_to']);
        $this->assertSame('253', $result[0]['total_addresses']);
    }

    public function test_compute_effective_ranges_exclusion_splits_pool(): void
    {
        $config = [
            'pools' => [
                ['name' => 'LAN', 'network' => '10.0.0.0', 'mask' => '255.255.255.0', 'gateway' => '10.0.0.1'],
            ],
            'excluded' => [
                ['start' => '10.0.0.100', 'end' => '10.0.0.149'],
            ],
        ];

        $result = $this->parser->computeEffectiveRanges($config);

        // Exclusion in the middle splits into two ranges; method returns the first (lowest) contiguous range
        $this->assertGreaterThanOrEqual(1, count($result));
        // First range: 10.0.0.1 - 10.0.0.99
        $this->assertSame('10.0.0.1', $result[0]['range_from']);
        $this->assertSame('10.0.0.99', $result[0]['range_to']);
        $this->assertSame('99', $result[0]['total_addresses']);
        // Second range: 10.0.0.150 - 10.0.0.254
        $this->assertSame('10.0.0.150', $result[1]['range_from']);
        $this->assertSame('10.0.0.254', $result[1]['range_to']);
        $this->assertSame('105', $result[1]['total_addresses']);
    }

    public function test_compute_effective_ranges_out_of_range_exclusions_ignored(): void
    {
        $config = [
            'pools' => [
                ['name' => 'LAN', 'network' => '10.0.0.0', 'mask' => '255.255.255.0', 'gateway' => '10.0.0.1'],
            ],
            'excluded' => [
                ['start' => '192.168.1.1', 'end' => '192.168.1.10'],
            ],
        ];

        $result = $this->parser->computeEffectiveRanges($config);

        $this->assertCount(1, $result);
        $this->assertSame('10.0.0.1', $result[0]['range_from']);
        $this->assertSame('10.0.0.254', $result[0]['range_to']);
        $this->assertSame('254', $result[0]['total_addresses']);
    }

    public function test_compute_effective_ranges_multiple_pools(): void
    {
        $config = [
            'pools' => [
                ['name' => 'LAN', 'network' => '10.0.0.0', 'mask' => '255.255.255.0', 'gateway' => '10.0.0.1'],
                ['name' => 'GUESTS', 'network' => '10.1.0.0', 'mask' => '255.255.128.0', 'gateway' => '10.1.0.1'],
            ],
            'excluded' => [
                ['start' => '10.0.0.1', 'end' => '10.0.0.9'],
                ['start' => '10.1.0.1', 'end' => '10.1.0.1'],
            ],
        ];

        $result = $this->parser->computeEffectiveRanges($config);

        $this->assertCount(2, $result);
        $this->assertSame('LAN', $result[0]['name']);
        $this->assertSame('10.0.0.10', $result[0]['range_from']);
        $this->assertSame('10.0.0.254', $result[0]['range_to']);
        $this->assertSame('245', $result[0]['total_addresses']);

        $this->assertSame('GUESTS', $result[1]['name']);
        $this->assertSame('10.1.0.2', $result[1]['range_from']);
        $this->assertSame('10.1.127.254', $result[1]['range_to']);
    }

    // -------------------------------------------------------------------------
    // parseDhcpv6BindingTable
    // -------------------------------------------------------------------------

    public function test_parse_dhcpv6_binding_table_with_duid_ll(): void
    {
        $output = implode("\n", [
            'Client: FE80::1',
            '  DUID: 00030001AABBCCDDEEFF',
            '  Username : unassigned',
            '  VRF : default',
            '  IA NA: IA ID 0x00000001, T1 43200, T2 69120',
            '    Address: 2001:DB8::100',
            '            preferred lifetime 86400, valid lifetime 172800',
            '            expires at Jun 09 2026 12:00 AM (172800 seconds)',
        ]);

        $result = $this->parser->parseDhcpv6BindingTable($output);

        $this->assertCount(1, $result);
        $this->assertSame('2001:DB8::100', $result[0]['ip']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $result[0]['mac']);
        $this->assertSame('00030001AABBCCDDEEFF', $result[0]['duid']);
        $this->assertSame('0x00000001', $result[0]['iaid']);
        $this->assertSame('Jun 09 2026 12:00 AM', $result[0]['expires']);
    }

    public function test_parse_dhcpv6_binding_table_with_duid_llt(): void
    {
        // DUID-LLT format: 0001 (type) + 0001 (hw-type) + AABBCCDD (time, 4B) + EEFF0011AABB (MAC, 6B)
        $output = implode("\n", [
            'Client: FE80::2',
            '  DUID: 00010001AABBCCDDEEFF0011AABB',
            '  Username : unassigned',
            '  VRF : default',
            '  IA NA: IA ID 0x00000002, T1 43200, T2 69120',
            '    Address: 2001:DB8::101',
            '            preferred lifetime 86400, valid lifetime 172800',
            '            expires at Jun 09 2026 01:00 AM (172800 seconds)',
        ]);

        $result = $this->parser->parseDhcpv6BindingTable($output);

        $this->assertCount(1, $result);
        $this->assertSame('2001:DB8::101', $result[0]['ip']);
        $this->assertSame('EE:FF:00:11:AA:BB', $result[0]['mac']);
    }

    public function test_parse_dhcpv6_binding_table_duid_en_yields_null_mac(): void
    {
        $output = implode("\n", [
            'Client: FE80::3',
            '  DUID: 00020000000AABBCCDD',
            '  Username : unassigned',
            '  VRF : default',
            '  IA NA: IA ID 0x00000003, T1 43200, T2 69120',
            '    Address: 2001:DB8::102',
            '            preferred lifetime 86400, valid lifetime 172800',
            '            expires at Jun 09 2026 02:00 AM (172800 seconds)',
        ]);

        $result = $this->parser->parseDhcpv6BindingTable($output);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['mac']);
    }

    public function test_parse_dhcpv6_binding_table_duid_uuid_yields_null_mac(): void
    {
        $output = implode("\n", [
            'Client: FE80::4',
            '  DUID: 000400000000000000000000000000000001',
            '  Username : unassigned',
            '  VRF : default',
            '  IA NA: IA ID 0x00000004, T1 43200, T2 69120',
            '    Address: 2001:DB8::103',
            '            preferred lifetime 86400, valid lifetime 172800',
            '            expires at Jun 09 2026 03:00 AM (172800 seconds)',
        ]);

        $result = $this->parser->parseDhcpv6BindingTable($output);

        $this->assertCount(1, $result);
        $this->assertNull($result[0]['mac']);
    }

    public function test_parse_dhcpv6_binding_table_multiple_clients(): void
    {
        $output = implode("\n", [
            'Client: FE80::1',
            '  DUID: 00030001AABBCCDDEEFF',
            '  Username : unassigned',
            '  VRF : default',
            '  IA NA: IA ID 0x00000001, T1 43200, T2 69120',
            '    Address: 2001:DB8::100',
            '            preferred lifetime 86400, valid lifetime 172800',
            '            expires at Jun 09 2026 12:00 AM (172800 seconds)',
            '',
            'Client: FE80::2',
            '  DUID: 00030001FFEEDDCCBBAA',
            '  Username : unassigned',
            '  VRF : default',
            '  IA NA: IA ID 0x00000002, T1 43200, T2 69120',
            '    Address: 2001:DB8::101',
            '            preferred lifetime 86400, valid lifetime 172800',
            '            expires at Jun 09 2026 01:00 AM (172800 seconds)',
        ]);

        $result = $this->parser->parseDhcpv6BindingTable($output);

        $this->assertCount(2, $result);
        $this->assertSame('2001:DB8::100', $result[0]['ip']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $result[0]['mac']);
        $this->assertSame('2001:DB8::101', $result[1]['ip']);
        $this->assertSame('FF:EE:DD:CC:BB:AA', $result[1]['mac']);
    }

    public function test_parse_dhcpv6_binding_table_skips_ia_pd(): void
    {
        $output = implode("\n", [
            'Client: FE80::1',
            '  DUID: 00030001AABBCCDDEEFF',
            '  Username : unassigned',
            '  VRF : default',
            '  IA PD: IA ID 0x00000001, T1 43200, T2 69120',
            '    Prefix: 2001:DB8::/48',
            '            preferred lifetime 86400, valid lifetime 172800',
            '            expires at Jun 09 2026 12:00 AM (172800 seconds)',
            '  IA NA: IA ID 0x00000002, T1 43200, T2 69120',
            '    Address: 2001:DB8::100',
            '            preferred lifetime 86400, valid lifetime 172800',
            '            expires at Jun 09 2026 12:00 AM (172800 seconds)',
        ]);

        $result = $this->parser->parseDhcpv6BindingTable($output);

        $this->assertCount(1, $result);
        $this->assertSame('2001:DB8::100', $result[0]['ip']);
    }

    public function test_parse_dhcpv6_binding_table_empty_output(): void
    {
        $result = $this->parser->parseDhcpv6BindingTable('');

        $this->assertSame([], $result);
    }

    public function test_parse_dhcpv6_binding_table_error_output(): void
    {
        $result = $this->parser->parseDhcpv6BindingTable('% Invalid input detected');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // parseDhcpv6PoolStats
    // -------------------------------------------------------------------------

    public function test_parse_dhcpv6_pool_stats_single_pool(): void
    {
        $output = implode("\n", [
            'DHCPv6 pool: LAN6',
            '  Address allocation prefix: 2001:DB8::/64',
            '  DNS server: 2001:4860:4860::8888',
            '  Active clients: 15',
        ]);

        $result = $this->parser->parseDhcpv6PoolStats($output);

        $this->assertCount(1, $result);
        $this->assertSame('LAN6', $result[0]['name']);
        $this->assertSame('2001:DB8::/64', $result[0]['prefix']);
        $this->assertSame('15', $result[0]['active_clients']);
    }

    public function test_parse_dhcpv6_pool_stats_multiple_pools(): void
    {
        $output = implode("\n", [
            'DHCPv6 pool: LAN6',
            '  Address allocation prefix: 2001:DB8::/64',
            '  DNS server: 2001:4860:4860::8888',
            '  Active clients: 15',
            'DHCPv6 pool: GUESTS6',
            '  Address allocation prefix: 2001:DB8:1::/64',
            '  DNS server: 2001:4860:4860::8844',
            '  Active clients: 3',
        ]);

        $result = $this->parser->parseDhcpv6PoolStats($output);

        $this->assertCount(2, $result);
        $this->assertSame('LAN6', $result[0]['name']);
        $this->assertSame('2001:DB8::/64', $result[0]['prefix']);
        $this->assertSame('15', $result[0]['active_clients']);
        $this->assertSame('GUESTS6', $result[1]['name']);
        $this->assertSame('2001:DB8:1::/64', $result[1]['prefix']);
        $this->assertSame('3', $result[1]['active_clients']);
    }

    public function test_parse_dhcpv6_pool_stats_empty_output(): void
    {
        $result = $this->parser->parseDhcpv6PoolStats('');

        $this->assertSame([], $result);
    }

    public function test_parse_dhcpv6_pool_stats_error_output(): void
    {
        $result = $this->parser->parseDhcpv6PoolStats('% Invalid input detected');

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // parseDhcpv6PoolConfig
    // -------------------------------------------------------------------------

    public function test_parse_dhcpv6_pool_config_single_pool(): void
    {
        $output = implode("\n", [
            'ipv6 dhcp pool LAN6',
            ' address prefix 2001:DB8::/64',
            ' dns-server 2001:4860:4860::8888',
        ]);

        $result = $this->parser->parseDhcpv6PoolConfig($output);

        $this->assertArrayHasKey('pools', $result);
        $this->assertCount(1, $result['pools']);
        $this->assertSame('LAN6', $result['pools'][0]['name']);
        $this->assertSame('2001:DB8::/64', $result['pools'][0]['prefix']);
    }

    public function test_parse_dhcpv6_pool_config_multiple_pools(): void
    {
        $output = implode("\n", [
            'ipv6 dhcp pool LAN6',
            ' address prefix 2001:DB8::/64',
            ' dns-server 2001:4860:4860::8888',
            '!',
            'ipv6 dhcp pool GUESTS6',
            ' address prefix 2001:DB8:1::/64',
            ' dns-server 2001:4860:4860::8844',
        ]);

        $result = $this->parser->parseDhcpv6PoolConfig($output);

        $this->assertCount(2, $result['pools']);
        $this->assertSame('LAN6', $result['pools'][0]['name']);
        $this->assertSame('2001:DB8::/64', $result['pools'][0]['prefix']);
        $this->assertSame('GUESTS6', $result['pools'][1]['name']);
        $this->assertSame('2001:DB8:1::/64', $result['pools'][1]['prefix']);
    }

    public function test_parse_dhcpv6_pool_config_empty_output(): void
    {
        $result = $this->parser->parseDhcpv6PoolConfig('');

        $this->assertSame(['pools' => []], $result);
    }

    public function test_parse_dhcpv6_pool_config_error_output(): void
    {
        $result = $this->parser->parseDhcpv6PoolConfig('% Invalid input detected');

        $this->assertSame(['pools' => []], $result);
    }

    // -------------------------------------------------------------------------
    // parseDhcpSnoopingTable
    // -------------------------------------------------------------------------

    public function test_parse_dhcp_snooping_table_multiple_entries(): void
    {
        $output = implode("\n", [
            'MacAddress          IpAddress        Lease(sec)  Type           VLAN  Interface',
            '-----------------   ---------------  ----------  -------------  ----  --------------------',
            '00:11:22:33:44:55   10.0.0.50        86400       dhcp-snooping   100   GigabitEthernet1/0/1',
            'AA:BB:CC:DD:EE:FF   10.0.0.51        86400       dhcp-snooping   100   GigabitEthernet1/0/2',
            'Total number of bindings: 2',
        ]);

        $result = $this->parser->parseDhcpSnoopingTable($output);

        $this->assertCount(2, $result);

        $this->assertSame('10.0.0.50', $result[0]['ip']);
        $this->assertSame('00:11:22:33:44:55', $result[0]['mac']);
        $this->assertSame(100, $result[0]['vlan']);
        $this->assertSame('GigabitEthernet1/0/1', $result[0]['interface']);
        $this->assertSame(86400, $result[0]['lease_seconds']);

        $this->assertSame('10.0.0.51', $result[1]['ip']);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $result[1]['mac']);
        $this->assertSame(100, $result[1]['vlan']);
        $this->assertSame('GigabitEthernet1/0/2', $result[1]['interface']);
    }

    public function test_parse_dhcp_snooping_table_normalises_mac_to_uppercase(): void
    {
        $output = implode("\n", [
            'MacAddress          IpAddress        Lease(sec)  Type           VLAN  Interface',
            '-----------------   ---------------  ----------  -------------  ----  --------------------',
            'aa:bb:cc:dd:ee:ff   10.0.0.50        3600        dhcp-snooping   200   Gi1/0/5',
        ]);

        $result = $this->parser->parseDhcpSnoopingTable($output);

        $this->assertCount(1, $result);
        $this->assertSame('AA:BB:CC:DD:EE:FF', $result[0]['mac']);
    }

    public function test_parse_dhcp_snooping_table_vlan_is_integer(): void
    {
        $output = implode("\n", [
            'MacAddress          IpAddress        Lease(sec)  Type           VLAN  Interface',
            '-----------------   ---------------  ----------  -------------  ----  --------------------',
            '00:11:22:33:44:55   10.0.0.50        86400       dhcp-snooping   42   GigabitEthernet1/0/1',
        ]);

        $result = $this->parser->parseDhcpSnoopingTable($output);

        $this->assertCount(1, $result);
        $this->assertSame(42, $result[0]['vlan']);
        $this->assertIsInt($result[0]['vlan']);
    }

    public function test_parse_dhcp_snooping_table_empty_output(): void
    {
        $result = $this->parser->parseDhcpSnoopingTable('');

        $this->assertSame([], $result);
    }

    public function test_parse_dhcp_snooping_table_error_output(): void
    {
        $result = $this->parser->parseDhcpSnoopingTable('% Invalid input detected');

        $this->assertSame([], $result);
    }

    public function test_parse_dhcp_snooping_table_only_header_returns_empty(): void
    {
        $output = implode("\n", [
            'MacAddress          IpAddress        Lease(sec)  Type           VLAN  Interface',
            '-----------------   ---------------  ----------  -------------  ----  --------------------',
            'Total number of bindings: 0',
        ]);

        $result = $this->parser->parseDhcpSnoopingTable($output);

        $this->assertSame([], $result);
    }
}
