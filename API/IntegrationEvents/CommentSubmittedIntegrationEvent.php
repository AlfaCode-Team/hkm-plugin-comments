<?php

declare(strict_types=1);

namespace Plugins\Comments\API\IntegrationEvents;

use AlfacodeTeam\PhpServicePlatform\Kernel\Events\Contracts\IntegrationEventContract;

/**
 * Cross-module announcement that a comment was submitted, whatever its
 * resulting status. Primitives only, and deliberately narrow: no email, no
 * body, no IP — a listener (e.g. a notification plugin) learns THAT a comment
 * landed on a post and in what state, never its content.
 */
final readonly class CommentSubmittedIntegrationEvent implements IntegrationEventContract
{
    public string $version;

    public function __construct(
        public string $commentId,
        public string $postId,
        public string $status,
        public string $occurredAt,
    ) {
        $this->version = '1.0';
    }

    public function name(): string
    {
        return 'comment.submitted';
    }

    public function version(): string
    {
        return $this->version;
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'commentId'  => $this->commentId,
            'postId'     => $this->postId,
            'status'     => $this->status,
            'occurredAt' => $this->occurredAt,
            'version'    => $this->version,
        ];
    }
}
