<?php

declare(strict_types=1);

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OutOfBoundsException;
use Throwable;

final class Fake
{
    /**
     * @param  array<string, string>  $headers
     */
    public static function response(int $status = 200, array $headers = [], string $body = ''): PromiseInterface
    {
        return Http::response($body, $status, $headers);
    }

    /**
     * @param  list<PromiseInterface|Throwable>  $queue
     */
    public static function sequence(array $queue): void
    {
        Http::fake(['*' => function () use (&$queue): PromiseInterface {
            $next = array_shift($queue);
            if ($next === null) {
                throw new OutOfBoundsException('No fake HTTP response queued');
            }
            if ($next instanceof Throwable) {
                throw $next;
            }

            return $next;
        }]);
    }

    /**
     * @return list<Request>
     */
    public static function requests(): array
    {
        return array_values(array_map(
            fn (array $pair): Request => $pair[0],
            Http::recorded()->all(),
        ));
    }
}
