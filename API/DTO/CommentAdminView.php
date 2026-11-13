<?php

declare(strict_types=1);

namespace Plugins\Comments\API\DTO;

/**
 * CommentAdminView — the MODERATION read model: every column except the raw
 * IP (only its salted hash ever existed past the request that wrote it).
 * Never return this from a public route.
 */
final readonly class CommentAdminView
{
    public function __construct(
        public string $id,
        public string $postId,
        public ?string $parentId,
        public int $depth,
        public ?string $authorUserId,
        public string $authorName,
        public string $authorEmail,
        public ?string $authorUrl,
        public string $body,
        public string $status,
        public string $ipHash,
        public ?string $userAgent,
        public string $createdAt,
        public string $updatedAt,
        public ?string $approvedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'postId'        => $this->postId,
            'parentId'      => $this->parentId,
            'depth'         => $this->depth,
            'authorUserId'  => $this->authorUserId,
            'authorName'    => $this->authorName,
            'authorEmail'   => $this->authorEmail,
            'authorUrl'     => $this->authorUrl,
            'body'          => $this->body,
            'status'        => $this->status,
            'ipHash'        => $this->ipHash,
            'userAgent'     => $this->userAgent,
            'createdAt'     => $this->createdAt,
            'updatedAt'     => $this->updatedAt,
            'approvedAt'    => $this->approvedAt,
        ];
    }
}
