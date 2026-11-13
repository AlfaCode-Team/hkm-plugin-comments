<?php

declare(strict_types=1);

namespace Plugins\Comments;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\DomainEventCollector;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SystemClock;
use Plugins\Comments\API\Contracts\CommentServiceContract;
use Plugins\Comments\Application\Services\CommentService;
use Plugins\Comments\Application\Spam\FormToken;
use Plugins\Comments\Infrastructure\Http\Stages\CommentModerateStage;
use Plugins\Comments\Infrastructure\Http\Stages\CommentThrottleStage;
use Plugins\Comments\Infrastructure\Persistence\CommentRepository;
use Plugins\Posts\API\Contracts\PostServiceContract;

/**
 * Provider — wires the 'comment.discussion' domain: reader comments on a
 * `Plugins\Posts` post.
 *
 * Unlike Posts (no routes at all), this plugin DOES declare its own routes
 * and its own two route-filter aliases (`comments.throttle`, `comments.moderate`)
 * — see module.json — so a host project needs no extra wiring beyond
 * `requires: ["comment.discussion"]` wherever it wants to link to a thread.
 *
 * Rows live on the request's `DatabasePort` — the tenant database under
 * Tenancy, the central one otherwise. The repository AND the transaction
 * manager are built over that one connection, same convention as the
 * sibling Posts plugin's Provider.
 */
final class Provider implements ModuleContract
{
    public const string DOMAIN = 'comment.discussion';

    public function solves(): string
    {
        return self::DOMAIN;
    }

    /** @return list<string> */
    public function requires(): array
    {
        // Mirrors module.json "requires": comments live in the request's
        // database, and submit() calls PostServiceContract to check the
        // owning post is published and accepting comments.
        return ['database.management', 'post.publishing'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [CommentServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bind(CommentServiceContract::class, static function (ModuleContainer $c): CommentService {
            $db = $c->make(DatabasePort::class);

            return new CommentService(
                repository: new CommentRepository($db),
                // Over the SAME connection as the repository — the
                // container's own TransactionManager wraps the core
                // connection, which on a Tenancy host is not the one
                // `comments` is written through.
                transaction: new TransactionManager($db),
                collector: $c->has(DomainEventCollector::class) ? $c->make(DomainEventCollector::class) : new DomainEventCollector(),
                eventBus: $c->make(EventBus::class),
                // ClockPort has no default binding; the kernel itself falls
                // back to SystemClock the same way.
                clock: self::clock($c),
                posts: $c->make(PostServiceContract::class),
                formToken: new FormToken(self::secret()),
                cache: $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
                moderationPolicy: strtolower(self::env('COMMENTS_MODERATION', 'first')),
                moderatePermission: self::env('COMMENTS_MODERATE_PERMISSION', 'comments:moderate'),
                minSeconds: max(0, (int) self::env('COMMENTS_MIN_SECONDS', '3')),
                maxLinks: max(0, (int) self::env('COMMENTS_MAX_LINKS', '2')),
                maxDepth: max(0, (int) self::env('COMMENTS_MAX_DEPTH', '3')),
                ratePerMinute: max(0, (int) self::env('COMMENTS_RATE_PER_MINUTE', '5')),
                ipSalt: self::secret(),
            );
        });
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Own filter aliases — a host needs no dependency on SecurityFilters
        // to get a throttled submit route and a gated moderation API.
        $http->filter(CommentThrottleStage::ALIAS, CommentThrottleStage::class);
        $http->filter(CommentModerateStage::ALIAS, CommentModerateStage::class);
    }

    private static function clock(ModuleContainer $c): ClockPort
    {
        return $c->has(ClockPort::class) ? $c->make(ClockPort::class) : new SystemClock();
    }

    /** COMMENTS_SECRET, falling back to APP_KEY, falling back to '' (a weak but functioning HMAC key). */
    private static function secret(): string
    {
        $secret = self::env('COMMENTS_SECRET');

        return $secret !== '' ? $secret : self::env('APP_KEY');
    }

    /** An env value as a trimmed string; missing or empty → $default. */
    private static function env(string $key, string $default = ''): string
    {
        $value = env($key);
        if ($value === null || $value === false) {
            return $default;
        }

        $value = trim((string) $value);

        return $value === '' ? $default : $value;
    }
}
