<?php

declare(strict_types=1);

namespace Plugins\Comments\Infrastructure\Persistence;

use Plugins\Comments\Domain\ValueObjects\CommentStatus;

/**
 * CommentCriteria — what the admin listing filters on, already validated by
 * the service.
 */
final readonly class CommentCriteria
{
    public function __construct(
        public ?CommentStatus $status = null,
        public ?string $postId = null,
        public ?string $search = null,
    ) {
    }
}
