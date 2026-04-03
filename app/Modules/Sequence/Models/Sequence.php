<?php

namespace App\Modules\Sequence\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    use HasFactory;

    protected $fillable = ['company_id', 'status', 'amount', 'currency'];

    public const TERMINAL_STATUSES = ['cancelled', 'recovered'];
    public const ACTIVE_STATUSES = ['active', 'installment', 'partially_paid_recovery', 'will_pay_later'];

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES);
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES);
    }

    public function company()
    {
        return $this->belongsTo(\App\Modules\Company\Models\Company::class);
    }

    public function payments()
    {
        return $this->hasMany(\App\Modules\Payment\Models\UserPayment::class);
    }

    protected static function newFactory()
    {
        return \Database\Factories\SequenceFactory::new();
    }
}
