<?php

declare(strict_types=1);

namespace Plugins\Comments\API\DTO;

/**
 * CommentView — the PUBLIC read model. Primitives only, and deliberately
 * narrow: no `authorEmail`, no `ipHash`, no `userAgent`. `body` is already
 * HTML-escaped (`htmlspecialchars`, ENT_QUOTES) — this plugin stores plain
 * text, but a commenter can still type literal `<script>`, so the view is
 * safe to interpolate directly into an HTML template without the host having
 * to remember to escape it again.
 *
 * `status` IS included even though a public thread only ever shows approved
 * comments: `submit()` returns this same DTO so a submitter can be told their
 * comment is awaiting moderation. It is not sensitive information about
 * anyone but the submitter themselves.
 */
final readonly class CommentView
{
    /**
     * @param list<CommentView> $replies Oldest first. Only populated by
     *        {@see \Plugins\Comments\API\Contracts\CommentServiceContract::thread()};
     *        empty for the DTO `submit()` returns.
     */
    public function __construct(
        public string $id,
        public string $postId,
        public ?string $parentId,
        public int $depth,
        public ?string $authorName,
        public ?string $authorUrl,
        public string $body,
        public string $status,
        public string $createdAt,
        public array $replies = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id'         => $this->id,
            'postId'     => $this->postId,
            'parentId'   => $this->parentId,
            'depth'      => $this->depth,
            'authorName' => $this->authorName,
            'authorUrl'  => $this->authorUrl,
            'body'       => $this->body,
            'status'     => $this->status,
            'createdAt'  => $this->createdAt,
            'replies'    => array_map(static fn(self $r): array => $r->toArray(), $this->replies),
        ];
    }
}
