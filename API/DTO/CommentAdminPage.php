<?php

declare(strict_types=1);

namespace Plugins\Comments\API\DTO;

/**
 * CommentAdminPage — one page of the moderation listing. Flat, newest first —
 * an editor triaging the queue does not want it pre-threaded.
 */
final readonly class CommentAdminPage
{
    /** @param list<CommentAdminView> $items */
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
            'items'    => array_map(static fn(CommentAdminView $v): array => $v->toArray(), $this->items),
            'total'    => $this->total,
            'page'     => $this->page,
            'perPage'  => $this->perPage,
            'lastPage' => $this->lastPage(),
            'hasMore'  => $this->hasMore(),
        ];
    }
}
