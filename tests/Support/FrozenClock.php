<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\ClockPort;

/**
 * A ClockPort frozen at a fixed instant, and ADVANCEABLE — the spam guard
 * tests need to move time forward (token age, duplicate window, rate-limit
 * windows) without sleeping.
 */
final class FrozenClock implements ClockPort
{
    public function __construct(private \DateTimeImmutable $now)
    {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }

    public function timestamp(): int
    {
        return $this->now->getTimestamp();
    }

    public function advance(int $seconds): void
    {
        $this->now = $this->now->modify("+{$seconds} seconds");
    }
}
