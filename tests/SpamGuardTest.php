<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments;

use PHPUnit\Framework\TestCase;
use Plugins\Comments\Application\Spam\SpamGuard;

final class SpamGuardTest extends TestCase
{
    public function test_honeypot_triggers_on_any_non_empty_value(): void
    {
        self::assertTrue(SpamGuard::honeypotTriggered('http://spam.example'));
        self::assertTrue(SpamGuard::honeypotTriggered('   x  '));
        self::assertFalse(SpamGuard::honeypotTriggered(''));
        self::assertFalse(SpamGuard::honeypotTriggered('   '));
    }

    public function test_submitted_too_fast(): void
    {
        $issuedAt = 1_000;

        self::assertTrue(SpamGuard::submittedTooFast($issuedAt, 1_002, minSeconds: 3));
        self::assertFalse(SpamGuard::submittedTooFast($issuedAt, 1_003, minSeconds: 3));
        self::assertFalse(SpamGuard::submittedTooFast($issuedAt, 1_010, minSeconds: 3));
    }

    public function test_min_seconds_zero_disables_the_check(): void
    {
        self::assertFalse(SpamGuard::submittedTooFast(1_000, 1_000, minSeconds: 0));
    }

    public function test_form_token_expired(): void
    {
        $issuedAt = 1_000;

        self::assertFalse(SpamGuard::formTokenExpired($issuedAt, 1_000 + 86_400, maxAgeSeconds: 86_400));
        self::assertTrue(SpamGuard::formTokenExpired($issuedAt, 1_000 + 86_401, maxAgeSeconds: 86_400));
    }

    public function test_link_counting_is_case_insensitive_and_counts_every_occurrence(): void
    {
        self::assertSame(0, SpamGuard::countLinks('No links here.'));
        self::assertSame(1, SpamGuard::countLinks('See HTTPS://example.com for details.'));
        self::assertSame(3, SpamGuard::countLinks('http://a.com http://b.com https://c.com'));
    }

    public function test_exceeds_max_links(): void
    {
        self::assertFalse(SpamGuard::exceedsMaxLinks('http://a.com http://b.com', maxLinks: 2));
        self::assertTrue(SpamGuard::exceedsMaxLinks('http://a.com http://b.com http://c.com', maxLinks: 2));
    }
}
