<?php
namespace Awz\Allxls\Access\Tables;

use Bitrix\Main\Access\Role\AccessRoleRelationTable;

class RoleRelationTable extends AccessRoleRelationTable
{
    public static function getTableName()
    {
        return 'awz_allxls_role_relation';
    }

}