# Bitrix API — Импорт свойств элементов инфоблока (по методологии ESOL.ImportExportExcel)

## 1. Основные классы API

| Класс | Назначение |
|-------|------------|
| `CIBlockElement` | Элементы инфоблока |
| `CIBlockProperty` | Свойства инфоблока |
| `CIBlockPropertyEnum` | Значения свойств-списков |
| `CIBlockSection` | Разделы инфоблока |
| `CFile` | Файлы |
| `CCatalogPrice` / `Bitrix\Catalog\PriceTable` | Цены |
| `CCatalogStoreProduct` / `Bitrix\Catalog\StoreProductTable` | Остатки на складах |
| `Bitrix\Catalog\ProductTable` | Продукты (каталог) |

---

## 2. Загрузка свойств элемента

### CIBlockElement::GetProperty

Получает текущие значения свойств элемента.

```php
// Получить все свойства элемента
$dbRes = CIBlockElement::GetProperty($IBLOCK_ID, $ELEMENT_ID, array(), array());
while ($arProp = $dbRes->Fetch()) {
    // $arProp: ID, NAME, CODE, PROPERTY_TYPE (S/N/L/E/F/G), 
    //         MULTIPLE (Y/N), VALUE, DESCRIPTION, VALUE_NUM, XML_ID
}

// Получить конкретные свойства по ID
$dbRes = CIBlockElement::GetProperty(
    $IBLOCK_ID,
    $ELEMENT_ID,
    array("sort" => "asc", "by" => "name"),
    array("ID" => array(42, 43, 44))  // массив ID свойств
);

// Получить одно свойство
$dbRes = CIBlockElement::GetProperty(
    $IBLOCK_ID,
    $ELEMENT_ID,
    array(),
    array("ID" => 42)
);
$arProp = $dbRes->Fetch();
// $arProp['VALUE']      — строковое/числовое значение
// $arProp['DESCRIPTION'] — описание
// $arProp['VALUE_ENUM']  — ID перечисления для типа L
// $arProp['VALUE_NUM']   — числовое значение для типа N
// $arProp['PROPERTY_VALUE_ID'] — ID записи в b_iblock_element_property
```

---

## 3. Установка свойств элемента

### CIBlockElement::SetPropertyValuesEx

Устанавливает значения **одного или нескольких** свойств элемента. Поддерживает все типы: S, N, L, E, F, G.

```php
// === S (Строка, не множественное) ===
$arFields = array(
    'COLOR' => 'Красный'
);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);

// Удалить свойство — передать false
$arFields = array('COLOR' => false);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);

// Удалить файл — передать массив с 'del'=>'Y'
$arFields = array('PHOTO' => array('del' => 'Y'));
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === N (Число, не множественное) ===
$arFields = array('WEIGHT' => 2.5);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === L (Список, не множественное) ===
// По значению (VALUE):
$arFields = array('SIZE' => 'Большой');

// По XML_ID:
$arFields = array('SIZE' => false);  // сначала удалить старое
$ibpenum = new CIBlockPropertyEnum;
$ibpenum->Add(array('VALUE' => 'Большой', 'XML_ID' => 'large', 'SORT' => 500, 'PROPERTY_ID' => PROP_ID));
$arFields = array('SIZE' => 'Большой');  // теперь привязать
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === E (Ссылка на элемент, не множественное) ===
// По ID элемента:
$arFields = array('RELATED_ELEMENT' => 123);

// По символическому коду:
$arFields = array('RELATED_ELEMENT' => false);  // очистить
$arFields = array('RELATED_ELEMENT' => 456);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === F (Файл, не множественное) ===
// Загрузить файл из tmp_name (результат CFile::MakeFileArray):
$arFile = CFile::MakeFileArray('/path/to/image.jpg');
$arFields = array('PHOTO' => $arFile);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);

// По ExternalId (для CFile::GetFileArray):
$arFile = CFile::GetFileArray($FILE_ID);
$arFields = array('PHOTO' => $arFile);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);

// Удалить файл:
$arFields = array('PHOTO' => array('del' => 'Y'));
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === F (Файл) + описание ===
$arFile = CFile::MakeFileArray('/path/to/image.jpg');
$arFile['description'] = 'Описание фото';
$arFile['alt'] = 'Альтернативный текст';
$arFields = array('PHOTO' => $arFile);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);
```

---

## 4. Множественные свойства

Передаём как массив значений. Для каждого элемента массива можно указать `VALUE` и `DESCRIPTION`.

```php
// === S (Строка, множественное) ===
$arFields = array(
    'TAGS' => array(
        0 => 'тег1',
        1 => 'тег2',
        2 => 'тег3'
    )
);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === S (Множественное) с описаниями ===
$arFields = array(
    'TAGS' => array(
        0 => array('VALUE' => 'тег1', 'DESCRIPTION' => 'первый тег'),
        1 => array('VALUE' => 'тег2', 'DESCRIPTION' => 'второй тег')
    )
);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === L (Список, множественное) ===
$arFields = array(
    'CATEGORIES' => array('Категория1', 'Категория2', 'Категория3')
);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === E (Элементы, множественное) ===
$arFields = array(
    'RELATED_ELEMENTS' => array(100, 200, 300)
);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === G (Раздел, множественное) ===
$arFields = array(
    'SECTIONS' => array(10, 20, 30)
);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === F (Файлы, множественное) ===
$arFile1 = CFile::MakeFileArray('/path/img1.jpg');
$arFile2 = CFile::MakeFileArray('/path/img2.jpg');
$arFields = array(
    'PHOTOS' => array($arFile1, $arFile2)
);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);


// === G (Раздел, множественное) + описание ===
$arFields = array(
    'SECTIONS' => array(
        0 => array('VALUE' => 10, 'DESCRIPTION' => 'Первый раздел'),
        1 => array('VALUE' => 20, 'DESCRIPTION' => 'Второй раздел')
    )
);
CIBlockElement::SetPropertyValuesEx($ELEMENT_ID, $IBLOCK_ID, $arFields);
```

---

## 5. Добавление нового элемента со свойствами

```php
$el = new CIBlockElement();

$arLoadArray = Array(
    'IBLOCK_SECTION_ID' => 5,
    'NAME'              => 'Название товара',
    'ACTIVE'            => 'Y',
    'CODE'              => 'ITEM_CODE',
    'PREVIEW_TEXT'      => 'Описание превью',
    'DETAIL_TEXT'       => 'Детальное описание',
    'PROPERTY_VALUES'   => array(
        'COLOR'     => 'Красный',                  // S
        'WEIGHT'    => 2.5,                        // N
        'SIZE'      => 'Большой',                   // L
        'RELATED'   => 100,                         // E
        'PHOTO'     => CFile::MakeFileArray('/p.jpg'),  // F
        'TAGS'      => array('tag1', 'tag2'),      // S (множ.)
        'SECTIONS'  => array(10, 20),              // G (множ.)
    ),
    'IPROPERTY_TEMPLATES' => array(       // SEO-шаблоны
        'TITLE'       => '{%= NAME %}',
        'META_TITLE'  => '{%= NAME %}',
        'META_DESCR'  => '{%= PREVIEW_TEXT %}',
    ),
);

$ID = $el->Add($arLoadArray, false, true);  // false=не обновлять поиск, true=авто UID
if ($ID > 0) {
    echo "Добавлен элемент с ID: $ID";
}
```

---

## 6. Обновление элемента

```php
$el = new CIBlockElement();

$arUpdateArray = Array(
    'NAME'           => 'Новое название',
    'CODE'           => 'NEW_CODE',
    'ACTIVE'         => 'Y',
    'SORT'           => 100,
    'ACTIVE_FROM'    => '2024-01-01',
    'ACTIVE_TO'      => '2024-12-31',
    'PREVIEW_PICTURE'=> CFile::MakeFileArray('/new_preview.jpg'),
    'DETAIL_PICTURE'=> CFile::MakeFileArray('/new_detail.jpg'),
    'PROPERTY_VALUES' => array(
        'COLOR'  => 'Синий',
        'WEIGHT' => 3.0,
    ),
    'IBLOCK_SECTION_ID' => 8,    // перенос в другой раздел
);

$arResult = $el->Update($ID, $arUpdateArray, false, true);
if ($arResult === true) {
    echo "Обновлено успешно";
} else {
    echo "Ошибка: " . $el->LAST_ERROR;
}
```

---

## 7. Получение информации о свойстве

### CIBlockProperty::GetList

```php
// Получить все свойства инфоблока
$dbRes = CIBlockProperty::GetList(
    array("sort" => "asc", "by" => "name"),
    array("IBLOCK_ID" => $IBLOCK_ID)
);
while ($arProp = $dbRes->Fetch()) {
    // $arProp:
    //   ID, NAME, CODE, XML_ID, SORT, ACTIVE
    //   PROPERTY_TYPE: S/N/L/E/F/G
    //   MULTIPLE: Y/N
    //   USER_TYPE: пользовательский тип (UserID, directory, video, HTML, DateTime...)
    //   LINK_IBLOCK_ID: для типов E/G — ID связанного инфоблока
    //   WITH_DESCRIPTION: Y/N — поддерживает ли описание
    //   IS_REQUIRED: Y/N — обязательность
    //   ENUM_CAPTION: для типов E/G
}

// Получить свойство по CODE
$dbRes = CIBlockProperty::GetList(array(), array(
    "IBLOCK_ID" => $IBLOCK_ID,
    "CODE"      => "COLOR"
));
$arProp = $dbRes->Fetch();

// Получить свойство по ID
$dbRes = CIBlockProperty::GetList(array(), array("ID" => 42));
$arProp = $dbRes->Fetch();

// Получить все ценности списка
$dbRes = CIBlockPropertyEnum::GetList(
    array("SORT" => "ASC", "VALUE" => "ASC"),
    array("PROPERTY_ID" => $PROP_ID)
);
while ($arEnum = $dbRes->Fetch()) {
    // $arEnum: ID, PROPERTY_ID, VALUE, XML_ID, SORT, DEF, VALUE_ENUM
}
```

---

## 8. Пользовательские типы свойств

```php
// userID — ссылка на пользователя
$arFields = array('USER_PROP' => 5);                           // по ID
$arFields = array('USER_PROP' => array('LOGIN' => 'admin'));   // по логину
$arFields = array('USER_PROP' => array('EMAIL' => 'test@test')); // по email
CIBlockElement::SetPropertyValuesEx($ID, $IBLOCK_ID, $arFields);

// video — видео (строка с iframe/embed)
$arFields = array('VIDEO_PROP' => '<iframe src="https://youtube.com/watch?v=xxx"></iframe>');
CIBlockElement::SetPropertyValuesEx($ID, $IBLOCK_ID, $arFields);
// Или через SetPropertyValueCode для типа S:user_type=video
\CIBlockElement::SetPropertyValueCode($ID, 'VIDEO_PROP', '<iframe src="..."/>');

// HTML
$arFields = array('HTML_PROP' => '<b>Жирный текст</b>');
CIBlockElement::SetPropertyValuesEx($ID, $IBLOCK_ID, $arFields);

// date / datetime
$arFields = array('DATE_PROP' => '2024-01-01 12:00:00');
CIBlockElement::SetPropertyValuesEx($ID, $IBLOCK_ID, $arFields);

// directory (HLB) — работает как E, но через HL-блок
$arFields = array('DIR_PROP' => 'Значение UF_NAME из HLB');
CIBlockElement::SetPropertyValuesEx($ID, $IBLOCK_ID, $arFields);

// URL (UserTypeURL)
$arFields = array('URL_PROP' => 'https://example.com');
CIBlockElement::SetPropertyValuesEx($ID, $IBLOCK_ID, $arFields);
```

---

## 9. Поиск существующих элементов (для обновления)

```php
// Получить список элементов с фильтрацией
$arFilter = array(
    "IBLOCK_ID"       => $IBLOCK_ID,
    "!CHECK_PERMISSIONS" => "N",
    "=XML_ID"         => "ITEM_XML_ID",   // точное совпадение
    "%CODE"           => "part_code",     // частичное совпадение
    "ID"              => array(100, 200),
);

$dbRes = CIBlockElement::GetList(
    array("SORT" => "asc"),
    $arFilter,
    false,
    false,
    array("ID", "NAME", "XML_ID", "CODE", "IBLOCK_SECTION_ID", "PROPERTY_42")
);
while ($arElement = $dbRes->Fetch()) {
    // $arElement: ID, NAME, ..., PROPERTY_42
}

// Массовая загрузка свойств всех элементов (batch)
$dbRes = CIBlockElement::GetPropertyValues(
    $IBLOCK_ID,
    array("ID" => array(100, 200, 300)),
    true,                                  // multiple = да
    array("ID" => array(42, 43, 44))      // какие свойства грузить
);
while ($arr = $dbRes->Fetch()) {
    // Структура: arr[PROP_ID] = value (или массив для множественных)
    // arr['DESCRIPTION'][PROP_ID] = description
    // arr['PROPERTY_VALUE_ID'][PROP_ID] = id записи свойства
}
```

---

## 10. Обновление поиска

```php
// Переиндексировать элемент после изменения свойств, влияющих на поиск
CIBlockElement::UpdateSearch($ID, true);
```

---

## 11. Прайс-лист

### Создание / обновление цен

```php
// Через CCatalogProduct (старый API)
$obProduct = new CCatalogProduct();

// Добавить/обновить цену
$result = CCatalogPrice::Add(array(
    "CATALOG_GROUP_ID" => 1,               // группа цен (1 = базовая)
    "PRODUCT_ID"       => $ID,
    "PRICE"            => 1000.00,
    "CURRENCY"         => "RUB",
    "QUANTITY_FROM"    => 0,
    "QUANTITY_TO"      => 0,
));

// Обновить цену
$ccPrice = new CCatalogPrice();
$arFilter = array("PRODUCT_ID" => $ID, "CATALOG_GROUP_ID" => 1);
$dbrPrice = $ccPrice->GetList(array(), $arFilter);
if ($arPrice = $dbrPrice->Fetch()) {
    $ccPrice->Update($arPrice["ID"], array("PRICE" => 1200.00, "CURRENCY" => "RUB"));
}
```

---

## 12. Остатки на складах

```php
// Обновить остаток на складе
$arStoreProduct = array(
    "PRODUCT_ID" => $ID,
    "STORE_ID"   => 1,
    "AMOUNT"     => 100.0,
);

// Bitrix D7
use Bitrix\Catalog\StoreProductTable;

$arFilter = array(
    'PRODUCT_ID' => $ID,
    'STORE_ID'   => 1,
);
$dbResult = StoreProductTable::getList(array('filter' => $arFilter, 'select' => array('ID', 'AMOUNT')));
if ($arr = $dbResult->Fetch()) {
    StoreProductTable::update($arr['ID'], array('AMOUNT' => 100));
} else {
    StoreProductTable::add(array(
        'PRODUCT_ID' => $ID,
        'STORE_ID'   => 1,
        'AMOUNT'     => 100,
    ));
}
```

---

## 13. Привязка к разделам

```php
// При добавлении элемента
$arLoadArray['IBLOCK_SECTION_ID'] = 5;          // один раздел
$arLoadArray['IBLOCK_SECTION']  = array(5, 6);  // несколько разделов

// При обновлении
$el = new CIBlockElement();
$el->Update($ID, array(
    'IBLOCK_SECTION' => array(5, 6),
), false, true, true);  // true=true — не триггерить события, чистить кэш
```

---

## 14. Вспомогательные функции для формата данных импорта

### CFile::MakeFileArray — подготовить файл из пути

```php
// Из локального файла
$arFile = CFile::MakeFileArray('/var/www/upload/image.jpg');

// Результат:
// array(
//   'name'         => 'image.jpg',
//   'type'         => 'image/jpeg',
//   'tmp_name'     => '/var/www/upload/image.jpg',
//   'remove'       => 'N',
//   'MODULE_ID'    => false,
//   'size'         => 12345,
//   'description'  => '',
//   'external_id'  => '',
// )
```

### CFile::GetFileArray — получить файл по ID

```php
$arFile = CFile::GetFileArray($FILE_ID);
// Результат — тот же формат MakeFileArray, готовый для SetPropertyValuesEx
```

### Str2Url — транслитерация (UID модуля)

```php
// Пример конвертации строки в символьный код
$name = 'Тестовый товар';
$xmlId = Str2Url($name, array('lowercase' => true));
// результат: 'tEstovyi-tovar' (зависит от settings)
```

---

## 15. Сводная таблица типов свойств и формата данных

| Тип | PHP-тип значения | Пример для SetPropertyValuesEx |
|-----|------------------|-------------------------------|
| **S** (строка) | `string` | `'COLOR' => 'Красный'` |
| **S** множ. | `array(string)` | `'TAGS' => ['t1','t2']` |
| **N** (число) | `float|int` | `'WEIGHT' => 2.5` |
| **L** (список) | `string` (VALUE) | `'SIZE' => 'Большой'` |
| **L** множ. | `array(string)` | `'CAT' => ['a','b']` |
| **E** (элемент) | `int` (ID) или `array` | `'REL' => 100` |
| **E** множ. | `array(int)` | `'RELS' => [100,200]` |
| **G** (раздел) | `int` (ID) или `array` | `'SEC' => 5` |
| **G** множ. | `array(int)` | `'SECS' => [5,10]` |
| **F** (файл) | `array` (MakeFileArray) | `'IMG' => CFile::MakeFileArray(...)` |
| **F** множ. | `array(array)` | `'IMGS' => [CFile::MakeFileArray(...)]` |
| **S+desc** | `array(VALUE, DESCRIPTION)` | `'TAG' => ['VALUE'=>'x','DESCRIPTION'=>'y']` |
| **F+desc** | `array + description` | `$f = CFile::MakeFileArray(...); $f['description']='текст';` |
| **удалить** | `false` (S/N/L) или `['del'=>'Y']` (F) | `'COLOR' => false` / `'IMG' => ['del'=>'Y']` |
| **video** | `string` (iframe) | `'VID' => '<iframe src="...">'` |
| **directory** | `string` (UF_NAME) | `'DIR' => 'Значение HLB'` |

---

## 16. Полная последовательность импорта одной строки

```php
// 1. Ищем существующий элемент
$arFilter = array('IBLOCK_ID' => $IBLOCK_ID, '=XML_ID' => $xmlId);
$dbRes = CIBlockElement::GetList(array(), $arFilter, false, false, array('ID'));
$arElement = $dbRes->Fetch();

if ($arElement) {
    // === ОБНОВЛЕНИЕ ===
    
    // Получаем текущие свойства для сравнения
    $dbResOldProps = CIBlockElement::GetProperty($IBLOCK_ID, $arElement['ID'], array(), array("ID" => array_keys($arNewProps)));
    $arOldVals = array();
    while ($arr = $dbResOldProps->Fetch()) {
        $arOldVals[] = $arr;
    }
    
    // Обновляем поля элемента
    $el = new CIBlockElement();
    $arResult = $el->Update($arElement['ID'], array(
        'NAME'       => $name,
        'PROPERTY_VALUES' => $arNewProps,
    ), false, true);
    
} else {
    // === ДОБАВЛЕНИЕ ===
    $el = new CIBlockElement();
    $arLoadArray = array(
        'IBLOCK_ID'      => $IBLOCK_ID,
        'IBLOCK_SECTION_ID' => $sectionId,
        'NAME'           => $name,
        'ACTIVE'         => 'Y',
        'XML_ID'         => $xmlId,
        'PROPERTY_VALUES' => $arNewProps,
    );
    $ID = $el->Add($arLoadArray, false, true);
    if ($ID > 0) {
        // Индексируем, если нужны поисковые свойства
        CIBlockElement::UpdateSearch($ID, true);
    }
}
```
