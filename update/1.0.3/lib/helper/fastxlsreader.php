<?php
namespace Awz\Allxls\Helper;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * Быстрый потоковый читатель XLSX напрямую из zip (XMLReader + zip://-стрим).
 * Не загружает файл целиком: workbook/sharedStrings/листы читаются побайтово.
 *
 * Два режима работы:
 *  1) readSheetsHead($maxRow) — разовое чтение заголовков и сэмплов всех листов.
 *  2) Пошаговый режим с сохранением состояния: openSheet() -> readRows() ->
 *     getState()/setState(). Состояние содержит индекс листа и номер последней
 *     прочитанной строки, что позволяет продолжить парсинг XML с нужного места
 *     (стримпереоткрывается и быстро проматывается до сохранённой строки).
 */
class FastXlsReader {

    /** @var string абсолютный путь к файлу */
    private string $absPath;

    /** @var array[] список листов книги: [['NAME'=>..,'PATH'=>..],...] */
    private array $sheets = [];

    /** @var int[] итоговое количество строк по листам (из <dimension ref="A1:M100"/>) */
    private array $sheetTotalRows = [];

    /** @var string[] общие строки (sharedStrings) */
    private array $sharedStrings = [];

    /** @var array состояние пошагового чтения */
    private array $state = [
        'sheetIndex' => -1, // индекс текущего листа
        'lastRow'    => 0,  // номер последней полностью прочитанной строки
        'done'       => false,
    ];

    /** @var \XMLReader|null открытый reader текущего листа */
    private $reader = null;

    /** @var bool reader уже стоит на начале строки <row> (после fastForward) — не сдвигать */
    private bool $atRowStart = false;

    public function __construct(string $absPath)
    {
        $this->absPath = $absPath;
        $this->loadStructure();
        $this->loadSharedStrings();
    }

    public function __destruct()
    {
        $this->close();
    }

    /**
     * Список листов книги: [['NAME'=>..,'PATH'=>..],...]
     * @return array[]
     */
    public function getSheets(): array
    {
        return $this->sheets;
    }

    /**
     * Индекс листа по имени (для readRow()/getTotalRows()).
     * @return int индекс или -1, если лист не найден
     */
    public function getSheetIndexByName(string $name): int
    {
        foreach ($this->sheets as $idx => $sheet) {
            if ((string)$sheet['NAME'] === $name) {
                return (int)$idx;
            }
        }
        return -1;
    }

    /**
     * Итоговое количество строк листа (из <dimension>).
     * 0 — если определить не удалось.
     */
    public function getTotalRows(int $sheetIndex): int
    {
        return (int)($this->sheetTotalRows[$sheetIndex] ?? 0);
    }

    /**
     * Быстрое чтение одной строки листа напрямую из zip (аналог readSheetRow
     * из Pimport, но без загрузки книги через PhpSpreadsheet).
     *
     * Стрим открывается, проматывается до нужной строки и читается ровно она.
     *
     * @param int $sheetIndex индекс листа (порядок в getSheets())
     * @param int $rowNum номер строки (1-based)
     * @return array|null плотный массив значений; null — строка за пределами листа
     */
    public function readRow(int $sheetIndex, int $rowNum): ?array
    {
        if (!isset($this->sheets[$sheetIndex]) || $rowNum < 1) {
            return null;
        }

        $stream = $this->sheetStream($this->sheets[$sheetIndex]['PATH']);
        $reader = new \XMLReader();
        if (!$reader->open($stream, null, LIBXML_NOWARNING)) {
            return null;
        }

        // докрутка до нужной строки; false — в потоке нет строк с номером >= rowNum
        $rowExists = true;
        $atStart = false;
        if ($rowNum > 1) {
            $rowExists = $this->fastForward($reader, $rowNum - 1);
            // reader стоит на начале строки rowNum — parseRows не должен её сдвигать
            $atStart = $rowExists;
        }

        $rows = [];
        if ($rowExists) {
            $this->parseRows($reader, $rows, $rowNum, 1, $atStart);
        }
        $reader->close();

        if (isset($rows[$rowNum])) {
            $normalized = $this->normalizeRows([$rowNum => $rows[$rowNum]]);
            return $normalized[$rowNum] ?? [];
        }

        // строка есть в потоке, но пустая (нет ячеек со значениями) — возвращаем пустой массив,
        // null зарезервирован для признака "лист дочитан"
        return $rowExists ? [] : null;
    }

    /**
     * Пошаговое чтение следующей строки открытого листа (после openSheet()).
     * В отличие от readRows() возвращает КАЖДУЮ строку XML, включая пустые
     (без ячеек со значениями) — это важно для пошагового импорта,
     * где пустые строки учитываются в статистике и сдвигают курсор.
     *
     * @return array{row: int, data: ?array}
     *         row=0, data=null — лист дочитан (EOF);
     *         row=номер, data=[] — строка есть, но пустая;
     *         row=номер, data=[...] — плотный массив значений строки
     */
    public function readNextRow(): array
    {
        if (!$this->reader instanceof \XMLReader) {
            return ['row' => 0, 'data' => null];
        }
        $reader = $this->reader;

        while (true) {
            // после fastForward reader уже стоит на элементе <row>:
            // первый read() пропустил бы эту строку (потеря первой строки хита)
            if (!$this->atRowStart) {
                if (!$reader->read()) {
                    $this->state['done'] = true;
                    return ['row' => 0, 'data' => null];
                }
            }
            $this->atRowStart = false;

            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $rowNum = (int)$reader->getAttribute('r');
            if ($rowNum <= 0) {
                $rowNum = (int)$this->state['lastRow'] + 1;
            }

            $data = [];
            if (!$reader->isEmptyElement) {
                while ($reader->read()) {
                    if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'c') {
                        $ref = (string)$reader->getAttribute('r');
                        $cellType = (string)$reader->getAttribute('t');

                        $colIndex = 0;
                        if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $m)) {
                            $colIndex = Coordinate::columnIndexFromString($m[1]) - 1;
                        } else {
                            $colIndex = count($data);
                        }

                        $value = '';
                        if (!$reader->isEmptyElement) {
                            while ($reader->read()) {
                                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'v') {
                                    $value = $reader->readString();
                                } elseif ($reader->nodeType === \XMLReader::ELEMENT && $cellType === 'inlineStr' && $reader->localName === 't') {
                                    $value = $reader->readString();
                                } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'c') {
                                    break;
                                }
                            }
                        }

                        // подстановка строки из sharedStrings
                        if ($cellType === 's' && $value !== '') {
                            $value = $this->sharedStrings[(int)$value] ?? '';
                        }

                        if ($value !== '') {
                            $data[$colIndex] = $value;
                        }
                    } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'row') {
                        break;
                    }
                }
            }

            $this->state['lastRow'] = $rowNum;

            // нормализация в плотный массив (как в normalizeRows)
            $maxCol = $data ? max(array_keys($data)) + 1 : 0;
            $line = [];
            for ($i = 0; $i < $maxCol; $i++) {
                $line[] = (string)($data[$i] ?? '');
            }
            return ['row' => $rowNum, 'data' => $line];
        }
    }

    /**
     * Разовое чтение первых строк всех листов (заголовки + сэмплы).
     *
     * @param int $maxRow сколько строк прочитать (заголовок + сэмплы)
     * @return array[] список листов: ['NAME'=>, 'INDEX'=>, 'ROWS'=>[номерСтроки=>[...],...]]
     * @throws \Exception
     */
    public function readSheetsHead(int $maxRow): array
    {
        $result = [];
        foreach ($this->sheets as $sheetIndex => $sheet) {
            $stream = $this->sheetStream($sheet['PATH']);
            $reader = new \XMLReader();
            if (!$reader->open($stream, null, LIBXML_NOWARNING)) {
                continue;
            }

            $rows = [];
            $this->parseRows($reader, $rows, $maxRow, null);
            $reader->close();

            $normalized = $this->normalizeRows($rows);
            if (!empty($normalized)) {
                $result[] = ['NAME' => $sheet['NAME'], 'INDEX' => $sheetIndex, 'ROWS' => $normalized];
            }
        }

        if (empty($result)) {
            throw new \Exception('NO_SHEETS_READ');
        }
        return $result;
    }

    /**
     * Открытие листа для пошагового чтения. Если в состоянии указан прогресс —
     * стрим проматывается до следующей строки.
     *
     * @param int $sheetIndex индекс листа (порядок в getSheets())
     * @return bool
     */
    public function openSheet(int $sheetIndex): bool
    {
        $this->close();
        if (!isset($this->sheets[$sheetIndex])) {
            return false;
        }

        $stream = $this->sheetStream($this->sheets[$sheetIndex]['PATH']);
        $reader = new \XMLReader();
        if (!$reader->open($stream, null, LIBXML_NOWARNING)) {
            return false;
        }
        $this->reader = $reader;
        $this->state['sheetIndex'] = $sheetIndex;

        // докрутка до последней прочитанной строки (продолжение с нужного места)
        $skipTo = (int)($this->state['lastRow'] ?? 0);
        if ($skipTo > 0) {
            // true — reader стоит на начале строки (первая непрочитанная);
            // readNextRow() в этом случае НЕ должен вызывать read() перед ней
            $this->atRowStart = $this->fastForward($reader, $skipTo);
        }
        return true;
    }

    /**
     * Чтение порции строк текущего листа. Состояние обновляется.
     *
     * @param int $maxRows максимум строк за шаг
     * @param int $maxRowNumber не читать строки с номером больше этого (0 — без ограничения)
     * @return array[номерСтроки => [...]] плотные строки
     */
    public function readRows(int $maxRows = 250, int $maxRowNumber = 0): array
    {
        if (!$this->reader instanceof \XMLReader) {
            return [];
        }

        $rows = [];
        $this->parseRows($this->reader, $rows, $maxRowNumber, $maxRows);

        if (!empty($rows)) {
            $last = max(array_keys($rows));
            if ($last > (int)$this->state['lastRow']) {
                $this->state['lastRow'] = $last;
            }
        }
        if ($maxRows > 0 && count($rows) < $maxRows) {
            $this->state['done'] = true; // лист дочитан
        }

        return $this->normalizeRows($rows);
    }

    /**
     * Сериализуемое состояние чтения (можно хранить в профиле и продолжать позже).
     * @return array
     */
    public function getState(): array
    {
        return $this->state;
    }

    /**
     * Восстановление состояния чтения (до openSheet()).
     * @param array $state
     */
    public function setState(array $state): void
    {
        $this->state = array_merge($this->state, $state);
    }

    /**
     * Закрытие открытых ресурсов.
     */
    public function close(): void
    {
        if ($this->reader instanceof \XMLReader) {
            $this->reader->close();
            $this->reader = null;
        }
    }

    /**
     * Структура книги из workbook.xml + rels (файлы небольшие, читаем целиком через DOMDocument).
     */
    private function loadStructure(): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->absPath) !== true) {
            throw new \Exception('ZIP_OPEN_FAIL');
        }

        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) {
            $zip->close();
            throw new \Exception('WORKBOOK_READ_FAIL');
        }

        $relTargets = [];
        $relDoc = new \DOMDocument();
        if ($relDoc->loadXML($rels)) {
            foreach ($relDoc->getElementsByTagName('Relationship') as $rel) {
                $relTargets[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }

        $wbDoc = new \DOMDocument();
        if (!$wbDoc->loadXML($wb)) {
            $zip->close();
            throw new \Exception('WORKBOOK_PARSE_FAIL');
        }
        foreach ($wbDoc->getElementsByTagName('sheet') as $sheetNode) {
            $name = $sheetNode->getAttribute('name');
            $rid = $sheetNode->getAttribute('r:id');
            if ($rid === '') {
                $rid = $sheetNode->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            }
            $target = $relTargets[$rid] ?? '';
            if ($target === '') continue;
            $target = ltrim(str_replace('\\', '/', $target), '/');
            if (strpos($target, 'xl/') !== 0) {
                $target = 'xl/' . $target;
            }
            $this->sheets[] = ['NAME' => $name, 'PATH' => $target];
            $this->sheetTotalRows[count($this->sheets) - 1] = $this->readDimensionRows($target);
        }
        $zip->close();
    }

    /**
     * Итоговое количество строк листа из тега <dimension ref="A1:M100"/>.
     * Тег находится в начале XML листа — читается мгновенно.
     * Если dimension отсутствует/некорректен — возвращается 0.
     */
    private function readDimensionRows(string $path): int
    {
        $reader = new \XMLReader();
        if (!$reader->open($this->sheetStream($path), null, LIBXML_NOWARNING)) {
            return 0;
        }
        $total = 0;
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'dimension') {
                $ref = (string)$reader->getAttribute('ref');
                if (preg_match('/(\d+)\s*$/', $ref, $m)) {
                    $total = (int)$m[1];
                }
                break;
            }
            // protection от битых файлов без dimension
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'sheetData') {
                break;
            }
        }
        $reader->close();
        return $total;
    }

    /**
     * Потоковое чтение sharedStrings.xml (через zip://-стрим, чанками).
     */
    private function loadSharedStrings(): void
    {
        // Проверяем наличие файла в архиве, чтобы XMLReader не выдавал warning
        $testZip = new \ZipArchive();
        $testZip->open($this->absPath);
        $hasSharedStrings = $testZip->locateName('xl/sharedStrings.xml') !== false;
        $testZip->close();
        if (!$hasSharedStrings) {
            return;
        }

        $reader = new \XMLReader();
        if ($reader->open($this->sheetStream('xl/sharedStrings.xml'), null, LIBXML_NOWARNING)) {
            $current = null;
            $currentText = '';
            $inT = false;
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT) {
                    if ($reader->localName === 'si') {
                        $current = '';
                    } elseif ($reader->localName === 't') {
                        $inT = true;
                        $currentText = '';
                    }
                } elseif ($reader->nodeType === \XMLReader::END_ELEMENT) {
                    if ($reader->localName === 't') {
                        $current .= $currentText;
                        $inT = false;
                    } elseif ($reader->localName === 'si') {
                        $this->sharedStrings[] = $current;
                        $current = null;
                    }
                } elseif ($reader->nodeType === \XMLReader::TEXT && $inT) {
                    $currentText .= $reader->value;
                }
            }
            $reader->close();
        }
    }

    /**
     * URI потока для члена архива (XMLReader читает его побайтово, без загрузки в память).
     * @param string $path путь внутри xlsx (например, xl/worksheets/sheet1.xml)
     * @return string
     */
    private function sheetStream(string $path): string
    {
        return 'zip://' . $this->absPath . '#' . $path;
    }

    /**
     * Быстрая прокрутка стрима до строки с номером больше $afterRow
     * (продолжение парсинга с сохранённой позиции).
     */
    private function fastForward(\XMLReader $reader, int $afterRow): bool
    {
        while ($reader->read()) {
            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'row') {
                $rowNum = (int)$reader->getAttribute('r');
                if ($rowNum > $afterRow) {
                    return true; // reader стоит на первой непрочитанной строке
                }
                if ($reader->isEmptyElement) continue;
                // проматываем содержимое строки
                while ($reader->read()) {
                    if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'row') {
                        break;
                    }
                }
            }
        }
        return false; // строки в потоке закончились
    }

    /**
     * Потоковый разбор строк листа из XML.
     *
     * @param \XMLReader $reader открытый reader листа
     * @param array $rows [номерСтроки => [индексКолонки => значение]] (накопительный)
     * @param int $maxRowNumber максимальный номер строки (0 — без ограничения)
     * @param int|null $limitRows максимум строк за вызов (null — без ограничения)
     */
    private function parseRows(\XMLReader $reader, array &$rows, int $maxRowNumber, ?int $limitRows, bool $atStart = false): void
    {
        $curRow = 0;
        $curRowData = [];
        $read = 0;
        $stop = false;

        // $atStart: reader уже стоит на элементе <row> — первый read() пропустил бы её
        $advance = !$atStart;
        while (!$stop) {
            if ($advance && !$reader->read()) break;
            $advance = true;
            if ($reader->nodeType === \XMLReader::ELEMENT) {
                if ($reader->localName === 'row') {
                    // Сохраняем предыдущую строку перед началом новой
                    if (!empty($curRowData)) {
                        $rows[$curRow] = $curRowData;
                        $read++;
                        if ($limitRows !== null && $read >= $limitRows) {
                            $stop = true;
                            break;
                        }
                    }

                    $curRow = (int)$reader->getAttribute('r');
                    if ($curRow <= 0) {
                        $curRow = ((int)array_key_last($rows) ?: 0) + 1;
                    }
                    if ($maxRowNumber > 0 && $curRow > $maxRowNumber) {
                        $curRowData = [];
                        break;
                    }
                    $curRowData = [];

                } elseif ($reader->localName === 'c') {
                    $ref = (string)$reader->getAttribute('r');
                    $cellType = (string)$reader->getAttribute('t');

                    // Индекс колонки
                    $colIndex = 0;
                    if ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $m)) {
                        $colIndex = Coordinate::columnIndexFromString($m[1]) - 1;
                    } else {
                        $colIndex = count($curRowData);
                    }

                    // Значение внутри тега ячейки <c>
                    $value = '';
                    if (!$reader->isEmptyElement) {
                        while ($reader->read()) {
                            if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'v') {
                                $value = $reader->readString();
                            } elseif ($reader->nodeType === \XMLReader::ELEMENT && $cellType === 'inlineStr' && $reader->localName === 't') {
                                $value = $reader->readString();
                            } elseif ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'c') {
                                break;
                            }
                        }
                    }

                    // Подстановка строки из sharedStrings
                    if ($cellType === 's' && $value !== '') {
                        $idx = (int)$value;
                        $value = $this->sharedStrings[$idx] ?? '';
                    }

                    if ($value !== '') {
                        $curRowData[$colIndex] = $value;
                    }
                }
            }
        }

        // Последняя строка листа при штатном завершении потока
        if (!$stop && !empty($curRowData)) {
            $rows[$curRow] = $curRowData;
        }
    }

    /**
     * Нормализация: разреженный массив строк -> плотный, с сохранением номеров строк.
     * @param array $rows
     * @return array
     */
    private function normalizeRows(array $rows): array
    {
        if (empty($rows)) {
            return [];
        }
        $maxCol = 0;
        foreach ($rows as $r) {
            $maxCol = max($maxCol, count($r) ? max(array_keys($r)) + 1 : 0);
        }
        $normalized = [];
        foreach ($rows as $rowNum => $r) {
            $line = [];
            for ($i = 0; $i < $maxCol; $i++) {
                $line[] = (string)($r[$i] ?? '');
            }
            $normalized[$rowNum] = $line;
        }
        return $normalized;
    }
}
