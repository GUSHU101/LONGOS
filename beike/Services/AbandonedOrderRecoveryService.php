<?php

namespace Beike\Services;

use App\Mail\MailSenderProfileService;
use App\Saas\Models\Domain;
use App\Saas\Support\SensitiveValueRedactor;
use Beike\Mail\AbandonedOrderRecoveryMail;
use Beike\Models\AbandonedOrderRecovery;
use Beike\Models\AdminUser;
use Beike\Models\Cart;
use Beike\Models\CartProduct;
use Beike\Models\Discount;
use Beike\Models\Order;
use Beike\Models\ProductSku;
use Beike\Repositories\CartRepo;
use Beike\Repositories\DiscountRepo;
use Beike\Repositories\SettingRepo;
use Beike\Shop\Services\CartService;
use Exception;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AbandonedOrderRecoveryService
{
    public const SETTING_NAME = 'abandoned_order_recovery';

    public const CART_RECOVERY_KEY = 'abandoned_order_recovery_id';

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'delay_hours'      => 24,
            'cooldown_hours'   => 48,
            'max_attempts'     => 3,
            'link_days'        => 14,
            'subject'          => '{store_name}: your saved order {order_number}',
            'heading'          => 'Your order is still saved',
            'message'          => "Hi {customer_name},\n\nYou left before completing payment. We kept the available items from order {order_number} together so you can return without starting over.",
            'button_label'     => 'Return to secure checkout',
            'discount_message' => 'A recovery offer is ready for you. Use code {discount_code} at checkout.',
        ];
    }

    /** @return array<string, mixed> */
    public function settings(): array
    {
        $stored = system_setting('base.' . self::SETTING_NAME, []);

        return $this->normalizeSettings(is_array($stored) ? $stored : []);
    }

    /** @param array<string, mixed> $input */
    public function saveSettings(array $input): array
    {
        $settings = $this->normalizeSettings($input);
        SettingRepo::storeValue(self::SETTING_NAME, $settings);

        return $settings;
    }

    /** @param array<string, mixed> $filters */
    public function candidates(array $filters = []): LengthAwarePaginator
    {
        $threshold = now()->subHours($this->settings()['delay_hours']);
        $query     = Order::query()
            ->where('status', StateMachineService::UNPAID)
            ->where('created_at', '<=', $threshold)
            ->whereNotNull('email')
            ->where('email', '<>', '')
            ->whereHas('orderProducts')
            ->with([
                'orderProducts:id,order_id',
                'abandonedOrderRecoveries' => static function ($query): void {
                    $query->select([
                        'id', 'order_id', 'status', 'sent_at', 'failed_at',
                        'suppressed_at', 'created_at',
                    ])->latest('id');
                },
            ]);

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(static function ($query) use ($keyword): void {
                $query->where('number', 'like', '%' . $keyword . '%')
                    ->orWhere('customer_name', 'like', '%' . $keyword . '%')
                    ->orWhere('email', 'like', '%' . $keyword . '%');
            });
        }

        $orders = $query->orderByDesc('created_at')->paginate(20)->withQueryString();
        $orders->getCollection()->each(function (Order $order): void {
            $order->setAttribute('recovery_state', $this->deliveryState($order));
        });

        return $orders;
    }

    public function activeDiscountCodes(): Collection
    {
        return Discount::query()
            ->active()
            ->where('mode', 'code')
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{eligible: bool, code: string, label: string, attempts: int, available_at: ?string}
     */
    public function deliveryState(Order $order, ?array $settings = null): array
    {
        $settings   = $settings ?? $this->settings();
        $recoveries = $order->relationLoaded('abandonedOrderRecoveries')
            ? $order->abandonedOrderRecoveries
            : AbandonedOrderRecovery::query()->where('order_id', $order->id)->latest('id')->get();
        $attempts   = $recoveries->where('status', AbandonedOrderRecovery::STATUS_SENT)->count();
        $state      = [
            'eligible'     => false,
            'code'         => 'unknown',
            'label'        => trans('admin/abandoned_order_recovery.state_unknown'),
            'attempts'     => $attempts,
            'available_at' => null,
        ];

        if ($order->status !== StateMachineService::UNPAID) {
            return array_merge($state, ['code' => 'not_unpaid', 'label' => trans('admin/abandoned_order_recovery.state_not_unpaid')]);
        }
        if (! filter_var($order->email, FILTER_VALIDATE_EMAIL)) {
            return array_merge($state, ['code' => 'invalid_email', 'label' => trans('admin/abandoned_order_recovery.state_invalid_email')]);
        }
        $hasProducts = $order->relationLoaded('orderProducts')
            ? $order->orderProducts->isNotEmpty()
            : $order->orderProducts()->exists();
        if (! $hasProducts) {
            return array_merge($state, ['code' => 'no_products', 'label' => trans('admin/abandoned_order_recovery.state_no_products')]);
        }
        if (! $order->created_at || $order->created_at->gt(now()->subHours($settings['delay_hours']))) {
            $available = $order->created_at?->copy()->addHours($settings['delay_hours']);

            return array_merge($state, [
                'code'         => 'too_new',
                'label'        => trans('admin/abandoned_order_recovery.state_too_new'),
                'available_at' => $available?->toDateTimeString(),
            ]);
        }
        if ($recoveries->contains(static fn (AbandonedOrderRecovery $item): bool => $item->suppressed_at !== null)) {
            return array_merge($state, ['code' => 'suppressed', 'label' => trans('admin/abandoned_order_recovery.state_suppressed')]);
        }

        $inFlight = $recoveries->first(static fn (AbandonedOrderRecovery $item): bool => $item->status === AbandonedOrderRecovery::STATUS_SENDING
            && $item->created_at?->gt(now()->subMinutes(15))
        );
        if ($inFlight) {
            return array_merge($state, ['code' => 'sending', 'label' => trans('admin/abandoned_order_recovery.state_sending')]);
        }
        $recentFailure = $recoveries->first(static fn (AbandonedOrderRecovery $item): bool => $item->status === AbandonedOrderRecovery::STATUS_FAILED
            && $item->failed_at?->gt(now()->subMinutes(15))
        );
        if ($recentFailure) {
            return array_merge($state, ['code' => 'retry_wait', 'label' => trans('admin/abandoned_order_recovery.state_retry_wait')]);
        }
        if ($attempts >= $settings['max_attempts']) {
            return array_merge($state, ['code' => 'maxed', 'label' => trans('admin/abandoned_order_recovery.state_maxed')]);
        }

        /** @var AbandonedOrderRecovery|null $lastSent */
        $lastSent = $recoveries->first(static fn (AbandonedOrderRecovery $item): bool => $item->status === AbandonedOrderRecovery::STATUS_SENT && $item->sent_at !== null
        );
        if ($lastSent) {
            $available = $lastSent->sent_at->copy()->addHours($settings['cooldown_hours']);
            if ($available->isFuture()) {
                return array_merge($state, [
                    'code'         => 'cooldown',
                    'label'        => trans('admin/abandoned_order_recovery.state_cooldown'),
                    'available_at' => $available->toDateTimeString(),
                ]);
            }
        }

        return [
            'eligible'     => true,
            'code'         => 'eligible',
            'label'        => trans('admin/abandoned_order_recovery.state_eligible'),
            'attempts'     => $attempts,
            'available_at' => null,
        ];
    }

    public function send(Order $order, ?int $discountId, ?AdminUser $actor): AbandonedOrderRecovery
    {
        $settings = $this->settings();
        $discount = $this->resolveDiscount($discountId);

        [$recovery, $accessToken, $template] = DB::transaction(function () use ($order, $discount, $settings, $actor): array {
            /** @var Order $locked */
            $locked = Order::query()->with('orderProducts')->lockForUpdate()->findOrFail($order->id);
            $state  = $this->deliveryState($locked, $settings);
            if (! $state['eligible']) {
                throw ValidationException::withMessages([
                    'orders' => [trans('admin/abandoned_order_recovery.send_blocked', ['reason' => $state['label']])],
                ]);
            }

            $deliveryId  = 'recovery:' . $locked->id . ':' . Str::uuid();
            $accessToken = $this->tokenForDeliveryId($deliveryId);
            $category    = $discount ? MailSenderProfileService::MARKETING : MailSenderProfileService::ORDERS;
            $template    = $this->renderTemplate($locked, $discount, $settings);

            $retryBefore = now()->subMinutes(15);
            $failedQuery = AbandonedOrderRecovery::query()
                ->where('order_id', $locked->id)
                ->where(static function ($query) use ($retryBefore): void {
                    $query->where('status', AbandonedOrderRecovery::STATUS_FAILED)
                        ->orWhere(static function ($query) use ($retryBefore): void {
                            // If PHP stopped after the relay accepted the
                            // message but before the row was marked sent,
                            // retry the same delivery ID. The relay can then
                            // return its idempotent receipt instead of
                            // delivering a second recovery message.
                            $query->where('status', AbandonedOrderRecovery::STATUS_SENDING)
                                ->where('created_at', '<=', $retryBefore);
                        });
                })
                ->where('expires_at', '>', now())
                ->when(
                    $discount,
                    static fn ($query) => $query->where('discount_id', $discount->id),
                    static fn ($query) => $query->whereNull('discount_id'),
                )
                ->latest('id')
                ->lockForUpdate();
            /** @var AbandonedOrderRecovery|null $failedRecovery */
            $failedRecovery = $failedQuery->first();
            if ($failedRecovery) {
                $failedToken = $this->tokenForDeliveryId($failedRecovery->delivery_id);
                if (hash_equals($failedRecovery->access_token_hash, hash('sha256', $failedToken))) {
                    $template = $this->templateFromRecovery($failedRecovery);
                    $failedRecovery->forceFill([
                        'admin_user_id'      => $actor?->id,
                        'status'             => AbandonedOrderRecovery::STATUS_SENDING,
                        'transport_attempts' => $failedRecovery->transport_attempts + 1,
                        'failed_at'          => null,
                        'failure_message'    => null,
                    ])->save();

                    return [$failedRecovery, $failedToken, $template];
                }
            }

            $recovery    = AbandonedOrderRecovery::query()->create([
                'order_id'          => $locked->id,
                'admin_user_id'     => $actor?->id,
                'discount_id'       => $discount?->id,
                'recipient_email'   => strtolower(trim((string) $locked->email)),
                'delivery_id'       => $deliveryId,
                'access_token_hash' => hash('sha256', $accessToken),
                'status'            => AbandonedOrderRecovery::STATUS_SENDING,
                'sender_category'   => $category,
                'subject'           => $template['subject'],
                'body'              => $template['message'],
                'discount_code'     => $discount?->code,
                'expires_at'        => now()->addDays($settings['link_days']),
                'metadata'          => [
                    'heading'          => $template['heading'],
                    'button_label'     => $template['button_label'],
                    'discount_message' => $template['discount_message'],
                    'order_total'      => $locked->total_format,
                    'order_locale'     => $locked->locale,
                ],
            ]);

            return [$recovery, $accessToken, $template];
        }, 3);

        $recoveryUrl = $this->signedRecoveryUrl($accessToken, $recovery->expires_at);

        try {
            $mailable = new AbandonedOrderRecoveryMail($order, $template, $discount, $recoveryUrl);
            app(MailSenderProfileService::class)->apply($mailable, $recovery->sender_category, $recovery->delivery_id);
            $recipientName = mb_substr(trim((string) preg_replace('/[\r\n\x00]+/', ' ', (string) $order->customer_name)), 0, 200);
            Mail::to($recovery->recipient_email, $recipientName ?: null)
                ->locale($order->locale ?: app()->getLocale())
                ->send($mailable);
            $recovery->forceFill([
                'status'          => AbandonedOrderRecovery::STATUS_SENT,
                'sent_at'         => now(),
                'failed_at'       => null,
                'failure_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $safeMessage = Str::limit(SensitiveValueRedactor::redact($exception->getMessage()), 1500, '');
            $recovery->forceFill([
                'status'          => AbandonedOrderRecovery::STATUS_FAILED,
                'failed_at'       => now(),
                'failure_message' => $safeMessage,
            ])->save();
            Log::error('Abandoned order recovery email failed.', [
                'recovery_id' => $recovery->id,
                'order_id'    => $order->id,
                'exception'   => $exception::class,
            ]);

            throw new RuntimeException(trans('admin/abandoned_order_recovery.send_failed'));
        }

        return $recovery->refresh();
    }

    /** @param array<string, mixed> $settings */
    public function preview(Order $order, ?int $discountId, array $settings): string
    {
        $discount = $this->resolveDiscount($discountId);
        $template = $this->renderTemplate($order, $discount, $this->normalizeSettings($settings));

        return view('mails.abandoned_order_recovery', [
            'order'       => $order,
            'template'    => $template,
            'discount'    => $discount,
            'recoveryUrl' => shop_route('checkout.index'),
        ])->render();
    }

    public function findPublic(string $token): ?AbandonedOrderRecovery
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            return null;
        }

        return AbandonedOrderRecovery::query()
            ->with(['order.orderProducts.productSku.product'])
            ->where('access_token_hash', hash('sha256', $token))
            ->where('status', AbandonedOrderRecovery::STATUS_SENT)
            ->where('expires_at', '>', now())
            ->first();
    }

    public function signedRecoveryUrl(string $token, mixed $expiresAt): string
    {
        if (! preg_match('/^[A-Za-z0-9]{64}$/', $token)) {
            throw new RuntimeException('Invalid recovery token.');
        }

        // Sign the path rather than the host. A tenant may promote a new
        // primary domain while an already delivered recovery is still valid.
        $relativeUrl = URL::temporarySignedRoute(
            'shop.order_recovery.show',
            $expiresAt,
            ['token' => $token],
            absolute: false,
        );

        $site = current_site();
        if ((bool) config('saas.enabled') && $site) {
            // Prefer the platform-issued tenant host for operational links.
            // A merchant can replace a custom primary domain without breaking
            // recovery emails that are still inside their validity window.
            $platformDomain = $site->domains()
                ->routable()
                ->where('origin', Domain::ORIGIN_PLATFORM)
                ->orderByDesc('is_primary')
                ->first();
            if ($platformDomain) {
                return rtrim("{$platformDomain->scheme}://{$platformDomain->host}", '/') . '/' . ltrim($relativeUrl, '/');
            }
        }

        return url($relativeUrl);
    }

    public function markOpened(AbandonedOrderRecovery $recovery): void
    {
        if (! $recovery->opened_at) {
            $recovery->forceFill(['opened_at' => now()])->save();
        }
    }

    /**
     * Restore currently available SKU quantities without double-incrementing
     * when a customer clicks the same email more than once.
     *
     * @return array{restored: int, unavailable: int, discount_applied: bool}
     */
    public function restoreToCart(AbandonedOrderRecovery $recovery): array
    {
        return DB::transaction(function () use ($recovery): array {
            $lockedRecovery = AbandonedOrderRecovery::query()->lockForUpdate()->findOrFail($recovery->id);
            $order          = Order::query()->lockForUpdate()->find($lockedRecovery->order_id);
            $order?->load(['orderProducts.productSku.product']);
            $lockedRecovery->setRelation('order', $order);

            return $this->restoreLocked($lockedRecovery);
        }, 3);
    }

    /** @return array{restored: int, unavailable: int, discount_applied: bool} */
    private function restoreLocked(AbandonedOrderRecovery $recovery): array
    {
        $order = $recovery->order;
        if (! $order || $order->status !== StateMachineService::UNPAID || $recovery->suppressed_at) {
            throw ValidationException::withMessages(['recovery' => [trans('shop/order_recovery.unavailable')]]);
        }

        $customer = current_customer();
        if ($customer && $order->customer_id && (int) $customer->id !== (int) $order->customer_id) {
            throw ValidationException::withMessages(['recovery' => [trans('shop/order_recovery.unavailable')]]);
        }

        $cart        = CartRepo::createCart($customer);
        $restored    = 0;
        $unavailable = 0;
        $plans       = [];
        foreach ($order->orderProducts->groupBy('product_sku') as $orderProducts) {
            $orderProduct = $orderProducts->first();
            /** @var ProductSku|null $sku */
            $sku = $orderProduct->productSku;
            if (! $sku || ! $sku->active || ! $sku->product?->active) {
                $unavailable++;

                continue;
            }

            $orderedQuantity = $orderProducts->sum(static fn ($item): int => max(0, (int) $item->quantity));
            // An unpaid order may already have reserved this stock. Include
            // only its own reservation when planning, then release it before
            // CartService performs its normal live-stock validation.
            $availableAfterRelease = (int) $sku->quantity + ($order->stock_reserved ? $orderedQuantity : 0);
            $quantity              = min(max(1, $orderedQuantity), $availableAfterRelease);
            if ($quantity < 1) {
                $unavailable++;

                continue;
            }
            $plans[] = ['sku' => $sku, 'quantity' => $quantity];
        }

        if ($plans === []) {
            throw ValidationException::withMessages(['recovery' => [trans('shop/order_recovery.no_available_products')]]);
        }

        // Rebuilding the order is the only way to apply a checkout discount.
        // Cancel the original without a second customer email and release its
        // reservation in the same transaction as the cart restoration.
        StateMachineService::getInstance($order)->changeStatus(
            StateMachineService::CANCELLED,
            trans('shop/order_recovery.cancelled_for_restore'),
            false,
        );

        foreach ($plans as $plan) {
            /** @var ProductSku $sku */
            $sku      = $plan['sku']->refresh();
            $quantity = min((int) $plan['quantity'], (int) $sku->quantity);
            if ($quantity < 1) {
                $unavailable++;

                continue;
            }
            $existing = $this->cartItem($customer?->id, $sku->sku);

            try {
                if ($existing) {
                    $target = min(max((int) $existing->quantity, $quantity), (int) $sku->quantity);
                    CartService::updateQuantity($customer, $existing->id, $target);
                } else {
                    CartService::add($sku, $quantity, $customer);
                }
                $restored++;
            } catch (Exception $exception) {
                if ($exception instanceof QueryException) {
                    throw $exception;
                }
                $unavailable++;
            }
        }

        if ($restored < 1) {
            throw ValidationException::withMessages(['recovery' => [trans('shop/order_recovery.no_available_products')]]);
        }

        $discountApplied = false;
        $extra           = is_array($cart->extra) ? $cart->extra : [];
        if ($recovery->discount_code && DiscountRepo::activeCode($recovery->discount_code)) {
            $extra['discount_code'] = $recovery->discount_code;
            $discountApplied        = true;
        }
        $extra[self::CART_RECOVERY_KEY] = $recovery->id;
        $cart->forceFill(['extra' => $extra])->save();
        $cart->extra = $extra;

        $recovery->forceFill(['restored_at' => $recovery->restored_at ?: now()])->save();

        return [
            'restored'         => $restored,
            'unavailable'      => $unavailable,
            'discount_applied' => $discountApplied,
        ];
    }

    public function suppress(AbandonedOrderRecovery $recovery): void
    {
        $recovery->forceFill(['suppressed_at' => $recovery->suppressed_at ?: now()])->save();
    }

    public function markConverted(Cart $cart, Order $newOrder): void
    {
        $extra      = is_array($cart->extra) ? $cart->extra : json_decode((string) $cart->extra, true);
        $recoveryId = (int) data_get($extra, self::CART_RECOVERY_KEY, 0);
        if ($recoveryId < 1) {
            return;
        }

        AbandonedOrderRecovery::query()
            ->whereKey($recoveryId)
            ->where('status', AbandonedOrderRecovery::STATUS_SENT)
            ->whereNull('converted_at')
            ->update([
                'converted_order_id' => $newOrder->id,
                'converted_at'       => now(),
                'updated_at'         => now(),
            ]);
    }

    /** @param array<string, mixed> $input */
    public function normalizeSettings(array $input): array
    {
        $settings = array_merge(self::defaults(), $input);

        return [
            'delay_hours'      => min(720, max(1, (int) $settings['delay_hours'])),
            'cooldown_hours'   => min(720, max(1, (int) $settings['cooldown_hours'])),
            'max_attempts'     => min(10, max(1, (int) $settings['max_attempts'])),
            'link_days'        => min(90, max(1, (int) $settings['link_days'])),
            'subject'          => $this->cleanTemplateLine($settings['subject'], 180, self::defaults()['subject']),
            'heading'          => $this->cleanTemplateLine($settings['heading'], 180, self::defaults()['heading']),
            'message'          => $this->cleanTemplateText($settings['message'], 2000, self::defaults()['message']),
            'button_label'     => $this->cleanTemplateLine($settings['button_label'], 64, self::defaults()['button_label']),
            'discount_message' => $this->cleanTemplateText($settings['discount_message'], 300, self::defaults()['discount_message']),
        ];
    }

    /** @return array<string, string> */
    private function renderTemplate(Order $order, ?Discount $discount, array $settings): array
    {
        $variables = [
            '{store_name}'    => site_brand_name(),
            '{customer_name}' => trim((string) $order->customer_name) ?: trans('shop/order_recovery.customer'),
            '{order_number}'  => (string) $order->number,
            '{order_total}'   => (string) $order->total_format,
            '{discount_code}' => (string) ($discount?->code ?? ''),
        ];

        return [
            'subject'          => $this->cleanTemplateLine(strtr($settings['subject'], $variables), 255, self::defaults()['subject']),
            'heading'          => strtr($settings['heading'], $variables),
            'message'          => strtr($settings['message'], $variables),
            'button_label'     => strtr($settings['button_label'], $variables),
            'discount_message' => $discount ? strtr($settings['discount_message'], $variables) : '',
        ];
    }

    private function resolveDiscount(?int $discountId): ?Discount
    {
        if (! $discountId) {
            return null;
        }

        $discount = Discount::query()->active()->where('mode', 'code')->find($discountId);
        if (! $discount) {
            throw ValidationException::withMessages([
                'discount_id' => [trans('admin/abandoned_order_recovery.invalid_discount')],
            ]);
        }

        return $discount;
    }

    /** @return array{subject: string, heading: string, message: string, button_label: string, discount_message: string} */
    private function templateFromRecovery(AbandonedOrderRecovery $recovery): array
    {
        $metadata = is_array($recovery->metadata) ? $recovery->metadata : [];

        return [
            'subject'          => $recovery->subject,
            'heading'          => (string) ($metadata['heading'] ?? self::defaults()['heading']),
            'message'          => $recovery->body,
            'button_label'     => (string) ($metadata['button_label'] ?? self::defaults()['button_label']),
            'discount_message' => (string) ($metadata['discount_message'] ?? ''),
        ];
    }

    private function tokenForDeliveryId(string $deliveryId): string
    {
        $appKey = (string) config('app.key');
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is required for recovery links.');
        }

        return hash_hmac('sha256', $deliveryId, hash('sha256', $appKey, true));
    }

    private function cartItem(?int $customerId, string $sku): ?CartProduct
    {
        return CartProduct::query()
            ->when($customerId, static fn ($query) => $query->where('customer_id', $customerId))
            ->when(! $customerId, static fn ($query) => $query->where('session_id', get_session_id()))
            ->where('product_sku', $sku)
            ->first();
    }

    private function cleanTemplateLine(mixed $value, int $limit, string $fallback): string
    {
        $value = trim((string) $value);
        $value = preg_replace('/[\r\n\x00]+/', ' ', $value) ?? '';

        return $value === '' ? $fallback : mb_substr($value, 0, $limit);
    }

    private function cleanTemplateText(mixed $value, int $limit, string $fallback): string
    {
        $value = trim(str_replace("\0", '', (string) $value));

        return $value === '' ? $fallback : mb_substr($value, 0, $limit);
    }
}
