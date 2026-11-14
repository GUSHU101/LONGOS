<?php

namespace Beike\Admin\Http\Resources;

use App\Saas\TenantManager;
use Beike\Models\AdminUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Throwable;

class PluginResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array
     * @throws \Exception
     */
    public function toArray($request): array
    {
        return [
            'code'           => $this->code,
            'name'           => $this->getLocaleName(),
            'description'    => $this->getLocaleDescription(),
            'path'           => $this->path,
            'version'        => $this->version,
            'dir_name'       => $this->dirName,
            'type'           => $this->type,
            'type_format'    => trans('admin/plugin.' . $this->type),
            'icon'           => plugin_resize($this->code, $this->icon, 200, 200),
            'author'         => $this->author,
            'status'         => $this->getStatus(),
            'installed'      => $this->getInstalled(),
            'edit_url'       => $this->getEditUrl(),
            'can_update'     => $this->getCanUpdate(),
            'status_mutable' => $this->statusMutable($request),
        ];
    }

    private function statusMutable(Request $request): bool
    {
        try {
            $user = Auth::guard(AdminUser::AUTH_GUARD)->user();
            if (! $user || (! $user->is_root && ! $user->can('plugins_update_status'))) {
                return false;
            }

            if ($this->code !== 'longos_platform_pay' || ! config('saas.enabled')) {
                return true;
            }

            $tenants = app(TenantManager::class);
            $site    = $tenants->current();
            if (! $site) {
                return false;
            }
            if (! (bool) data_get($site->meta, 'control_site', false)) {
                return ! $tenants->isControlHost($request->getHost());
            }

            return (bool) $user->is_root && $tenants->isControlHost($request->getHost());
        } catch (Throwable) {
            return false;
        }
    }
}
