<?php

namespace App\Tools\Process;

use App\Tools\Contracts\RepositoryInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

class Installer
{
    /**
     * The module name.
     *
     * @var string
     */
    protected $name;

    /**
     * The version of module being installed.
     *
     * @var string
     */
    protected $version;

    /**
     * The module repository instance.
     * @var \App\Tools\Contracts\RepositoryInterface
     */
    protected $repository;

    /**
     * The console command instance.
     *
     * @var \Illuminate\Console\Command
     */
    protected $console;

    /**
     * The destionation path.
     *
     * @var string
     */
    protected $path;

    /**
     * The process timeout.
     *
     * @var int
     */
    protected $timeout = 3360;

    /**
     * @var null|string
     */
    private $type;

    /**
     * @var bool
     */
    private $tree;

    /**
     * The constructor.
     *
     * @param string $name
     * @param string $version
     * @param string $type
     * @param bool   $tree
     */
    public function __construct($name, $version = null, $type = null, $tree = false)
    {
        $this->name    = $name;
        $this->version = $version;
        $this->type    = $type;
        $this->tree    = $tree;
    }

    /**
     * Set destination path.
     *
     * @param string $path
     *
     * @return $this
     */
    public function setPath($path)
    {
        $this->path = $path;

        return $this;
    }

    /**
     * Set the module repository instance.
     * @param \App\Tools\Contracts\RepositoryInterface $repository
     * @return $this
     */
    public function setRepository(RepositoryInterface $repository)
    {
        $this->repository = $repository;

        return $this;
    }

    /**
     * Set console command instance.
     *
     * @param \Illuminate\Console\Command $console
     *
     * @return $this
     */
    public function setConsole(Command $console)
    {
        $this->console = $console;

        return $this;
    }

    /**
     * Set process timeout.
     *
     * @param int $timeout
     *
     * @return $this
     */
    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;

        return $this;
    }

    /**
     * Run the installation process.
     *
     * @return \Symfony\Component\Process\Process
     */
    public function run()
    {
        $process = $this->getProcess();

        $process->setTimeout($this->timeout);

        $process->run($this->console instanceof Command
            ? function ($type, $line): void {
                $this->console->line($line);
            }
            : null);

        return $process;
    }

    /**
     * Get process instance.
     *
     * @return \Symfony\Component\Process\Process
     */
    public function getProcess()
    {
        if ($this->type && $this->type !== 'composer') {
            if ($this->tree) {
                return $this->installViaSubtree();
            }

            return $this->installViaGit();
        }

        return $this->installViaComposer();
    }

    /**
     * Get destination path.
     *
     * @return string
     */
    public function getDestinationPath()
    {
        if ($this->path) {
            return $this->path;
        }

        return $this->repository->getModulePath($this->getModuleName());
    }

    /**
     * Get git repo url.
     *
     * @return string|null
     */
    public function getRepoUrl()
    {
        switch ($this->type) {
            case 'github':
                return "git@github.com:{$this->name}.git";

            case 'github-https':
                return "https://github.com/{$this->name}.git";

            case 'gitlab':
                return "git@gitlab.com:{$this->name}.git";

                break;

            case 'bitbucket':
                return "git@bitbucket.org:{$this->name}.git";

            default:

                // Check of type 'scheme://host/path'
                if (filter_var($this->type, FILTER_VALIDATE_URL)) {
                    return $this->type;
                }

                // Check of type 'user@host'
                if (filter_var($this->type, FILTER_VALIDATE_EMAIL)) {
                    return "{$this->type}:{$this->name}.git";
                }

                return;

                break;
        }
    }

    /**
     * Get branch name.
     *
     * @return string
     */
    public function getBranch()
    {
        $branch = is_null($this->version) ? 'master' : trim((string) $this->version);
        if ($branch === '' || strlen($branch) > 255 || preg_match('/[\x00-\x20\x7f~^:?*\[\\]/', $branch)) {
            throw new InvalidArgumentException('The Git branch or ref is invalid.');
        }

        return $branch;
    }

    /**
     * Get module name.
     *
     * @return string
     */
    public function getModuleName()
    {
        $parts = explode('/', $this->name);

        return Str::studly(end($parts));
    }

    /**
     * Get composer package name.
     *
     * @return string
     */
    public function getPackageName()
    {
        $name = strtolower(trim((string) $this->name));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9_.-]*[a-z0-9])?$/', $name)) {
            throw new InvalidArgumentException('The Composer package name is invalid.');
        }

        $version = is_null($this->version) ? 'dev-master' : trim((string) $this->version);
        if ($version === '' || strlen($version) > 255 || preg_match('/[\x00-\x1f\x7f]/', $version)) {
            throw new InvalidArgumentException('The Composer package version is invalid.');
        }

        return $name . ':' . $version;
    }

    /**
     * Install the module via git.
     *
     * @return \Symfony\Component\Process\Process
     */
    public function installViaGit()
    {
        $repository = $this->getRepoUrl();
        if (! is_string($repository) || $repository === '') {
            throw new InvalidArgumentException('The Git repository type is invalid.');
        }

        return new Process([
            'git',
            'clone',
            '--branch',
            $this->getBranch(),
            '--single-branch',
            $repository,
            $this->getDestinationPath(),
        ], base_path());
    }

    /**
     * Install the module via git subtree.
     *
     * @return \Symfony\Component\Process\Process
     */
    public function installViaSubtree()
    {
        $repository = $this->getRepoUrl();
        if (! is_string($repository) || $repository === '') {
            throw new InvalidArgumentException('The Git repository type is invalid.');
        }

        return new Process([
            'git',
            'subtree',
            'add',
            '--prefix=' . $this->relativeDestinationPath(),
            '--squash',
            $repository,
            $this->getBranch(),
        ], base_path());
    }

    /**
     * Install the module via composer.
     *
     * @return \Symfony\Component\Process\Process
     */
    public function installViaComposer()
    {
        return new Process(['composer', 'require', $this->getPackageName()], base_path());
    }

    private function relativeDestinationPath(): string
    {
        $base        = rtrim(str_replace('\\', '/', base_path()), '/');
        $destination = rtrim(str_replace('\\', '/', (string) $this->getDestinationPath()), '/');
        if (! str_starts_with(strtolower($destination . '/'), strtolower($base . '/'))) {
            throw new InvalidArgumentException('The module destination must stay inside the application directory.');
        }

        $relative = ltrim(substr($destination, strlen($base)), '/');
        if ($relative === '' || preg_match('#(?:^|/)\.\.(?:/|$)#', $relative)) {
            throw new InvalidArgumentException('The module destination is invalid.');
        }

        return $relative;
    }
}
