<?php

namespace Gloudemans\Tests\Shoppingcart\Fixtures;

use Sushi\Sushi;
use Illuminate\Database\Eloquent\Model;
use Gloudemans\Shoppingcart\Contracts\Buyable;

class BuyableProduct extends Model implements Buyable
{
    use BuyableProductTrait;
    use Sushi;

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'id',
        'name',
        'title',
        'description',
        'price',
        'weight',
    ];

    protected $attributes = [
        'id'          => 1,
        'name'        => 'Item name',
        'description' => null,
        'price'       => 10.00,
        'weight'      => 0,
    ];

    protected $rows = [
        [
            'id'          => 1,
            'name'        => 'Item name',
            'description' => null,
            'price'       => 10.00,
            'weight'      => 0,
        ]
    ];
}
