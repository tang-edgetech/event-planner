<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

/**
 * An uploaded image. `url` is relative ("media/2026/11/abc.jpg"); each frontend serves it under its own /media path.
 */
#[Unguarded]
#[Appends(['url'])]
class Media extends Model
{
    protected $table = 'media';

    /**
     * @return Attribute<string, never>
     */
    protected function url(): Attribute
    {
        return Attribute::get(fn () => 'media/'.$this->path);
    }
}
