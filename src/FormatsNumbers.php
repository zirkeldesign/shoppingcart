<?php

namespace Gloudemans\Shoppingcart;

trait FormatsNumbers
{
    /**
     * Get the formatted number.
     *
     * @param float  $value
     * @param null|int    $decimals
     * @param null|string $decimalPoint
     * @param null|string $thousandSeparator
     *
     * @return string
     */
    private function numberFormat($value, $decimals = null, $decimalPoint = null, $thousandSeparator = null, bool $raw = false): string
    {
        if ($raw) {
            return $value;
        }

        if (is_null($decimals)) {
            $decimals = config('cart.format.decimals', 2);
        }

        if (is_null($decimalPoint)) {
            $decimalPoint = config('cart.format.decimal_point', '.');
        }

        if (is_null($thousandSeparator)) {
            $thousandSeparator = config('cart.format.thousand_separator', ',');
        }

        return number_format($value, $decimals, $decimalPoint, $thousandSeparator);
    }
}
