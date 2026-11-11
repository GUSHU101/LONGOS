<?php

namespace Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugin\LongosInstantTranslate\Services\StorefrontPolicy;

class InstantTranslateStorefrontPolicyTest extends TestCase
{
    #[DataProvider('allowedRoutes')]
    public function test_safe_storefront_get_routes_are_allowed(string $name): void
    {
        self::assertTrue((new StorefrontPolicy)->allows($this->request('GET', $name)));
    }

    public static function allowedRoutes(): array
    {
        return [
            'home'     => ['shop.home.index'],
            'cart'     => ['shop.carts.index'],
            'checkout' => ['shop.checkout.index'],
        ];
    }

    #[DataProvider('blockedRoutes')]
    public function test_sensitive_or_mutating_routes_are_blocked(string $method, string $name): void
    {
        self::assertFalse((new StorefrontPolicy)->allows($this->request($method, $name)));
    }

    public static function blockedRoutes(): array
    {
        return [
            'checkout mutation' => ['PUT', 'shop.checkout.update'],
            'checkout success'  => ['GET', 'shop.checkout.success'],
            'account'           => ['GET', 'shop.account.index'],
            'order'             => ['GET', 'shop.orders.show'],
        ];
    }

    private function request(string $method, string $name): Request
    {
        $request = Request::create('/test', $method);
        $route   = new Route([$method], '/test', static fn () => null);
        $route->name($name);
        $request->setRouteResolver(static fn (): Route => $route);

        return $request;
    }
}
