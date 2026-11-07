<?php
namespace Awz\Allxls\Access\Custom\Rules;

use Bitrix\Main\Access\AccessibleItem;
use Awz\Allxls\Access\Custom\PermissionDictionary;
use Awz\Allxls\Access\Custom\Helper;

class Exportadd extends \Bitrix\Main\Access\Rule\AbstractRule
{

    public function execute(AccessibleItem $item = null, $params = null): bool
    {
        if ($this->user->isAdmin() && !Helper::ADMIN_DECLINE)
        {
            return true;
        }
        if ($this->user->getPermission(PermissionDictionary::EXPORT_ADD))
        {
            return true;
        }
        return false;
    }

}