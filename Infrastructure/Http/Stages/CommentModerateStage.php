<?php

declare(strict_types=1);

namespace Plugins\Comments\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\Comments\Support\Messages;

/**
 * Route filter `comments.moderate` — the ONLY authorization check in this
 * plugin's HTTP surface. Declared on the whole `/api/admin/comments` group in
 * module.json, so every admin action is gated the same way:
 *
 *   - no Identity, or a guest Identity → 401
 *   - an Identity lacking the permission → 403
 *   - otherwise → pass through to the controller / service
 *
 * `Identity::hasPermission()` already treats '*' as "every permission", so an
 * admin role with a wildcard passes without this stage special-casing it.
 *
 * This plugin registers the alias itself (via Provider::boot()) rather than
 * depending on the SecurityFilters plugin's generic `auth` filter, because
 * the permission NAME is this plugin's own config (`COMMENTS_MODERATE_PERMISSION`),
 * not something a generic auth filter would know to check.
 */
final class CommentModerateStage implements HttpStageContract
{
    public const ALIAS = 'comments.moderate';

    private const DEFAULT_PERMISSION = 'comments:moderate';

    public function handle(Request $request, callable $next): Response
    {
        $identity = $request->identity();

        if ($identity === null || $identity->isGuest()) {
            return Response::unauthorized(Messages::get('moderate.unauthenticated', 'You must sign in to moderate comments.'));
        }

        $permission = self::permission();
        if (!$identity->hasPermission($permission)) {
            return Response::forbidden(Messages::get('moderate.forbidden', 'You do not have permission to moderate comments.'));
        }

        return $next($request);
    }

    private static function permission(): string
    {
        $value = \function_exists('env') ? env('COMMENTS_MODERATE_PERMISSION') : null;
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? self::DEFAULT_PERMISSION : $value;
    }
}
