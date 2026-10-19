<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var string[]
     */
    protected $dontReport = [
        //
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var string[]
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     *
     * @return void
     */
    public function register()
    {
        $this->reportable(function (Throwable $e) {
            //
        });
    }

    /**
     * Convert the given exception to an array.
     *
     * @param \Throwable $e
     * @return array
     */
    protected function convertExceptionToArray(Throwable $e)
    {

        return config('app.debug') ? [
            'message'   => $e->getMessage(),
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => collect($e->getTrace())->map(fn ($trace) => Arr::except($trace, ['args']))->all(),
        ] : [
            'message' => $this->isHttpException($e) ? $e->getMessage() : 'Server Error',
        ];
    }

    /**
     * 自定义错误信息页面, 前台与后台不同, 需要分开定义
     */
    protected function registerErrorViewPaths()
    {
        // Installation deliberately runs before a database exists. Resolving
        // the admin route here can read the database-backed admin setting and
        // mask the original installer exception with a second 500 error.
        if (is_installer()) {
            (new RegisterErrorViewPaths)();

            return;
        }

        if (is_admin()) {
            (new RegisterAdminErrorViewPaths)();
        } else {
            (new RegisterErrorViewPaths)();
        }
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param \Illuminate\Http\Request $request
     * @param \Throwable               $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Throwable $exception)
    {
        if ($exception instanceof AuthenticationException) {
            if (is_admin()) {
                $loginUrl = admin_route('login.show');

                if ($request->expectsJson()) {
                    return response()->json([
                        'code'     => 401,
                        'message'  => __('auth.token_expired'),
                        'redirect' => $loginUrl,
                    ], 401);
                }

                return redirect($loginUrl);
            }
        }

        if ($exception instanceof TokenMismatchException) {
            if (is_admin()) {
                $loginUrl = admin_route('login.show');
                $message  = __('auth.token_expired');

                if ($request->expectsJson()) {
                    return response()->json([
                        'code'     => 419,
                        'message'  => $message,
                        'redirect' => $loginUrl,
                    ], 419);
                }

                return redirect($loginUrl)->with('warning', $message);
            }
        }

        if ($exception instanceof PostTooLargeException
            && is_admin()
            && str_ends_with(trim($request->path(), '/'), 'shopify-products/import')
        ) {
            $translationKey = 'ShopifyProductTransfer::transfer.post_too_large';
            $message        = trans($translationKey);
            if ($message === $translationKey) {
                $message = '上传内容超过服务器限制，请缩小文件后重试。';
            }
            if ($request->expectsJson()) {
                return response()->json(['code' => 413, 'message' => $message], 413);
            }

            try {
                return redirect()->to(admin_route('shopify_product_transfer.index'))->with('warning', $message);
            } catch (Throwable) {
                // The route can be unavailable while a plugin is being
                // disabled. Preserve the correct HTTP status in that case.
                return response($message, 413);
            }
        }

        if ($request->expectsJson()) {
            // 处理权限验证异常
            if ($exception instanceof AuthorizationException) {
                return response()->json([
                    'code'    => 403,
                    'message' => trans('admin/common.no_permission'),
                ], 403);
            }

            // 处理表单验证异常
            if ($exception instanceof ValidationException) {
                return response()->json([
                    'code'    => 422,
                    'message' => $exception->getMessage(),
                    'errors'  => $exception->errors(),
                ], 422);
            }

            // 其他 Ajax 异常
            $statusCode = method_exists($exception, 'getStatusCode') ? $exception->getStatusCode() : 500;
            $message    = $exception->getMessage();

            // Runtime exception text frequently contains SQL, absolute paths,
            // class names or credentials. Production JSON responses must not
            // expose those details while the original exception remains in
            // the server log for operators.
            if ($statusCode >= 500 && ! config('app.debug')) {
                $message = '';
            }

            if (empty($message) || $message === 'Server Error') {
                $message = match ($statusCode) {
                    401     => __('auth.token_expired'),
                    403     => trans('admin/common.no_permission'),
                    404     => trans('admin/builder.text_no_data'),
                    default => '服务器错误，请联系管理员',
                };
            }

            return response()->json([
                'code'    => $statusCode,
                'message' => $message,
            ], $statusCode);
        }

        return parent::render($request, $exception);
    }
}
