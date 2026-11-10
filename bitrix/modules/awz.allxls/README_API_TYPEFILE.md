```php 
use Bitrix\Main\Loader;
Loader::includeModule('iblock');

$elementId = 456; 
$iblockId = 5;
$propertyCode = 'MORE_PHOTO'; // Символьный код свойства

// Шаг 1: Получаем текущие файлы элемента
$currentFiles = [];
$dbProps = CIBlockElement::GetProperty($iblockId, $elementId, [], ['CODE' => $propertyCode]);
while ($arProp = $dbProps->Fetch()) {
    if ($arProp['VALUE']) {
        // Сохраняем структуру старых значений, используя их ID записи в базе (PROPERTY_VALUE_ID)
        $currentFiles[$arProp['PROPERTY_VALUE_ID']] = [
            'VALUE' => $arProp['VALUE'],
            'DESCRIPTION' => $arProp['DESCRIPTION']
        ];
    }
}

// Шаг 2: Готовим новые файлы к добавлению
$newFiles = [
    CFile::MakeFileArray($_SERVER['DOCUMENT_ROOT'] . '/upload/tmp/img1.jpg'),
    CFile::MakeFileArray($_SERVER['DOCUMENT_ROOT'] . '/upload/tmp/img2.jpg')
];

// Шаг 3: Объединяем старые и новые файлы в один массив
$finalFiles = $currentFiles;
foreach ($newFiles as $fileArray) {
    if ($fileArray) {
        $finalFiles[] = ['VALUE' => $fileArray, 'DESCRIPTION' => ''];
    }
}

// Шаг 4: Обновляем свойство элемента
CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
    $propertyCode => $finalFiles
]);

```

```php 
// Чтобы изменить описание у файла, зная ID его значения в базе (например, 2801)
$propertyValueId = 2801; 

CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
    $propertyCode => [
        $propertyValueId => [
            'VALUE' => 123, // Передаем ID самого файла (из b_file), чтобы он не перезаписался
            'DESCRIPTION' => 'Новое описание для этого файла' // Меняем только описание
        ]
    ]
]);

```

> ВНИМАНИЕ (актуально для модуля): на новых версиях ядра
> `SetPropertyValuesEx` падает на файловых значениях множественных
> свойств («Cannot use a scalar value as an array»,
> `iblockelement/classes/general/iblockelement.php`), если в 'VALUE'
> передан голый int (ID файла из b_file). Модуль
> (`Services\iblock::buildFilePropertyValues`) передает сохраняемые
> записи в формате `'VALUE' => ['VALUE' => ID_файла]` — вложенный
> массив-обертка ядро распознает как существующий ID файла
> (файл не перезаливается, запись сохраняется).

```php 
// Допустим, мы хотим удалить файл, у которого ID значения свойства равен 2801
$propertyValueIdToRemove = 2801;

CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
    $propertyCode => [
        $propertyValueIdToRemove => [
            'del' => 'Y' // Флаг на удаление конкретной записи
        ]
    ]
]);

```

```php 
CIBlockElement::SetPropertyValuesEx($elementId, $iblockId, [
    $propertyCode => false // Или array() — удалит абсолютно все файлы из этого свойства у элемента
]);

```