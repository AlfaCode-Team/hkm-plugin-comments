<?php

declare(strict_types=1);

namespace Plugins\Comments\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Comments\API\Contracts\CommentServiceContract;

/**
 * Public comment endpoints — thin HTTP boundary, no business logic. Takes
 * `(Request, ...routeParams): Response` directly (not RequestAware): this
 * plugin has no dependency on any project-level controller base.
 */
final class CommentController
{
    public function __construct(
        private readonly CommentServiceContract $comments,
    ) {
    }

    public function thread(Request $request, string $postId): Response
    {
        $page = $this->comments->thread($postId, (int) $request->query('page', '1'), (int) $request->query('perPage', '20'));

        return Response::json(['data' => $page->toArray()]);
    }

    public function submit(Request $request, string $postId): Response
    {
        $view = $this->comments->submit($postId, $request->body(), $request->identity(), (string) $request->ip(), $request->userAgent());

        return Response::created($view->toArray());
    }

    public function formToken(Request $request): Response
    {
        return Response::json(['data' => ['token' => $this->comments->formToken()]]);
    }
}
