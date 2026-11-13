<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use PHPUnit\Framework\TestCase;
use Plugins\Comments\Infrastructure\Http\Stages\CommentThrottleStage;

/**
 * With no request-scoped ModuleContainer attached (never the case for a real
 * HTTP request, but exactly the case in a unit test that does not build the
 * whole pipeline), the stage has nothing to count in and must pass every
 * request through rather than throw.
 */
final class CommentThrottleStageTest extends TestCase
{
    public function test_passes_through_when_no_container_is_attached(): void
    {
        $stage    = new CommentThrottleStage();
        $request  = Request::build('POST', '/api/comments/posts/ABC');
        $response = $stage->handle($request, static fn(Request $r): Response => Response::text('OK'));

        self::assertSame('OK', $response->body());
    }
}
