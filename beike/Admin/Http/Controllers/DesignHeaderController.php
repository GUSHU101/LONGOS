<?php

namespace Beike\Admin\Http\Controllers;

use Beike\Repositories\SettingRepo;
use Beike\Services\DesignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DesignHeaderController extends Controller
{
    /**
     * 展示所有模块编辑器
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): RedirectResponse
    {
        return redirect()->to(admin_route('design.index'));
    }

    /**
     * 更新所有数据
     *
     * @param Request $request
     * @return JsonResponse
     * @throws \Throwable
     */
    public function update(Request $request): JsonResponse
    {
        $content = json_decode($request->getContent(), true);

        SettingRepo::storeValue('header_setting', DesignService::normalizeHeaderSetting($content));

        return json_success(trans('common.updated_success'));
    }
}
