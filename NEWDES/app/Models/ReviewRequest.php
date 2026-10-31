<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReviewRequest extends Model
{
    protected $table = 'review_requests';

    protected $guarded = [];

    protected function casts(): array
    {
        return [];
    }
}
