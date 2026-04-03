<?php

namespace App\Modules\Company\Enums;

enum CompanyStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
