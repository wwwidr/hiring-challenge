<?php

namespace App\Modules\Company\Models;

use App\Modules\Company\Enums\CompanyStatus;
use App\Modules\Sequence\Models\Sequence;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'status'];

    protected $casts = [
        'status' => CompanyStatus::class,
    ];

    public function scopeSearch($query, string $term)
    {
        $escaped = str_replace(['%', '_'], ['\%', '\_'], $term);

        return $query->where('name', 'like', "%{$escaped}%");
    }

    public function scopeWithStatus($query, CompanyStatus|string|null $status)
    {
        if ($status && $status !== 'all') {
            return $query->where('status', $status instanceof CompanyStatus ? $status->value : $status);
        }

        return $query;
    }

    public function sequences()
    {
        return $this->hasMany(Sequence::class);
    }

    protected static function newFactory()
    {
        return CompanyFactory::new();
    }
}
