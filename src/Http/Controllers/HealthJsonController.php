<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Http\Controllers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Spatie\Health\Commands\RunHealthChecksCommand;
use Spatie\Health\ResultStores\ResultStore;

/**
 * JSON health endpoint equivalent to Spatie's HealthCheckJsonResultsController,
 * except that fresh runs never send notifications.
 *
 * Deploy gates poll this endpoint right after a container swap, when transient
 * failures are expected. Those polls are on-demand questions, not part of the
 * alerting loop: the scheduled `health:check` run owns alerting. Running fresh
 * checks with `--no-notification` keeps a rollout from paging anyone while the
 * response body, status code and headers stay identical to Spatie's controller.
 */
final class HealthJsonController
{
    public function __invoke(Request $request, ResultStore $resultStore, Repository $config): Response
    {
        if ($request->has('fresh') || (bool) $config->get('health.oh_dear_endpoint.always_send_fresh_results')) {
            Artisan::call(RunHealthChecksCommand::class, ['--no-notification' => true]);
        }

        $checkResults = $resultStore->latestResults();

        $statusCode = Response::HTTP_OK;

        if ($checkResults?->containsFailingCheck()) {
            $failureStatus = $config->get('health.json_results_failure_status', Response::HTTP_OK);
            $statusCode = is_numeric($failureStatus) ? (int) $failureStatus : Response::HTTP_OK;
        }

        return new Response($checkResults?->toJson() ?? '', $statusCode, [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, post-check=0, pre-check=0',
        ]);
    }
}
