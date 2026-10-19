<?php

namespace App\Tools\Contracts;

interface RunableInterface
{
    /**
     * Run the specified command.
     *
     * @param list<string> $command
     */
    public function run($command);
}
