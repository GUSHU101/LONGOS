<?php

namespace App\Saas\Actions;

use Illuminate\Support\Facades\Context;
use Spatie\Multitenancy\Actions\MakeTenantCurrentAction as SpatieMakeTenantCurrentAction;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Events\MadeTenantCurrentEvent;
use Spatie\Multitenancy\Events\MakingTenantCurrentEvent;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;
use Throwable;

/**
 * Makes the tenant switch atomic from the application's perspective.
 *
 * Spatie intentionally keeps switch tasks small and composable. LONGOS adds
 * rollback here because a failed database/settings refresh in a long-lived
 * worker must not leave the process half switched to a tenant.
 */
class MakeTenantCurrentAction extends SpatieMakeTenantCurrentAction
{
    public function execute(IsTenant $tenant): static
    {
        event(new MakingTenantCurrentEvent($tenant));
        $completed = [];

        try {
            $this->tasksCollection->each(function (SwitchTenantTask $task) use ($tenant, &$completed): void {
                $task->makeCurrent($tenant);
                $completed[] = $task;
            });

            $this->bindAsCurrentTenant($tenant);
            event(new MadeTenantCurrentEvent($tenant));
        } catch (Throwable $exception) {
            app()->forgetInstance((string) config('multitenancy.current_tenant_container_key'));
            Context::forget((string) config('multitenancy.current_tenant_context_key'));

            foreach (array_reverse($completed) as $task) {
                try {
                    $task->forgetCurrent();
                } catch (Throwable $rollbackException) {
                    report($rollbackException);
                }
            }

            throw $exception;
        }

        return $this;
    }
}
