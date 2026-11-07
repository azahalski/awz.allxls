<?php
namespace Awz\Allxls\Helper;

use Bitrix\Iblock\ElementTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

class Iblock {

    /**
     * Генерирует уникальный символьный код на основе названия элемента.
     * Если код занят, добавляет числовой суффикс (-2, -3, ...).
     *
     * @param string $iblock_id ид информационного блока.
     * @param string $name Название элемента (основа для кода).
     * @return string Уникальный символьный код.
     */
    public static function generateUniqueCode(string $iblock_id, string $name): string
    {
        $name = trim($name);
        $base = \CUtil::translit($name, 'ru', [
            'replace_space' => '-',
            'replace_other' => '',
        ]);
        $base = trim($base, '-');
        if ($base === '') return $base;

        $code = $base;
        $suffix = 2;
        while (self::codeExists($iblock_id, $code)) {
            $code = $base . '-' . $suffix;
            $suffix++;
        }

        return $code;
    }

    public static function codeExists(string $iblock_id, string $code): bool
    {
        if ((int)$iblock_id <= 0 || $code === '' || !Loader::includeModule('iblock')) {
            return false;
        }

        $row = ElementTable::getList([
            'select'=>['ID'],
            'filter'=>['=IBLOCK_ID'=>(int)$iblock_id, '=CODE'=>(string)$code]
        ])->fetch();

        return !empty($row);
    }

}