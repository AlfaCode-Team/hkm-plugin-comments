<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\EventListenerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Records every integration event it receives, keyed by event name — a
 * static buffer because `EventBus::resolveListener()` falls back to
 * `new $listenerClass()` for a zero-argument constructor, so the test cannot
 * inject an instance to assert against afterwards.
 */
final class RecordingListener implements EventListenerContract
{
    /** @var array<string, list<IntegrationEventContract>> */
    public static array $received = [];

    public function handle(IntegrationEventContract $event): void
    {
        self::$received[$event->name()][] = $event;
    }

    public static function reset(): void
    {
        self::$received = [];
    }
}
