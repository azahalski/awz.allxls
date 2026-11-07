<?php
namespace Awz\Allxls\Access\Custom;

use Awz\Allxls\Access\Permission;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class PermissionDictionary
    extends Permission\PermissionDictionary
{
    /*awz.gen start - !!!nodelete*/
	public const MODULE_SETT_VIEW = "96";
	public const MODULE_SETT_EDIT = "97";
	public const MODULE_RIGHT_VIEW = "98";
	public const MODULE_RIGHT_EDIT = "99";
	public const PROFILE_SECTION_ADD = "10";
	public const PROFILE_SECTION_EDIT = "11";
	public const PROFILE_SECTION_DELETE = "12";
	public const PROFILE_SECTION_VIEW = "13";
	public const EXPORT_VIEW = "20";
	public const EXPORT_ADD = "21";
	public const EXPORT_EDIT = "22";
	public const EXPORT_DELETE = "24";
	public const EXPORT_LOAD = "27";
	public const IMPORT_VIEW = "30";
	public const IMPORT_ADD = "31";
	public const IMPORT_EDIT = "32";
	public const IMPORT_DELETE = "34";
	public const IMPORT_LOAD = "37";
	/*awz.gen end - !!!nodelete*/
}