<?php

namespace Gloudemans\Shoppingcart;

trait CanBeBought
{
    /**
     * Get the identifier of the Buyable item.
     *
     * @return int|string
     */
    public function getBuyableIdentifier($options = null): int|string
    {
        return method_exists($this, 'getKey') ? $this->getKey() : $this->id;
    }

    /**
     * Get the name, title or description of the Buyable item.
     *
     * @return string
     */
    public function getBuyableDescription($options = null)
    {
        if ($name = $this->getAttribute('name')) {
            return $name;
        }

        if ($title = $this->getAttribute('title')) {
            return $title;
        }

        if ($description = $this->getAttribute('description')) {
            return $description;
        }
    }

    /**
     * Get the price of the Buyable item.
     *
     * @return float
     */
    public function getBuyablePrice($options = null): float
    {
        return $this->getAttribute('price') ?: 0;
    }

    /**
     * Get the weight of the Buyable item.
     *
     * @return float
     */
    public function getBuyableWeight($options = null): float
    {
        return $this->getAttribute('weight') ?: 0;
    }
}
