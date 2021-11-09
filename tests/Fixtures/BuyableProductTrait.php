<?php

namespace Gloudemans\Tests\Shoppingcart\Fixtures;

trait BuyableProductTrait
{
    /**
     * Get the identifier of the Buyable item.
     *
     * @return int|string
     */
    public function getBuyableIdentifier($options = null): int|string
    {
        return $this->id;
    }

    /**
     * Get the description or title of the Buyable item.
     *
     * @return string
     */
    public function getBuyableDescription($options = null): string
    {
        return $this->name ?: $this->title ?: $this->description;
    }

    /**
     * Get the price of the Buyable item.
     *
     * @return float
     */
    public function getBuyablePrice($options = null): float
    {
        return $this->price;
    }

    /**
     * Get the price of the Buyable item.
     *
     * @return float
     */
    public function getBuyableWeight($options = null): float
    {
        return $this->weight;
    }
}
