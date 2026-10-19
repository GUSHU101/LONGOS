<?php

namespace Beike\Installer\Helpers;

class PermissionsChecker
{
    /**
     * @var array
     */
    protected $results = [];

    /**
     * Set the result array permissions and errors.
     *
     * @return mixed
     */
    public function __construct()
    {
        $this->results['permissions'] = [];

        $this->results['errors'] = null;
    }

    /**
     * Check for the folders permissions.
     *
     * @param array $folders
     * @return array
     */
    public function check(array $folders): array
    {
        foreach ($folders as $folder => $requirement) {
            if (! $this->isWritable($folder)) {
                $this->addFileAndSetErrors($folder, $requirement, false);
            } else {
                $this->addFile($folder, $requirement, true);
            }
        }

        return $this->results;
    }

    private function isWritable(string $path): bool
    {
        $absolute = base_path($path);
        if (file_exists($absolute)) {
            return is_writable($absolute);
        }

        $parent = dirname($absolute);
        while (! is_dir($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                return false;
            }
            $parent = $next;
        }

        return is_writable($parent);
    }

    /**
     * Add the file to the list of results.
     *
     * @param $folder
     * @param $permission
     * @param $isSet
     */
    private function addFile($folder, $requirement, $isSet): void
    {
        $this->results['permissions'][] = [
            'folder'     => $folder,
            'permission' => $requirement,
            'isSet'      => $isSet,
        ];
    }

    /**
     * Add the file and set the errors.
     *
     * @param $folder
     * @param $permission
     * @param $isSet
     */
    private function addFileAndSetErrors($folder, $requirement, $isSet): void
    {
        $this->addFile($folder, $requirement, $isSet);

        $this->results['errors'] = true;
    }
}
