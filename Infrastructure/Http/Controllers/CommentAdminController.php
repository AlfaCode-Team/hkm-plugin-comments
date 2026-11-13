<?php

declare(strict_types=1);

namespace Plugins\Comments\Infrastructure\Http\Controllers;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use Plugins\Comments\API\Contracts\CommentServiceContract;
use Plugins\Comments\Support\Messages;

/**
 * Moderation endpoints. Every action on this controller sits behind the
 * `comments.moderate` route filter (module.json group) — this class performs
 * no authorization of its own, same split as PaymentStatusController /
 * WebhookController in the sibling Payment plugin.
 */
final class CommentAdminController
{
    public function __construct(
        private readonly CommentServiceContract $comments,
    ) {
    }

    public function index(Request $request): Response
    {
        $page = $this->comments->list(
            $request->query('status'),
            $request->query('postId'),
            $request->query('search'),
            (int) $request->query('page', '1'),
            (int) $request->query('perPage', '20'),
        );

        return Response::json(['data' => $page->toArray()]);
    }

    public function show(Request $request, string $id): Response
    {
        $view = $this->comments->find($id);

        return $view !== null ? Response::json(['data' => $view->toArray()]) : Response::notFound(Messages::get('not_found', 'Comment [:id] was not found.', ['id' => $id]));
    }

    public function updateStatus(Request $request, string $id): Response
    {
        $view = $this->comments->moderate($id, (string) $request->input('status', ''), (string) ($request->identity()?->userId ?? ''));

        return $view !== null ? Response::json(['data' => $view->toArray()]) : Response::notFound(Messages::get('not_found', 'Comment [:id] was not found.', ['id' => $id]));
    }

    public function updateBody(Request $request, string $id): Response
    {
        $view = $this->comments->update($id, (string) $request->input('body', ''), (string) ($request->identity()?->userId ?? ''));

        return $view !== null ? Response::json(['data' => $view->toArray()]) : Response::notFound(Messages::get('not_found', 'Comment [:id] was not found.', ['id' => $id]));
    }

    public function destroy(Request $request, string $id): Response
    {
        return $this->comments->delete($id) ? Response::empty(204) : Response::notFound(Messages::get('not_found', 'Comment [:id] was not found.', ['id' => $id]));
    }

    public function bulk(Request $request): Response
    {
        $changed = $this->comments->bulkModerate(
            (array) $request->input('ids', []),
            (string) $request->input('status', ''),
            (string) ($request->identity()?->userId ?? ''),
        );

        return Response::json(['data' => ['changed' => $changed]]);
    }
}
