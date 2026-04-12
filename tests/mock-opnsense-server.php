<?php

// Simple mock OpnSense server for tests
// Returns valid JSON for all requests
header('Content-Type: application/json');

$uri = $_SERVER['REQUEST_URI'] ?? '';

if (str_contains($uri, 'session/list')) {
    echo json_encode([]);
} elseif (str_contains($uri, 'session/disconnect')) {
    echo json_encode(['status' => 'ok']);
} elseif (str_contains($uri, 'captiveportal/access/logon')) {
    echo json_encode(['status' => 'ok']);
} elseif (str_contains($uri, 'trafficshaper/settings/getRule')) {
    echo json_encode([
        'rule' => [
            'description' => 'test',
            'destination_not' => '0',
            'direction' => new stdClass,
            'dscp' => new stdClass,
            'dst_port' => '',
            'enabled' => '1',
            'interface' => new stdClass,
            'interface2' => new stdClass,
            'iplen' => '',
            'proto' => new stdClass,
            'sequence' => '1',
            'source_not' => '0',
            'src_port' => '',
            'target' => new stdClass,
            'destination' => new stdClass,
            'source' => new stdClass,
        ],
    ]);
} elseif (str_contains($uri, 'trafficshaper/settings/setRule')) {
    echo json_encode(['result' => 'saved']);
} elseif (str_contains($uri, 'trafficshaper/service/reconfigure')) {
    echo json_encode(['status' => 'ok']);
} else {
    echo json_encode(['status' => 'ok']);
}
