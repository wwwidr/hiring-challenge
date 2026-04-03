<?php

namespace App\Modules\Company\Enums;

enum CompanyStatusFilter: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case All = 'all';
}
