<?php

declare(strict_types=1);

namespace Shopper\Api\Actions;

use LogicException;
use Shopper\Api\Exceptions\PaymentNotCapturedException;
use Shopper\Cart\Actions\CreateOrderFromCartAction;
use Shopper\Cart\CartManager;
use Shopper\Cart\Exceptions\CartCompletedException;
use Shopper\Cart\Exceptions\DiscountLimitReachedException;
use Shopper\Cart\Exceptions\InsufficientStockException;
use Shopper\Cart\Exceptions\MissingPriceException;
use Shopper\Cart\Exceptions\PriceChangedException;
use Shopper\Cart\Exceptions\PromotionUnavailableException;
use Shopper\Cart\Exceptions\QuantityRuleViolationException;
use Shopper\Cart\Models\Cart;
use Shopper\Cart\Pipelines\CartPipelineContext;
use Shopper\Core\Contracts\PaymentSessionGateway;
use Shopper\Core\Contracts\StockTransactionRunner;
use Shopper\Core\Exceptions\CampaignBudgetExceededException;
use Shopper\Core\Exceptions\PaymentProviderUnavailableException;
use Shopper\Core\Models\Contracts\Order;
use Shopper\Http\Enum\ErrorCode;
use Shopper\Http\Exceptions\ApiValidationException;
use Shopper\Payment\Actions\SettlePayment;
use Shopper\Payment\Contracts\CompensationStore;
use Shopper\Payment\Enum\TransactionStatus;
use Shopper\Payment\Enum\TransactionType;
use Shopper\Payment\Jobs\SyncPendingPaymentJob;
use Shopper\Payment\Models\PaymentTransaction;
use Shopper\Payment\PaymentManager;
use Shopper\Payment\PaymentReferenceLock;
use Throwable;

final readonly class CompleteCartAction
{
    public function __construct(
        private RefreshCartShippingAction $refreshShipping,
        private CartManager $cartManager,
        private CreateOrderFromCartAction $createOrderFromCart,
        private SettlePayment $settle,
        private PaymentSessionGateway $paymentSessions,
    ) {}

    /**
     * Turn the cart into an order. Placement is idempotent: completing a cart
     * that already produced an order answers with that order instead of
     * failing or duplicating it. The shipping price is re-quoted from the
     * stored option id right before the order freezes it, so a stale snapshot
     * can never be charged.
     */
    public function execute(Cart $cart): Order
    {
        if ($cart->getConnection()->transactionLevel() > 0) {
            throw new LogicException('A cart is completed outside any open transaction: its compensations must never sit under a foreign rollback.');
        }

        if ($cart->isCompleted()) {
            /** @var Order $order */
            $order = $cart->order()->firstOrFail();

            return $order;
        }

        return $this->cartManager->withPaymentSessionLock($cart, fn (): Order => $this->completeUnderPaymentLock($cart->unsetRelations()->refresh()));
    }

    /**
     * A provider payment is completed under the lock of its reference, the
     * one every refund of that payment takes: a payment whose refund was
     * decided is never turned into an order.
     */
    private function completeUnderPaymentLock(Cart $cart): Order
    {
        if ($cart->isCompleted()) {
            /** @var Order $order */
            $order = $cart->order()->firstOrFail();

            return $order;
        }

        $reference = $cart->payment_session['reference'] ?? null;

        if (! $cart->holdsProviderPaymentSession() || ! is_string($reference)) {
            return $this->complete($cart);
        }

        return PaymentReferenceLock::run($reference, fn (): Order => $this->complete($cart));
    }

    /**
     * The preparation (email, shipping re-quote with a possible release of
     * the payment session, the payment read) runs once, committed, before
     * the stock runner. Only the order transaction is repeated, over a cart
     * loaded again by key; the compensations of a business refusal run
     * after the rollback, outside the runner, over a freshly loaded cart.
     */
    private function complete(Cart $cart): Order
    {
        $reference = $cart->holdsProviderPaymentSession() ? ($cart->payment_session['reference'] ?? null) : null;

        if (is_string($reference) && $this->compensations()?->compensated($reference)) {
            throw ApiValidationException::withCode(ErrorCode::PaymentReleased, [
                'cart' => __('shopper-api::messages.payment.released'),
            ]);
        }

        $cart->load(['zone.currency', 'zone.carriers', $cart->payment_session === null ? 'lines.purchasable' : 'lines.purchasable.prices.currency', 'addresses.country', 'customer']);

        if ($cart->lines->isEmpty()) {
            throw ApiValidationException::withCode(ErrorCode::CartEmpty, [
                'cart' => __('shopper-api::messages.cart.empty'),
            ]);
        }

        if (! $cart->payment_method_id) {
            throw ApiValidationException::withCode(ErrorCode::PaymentMethodRequired, [
                'payment_method' => __('shopper-api::messages.payment.method_required'),
            ]);
        }

        $this->ensureEmail($cart);

        $session = $cart->payment_session;
        $checked = false;
        $collected = null;
        $isCollected = function () use ($session, &$checked, &$collected): ?bool {
            if (! $checked) {
                $collected = $session === null ? false : $this->paymentSessions->collected($session);
                $checked = true;
            }

            return $collected;
        };

        $capturedAmount = null;

        if ($session !== null && $cart->holdsProviderPaymentSession() && config('shopper.api.complete_requires_capture', false)) {
            $capturedAmount = $this->capturedAmount($cart, $session);
            $checked = true;
            $collected = true;
        }

        $this->refreshShipping->execute($cart, $isCollected);

        if ($session !== null && ($this->cartManager->needsRevalidation($cart) || ($cart->holdsProviderPaymentSession() && $cart->promotions()->where('computed_amount', '>', 0)->exists()))) {
            $isCollected();
        }

        $cartClass = $cart::class;
        $cartId = $cart->getKey();
        $preparedChecked = $checked;
        $preparedCollected = $collected;

        try {
            $order = resolve(StockTransactionRunner::class)->retry(
                static fn (): Order => app(self::class)->attempt($cartClass, $cartId, $session, $preparedChecked, $preparedCollected, $capturedAmount),
            );
        } catch (CartCompletedException) {
            /** @var Order $order */
            $order = $this->freshCart($cartClass, $cartId)->order()->firstOrFail();

            return $order;
        } catch (CampaignBudgetExceededException) {
            $this->reopenPromotions($this->freshCart($cartClass, $cartId));

            throw ApiValidationException::withCode(ErrorCode::PromotionBudgetReached, [
                'promotion' => __('shopper-cart::messages.discount.campaign_budget_reached'),
            ]);
        } catch (PromotionUnavailableException $exception) {
            $this->reopenPromotions($this->freshCart($cartClass, $cartId));

            throw ApiValidationException::withCode(ErrorCode::PromotionNotApplicable, [
                'promotion' => $exception->getMessage(),
            ]);
        } catch (DiscountLimitReachedException $exception) {
            $this->reopenPromotions($this->freshCart($cartClass, $cartId));

            throw ApiValidationException::withCode(ErrorCode::PromotionLimitReached, [
                'promotion' => $exception->getMessage(),
            ]);
        } catch (InsufficientStockException $exception) {
            if ($session !== null && $isCollected() !== false && $this->paymentSessions->refund([...$session, 'cause' => 'stock_insufficient'])) {
                $this->cartManager->setPaymentSession($this->freshCart($cartClass, $cartId), null);

                throw ApiValidationException::withCode(ErrorCode::PaymentReleased, [
                    'cart' => __('shopper-api::messages.payment.released'),
                ]);
            }

            throw ApiValidationException::withCode(ErrorCode::StockInsufficient, [
                'cart' => $exception->getMessage(),
            ]);
        } catch (MissingPriceException $exception) {
            throw ApiValidationException::withCode(ErrorCode::PriceMissing, ['cart' => $exception->getMessage()]);
        } catch (QuantityRuleViolationException $exception) {
            throw ApiValidationException::withCode(ErrorCode::QuantityRuleViolated, ['cart' => $exception->getMessage()], $exception->context());
        } catch (PriceChangedException $exception) {
            $fresh = $this->freshCart($cartClass, $cartId);

            try {
                $this->cartManager->reprice($fresh);
            } catch (CartCompletedException) {
                /** @var Order $order */
                $order = $fresh->refresh()->order()->firstOrFail();

                return $order;
            }

            throw ApiValidationException::withCode(ErrorCode::PriceChanged, [
                'cart' => $exception->getMessage(),
            ]);
        }

        $this->settlePayment($this->freshCart($cartClass, $cartId), $order);

        return $order;
    }

    /**
     * One attempt of the order transaction. Everything it reads is loaded
     * again by key and the three checks are built over this cart with
     * their own flags, so a repeated attempt never sees what a rolled back
     * one left in memory. The payment read of the preparation is reused;
     * a read the preparation did not need is made lazily, once per attempt.
     *
     * @param  class-string<Cart>  $cartClass
     * @param  array<string, mixed>|null  $session
     */
    private function attempt(string $cartClass, int|string $cartId, ?array $session, bool $checked, ?bool $collected, ?int $capturedAmount): Order
    {
        $cart = $this->freshCart($cartClass, $cartId);
        $cart->load(['zone.currency', 'zone.carriers', $cart->payment_session === null ? 'lines.purchasable' : 'lines.purchasable.prices.currency', 'addresses.country', 'customer']);

        $isCollected = function () use ($session, &$checked, &$collected): ?bool {
            if (! $checked) {
                $collected = $session === null ? false : $this->paymentSessions->collected($session);
                $checked = true;
            }

            return $collected;
        };

        return $this->createOrderFromCart->execute(
            $cart,
            function (CartPipelineContext $context) use ($cart, $capturedAmount): void {
                $this->guardPaymentSession($cart, $context->total);

                if ($capturedAmount !== null && $capturedAmount !== $context->total) {
                    throw new PaymentNotCapturedException('captured');
                }
            },
            fn (Order $order) => $this->recordInitiatedPayment($cart, $order),
            fn (): bool => $cart->payment_session === $session && $isCollected() !== false,
        );
    }

    /**
     * The order is only placed over a payment the provider reports captured,
     * in the cart currency; the captured amount is checked against the cart
     * total inside the order transaction. Read before any side effect of
     * the completion, so a refusal leaves the cart as it was.
     *
     * @param  array<string, mixed>  $session
     */
    private function capturedAmount(Cart $cart, array $session): int
    {
        try {
            $payment = app(PaymentManager::class)->driver($session['driver'])->retrievePayment((string) $session['reference']);
        } catch (Throwable $exception) {
            throw new PaymentProviderUnavailableException($exception);
        }

        $currency = $payment->data['currency'] ?? null;

        if (
            $payment->status !== 'captured'
            || $payment->amount === null
            || ! is_string($currency)
            || mb_strtoupper($currency) !== mb_strtoupper((string) $cart->currency_code)
        ) {
            throw new PaymentNotCapturedException($payment->status);
        }

        return $payment->amount;
    }

    private function compensations(): ?CompensationStore
    {
        $store = config('shopper.payment.compensation_store');

        return is_string($store) && $store !== '' ? app($store) : null;
    }

    /**
     * @param  class-string<Cart>  $cartClass
     */
    private function freshCart(string $cartClass, int|string $cartId): Cart
    {
        /** @var Cart $cart */
        $cart = $cartClass::query()->findOrFail($cartId);

        return $cart;
    }

    /**
     * The browser confirms the payment before the storefront completes the
     * cart, so the provider often speaks before the order exists. Once the
     * order and its initiate transaction are committed, the events that
     * landed early are replayed, and a provider check is queued for a
     * payment still pending: the response never waits on the provider, and
     * placement is never blocked by the settlement. The order is the durable
     * anchor, the scheduled reconciliation is the safety net.
     */
    private function settlePayment(Cart $cart, Order $order): void
    {
        $reference = $cart->payment_session['reference'] ?? null;

        if (! is_string($reference)) {
            return;
        }

        try {
            $this->settle->execute($reference);

            if (config('shopper.payment.reconciliation.pull_on_completion', true)) {
                SyncPendingPaymentJob::dispatch($order->getKey(), $reference)
                    ->onQueue(config('shopper.payment.reconciliation.queue'));
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function reopenPromotions(Cart $cart): void
    {
        $this->cartManager->releasePaymentSession($cart);
        $this->cartManager->calculate($cart->unsetRelations());
    }

    /**
     * Freeze the contact email on the cart so the order can be confirmed and
     * looked up. A customer cart falls back to the customer's own email; a
     * guest cart must carry one, set at creation or with the checkout address.
     */
    private function ensureEmail(Cart $cart): void
    {
        if ($cart->email !== null) {
            return;
        }

        $email = $cart->customer?->getAttribute('email');

        if (! is_string($email)) {
            throw ApiValidationException::withCode(ErrorCode::EmailRequired, [
                'email' => __('shopper-api::messages.cart.email_required'),
            ]);
        }

        $this->cartManager->setEmail($cart, $email);
    }

    private function guardPaymentSession(Cart $cart, int $total): void
    {
        $session = $cart->payment_session;

        if (! $session || ! isset($session['amount'])) {
            if (($cart->paymentMethod->driver ?? 'manual') !== 'manual') {
                throw ApiValidationException::withCode(ErrorCode::PaymentSessionRequired, [
                    'payment_session' => __('shopper-api::messages.payment.session_required'),
                ]);
            }

            return;
        }

        if (($session['driver'] ?? 'manual') === 'manual' && ($cart->paymentMethod->driver ?? 'manual') === 'manual') {
            return;
        }

        if (
            $session['amount'] !== $total
            || (isset($session['currency']) && $session['currency'] !== $cart->currency_code)
        ) {
            throw ApiValidationException::withCode(ErrorCode::PaymentSessionMismatch, [
                'payment_session' => __('shopper-api::messages.payment.session_mismatch'),
            ]);
        }
    }

    /**
     * The intent opened against the cart is journalized on the order it paid
     * for, so the webhook that confirms or fails the payment later finds the
     * order through the same reference.
     */
    private function recordInitiatedPayment(Cart $cart, Order $order): void
    {
        $session = $cart->payment_session;

        if (! $session || ! isset($session['reference'])) {
            return;
        }

        PaymentTransaction::query()->create([
            'order_id' => $order->id,
            'payment_method_id' => $cart->payment_method_id,
            'driver' => $session['driver'] ?? 'manual',
            'type' => TransactionType::Initiate,
            'status' => TransactionStatus::Pending,
            'amount' => $order->price_amount,
            'currency_code' => $order->currency_code,
            'reference' => $session['reference'],
        ]);
    }
}
