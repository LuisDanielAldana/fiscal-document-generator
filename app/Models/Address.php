<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable([
    'country',
    'state',
    'city',
    'municipality',
    'zone',
    'zip_code',
    'street',
    'interior_number',
    'exterior_number',

])]
class Address extends Model
{
    public function addressable(): MorphTo
    {
        return $this->morphTo();
    }
}
