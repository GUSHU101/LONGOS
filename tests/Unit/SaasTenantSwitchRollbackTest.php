<?php

namespace Tests\Unit;

use App\Saas\Actions\MakeTenantCurrentAction;
use App\Saas\Models\Site;
use ArrayObject;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Spatie\Multitenancy\Contracts\IsTenant;
use Spatie\Multitenancy\Events\MadeTenantCurrentEvent;
use Spatie\Multitenancy\Tasks\SwitchTenantTask;
use Spatie\Multitenancy\Tasks\TasksCollection;

class SaasTenantSwitchRollbackTest extends TestCase
{
    private ?Container $previousContainer = null;

    private mixed $previousFacadeApplication = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer         = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container                       = new Container;
        $events                          = new Dispatcher($container);
        $container->instance('events', $events);
        $container->instance('config', new ConfigRepository([
            'multitenancy' => [
                'current_tenant_container_key' => 'currentTenant',
                'current_tenant_context_key'   => 'saas_site_id',
            ],
        ]));
        $container->instance(ContextRepository::class, new ContextRepository($events));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
    }

    protected function tearDown(): void
    {
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function test_completed_tasks_are_rolled_back_when_a_later_switch_fails(): void
    {
        $trace = new ArrayObject;
        $first = new class($trace) implements SwitchTenantTask
        {
            public function __construct(private readonly ArrayObject $trace) {}

            public function makeCurrent(IsTenant $tenant): void
            {
                $this->trace[] = 'make:first';
            }

            public function forgetCurrent(): void
            {
                $this->trace[] = 'forget:first';
            }
        };
        $failing = new class($trace) implements SwitchTenantTask
        {
            public function __construct(private readonly ArrayObject $trace) {}

            public function makeCurrent(IsTenant $tenant): void
            {
                $this->trace[] = 'make:failing';

                throw new RuntimeException('switch failed');
            }

            public function forgetCurrent(): void
            {
                $this->trace[] = 'forget:failing';
            }
        };

        $tasks = new TasksCollection;
        $tasks->push($first, $failing);
        $action = new MakeTenantCurrentAction($tasks);

        try {
            $action->execute(new Site(['database_name' => 'tenant_a']));
            self::fail('Expected the tenant switch to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('switch failed', $exception->getMessage());
        }

        self::assertSame(
            ['make:first', 'make:failing', 'forget:first'],
            $trace->getArrayCopy(),
        );
    }

    public function test_binding_and_tasks_are_rolled_back_when_a_made_event_listener_fails(): void
    {
        $trace = new ArrayObject;
        $task  = new class($trace) implements SwitchTenantTask
        {
            public function __construct(private readonly ArrayObject $trace) {}

            public function makeCurrent(IsTenant $tenant): void
            {
                $this->trace[] = 'make';
            }

            public function forgetCurrent(): void
            {
                $this->trace[] = 'forget';
            }
        };
        app('events')->listen(MadeTenantCurrentEvent::class, static function (): void {
            throw new RuntimeException('made event failed');
        });

        $tasks = new TasksCollection;
        $tasks->push($task);
        $tenant     = new Site(['database_name' => 'tenant_a']);
        $tenant->id = 99;

        try {
            (new MakeTenantCurrentAction($tasks))->execute($tenant);
            self::fail('Expected the made-current listener to fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('made event failed', $exception->getMessage());
        }

        self::assertSame(['make', 'forget'], $trace->getArrayCopy());
        self::assertFalse(app()->bound('currentTenant'));
        self::assertFalse(app(ContextRepository::class)->has('saas_site_id'));
    }
}
