<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Events\BandwidthAnomalyDetected;
use App\Models\IpAddress;
use App\Services\BandwidthAnomalyDetector;
use App\Services\Interfaces\IpBandwidthInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class DetectBandwidthAnomaliesCommand extends Command
{
    protected $signature = 'aperture:detect-bandwidth-anomalies';

    protected $description = 'Detect bandwidth anomalies by comparing short-term averages against long-term averages';

    public function handle(IpBandwidthInterface $ipBandwidth): int
    {
        $threshold = (float) config('aperture.bandwidth_anomaly.threshold', 3.0);
        $detector = new BandwidthAnomalyDetector(threshold: $threshold);

        try {
            $topTalkers = $ipBandwidth->getTopTalkers(limit: 50, range: '5m');
        } catch (Throwable $throwable) {
            Log::warning('Failed to fetch top talkers for bandwidth anomaly detection', [
                'error' => $throwable->getMessage(),
            ]);

            return self::SUCCESS;
        }

        foreach ($topTalkers as $talker) {
            try {
                $shortTerm = $ipBandwidth->getIpBandwidth($talker->ip, '5m');
                $longTerm = $ipBandwidth->getIpBandwidth($talker->ip, '1h');

                $shortTermAvg = (float) ($shortTerm->received + $shortTerm->sent);
                $longTermAvg = (float) ($longTerm->received + $longTerm->sent);

                if ($detector->isAnomaly($shortTermAvg, $longTermAvg)) {
                    $ratio = $detector->calculateRatio($shortTermAvg, $longTermAvg);

                    $userInfo = $this->resolveUser($talker->ip);

                    BandwidthAnomalyDetected::dispatch(
                        ipAddress: $talker->ip,
                        userName: $userInfo['name'],
                        userId: $userInfo['id'],
                        shortTermAvg: $shortTermAvg,
                        longTermAvg: $longTermAvg,
                        ratio: round($ratio, 2),
                        threshold: $threshold,
                    );

                    Log::info('Bandwidth anomaly detected', [
                        'ip' => $talker->ip,
                        'user' => $userInfo['name'],
                        'short_term_avg' => $shortTermAvg,
                        'long_term_avg' => $longTermAvg,
                        'ratio' => round($ratio, 2),
                        'threshold' => $threshold,
                    ]);
                }
            } catch (Throwable $e) {
                Log::warning('Failed to check bandwidth anomaly for IP', [
                    'ip' => $talker->ip,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Resolve the user associated with an IP address.
     *
     * @return array{name: ?string, id: ?int}
     */
    private function resolveUser(string $ip): array
    {
        $ipModel = IpAddress::where('address', $ip)->first();

        if ($ipModel === null) {
            return ['name' => null, 'id' => null];
        }

        $userIp = $ipModel->users()->with('user')->first();

        if ($userIp === null) {
            return ['name' => null, 'id' => null];
        }

        return [
            'name' => $userIp->user->nickname,
            'id' => $userIp->user->id,
        ];
    }
}
