<?php

namespace Gloudemans\Shoppingcart;

use ReflectionClass;
use Illuminate\Support\Arr;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Contracts\Support\Arrayable;
use Gloudemans\Shoppingcart\Contracts\Buyable;
use Gloudemans\Shoppingcart\Contracts\Calculator;
use Gloudemans\Shoppingcart\Calculation\DefaultCalculator;
use Gloudemans\Shoppingcart\Exceptions\InvalidCalculatorException;

/**
 * @property-read mixed discount
 * @property-read float discountTotal
 * @property-read float priceTarget
 * @property-read float priceNet
 * @property-read float priceTotal
 * @property-read float subtotal
 * @property-read float taxTotal
 * @property-read float tax
 * @property-read float total
 * @property-read float priceTax
 */
class CartItem implements Arrayable, Jsonable
{
    use FormatsNumbers;

    /**
     * The rowID of the cart item.
     *
     * @var string
     */
    public $rowId;

    /**
     * The ID of the cart item.
     *
     * @var int|string
     */
    public $id;

    /**
     * The quantity for this cart item.
     *
     * @var int|float
     */
    public $qty;

    /**
     * The name of the cart item.
     *
     * @var string
     */
    public $name;

    /**
     * The price without TAX of the cart item.
     *
     * @var float
     */
    public $price;

    /**
     * The weight of the product.
     *
     * @var float
     */
    public $weight;

    /**
     * The options for this cart item.
     *
     * @var CartItemOptions|array
     */
    public $options;

    /**
     * The tax rate for the cart item.
     *
     * @var int|float
     */
    public $taxRate = 0;

    /**
     * The FQN of the associated model.
     *
     * @var string|null
     */
    private $associatedModel = null;

    /**
     * The discount rate for the cart item.
     *
     * @var float
     */
    private $discountRate = 0;

    /**
     * The cart instance of the cart item.
     *
     * @var null|string
     */
    public $instance = null;

    /**
     * CartItem constructor.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param float      $weight
     * @param array      $options
     */
    public function __construct($id, $name, $price, $weight = 0, array $options = [])
    {
        if (empty($id)) {
            throw new \InvalidArgumentException('Please supply a valid identifier.');
        }

        if (empty($name)) {
            throw new \InvalidArgumentException('Please supply a valid name.');
        }

        if (strlen($price) < 0
            || !is_numeric($price)
        ) {
            throw new \InvalidArgumentException('Please supply a valid price.');
        }

        if (strlen($weight) < 0
            || !is_numeric($weight)
        ) {
            throw new \InvalidArgumentException('Please supply a valid weight.');
        }

        $this->id = $id;
        $this->name = $name;
        $this->price = floatval($price);
        $this->weight = floatval($weight);
        $this->options = new CartItemOptions($options);
        $this->rowId = $this->generateRowId($id, $options);
    }

    /**
     * Returns the formatted weight.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function weight($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->weight,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted price without tax.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function price($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->price,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted price with discount applied.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function priceTarget($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->priceTarget,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted price with tax.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function priceTax($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->priceTax,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted subtotal.
     * Subtotal is price for whole CartItem without TAX.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function subtotal($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->subtotal,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted total.
     * Total is price for whole CartItem with tax.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function total($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->total,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted tax.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function tax($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->tax,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted tax.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function taxTotal($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->taxTotal,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted discount.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function discount($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->discount,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted total discount for this cart item.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function discountTotal($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->discountTotal,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Returns the formatted total price for this cart item.
     *
     * @param int    $decimals
     * @param string $decimalPoint
     * @param string $thousandSeparator
     *
     * @return string
     */
    public function priceTotal($decimals = null, $decimalPoint = null, $thousandSeparator = null)
    {
        return $this->numberFormat(
            $this->priceTotal,
            $decimals,
            $decimalPoint,
            $thousandSeparator
        );
    }

    /**
     * Set the quantity for this cart item.
     *
     * @param int|float $qty
     */
    public function setQuantity($qty)
    {
        if (empty($qty)
            || !is_numeric($qty)
        ) {
            throw new \InvalidArgumentException('Please supply a valid quantity.');
        }

        $this->qty = $qty;
    }

    /**
     * Update the cart item from a Buyable.
     *
     * @param \Gloudemans\Shoppingcart\Contracts\Buyable $item
     *
     * @return void
     */
    public function updateFromBuyable(Buyable $item)
    {
        $this->id = $item->getBuyableIdentifier($this->options);
        $this->name = $item->getBuyableDescription($this->options);
        $this->price = $item->getBuyablePrice($this->options);
    }

    /**
     * Update the cart item from an array.
     *
     * @param array $attributes
     *
     * @return void
     */
    public function updateFromArray(array $attributes)
    {
        $this->id = Arr::get($attributes, 'id', $this->id);
        $this->qty = Arr::get($attributes, 'qty', $this->qty);
        $this->name = Arr::get($attributes, 'name', $this->name);
        $this->price = Arr::get($attributes, 'price', $this->price);
        $this->weight = Arr::get($attributes, 'weight', $this->weight);
        $this->options = new CartItemOptions(
            Arr::get($attributes, 'options', $this->options)
        );

        $this->rowId = $this->generateRowId($this->id, $this->options->all());
    }

    /**
     * Associate the cart item with the given model.
     *
     * @param mixed $model
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function associate($model)
    {
        $this->associatedModel = is_string($model) ? $model : get_class($model);

        return $this;
    }

    /**
     * Set the tax rate.
     *
     * @param int|float $taxRate
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function setTaxRate($taxRate)
    {
        $this->taxRate = $taxRate;

        return $this;
    }

    /**
     * Set the discount rate.
     *
     * @param int|float $discountRate
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function setDiscountRate($discountRate)
    {
        $this->discountRate = $discountRate;

        return $this;
    }

    /**
     * Set cart instance.
     *
     * @param null|string $instance
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public function setInstance($instance)
    {
        $this->instance = $instance;

        return $this;
    }

    /**
     * Get an attribute from the cart item or get the associated model.
     *
     * @param string $attribute
     *
     * @return mixed
     */
    public function __get($attribute)
    {
        if (\property_exists($this, $attribute)) {
            return $this->{$attribute};
        }

        $decimals = config('cart.format.decimals', 2);

        switch ($attribute) {
        case 'model':
            if (isset($this->associatedModel)) {
                return with(new $this->associatedModel())
                    ->find($this->id);
            }
            // no break
        case 'modelFQCN':
            if (isset($this->associatedModel)) {
                return $this->associatedModel;
            }
            // no break
        case 'weightTotal':
            return round($this->weight * $this->qty, $decimals);
        case 'shipping':
            return $this->price * 0.05;
        case 'shippingInt':
            return $this->price * 0.1;
        default:
            if (isset($this->associatedModel)) {
                $model = with(new $this->associatedModel())
                    ->find($this->id);
                if ($model
                    && \property_exists($model, $attribute)
                ) {
                    return $model->{$attribute};
                }
            };
        }

        $class = new ReflectionClass(config('cart.calculator', DefaultCalculator::class));

        if (!$class->implementsInterface(Calculator::class)) {
            throw new InvalidCalculatorException(
                'The configured Calculator seems to be invalid. Calculators have to implement the Calculator contract.'
            );
        }

        return call_user_func($class->getName() . '::getAttribute', $attribute, $this);
    }

    /**
     * Create a new instance from a Buyable.
     *
     * @param \Gloudemans\Shoppingcart\Contracts\Buyable $item
     * @param array                                      $options
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public static function fromBuyable(Buyable $item, array $options = [])
    {
        return new self(
            $item->getBuyableIdentifier($options),
            $item->getBuyableDescription($options),
            $item->getBuyablePrice($options),
            $item->getBuyableWeight($options),
            $options
        );
    }

    /**
     * Create a new instance from the given array.
     *
     * @param array $attributes
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public static function fromArray(array $attributes): CartItem
    {
        return new self(
            $attributes['id'],
            $attributes['name'],
            $attributes['price'],
            $attributes['weight'],
            Arr::get($attributes, 'options', [])
        );
    }

    /**
     * Create a new instance from the given attributes.
     *
     * @param int|string $id
     * @param string     $name
     * @param float      $price
     * @param array      $options
     *
     * @return \Gloudemans\Shoppingcart\CartItem
     */
    public static function fromAttributes($id, $name, $price, $weight, array $options = []): CartItem
    {
        return new self(...func_get_args());
    }

    /**
     * Generate a unique id for the cart item.
     *
     * @param string $id
     * @param array  $options
     *
     * @return string
     */
    protected function generateRowId($id, array $options): string
    {
        ksort($options);

        return md5($id . serialize($options));
    }

    /**
     * Get the instance as an array.
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'rowId'    => $this->rowId,
            'id'       => $this->id,
            'name'     => $this->name,
            'qty'      => $this->qty,
            'price'    => $this->price,
            'weight'   => $this->weight,
            'options'  => is_object($this->options)
                ? $this->options->toArray()
                : $this->options,
            'discount' => $this->discount,
            'tax'      => $this->tax,
            'subtotal' => $this->subtotal,
        ];
    }

    /**
     * Convert the object to its JSON representation.
     *
     * @param int $options
     *
     * @return string
     */
    public function toJson($options = 0): string|false
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Getter for the raw internal discount rate.
     * Should be used in calculators.
     *
     * @return float
     */
    public function getDiscountRate(): float
    {
        return $this->discountRate;
    }
}
