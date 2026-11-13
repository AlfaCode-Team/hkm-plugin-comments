<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Comments\Support\Kernel\Pageflow;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/**
 * Stands in for Pageflow's `http.pageflow` domain. Posts' admin PAGE routes
 * declare it in their route-level requires[], and the kernel validates that
 * at boot even for routes nobody requests. This suite never opens those
 * pages, so the domain only has to exist — the real Pageflow (and the
 * vite.manifest + i18n.translation it requires) would be dead weight here.
 * Lives in its own directory because the kernel reads module.json from the
 * provider's folder.
 */
final class PageflowStub implements ModuleContract
{
    public function solves(): string
    {
        return 'http.pageflow';
    }

    public function requires(): array
    {
        return [];
    }

    public function exposes(): array
    {
        return [];
    }

    public function register(ModuleContainer $container): void
    {
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
