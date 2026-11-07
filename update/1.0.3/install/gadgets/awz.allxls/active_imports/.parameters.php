<?
if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true) die();

$arParameters = Array(
    "USER_PARAMETERS" => Array(
        "PROFILES_COUNT" => array(
            "PARENT" => "BASE",
            "NAME" => GetMessage("GD_AWZ_ALLXLS_PROFILES_COUNT"),
            "TYPE" => "STRING",
            "DEFAULT" => '10',
        ),
        "SHOW_EXPORT" => array(
            "PARENT" => "BASE",
            "NAME" => GetMessage("GD_AWZ_ALLXLS_SHOW_EXPORT"),
            "TYPE" => "CHECKBOX",
            "DEFAULT" => 'N',
        ),
    ),
);