<?php

declare(strict_types=1);

namespace Plugins\Comments\Application\Spam;

/**
 * SpamGuard — the stateless, timing/content checks a submission has to pass.
 *
 * Deliberately pure static functions: no port, no clock, no I/O. Each rule is
 * one method so a test can cover it in isolation without building a whole
 * CommentService. The checks that need the DATABASE (duplicate detection) or
 * a PORT (the per-IP rate limit, which needs CachePort) are NOT here — they
 * stay in CommentService, which is where that collaboration belongs.
 */
final class SpamGuard
{
    private function __construct()
    {
        // Static-only.
    }

    /**
     * The honeypot field. A real visitor never sees or fills it (hidden by
     * CSS); anything in it means a bot filled every field it could find.
     */
    public static function honeypotTriggered(string $honeypotValue): bool
    {
        return trim($honeypotValue) !== '';
    }

    /** Submitted suspiciously fast after the form was rendered. */
    public static function submittedTooFast(int $formIssuedAt, int $now, int $minSeconds): bool
    {
        return $minSeconds > 0 && ($now - $formIssuedAt) < $minSeconds;
    }

    /** The form was rendered too long ago — most likely a replayed/stale token. */
    public static function formTokenExpired(int $formIssuedAt, int $now, int $maxAgeSeconds): bool
    {
        return ($now - $formIssuedAt) > $maxAgeSeconds;
    }

    /** Number of "http(s)://" occurrences in the body. */
    public static function countLinks(string $body): int
    {
        return (int) preg_match_all('#https?://#i', $body);
    }

    public static function exceedsMaxLinks(string $body, int $maxLinks): bool
    {
        return self::countLinks($body) > $maxLinks;
    }
}
