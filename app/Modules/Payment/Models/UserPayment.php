<?php

namespace App\Modules\Payment\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class UserPayment extends Model
{
    use HasFactory;

    protected $fillable = ['sequence_id', 'amount', 'currency', 'status'];

    public function sequence()
    {
        return $this->belongsTo(\App\Modules\Sequence\Models\Sequence::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\UserPaymentFactory::new();
    }
}
