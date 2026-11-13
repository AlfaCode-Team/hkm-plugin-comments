<?php

declare(strict_types=1);

namespace Plugins\Comments\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use Plugins\Comments\Support\Messages;

/**
 * Route filter `comments.throttle` — a fixed one-minute window per client IP,
 * kept in CachePort. Declared on the submit route in module.json.
 *
 * This is an HTTP-boundary short-circuit (429, before the service even
 * builds a DTO). `CommentService::submit()` ALSO enforces a per-IP limit
 * itself (see its `$cache`/`$ratePerMinute` constructor arguments), so a
 * caller that invokes the contract directly — bypassing HTTP entirely — is
 * still protected; that one throws a 422 ServiceException instead of
 * answering 429, since there is no HTTP response to shape at that layer.
 *
 * With no CachePort bound there is nothing to count in, and the stage passes
 * through — rate limiting then belongs to the edge (nginx `limit_req`) or to
 * whatever bound the service's own guard.
 *
 * Resolved once per worker by the FilterRegistry, so it holds NO request
 * state: the cache and clock are read from the request's container each time.
 */
final class CommentThrottleStage implements HttpStageContract
{
    public const ALIAS = 'comments.throttle';

    private const DEFAULT_PER_MINUTE = 5;

    public function handle(Request $request, callable $next): Response
    {
        $limit     = (int) (($request->attribute('filter_args')[self::ALIAS][0] ?? null) ?? self::DEFAULT_PER_MINUTE);
        $container = $request->container();

        if ($limit <= 0 || $container === null || !$container->has(CachePort::class)) {
            return $next($request);
        }

        /** @var CachePort $cache */
        $cache  = $container->make(CachePort::class);
        $now    = $container->has(ClockPort::class) ? $container->make(ClockPort::class)->timestamp() : time();
        $window = intdiv($now, 60);
        $key    = 'comments:http_rl:' . hash('sha256', (string) $request->ip()) . ':' . $window;

        try {
            if (!$cache->has($key)) {
                $cache->set($key, 0, 120);
            }
            $count = $cache->increment($key);
        } catch (\Throwable) {
            // A cache outage must not take comment submission down with it.
            return $next($request);
        }

        if ($count > $limit) {
            return Response::tooManyRequests(
                Messages::get('throttle.too_many', 'Too many comments. Please try again in a minute.'),
                retryAfter: 60 - ($now % 60),
            );
        }

        return $next($request);
    }
}
