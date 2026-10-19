<?php

namespace App\Saas\Actions;

use App\Saas\Models\Site;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobRetryRequested;
use Spatie\Multitenancy\Actions\MakeQueueTenantAwareAction;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Exceptions\CurrentTenantCouldNotBeDeterminedInTenantAwareJob;

class ContextAwareQueueTenancyAction extends MakeQueueTenantAwareAction
{
    protected function isTenantAware(JobProcessing|JobRetryRequested $event): bool
    {
        // Jobs dispatched centrally stay central. Jobs dispatched while a
        // tenant is current inherit Laravel Context and become tenant-aware.
        return $this->resolveTenantId($event) !== null && parent::isTenantAware($event);
    }

    protected function findTenant(JobProcessing|JobRetryRequested $event): IsTenant
    {
        $tenant = parent::findTenant($event);
        if ($tenant instanceof Site && $tenant->status !== Site::STATUS_ACTIVE) {
            if ($event instanceof JobProcessing) {
                $event->job->delete();
            }

            throw CurrentTenantCouldNotBeDeterminedInTenantAwareJob::noTenantFound($event);
        }

        return $tenant;
    }
}
