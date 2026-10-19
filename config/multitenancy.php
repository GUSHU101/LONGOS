<?php

use App\Saas\Actions\ContextAwareQueueTenancyAction;
use App\Saas\Actions\MakeTenantCurrentAction as AtomicMakeTenantCurrentAction;
use App\Saas\Models\Site;
use App\Saas\Tasks\ConfigureTenantRuntimeTask;
use App\Saas\Tasks\PrefixTenantCacheTask;
use App\Saas\Tasks\SwitchDefaultTenantDatabaseTask;
use App\Saas\TenantFinder\DomainTenantFinder;
use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\CallQueuedClosure;
use Spatie\Multitenancy\Actions\ForgetCurrentTenantAction;
use Spatie\Multitenancy\Actions\MigrateTenantAction;
use Spatie\Multitenancy\Jobs\NotTenantAware;
use Spatie\Multitenancy\Jobs\TenantAware;

return [
    // Always register the finder; it exits without touching the database when
    // SaaS is disabled. This avoids config-file load-order and cache issues.
    'tenant_finder' => DomainTenantFinder::class,

    'tenant_artisan_search_fields' => ['id', 'code'],

    // The order is deliberate: business models must point at the tenant DB
    // before settings, mail, filesystem and theme state are refreshed.
    'switch_tenant_tasks' => [
        SwitchDefaultTenantDatabaseTask::class,
        PrefixTenantCacheTask::class,
        ConfigureTenantRuntimeTask::class,
    ],

    'tenant_model'                       => Site::class,
    // ContextAwareQueueTenancyAction keeps central jobs central when no tenant
    // id is present. Keeping this true also lets already-queued tenant jobs
    // restore their database safely during a temporary SaaS maintenance flag.
    'queues_are_tenant_aware_by_default' => true,
    'tenant_database_connection_name'    => 'tenant',
    'landlord_database_connection_name'  => 'saas',
    'current_tenant_context_key'         => 'saas_site_id',
    'current_tenant_container_key'       => 'currentTenant',
    'shared_routes_cache'                => false,

    'actions' => [
        'make_tenant_current_action'     => AtomicMakeTenantCurrentAction::class,
        'forget_current_tenant_action'   => ForgetCurrentTenantAction::class,
        'make_queue_tenant_aware_action' => ContextAwareQueueTenancyAction::class,
        'migrate_tenant'                 => MigrateTenantAction::class,
    ],

    'queueable_to_job' => [
        SendQueuedMailable::class      => 'mailable',
        SendQueuedNotifications::class => 'notification',
        CallQueuedClosure::class       => 'closure',
        CallQueuedListener::class      => 'class',
        BroadcastEvent::class          => 'event',
    ],

    'tenant_aware_interface'     => TenantAware::class,
    'not_tenant_aware_interface' => NotTenantAware::class,
    'tenant_aware_jobs'          => [],
    'not_tenant_aware_jobs'      => [],
];
