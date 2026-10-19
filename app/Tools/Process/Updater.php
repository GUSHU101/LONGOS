<?php

namespace App\Tools\Process;

use App\Tools\Module;

class Updater extends Runner
{
    /**
     * Update the dependencies for the specified module by given the module name.
     *
     * @param string $module
     */
    public function update($module)
    {
        $module = $this->module->findOrFail($module);

        chdir(base_path());

        $this->installRequires($module);
        $this->installDevRequires($module);
        $this->copyScriptsToMainComposerJson($module);
    }

    /**
     * Check if composer should output anything.
     *
     * @return string
     */
    private function isComposerSilenced(): bool
    {
        return config('plugins.composer.composer-output') === false;
    }

    /**
     * @param Module $module
     */
    private function installRequires(Module $module)
    {
        $packages = $module->getComposerAttr('require', []);

        $arguments = ['composer', 'require'];
        foreach ($packages as $name => $version) {
            $arguments[] = $this->packageConstraint($name, $version);
        }

        if (count($arguments) > 2) {
            if ($this->isComposerSilenced()) {
                $arguments[] = '--quiet';
            }
            $this->run($arguments);
        }
    }

    /**
     * @param Module $module
     */
    private function installDevRequires(Module $module)
    {
        $devPackages = $module->getComposerAttr('require-dev', []);

        $arguments = ['composer', 'require', '--dev'];
        foreach ($devPackages as $name => $version) {
            $arguments[] = $this->packageConstraint($name, $version);
        }

        if (count($arguments) > 3) {
            if ($this->isComposerSilenced()) {
                $arguments[] = '--quiet';
            }
            $this->run($arguments);
        }
    }

    private function packageConstraint(mixed $name, mixed $version): string
    {
        $name    = trim((string) $name);
        $version = trim((string) $version);
        if (! preg_match('#^[a-z0-9_.-]+/[a-z0-9_.-]+$#i', $name) || $version === '' || preg_match('/[\x00-\x1f\x7f]/', $version)) {
            throw new \InvalidArgumentException("Invalid Composer package constraint for {$name}.");
        }

        return "{$name}:{$version}";
    }

    /**
     * @param Module $module
     */
    private function copyScriptsToMainComposerJson(Module $module)
    {
        $scripts = $module->getComposerAttr('scripts', []);

        $composer = json_decode(file_get_contents(base_path('composer.json')), true);

        foreach ($scripts as $key => $script) {
            if (array_key_exists($key, $composer['scripts'])) {
                $composer['scripts'][$key] = array_unique(array_merge($composer['scripts'][$key], $script));

                continue;
            }
            $composer['scripts'] = array_merge($composer['scripts'], [$key => $script]);
        }

        file_put_contents(base_path('composer.json'), json_encode($composer, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
