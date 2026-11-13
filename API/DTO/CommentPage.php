<?php

declare(strict_types=1);

namespace Plugins\Comments\API\DTO;

/**
 * CommentPage — one page of a public THREAD: top-level comments are
 * paginated, each with every reply already nested underneath it (replies
 * are never paginated separately — a thread with 40 replies on page 1's
 * first comment is still one page).
 */
final readonly class CommentPage
{
    /** @param list<CommentView> $items Top-level comments only; replies are nested inside each. */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {
    }

    public function lastPage(): int
    {
        return max(1, (int) ceil($this->total / max(1, $this->perPage)));
    }

    public function hasMore(): bool
    {
        return $this->page < $this->lastPage();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items'    => array_map(static fn(CommentView $v): array => $v->toArray(), $this->items),
            'total'    => $this->total,
            'page'     => $this->page,
            'perPage'  => $this->perPage,
            'lastPage' => $this->lastPage(),
            'hasMore'  => $this->hasMore(),
        ];
    }
}
