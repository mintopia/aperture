<?php

declare(strict_types=1);

namespace Tests\Unit\Services\ValueObjects;

use App\Services\ValueObjects\ActiveSession;
use App\Services\ValueObjects\TopTalker;
use App\Services\ValueObjects\UserBandwidth;
use Tests\TestCase;

class TrafficValueObjectsTest extends TestCase
{
    public function test_active_session_construction(): void
    {
        $session = new ActiveSession(ip: '10.0.0.1', user: 'testuser');

        $this->assertSame('10.0.0.1', $session->ip);
        $this->assertSame('testuser', $session->user);
    }

    public function test_top_talker_construction(): void
    {
        $talker = new TopTalker(ip: '10.0.0.1', received: 1024, sent: 512, nickname: 'Player1');

        $this->assertSame('10.0.0.1', $talker->ip);
        $this->assertSame(1024, $talker->received);
        $this->assertSame(512, $talker->sent);
        $this->assertSame('Player1', $talker->nickname);
    }

    public function test_top_talker_nickname_is_nullable(): void
    {
        $talker = new TopTalker(ip: '10.0.0.1', received: 0, sent: 0);

        $this->assertNull($talker->nickname);
    }

    public function test_user_bandwidth_construction(): void
    {
        $bandwidth = new UserBandwidth(
            received: 1024,
            sent: 512,
            timestamps: ['2026-01-01 00:00:00'],
            download: [100],
            upload: [50],
        );

        $this->assertSame(1024, $bandwidth->received);
        $this->assertSame(512, $bandwidth->sent);
        $this->assertSame(['2026-01-01 00:00:00'], $bandwidth->timestamps);
        $this->assertSame([100], $bandwidth->download);
        $this->assertSame([50], $bandwidth->upload);
    }
}
