<?php

namespace App\Tools\Process;

use App\Tools\Contracts\RepositoryInterface;
use App\Tools\Contracts\RunableInterface;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

class Runner implements RunableInterface
{
    /**
     * The module instance.
     * @var RepositoryInterface
     */
    protected $module;

    public function __construct(RepositoryInterface $module)
    {
        $this->module = $module;
    }

    /**
     * Run the given command.
     *
     * @param list<string> $command
     */
    public function run($command)
    {
        if (! is_array($command) || $command === [] || array_filter($command, static fn ($argument): bool => ! is_string($argument)) !== []) {
            throw new InvalidArgumentException('Process commands must be a non-empty list of string arguments.');
        }

        if (! app()->runningInConsole()) {
            throw new \LogicException('Process commands may only run from the console.');
        }

        $process = new Process(array_values($command), base_path());
        $process->setTimeout(null);
        $exitCode = $process->run(static function (string $type, string $buffer): void {
            fwrite($type === Process::ERR ? STDERR : STDOUT, $buffer);
        });

        if ($exitCode !== 0) {
            throw new RuntimeException(sprintf(
                'Dependency command failed with exit code %d: %s',
                $exitCode,
                $process->getErrorOutput() ?: $process->getOutput(),
            ));
        }

        return $exitCode;
    }
}
