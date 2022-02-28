<?php

namespace Gloudemans\Shoppingcart;

use Closure;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Database\Connection;
use Illuminate\Session\SessionManager;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Traits\Macroable;
use Illuminate\Contracts\Events\Dispatcher;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Gloudemans\Shoppingcart\Contracts\InstanceIdentifier;
use Gloudemans\Shoppingcart\Exceptions\InvalidRowIDException;
use Gloudemans\Shoppingcart\Exceptions\UnknownModelException;
use Gloudemans\Shoppingcart\Exceptions\CartAlreadyStoredException;

class Cart
{
    use Macroable;
    use FormatsNumbers;

    const DEFAULT_INSTANCE = 'default';

    /**
     * Instance of the session manager.
     *
     * @var \Illuminate\Session\SessionManager
     */
    private SessionManager $session;

    /**
     * Instance of the event dispatcher.
     *
     * @var \Illuminate\Contracts\Events\Dispatcher
     */
    private Dispatcher $events;

    /**
     * Holds the current cart instance.
     *
     * @var string
     */
    private $instance;

    /**
     * Holds the creation date of the cart.
     *
     * @var mixed
     */
    private $createdAt;

    /**
     * Holds the update date of the cart.
     *
     * @var mixed
     */
    private $updatedAt;

    /**
     * Defines the discount percentage.
     *
     * @var float
     */
    private float $discount = 0;

    /**
     * Defines the tax rate.
     *
     * @var float
     */
    private float $taxRate = 0;

    /**
     * Cart constructor.
     *
     * @param \Illuminate\Session\SessionManager      $session
     * @param \Illuminate\Contracts\Events\Dispatcher $events
     */
    public function __construct(SessionManager $session, Dispatcher $events)
    {
        $this->session = $session;
        $this->events = $events;
        $this->taxRate = config('cart.tax', 0);

        $this->instance(self::DEFAULT_INSTANCE);
    }

    /**
     * Set the current cart instance.
     *
     * @param string|null $instance
     *
     * @return \Gloudemans\Shoppingcart\Cart
     */
    public function instance($instance = null): Cart
    {
        $instance = $instance ?: self::DEFAULT_INSTANCE;

        if ($instance instanceof InstanceIdentifier) {
            $this->discount = $instance->getInstanceGlobalDiscount();
            $instance = $instance->getInstanceIdentifier();
        }

        $this->instance = 'cart.'.$instance;

        return $this;
    }

    /**
     * Get the current cart instance.
     *
     * @return string
     */
    public function currentInstance(): string
    {
        return str_replace('cart.', '', $this->instance);
    }

    /**
     * Add an item to the cart.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param float     $weight
     * @param array     $options
     *
     * @return array|\Gloudemans\Shoppingcart\CartItem
     */
    public function add($id, $name = null, $qty = null, $price = null, $weight = 0, array $options = []): array|CartItem
    {
        if ($this->isMulti($id)) {
            return array_map(
                function ($item) {
                    return $this->add($item);
                },
                $id
            );
        }

        $cartItem = $this->createCartItem($id, $name, $qty, $price, $weight, $options);

        return $this->addCartItem($cartItem);
    }

    /**
     * Add an item to the cart.
     *
     * @param \Gloudemans\Shoppingcart\CartItem $item          Item to add to the Cart
     * @param bool                              $keepDiscount  Keep the discount rate of the Item
     * @param bool                              $keepTax       Keep the Tax rate of the Item
     * @param bool                              $dispatchEvent
     *
     * @return \Gloudemans\Shoppingcart\CartItem The CartItem
     */
    public function addCartItem($item, $keepDiscount = false, $keepTax = false, $dispatchEvent = true): CartItem
    {
        if (!$keepDiscount) {
            $item->setDiscountRate($this->discount);
        }

        if (!$keepTax) {
            $item->setTaxRate($this->taxRate);
        }

        $content = $this->getContent();

        if ($content->has($item->rowId)) {
            $item->qty += $content->get($item->rowId)->qty;
        }

        $content->put($item->rowId, $item);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.adding', $item);
        }

        $this->session->put($this->instance, $content);

        if ($dispatchEvent) {
            $this->events->dispatch('cart.added', $item);
        }

        return $item;
    }

    /**
     * Update the cart item with the given rowId.
     *
     * @param string $rowId
     * @param mixed  $qty
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function update($rowId, $qty): ?CartItem
    {
        $cartItem = $this->get($rowId);

        if ($qty instanceof Buyable) {
            $cartItem->updateFromBuyable($qty);
        } elseif (is_array($qty)) {
            $cartItem->updateFromArray($qty);
        } else {
            $cartItem->qty = $qty;
        }

        $content = $this->getContent();

        if ($rowId !== $cartItem->rowId) {
            $itemOldIndex = $content->keys()->search($rowId);

            $content->pull($rowId);

            if ($content->has($cartItem->rowId)) {
                $existingCartItem = $this->get($cartItem->rowId);
                $cartItem->setQuantity($existingCartItem->qty + $cartItem->qty);
            }
        }

        if ($cartItem->qty <= 0) {
            $this->remove($cartItem->rowId);

            return null;
        }

        if (isset($itemOldIndex)) {
            $content = $content->slice(0, $itemOldIndex)
                ->merge([$cartItem->rowId => $cartItem])
                ->merge($content->slice($itemOldIndex));
        } else {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->dispatch('cart.updating', $cartItem);

        $this->session->put($this->instance, $content);

        $this->events->dispatch('cart.updated', $cartItem);

        return $cartItem;
    }

    /**
     * Remove the cart item with the given rowId from the cart.
     *
     * @param string $rowId
     *
     * @return void
     */
    public function remove($rowId)
    {
        $cartItem = $this->get($rowId);

        $content = $this->getContent();

        $content->pull($cartItem->rowId);

        $this->events->dispatch('cart.removing', $cartItem);

        $this->session->put($this->instance, $content);

        $this->events->dispatch('cart.removed', $cartItem);
    }

    /**
     * Get a cart item from the cart by its rowId.
     *
     * @param string $rowId
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function get($rowId): CartItem
    {
        $content = $this->getContent();

        if (!$content->has($rowId)) {
            throw new InvalidRowIDException("The cart does not contain rowId {$rowId}.");
        }

        return $content->get($rowId);
    }

    /**
     * Destroy the current cart instance.
     *
     * @return void
     */
    public function destroy(): void
    {
        $this->session->remove('coupons');
        $this->session->remove('cart_country');
        $this->session->remove($this->instance);
    }

    /**
     * Get the content of the cart.
     *
     * @return \Illuminate\Support\Collection
     */
    public function content(): Collection
    {
        if (is_null($this->session->get($this->instance))) {
            return new Collection([]);
        }

        return $this->session->get($this->instance);
    }

    /**
     * Get the total quantity of all CartItems in the cart.
     *
     * @return int|float
     */
    public function count(): int|float
    {
        return $this->getContent()->sum('qty');
    }

    /**
     * Get the amount of CartItems in the Cart.
     * Keep in mind that this does NOT count quantity.
     *
     * @return int|float
     */
    public function countItems(): int|float
    {
        return $this->getContent()->count();
    }

    /**
     * Get the total price of the items in the cart.
     *
     * @return float
     */
    public function totalFloat(): float
    {
        return $this->getContent()->reduce(
            function ($total, CartItem $cartItem) {
                return $total + $cartItem->total;
            },
            0
        );
    }

    /**
     * Get the total price of the items in the cart as formatted string.
     *
     * @param  bool   $int
     * @param  int    $decimals
     * @param  string $decimalPoint
     * @param  string $thousandSeparator
     * @param  bool   $raw
     * @return string|float
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeparator = null, $raw = false): string|float
    {
        return $this->numberFormat(
            $this->totalFloat(),
            $decimals,
            $decimalPoint,
            $thousandSeparator,
            $raw
        );
    }

    /**
     * Get the total tax of the items in the cart.
     *
     * @return float
     */
    public function taxFloat()
    {
        return $this->getContent()->reduce(
            function ($tax, CartItem $cartItem) {
                return $tax + $cartItem->taxTotal;
            },
            0
        );
    }

    /**
     * Get the total tax of the items in the cart as formatted string.
     *
     * @param  int    $decimals
     * @param  string $decimalPoint
     * @param  string $thousandSeparator
     * @return string
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeparator = null): string
    {
        return $this->numberFormat(
            $this->taxFloat(),
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Get the subtotal (total - tax) of the items in the cart.
     *
     * @return float
     */
    public function subtotalFloat(): float
    {
        return $this->getContent()->reduce(
            function ($subTotal, CartItem $cartItem) {
                return $subTotal + $cartItem->subtotal;
            },
            0
        );
    }

    /**
     * Get the subtotal (total - tax) of the items in the cart as formatted string.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeparator = null): string
    {
        return $this->numberFormat(
            $this->subtotalFloat(),
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Get the discount of the items in the cart.
     *
     * @return float
     */
    public function discountFloat(): float
    {
        return $this->getContent()->reduce(
            function ($discount, CartItem $cartItem) {
                return $discount + $cartItem->discountTotal;
            },
            0
        );
    }

    /**
     * Get the discount of the items in the cart as formatted string.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function discount($decimals = null, $decimalPoint = null, $thousandSeparator = null): string
    {
        return $this->numberFormat(
            $this->discountFloat(),
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    public function subtotalNoDiscounts($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        $subtotal = $this->getContent()
            ->reduce(
                function ($initial, CartItem $cartItem) {
                    return $initial + ($cartItem->qty * $cartItem->price);
                },
                0
            );

        return $this->numberFormat(
            $subtotal,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Get the price of the items in the cart as formatted string.
     * Get the total tax of the items in the cart.
     *
     * @param  int    $decimals
     * @param  string $decimalPoint
     * @param  string $thousandSeparator
     * @param  bool   $raw
     * @return float|int
     */
    public function discounts($decimals = null, $decimalPoint = null, $thousandSeparator = null, $raw = false): int|float
    {
        $subtotal = $this->subtotalFloat();

        $coupons = $this->coupons();

        if ($coupons->isEmpty()) {
            return 0;
        }

        $discounts = $coupons->reduce(
            function ($discount, $coupon) use ($subtotal) {
                return $discount + (
                    'relative' === $coupon['type'] ? $subtotal * $coupon['value'] / 100 : $coupon['value']
                );
            },
            0
        );

        return $this->numberFormat(
            $discounts,
            $decimals,
            $decimalPoint,
            $thousandSeparator,
            $raw
        );
    }

    /**
     * Get the total tax of the items in the cart.
     *
     * @param  int    $decimals
     * @param  string $decimalPoint
     * @param  string $thousandSeparator
     * @param  bool   $raw
     * @return int|float
     */
    public function shipping($decimals = null, $decimalPoint = null, $thousandSeparator = null, $raw = false)
    {
        $shipping = 0;

        if($this->session->get('coupons')['is_ship'] !== true) {
            $content = $this->getContent();
            $shipping = $content->reduce(
                function ($shipping, CartItem $cartItem) {
                    $shippingRate = (in_array($this->session->get('cart_country'), Config('cart.shipping.standard'))) ?
                    $cartItem->shipping : $cartItem->shippingInt;
                    $model = $cartItem->model->ArtworkTitle;
                    if (strpos($model, 'VOUCHER') === false) {
                        return $shipping + ($cartItem->qty * $shippingRate);
                    }
                    return $shipping;
                }, 0
            );
        }

        return $this->numberFormat(
            $shipping,
            $decimals,
            $decimalPoint,
            $thousandSeparator,
            $raw
        );
    }

    /**
     * Get the price of the items in the cart (not rounded).
     *
     * @return float
     */
    public function initialFloat(): float
    {
        return $this->getContent()->reduce(
            function ($initial, CartItem $cartItem) {
                return $initial + ($cartItem->qty * $cartItem->price);
            },
            0
        );
    }

    /**
     * Get the subtotal (total - tax) of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function initial($decimals = null, $decimalPoint = null, $thousandSeparator = null): string
    {
        return $this->numberFormat(
            $this->initialFloat(),
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Get the price of the items in the cart (previously rounded).
     *
     * @return float
     */
    public function priceTotalFloat(): float
    {
        return $this->getContent()->reduce(
            function ($initial, CartItem $cartItem) {
                return $initial + $cartItem->priceTotal;
            },
            0
        );
    }

    /**
     * Get the price of the items in the cart as formatted string.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function priceTotal($decimals = null, $decimalPoint = null, $thousandSeparator = null): string
    {
        return $this->numberFormat(
            $this->priceTotalFloat(),
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Get the total weight of the items in the cart.
     *
     * @return float
     */
    public function weightFloat()
    {
        return $this->getContent()->reduce(
            function ($total, CartItem $cartItem) {
                return $total + ($cartItem->qty * $cartItem->weight);
            },
            0
        );
    }

    /**
     * Get the total weight of the items in the cart.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function weight($decimals = null, $decimalPoint = null, $thousandSeparator = null): string
    {
        return $this->numberFormat(
            $this->weightFloat(),
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Search the cart content for a cart item matching the given search closure.
     *
     * @param \Closure $search
     *
     * @return \Illuminate\Support\Collection
     */
    public function search(Closure $search)
    {
        return $this->getContent()->filter($search);
    }

    /**
     * Associate the cart item with the given rowId with the given model.
     *
     * @param string $rowId
     * @param mixed  $model
     *
     * @return void
     */
    public function associate($rowId, $model)
    {
        if (is_string($model) && !class_exists($model)) {
            throw new UnknownModelException("The supplied model {$model} does not exist.");
        }

        $cartItem = $this->get($rowId);

        $cartItem->associate($model);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the tax rate for the cart item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     *
     * @return void
     */
    public function setTax($rowId, $taxRate): void
    {
        $cartItem = $this->get($rowId);

        $cartItem->setTaxRate($taxRate);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the global tax rate for the cart.
     * This will set the tax rate for all items.
     *
     * @param float $taxRate
     */
    public function setGlobalTax(float $taxRate): void
    {
        $this->taxRate = $taxRate;

        $content = $this->getContent();
        if ($content && $content->count()) {
            $content->each(
                function ($item, $key) {
                    $item->setTaxRate($this->taxRate);
                }
            );
        }
    }

    public function getTax(): float
    {
        return $this->taxRate;
    }

    /**
     * Set the discount rate for the cart item with the given rowId.
     *
     * @param string    $rowId
     * @param int|float $taxRate
     *
     * @return void
     */
    public function setDiscount($rowId, $discount)
    {
        $cartItem = $this->get($rowId);

        $cartItem->setDiscountRate($discount);

        $content = $this->getContent();

        $content->put($cartItem->rowId, $cartItem);

        $this->session->put($this->instance, $content);
    }

    /**
     * Set the global discount percentage for the cart.
     * This will set the discount for all cart items.
     *
     * @param float $discount
     *
     * @return void
     */
    public function setGlobalDiscount($discount): void
    {
        $this->discount = $discount;

        $content = $this->getContent();
        if ($content && $content->count()) {
            $content->each(
                function ($item, $key) {
                    $item->setDiscountRate($this->discount);
                }
            );
        }
    }

    /**
     * Store an the current instance of the cart.
     *
     * @param mixed $identifier
     *
     * @return void
     */
    public function store($identifier): void
    {
        $content = $this->getContent();

        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $instance = $this->currentInstance();

        if ($this->storedCartInstanceWithIdentifierExists($instance, $identifier)) {
            throw new CartAlreadyStoredException("A cart with identifier {$identifier} was already stored.");
        }

        $this->getConnection()->table($this->getTableName())->insert(
            [
                'identifier' => $identifier,
                'instance'   => $instance,
                'content'    => serialize($content),
                'created_at' => $this->createdAt ?: Carbon::now(),
                'updated_at' => Carbon::now(),
            ]
        );

        $this->events->dispatch('cart.stored');
    }

    /**
     * Restore the cart with the given identifier.
     *
     * @param mixed $identifier
     *
     * @return void
     */
    public function restore($identifier): void
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $currentInstance = $this->currentInstance();

        if (!$this->storedCartInstanceWithIdentifierExists($currentInstance, $identifier)) {
            return;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where(['identifier'=> $identifier, 'instance' => $currentInstance])->first();

        $storedContent = unserialize(data_get($stored, 'content'));

        $this->instance(data_get($stored, 'instance'));

        $content = $this->getContent();

        foreach ($storedContent as $cartItem) {
            $content->put($cartItem->rowId, $cartItem);
        }

        $this->events->dispatch('cart.restored');

        $this->session->put($this->instance, $content);

        $this->instance($currentInstance);

        $this->createdAt = Carbon::parse(data_get($stored, 'created_at'));
        $this->updatedAt = Carbon::parse(data_get($stored, 'updated_at'));

        $this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance' => $currentInstance])->delete();
    }

    /**
     * Erase the cart with the given identifier.
     *
     * @param mixed $identifier
     *
     * @return void
     */
    public function erase($identifier): void
    {
        if ($identifier instanceof InstanceIdentifier) {
            $identifier = $identifier->getInstanceIdentifier();
        }

        $instance = $this->currentInstance();

        if (!$this->storedCartInstanceWithIdentifierExists($instance, $identifier)) {
            return;
        }

        $this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance' => $instance])->delete();

        $this->events->dispatch('cart.erased');
    }

    /**
     * Merges the contents of another cart into this cart.
     *
     * @param mixed $identifier   Identifier of the Cart to merge with.
     * @param bool  $keepDiscount Keep the discount of the CartItems.
     * @param bool  $keepTax      Keep the tax of the CartItems.
     * @param bool  $dispatchAdd  Flag to dispatch the add events.
     *
     * @return bool
     */
    public function merge($identifier, $keepDiscount = false, $keepTax = false, $dispatchAdd = true, $instance = self::DEFAULT_INSTANCE): bool
    {
        if (!$this->storedCartInstanceWithIdentifierExists($instance, $identifier)) {
            return false;
        }

        $stored = $this->getConnection()->table($this->getTableName())
            ->where(['identifier'=> $identifier, 'instance'=> $instance])->first();

        $storedContent = unserialize($stored->content);

        foreach ($storedContent as $cartItem) {
            $this->addCartItem($cartItem, $keepDiscount, $keepTax, $dispatchAdd);
        }

        $this->events->dispatch('cart.merged');

        return true;
    }

    /**
     * Magic method to make accessing the total, tax and subtotal properties possible.
     *
     * @param string $attribute
     *
     * @return float|null
     */
    public function __get($attribute): null|string|float
    {
        switch ($attribute) {
        case 'total':
            return $this->total();
        case 'tax':
            return $this->tax();
        case 'subtotal':
            return $this->subtotal();
        }

        return null;
    }

    /**
     * Get the carts content, if there is no cart content set yet, return a new empty Collection.
     *
     * @return \Illuminate\Support\Collection
     */
    protected function getContent(): Collection
    {
        if ($this->session->has($this->instance)) {
            return $this->session->get($this->instance);
        }

        return new Collection();
    }

    /**
     * Create a new CartItem from the supplied attributes.
     *
     * @param mixed     $id
     * @param mixed     $name
     * @param int|float $qty
     * @param float     $price
     * @param float     $weight
     * @param array     $options
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    private function createCartItem($id, $name, $qty, $price, $weight, ?array $options): CartItem
    {
        if ($id instanceof Buyable) {
            $cartItem = CartItem::fromBuyable($id, $qty ?: []);
            $cartItem->setQuantity($name ?: 1);
            $cartItem->associate($id);
        } elseif (is_array($id)) {
            $cartItem = CartItem::fromArray($id);
            $cartItem->setQuantity($id['qty']);
        } else {
            $cartItem = CartItem::fromAttributes($id, $name, $price, $weight, $options);
            $cartItem->setQuantity($qty);
        }

        $cartItem->setInstance($this->currentInstance());

        return $cartItem;
    }

    /**
     * Check if the item is a multidimensional array or an array of Buyables.
     *
     * @param mixed $item
     *
     * @return bool
     */
    private function isMulti($item): bool
    {
        if (! is_array($item)) {
            return false;
        }

        return is_array(head($item))
            || head($item) instanceof Buyable;
    }

    /**
     * @param $identifier
     *
     * @return bool
     */
    private function storedCartInstanceWithIdentifierExists($instance, $identifier): bool
    {
        return $this->getConnection()->table($this->getTableName())->where(['identifier' => $identifier, 'instance'=> $instance])->exists();
    }

    /**
     * Get the database connection.
     *
     * @return \Illuminate\Database\Connection
     */
    private function getConnection(): Connection
    {
        return app(DatabaseManager::class)->connection($this->getConnectionName());
    }

    /**
     * Get the database table name.
     *
     * @return string
     */
    private function getTableName(): string
    {
        return config('cart.database.table', 'shoppingcart');
    }

    /**
     * Get the database connection name.
     *
     * @return string
     */
    private function getConnectionName(): string
    {
        $connection = config('cart.database.connection');

        return is_null($connection) ? config('database.default') : $connection;
    }

    /**
     * Get the creation date of the cart (db context).
     *
     * @return \Carbon\Carbon|null
     */
    public function createdAt(): ?Carbon
    {
        return $this->createdAt;
    }

    /**
     * Get the lats update date of the cart (db context).
     *
     * @return \Carbon\Carbon|null
     */
    public function updatedAt(): ?Carbon
    {
        return $this->updatedAt;
    }
}
