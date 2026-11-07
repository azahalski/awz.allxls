<?php
namespace Awz\Allxls\Helper;

/**
 * Быстрый потоковый читатель XML/YML (XMLReader, побайтово, файл не грузится в память).
 *
 * «Лист» — это повторяющийся элемент XML по заданному пути произвольной глубины,
 * например items->item, categories->section, shop->offers->offer.
 * Пути передаются в конструктор списком строк ('items->item', 'shop->offers->offer').
 *
 * Строка листа = плоская карта колонок:
 *  - тег элемента        -> ключ = имя тега (category, name)
 *  - атрибут тега        -> ключ = tag_attrимя, имя атрибута сохраняется как в XML
 *                           (<category id="111474" parentId="30149"> -> category_id, category_parentId)
 *  - вложенные теги      -> ключ = родитель_тег (image_url)
 *  - именованный тег     -> элемент с атрибутом name: текст -> тег_транслит(name)
 *                           (<warehouse name="Самара" unit="шт">5</warehouse> ->
 *                            warehouse_samara, warehouse_samara_unit + ключ warehouse + warehouse_json)
 *  - warehouse_json      -> агрегат всех именованных элементов тега в JSON:
 *                           [{"name":"Самара","unit":"шт","value":5},...]
 *  - повторяющиеся теги  -> значения склеиваются через разделитель (по умолчанию ", ")
 *
 * Два режима работы:
 *  1) readSheetsHead($maxItems) — разовое чтение шапки (union ключей) и сэмплов всех листов.
 *  2) Пошаговый режим с сохранением состояния: openSheet() -> readNextRow() ->
 *     getState()/setState(). Состояние — индекс листа, число прочитанных элементов
 *     и байтовая позиция курсора (см. ниже).
 *
 * Байтовый курсор (как у CSV): после каждого прочитанного элемента листа
 * запоминается байтовая позиция файла сразу после его закрывающего тега
 * (например после '</offer>'), она хранится в состоянии ('bytePos') и между
 * хитами reader открывается прямо на этой позиции через stream-wrapper
 * 'awzseek' — промотка с начала файла не выполняется. Позиция ищется
 * лексером по закрывающему тегу элемента листа (с учетом вложенности и
 * родителей пути). Корректно для однобайтовых кодировок (UTF-8, CP1251,
 * KOI8-R — тег ASCII-прозрачен); для UTF-16/UTF-32 — промотка seek-ом
 * от начала файла.
 */
class FastXmlReader {

	/** @var string абсолютный путь к файлу */
	private string $absPath;

	/** @var array[] список листов: [['NAME'=>..,'PATH'=>..,'SEGMENTS'=>[]],...] */
	private array $sheets = [];

	/** @var array состояние пошагового чтения */
	private array $state = [
		'sheetIndex' => -1, // индекс текущего листа
		'lastRow'    => 0,  // число полностью прочитанных элементов текущего листа
		'bytePos'    => 0,  // байтовая позиция курсора: после закрывающего тега последнего обработанного элемента (0 — с начала файла)
		'done'       => false,
	];

	/** @var \XMLReader|null открытый reader текущего листа */
	private $reader = null;

	/** @var array порядок ключей колонок для выравнивания строк (пусто — union первых N элементов) */
	private array $keys = [];

	/** @var int[] кеш итоговых количеств элементов по листам */
	private array $totalRowsCache = [];

	/** @var string разделитель повторяющихся значений */
	private string $repeatDelim = ', ';

	/** @var string[] стек открытых тегов текущего потока (для матчинга пути) */
	private array $elemStack = [];

	/** @var bool отслеживать байтовый курсор (однобайтовая кодировка, запись позиции после каждого элемента) */
	private bool $trackByte = false;

	/** @var resource|null raw-хэндл файла для поиска закрывающего тега элемента (байтовый курсор) */
	private $rawFh = null;

	/** @var string персистентный буфер лексера scanItemEnd (не читается заново на каждую строку) */
	private string $rawBuf = '';

	/** @var int байтовое смещение $this->rawBuf[0] в файле */
	private int $rawBufStart = 0;

	/** @var int текущая позиция скана внутри rawBuf */
	private int $rawBufPos = 0;

	/** @var bool читатель достиг конца потока (read()/next() вернули false):
	 * nodeType после EOF остается stale-ELEMENT — без флага seekNextItem
	 * зациклится на том же теге, а collectElement уйдет в рекурсию */
	private bool $readerEof = false;

	/** @var array карта именованных ключей колонок: ключ (tag_translitname) => исходное значение атрибута name */
	private array $namesMap = [];

	public function __construct(string $absPath, array $paths = [])
	{
		$this->absPath = $absPath;
		foreach ($paths as $path) {
			$segments = self::parsePath((string)$path);
			if (count($segments) < 2) {
				// путь должен содержать минимум родителя и элемент (items->item)
				continue;
			}
			$this->sheets[] = [
				'NAME'     => implode('->', $segments),
				'PATH'     => implode('->', $segments),
				'SEGMENTS' => $segments,
			];
		}
	}

	public function __destruct()
	{
		$this->close();
	}

	/**
	 * Разбор строки пути в сегменты ('items->item' / 'shop/offers/offer' / 'items>item')
	 * @return string[]
	 */
	public static function parsePath(string $path): array
	{
		$path = str_replace(['->', '>'], '/', (string)$path);
		$segments = [];
		foreach (explode('/', $path) as $seg) {
			$seg = trim($seg);
			if ($seg === '') continue;
			$segments[] = mb_strtolower($seg);
		}
		return $segments;
	}

	/**
	 * Список листов (путей): [['NAME','PATH','SEGMENTS'],...]
	 */
	public function getSheets(): array
	{
		return $this->sheets;
	}

	/**
	 * Индекс листа по имени (полный путь листа)
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
	 * Итоговое количество элементов листа.
	 * Определяется полным сканом файла (кеш на процесс).
	 */
	public function getTotalRows(int $sheetIndex): int
	{
		if (isset($this->totalRowsCache[$sheetIndex])) {
			return $this->totalRowsCache[$sheetIndex];
		}
		if (!isset($this->sheets[$sheetIndex])) {
			return 0;
		}
		$count = 0;
		// openStream() сбрасывает elemStack и readerEof (общие для инстанса) —
		// сохраняем состояние основного потока: getTotalRows вызывается
		// посреди чтения листа, затертый стек ломает матчинг пути в
		// seekNextItem, а stale readerEof=true после полного скана
		// завершал чтение после первого элемента
		$stackBak = $this->elemStack;
		$eofBak = $this->readerEof;
		$trackBak = $this->trackByte;
		$reader = $this->openStream($this->sheets[$sheetIndex]['SEGMENTS']);
		if ($reader) {
			$segments = $this->sheets[$sheetIndex]['SEGMENTS'];
			while ($this->seekNextItem($reader, $segments)) {
				$count++;
				// потребляем тело найденного элемента, иначе seek снова
				// сработает на том же теге (reader не сдвинулся)
				$this->skipItemBody($reader);
			}
			$reader->close();
		}
		$this->elemStack = $stackBak;
		$this->readerEof = $eofBak;
		$this->trackByte = $trackBak;
		$this->totalRowsCache[$sheetIndex] = $count;
		return $count;
	}

	/**
	 * Порядок ключей колонок для выравнивания строк (устанавливается перед openSheet)
	 */
	public function setKeys(array $keys): void
	{
		$this->keys = array_map('strval', $keys);
	}

	/**
	 * Состояние пошагового чтения
	 */
	public function getState(): array
	{
		return $this->state;
	}

	public function setState(array $state): void
	{
		if (isset($state['sheetIndex'])) $this->state['sheetIndex'] = (int)$state['sheetIndex'];
		if (isset($state['lastRow'])) $this->state['lastRow'] = max(0, (int)$state['lastRow']);
		if (isset($state['bytePos'])) $this->state['bytePos'] = max(0, (int)$state['bytePos']);
		if (isset($state['done'])) $this->state['done'] = (bool)$state['done'];
	}

	public function close(): void
	{
		if ($this->reader) {
			try { $this->reader->close(); } catch (\Throwable $e) { /* ignore */ }
			$this->reader = null;
		}
		if ($this->rawFh !== null) {
			fclose($this->rawFh);
			$this->rawFh = null;
		}
		$this->rawBuf = '';
		$this->rawBufStart = 0;
		$this->rawBufPos = 0;
	}

	/**
	 * Открытие листа с сохраненной позиции.
	 * После успешного вызова reader готов отдавать элементы начиная с state['lastRow']+1.
	 *
	 * Байтовый курсор (однобайтовые кодировки): если в состоянии есть позиция
	 * (bytePos > 0) —
	 * reader открывается сразу на ней через 'awzseek' (после закрывающего тега
	 * последнего обработанного элемента), промотка с начала файла не нужна.
	 * Иначе — промотка seek-ом от начала до элемента с номером lastRow+1.
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
		// порядок ключей не задан явно — берем union шапки (первые 30 элементов)
		if (!$this->keys) {
			$this->keys = $this->sheetHeaderKeys($sheetIndex);
		}
		$segments = $this->sheets[$sheetIndex]['SEGMENTS'];
		// смена листа — байтовая позиция предыдущего листа не действует
		if ($this->state['sheetIndex'] !== $sheetIndex) {
			$this->state['bytePos'] = 0;
		}
		$bytePos = (int)($this->state['bytePos'] ?? 0);
		if ($bytePos > 0 && $this->isByteScanFile()) {
			// валидность позиции: курсор хранится сразу после '>' закрывающего тега
			$valid = false;
			$size = (int)@filesize($this->absPath);
			if ($bytePos < $size) {
				$fh = @fopen($this->absPath, 'rb');
				if ($fh) {
					fseek($fh, $bytePos - 1);
					$valid = fread($fh, 1) === '>';
					fclose($fh);
				}
			}
			if ($valid) {
				$reader = $this->openStreamAt($bytePos, $segments);
				if ($reader) {
					// первый read() инициализирует парсер (виртуальный корень
					// awzseek-потока); дальше элементы листа отдает seekNextItem
					if ($reader->read()) {
						$this->reader = $reader;
						$this->state['sheetIndex'] = $sheetIndex;
						$this->trackByte = true;
						return true;
					}
					$reader->close();
				}
			}
			// позиция не открылась (за пределами файла/не на границе тега) —
			// фолбэк на промотку seek-ом
			$this->state['bytePos'] = 0;
		}
		$this->trackByte = $this->isByteScanFile();
		$reader = $this->openStream($segments);
		if (!$reader) {
			return false;
		}
		// промотка к элементу с номером lastRow+1
		for ($i = 0; $i < $this->state['lastRow']; $i++) {
			if (!$this->seekNextItem($reader, $segments)) {
				// сохраненная позиция за пределами файла — чтение завершено
				$reader->close();
				$this->state['done'] = true;
				return false;
			}
			$this->skipItemBody($reader);
		}
		$this->reader = $reader;
		$this->state['sheetIndex'] = $sheetIndex;
		return true;
	}

	/**
	 * Чтение следующего элемента текущего листа.
	 *
	 * @return array ['row' => номер (1-based), 'data' => array|null] — data=null, элементов больше нет
	 */
	public function readNextRow(): array
	{
		if ($this->state['done'] || $this->reader === null || !isset($this->sheets[$this->state['sheetIndex']])) {
			return ['row' => $this->state['lastRow'] + 1, 'data' => null];
		}
		$segments = $this->sheets[$this->state['sheetIndex']]['SEGMENTS'];
		if (!$this->seekNextItem($this->reader, $segments)) {
			$this->state['done'] = true;
			$this->close();
			return ['row' => $this->state['lastRow'] + 1, 'data' => null];
		}
		$values = $this->readItem($this->reader, $segments);
		if ($this->readerEof) {
			// EOF внутри тела элемента (файл оборван): читатель дальше не
			// сдвинется — это последний элемент листа
			$this->state['done'] = true;
		}
		// байтовый курсор: позиция сразу после закрывающего тега прочитанного
		// элемента (последний обработанный байт) — между хитами чтение
		// продолжается с нее без промотки (как у CSV)
		if ($this->trackByte) {
			$pos = $this->scanItemEnd($this->state['bytePos'], $segments);
			$this->state['bytePos'] = $pos >= 0 ? $pos : 0;
		}
		$this->state['lastRow']++;
		$rowNum = $this->state['lastRow'];
		$data = $this->alignRow($values);
		return ['row' => $rowNum, 'data' => $data];
	}

	/**
	 * Разовое чтение шапки и сэмплов всех листов.
	 * Возвращает по каждому листу ROWS[1..N]: строка 1 — список колонок
	 * (union ключей первых $maxItems элементов), строки 2..N+1 — данные.
	 *
	 * @param int $maxItems сколько элементов прочитать для шапки/сэмплов
	 * @return array[]
	 */
	public function readSheetsHead(int $maxItems = 30): array
	{
		$maxItems = max(1, $maxItems);
		$this->namesMap = [];
		$result = [];
		foreach ($this->sheets as $index => $sheet) {
			$segments = $sheet['SEGMENTS'];
			$keys = [];
			$matrix = []; // [itemId => [key => value]]
			$reader = $this->openStream($segments);
			$count = 0;
			if ($reader) {
				while ($count < $maxItems && $this->seekNextItem($reader, $segments)) {
					$values = $this->readItem($reader, $segments);
					foreach (array_keys($values) as $k) {
						if (!in_array($k, $keys, true)) $keys[] = $k;
					}
					$matrix[] = $values;
					$count++;
				}
				$reader->close();
			}
			$rows = [1 => $keys];
			foreach ($matrix as $i => $values) {
				$row = [];
				foreach ($keys as $k) {
					$row[] = (string)($values[$k] ?? '');
				}
				$rows[$i + 2] = $row;
			}
			$result[] = [
				'INDEX' => $index,
				'NAME'  => (string)$sheet['NAME'],
				'ROWS'  => $rows,
			];
		}
		return $result;
	}

	/**
	 * Union ключей первых 30 элементов листа (для openSheet без setKeys)
	 */
	protected function sheetHeaderKeys(int $sheetIndex): array
	{
		if (!isset($this->sheets[$sheetIndex])) return [];
		$segments = $this->sheets[$sheetIndex]['SEGMENTS'];
		$keys = [];
		$reader = $this->openStream($segments);
		if ($reader) {
			$count = 0;
			while ($count < 30 && $this->seekNextItem($reader, $segments)) {
				$values = $this->readItem($reader, $segments);
				foreach (array_keys($values) as $k) {
					if (!in_array($k, $keys, true)) $keys[] = $k;
				}
				$count++;
			}
			$reader->close();
		}
		return $keys;
	}

	/**
	 * Выравнивание карты значений строки под порядок ключей колонок
	 */
	protected function alignRow(array $values): array
	{
		$row = [];
		if ($this->keys) {
			foreach ($this->keys as $k) {
				$row[] = (string)($values[$k] ?? '');
			}
			return $row;
		}
		return array_values(array_map('strval', $values));
	}

	/**
	 * Открытие потока XML с приведением кодировки к UTF-8 (по encoding из декларации)
	 */
	protected function openStream(array $segments)
	{
		if (!is_file($this->absPath)) {
			return null;
		}
		$uri = $this->absPath;
		$head = '';
		$fh = @fopen($this->absPath, 'rb');
		if ($fh) {
			$head = (string)fread($fh, 2048);
			fclose($fh);
		}
		if (preg_match('/encoding\s*=\s*["\']([^"\']+)["\']/i', $head, $m)) {
			$enc = strtoupper(trim($m[1]));
			if ($enc !== '' && !in_array($enc, ['UTF-8', 'UTF8'], true)) {
				// имя кодировки для iconv-фильтра (CP1251, WINDOWS-1251, KOI8-R...)
				$filterEnc = str_replace(['/', '\\'], ['-'], $enc);
				$uri = 'php://filter/read=convert.iconv.'.$filterEnc.'.UTF-8/resource='.str_replace('\\', '/', $this->absPath);
			}
		}
		$reader = new \XMLReader();
		if (!$reader->open($uri, null, LIBXML_NOWARNING | LIBXML_NOERROR)) {
			return null;
		}
		$this->elemStack = [];
		$this->readerEof = false;
		return $reader;
	}

	/**
	 * Позиционирование reader на START_ELEMENT следующего элемента листа
	 * (элемент = сегмент пути на нужной глубине).
	 *
	 * @return bool false — элементов больше нет
	 */
	protected function seekNextItem(\XMLReader $reader, array $segments): bool
	{
		if ($this->readerEof) {
			return false;
		}
		$depth = count($segments);
		$leaf = $segments[$depth - 1];
		$stack = &$this->elemStack;
		// reader может уже стоять на ELEMENT (после collectElement курсор —
		// на узле, следующем за закрывающим тегом прочитанного элемента)
		$current = $reader->nodeType;
		while (true) {
			if ($current === \XMLReader::ELEMENT) {
				// самозакрытый тег: может быть элементом листа (только атрибуты)
				if ($reader->isEmptyElement) {
					$cln = $reader->localName;
					$cnt = count($stack);
					if ($cnt >= $depth - 1
						&& $depth >= 1
						&& $cln === $leaf
						&& !array_diff_assoc(array_slice($segments, 0, $depth - 1), array_slice($stack, -$depth + 1))
					) {
						return true;
					}
				} else {
					$cln = $reader->localName;
					$stack[] = $cln;
					$cnt = count($stack);
					// лист — повторяющийся элемент по СУФФИКСУ пути:
					// offers->offer матчится и внутри <shop><offers>; items->item — на любом уровне
					if ($cnt >= $depth
						&& $cln === $leaf
						&& !array_diff_assoc($segments, array_slice($stack, -$depth))
					) {
						// элемент будет потреблен collectElement (END не пройдет
						// через seekNextItem) — снимаем его со стека
						array_pop($stack);
						return true;
					}
				}
			} elseif ($current === \XMLReader::END_ELEMENT) {
				array_pop($stack);
			}
			if (!$reader->read()) {
				$this->readerEof = true;
				return false;
			}
			$current = $reader->nodeType;
		}
	}

	/**
	 * Пропуск тела текущего элемента (reader стоит на его START_ELEMENT).
	 * По завершении reader стоит на узле после закрывающего тега.
	 */
	protected function skipItemBody(\XMLReader $reader): void
	{
		if ($reader->isEmptyElement) {
			if (!$reader->read()) {
				$this->readerEof = true;
			}
			return;
		}
		// C-level пропуск всего поддерева элемента (без PHP-цикла и сборки ключей/транслита) —
		// используется в getTotalRows и промотке openSheet: там значения не нужны
		if (!$reader->next()) {
			$this->readerEof = true;
		}
	}

	/**
	 * Чтение флэт-карты значений текущего элемента (reader стоит на его START_ELEMENT).
	 * Атрибуты элемента -> leaf_attrключ, текст и вложенные теги -> рекурсивно.
	 *
	 * @return array [key => value] (повторы склеены разделителем)
	 */
	protected function readItem(\XMLReader $reader, array $segments): array
	{
		$leafTag = (string)$segments[count($segments) - 1];
		$leaf = $this->makeKey([$leafTag]);
		$values = [];
		$jsonValues = []; // именованные вложенные теги: [тег => список объектов]
		$leafNameAttr = '';
		$leafAttrs = []; // атрибуты элемента листа кроме name
		if ($reader->hasAttributes) {
			while ($reader->moveToNextAttribute()) {
				$attrName = $reader->localName;
				if ($attrName === '' || $attrName === 'xmlns') continue;
				if ($attrName === 'name') {
					$leafNameAttr = trim((string)$reader->value);
					continue;
				}
				$leafAttrs[$attrName] = (string)$reader->value;
			}
			$reader->moveToElement();
		}
		if ($leafNameAttr !== '') {
			// именованный элемент листа: ключи от имени тега
			// <warehouse name="Самара" unit="шт">5</warehouse> -> warehouse_samara, warehouse_samara_unit
			$leaf = $this->makeKey([ProfilesHelper::transliterate($leafTag), ProfilesHelper::transliterate($leafNameAttr)]);
			$this->namesMap[$leaf] = $leafNameAttr;
			foreach ($leafAttrs as $attrName => $attrValue) {
				$this->addValue($values, $this->makeKey([$leaf, $attrName], true), $attrValue);
			}
		} else {
			foreach ($leafAttrs as $attrName => $attrValue) {
				$this->addValue($values, $this->makeKey([$leaf, $attrName], true), $attrValue);
			}
		}
		// текст элемента и вложенные теги
		if ($reader->isEmptyElement) {
			// самозакрытый элемент листа — cursor двигаем дальше
			if (!$reader->read()) {
				$this->readerEof = true;
			}
		} else {
			$text = $this->collectElement($reader, $leaf, $values, $jsonValues);
			if ($text !== '') {
				$this->addValue($values, $leaf, $text);
				if ($leafNameAttr !== '') {
					// общий ключ тега: warehouse
					$this->addValue($values, $this->makeKey([ProfilesHelper::transliterate($leafTag)]), $text);
					// объект для JSON-агрегата: {"name":"Самара","unit":"шт","value":5}
					$jsonObj = ['name' => $leafNameAttr];
					foreach ($leafAttrs as $attrName => $attrValue) {
						$jsonObj[$attrName] = $attrValue;
					}
					$jsonObj['value'] = is_numeric($text) ? ($text + 0) : $text;
					$jsonValues[$leafTag][] = $jsonObj;
				}
			}
		}
		// у тега category колонка parentId присутствует всегда (пусто, если атрибута нет)
		if (mb_strtolower($leafTag) === 'category') {
			$pidKey = $this->makeKey([$leaf, 'parentId'], true);
			if (!isset($values[$pidKey])) {
				$values[$pidKey] = '';
			}
		}
		// склейка повторов
		foreach ($values as $key => $val) {
			if (is_array($val)) {
				$values[$key] = implode($this->repeatDelim, array_map('trim', $val));
			}
		}
		// JSON-агрегаты именованных тегов: warehouse_json
		foreach ($jsonValues as $tagName => $list) {
			if (empty($list)) continue;
			$key = $this->makeKey([ProfilesHelper::transliterate((string)$tagName)]) . '_json';
			$values[$key] = json_encode($list, JSON_UNESCAPED_UNICODE);
		}
		return $values;
	}

	/**
	 * Карта именованных колонок: ключ (tag_translitname) => исходное значение
	 * атрибута name (реальное название на русском). Заполняется при чтении
	 * элементов (readSheetsHead/readNextRow), сбрасывается в readSheetsHead.
	 */
	public function getNamesMap(): array
	{
		return $this->namesMap;
	}

	/**
	 * Сбор значений вложенного элемента (reader стоит на его START_ELEMENT).
	 * Текст элемента сохраняется под ключом prefix, вложенные теги — prefix_Тег
	 * (+ атрибуты как prefix_тег_attrимя, имя атрибута сохраняется как в XML).
	 * Элементы с атрибутом name формируют ключи от имени тега:
	 * <warehouse name="Самара" unit="шт">5</warehouse> ->
	 *   warehouse (текст), warehouse_samara (текст), warehouse_samara_unit (атрибут);
	 * каждый такой элемент добавляется в $jsonValues[тег] для агрегата warehouse_json.
	 * Возвращает текст самого элемента; reader после — на узле после закрывающего тега.
	 */
	protected function collectElement(\XMLReader $reader, string $prefix, array &$values, array &$jsonValues = []): string
	{
		$name = $reader->localName;
		$text = '';
		$depth = 0; // одноименные вложенные теги
		if ($reader->isEmptyElement) {
			$reader->read();
			return $text;
		}
		if (!$reader->read()) {
			// файл оборван сразу после открывающего тега — тела элемента нет
			$this->readerEof = true;
			return $text;
		}
		while (true) {
			$type = $reader->nodeType;
			if ($type === \XMLReader::END_ELEMENT && $reader->localName === $name) {
				if ($depth > 0) {
					$depth--;
					if (!$reader->read()) break;
					continue;
				}
				$reader->read();
				break;
			}
			if ($type === \XMLReader::ELEMENT) {
				$childName = $reader->localName;
				$childPrefix = $this->makeKey([$prefix, $childName]);
				$nameAttr = '';
				$childAttrs = []; // атрибуты кроме name: [имя => значение]
				if ($reader->hasAttributes) {
					while ($reader->moveToNextAttribute()) {
						$attrName = $reader->localName;
						if ($attrName === '' || $attrName === 'xmlns') continue;
						if ($attrName === 'name') {
							// <param name="длина">10</param> -> ключ param_dlina
							$nameAttr = trim((string)$reader->value);
							continue;
						}
						$childAttrs[$attrName] = (string)$reader->value;
					}
					$reader->moveToElement();
				}
				if ($nameAttr !== '') {
					// именованный элемент: ключи формируются от имени тега (без префикса родителя)
					$childPrefix = $this->makeKey([ProfilesHelper::transliterate($childName), ProfilesHelper::transliterate($nameAttr)]);
					$this->namesMap[$childPrefix] = $nameAttr;
					foreach ($childAttrs as $attrName => $attrValue) {
						// <warehouse name="Самара" unit="шт"> -> warehouse_samara_unit
						$this->addValue($values, $this->makeKey([$childPrefix, $attrName], true), $attrValue);
					}
				} else {
					foreach ($childAttrs as $attrName => $attrValue) {
						$this->addValue($values, $this->makeKey([$childPrefix, $attrName], true), $attrValue);
					}
				}
				$childText = $this->collectElement($reader, $childPrefix, $values, $jsonValues);
				if ($this->readerEof) {
					// EOF внутри вложенного элемента: reader не сдвинулся —
					// дальше читать нечего (иначе вечный цикл на stale-узле)
					break;
				}
				if ($childText !== '') {
					$this->addValue($values, $childPrefix, $childText);
					if ($nameAttr !== '') {
						// общий ключ тега: все значения именованных элементов warehouse
						$this->addValue($values, $this->makeKey([ProfilesHelper::transliterate($childName)]), $childText);
						// объект для JSON-агрегата: {"name":"Самара","unit":"шт","value":5}
						$jsonObj = ['name' => $nameAttr];
						foreach ($childAttrs as $attrName => $attrValue) {
							$jsonObj[$attrName] = $attrValue;
						}
						$jsonObj['value'] = is_numeric($childText) ? ($childText + 0) : $childText;
						$jsonValues[$childName][] = $jsonObj;
					}
				}
				// у тега category колонка parentId присутствует всегда (пусто, если атрибута нет)
				if ($nameAttr === '' && mb_strtolower($childName) === 'category') {
					$pidKey = $this->makeKey([$childPrefix, 'parentId'], true);
					if (!isset($values[$pidKey])) {
						$values[$pidKey] = '';
					}
				}
				continue;
			}
			if ($type === \XMLReader::TEXT || $type === \XMLReader::CDATA) {
				$text .= (string)$reader->value;
			}
			if (!$reader->read()) {
				$this->readerEof = true;
				break;
			}
		}
		return trim($text);
	}

	/**
	 * Ключ колонки: имя атрибута сохраняется как в XML (category_parentId),
	 * склейка сегментов "_"
	 * @param string[] $parts
	 */
	protected function makeKey(array $parts, bool $isAttr = false): string
	{
		$lastIdx = count($parts) - 1;
		foreach ($parts as $i => $part) {
			if ($isAttr && $i === $lastIdx) {
				$parts[$i] = trim((string)$part);
			} else {
				$parts[$i] = strtolower(preg_replace('/[^A-Za-z0-9]/', '_', (string)$part));
			}
		}
		$result = trim(implode('_', array_filter($parts, function ($v) { return $v !== ''; })), '_');
		return str_replace('__', '_', $result);
	}

	/**
	 * Добавление значения с накоплением повторов
	 */
	protected function addValue(array &$values, string $key, string $value): void
	{
		if ($key === '') return;
		if (isset($values[$key])) {
			if (is_array($values[$key])) {
				$values[$key][] = $value;
			} else {
				$values[$key] = [$values[$key], $value];
			}
		} else {
			$values[$key] = $value;
		}
	}

	/**
	 * Файл с однобайтовой ASCII-прозрачной кодировкой (UTF-8, CP1251, KOI8-R
	 * и др.): байтовый курсор корректен, так как закрывающий тег элемента
	 * листа ('</offer>') — чистый ASCII и побайтово одинаков в этих кодировках.
	 * Для многобайтовых (UTF-16/UTF-32 — ASCII в 2/4 байта) курсор неприменим.
	 */
	protected function isByteScanFile(): bool
	{
		$fh = @fopen($this->absPath, 'rb');
		if (!$fh) {
			return false;
		}
		$head = (string)fread($fh, 2048);
		fclose($fh);
		if (preg_match('/encoding\s*=\s*["\']([^"\']+)["\']/i', $head, $m)) {
			$enc = strtoupper(trim($m[1]));
			$enc = str_replace(['/', '\\', '-', ' '], '', $enc);
			if (str_starts_with($enc, 'UTF16') || str_starts_with($enc, 'UTF32')) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Поиск байтового конца текущего элемента листа (байтовый курсор):
	 * от позиции $from строковым лексером (без XML-парсинга) ищется
	 * закрывающий тег элемента листа ('</offer>' для offers->offer),
	 * с учетом вложенности одноименных тегов и родителей пути (матчинг
	 * по суффиксу стека — как в seekNextItem). Стек сеется родителями пути:
	 * позиция $from находится внутри контекста родителей (после закрывающего
	 * тега предыдущего элемента листа), родители снова открывшиеся после
	 * этой точки допушатся штатно — суффикс-матчинг их и так находит.
	 * Для самозакрытого элемента листа ('<offer/>') концом считается сам тег.
	 *
	 * Буфер лексера персистентный: вызов с $from = текущий курсор продолжает
	 * скан без чтения файла (элемент уже в буфере); позиция за курсором
	 * (после close/смены листа/невалидной позиции) — буфер сбрасывается.
	 *
	 * @param int $from байтовое смещение, с которого начинать скан
	 * @param array $segments путь листа (последний сегмент — сам элемент)
	 * @return int смещение сразу после закрывающего тега, -1 — не найдено
	 */
	protected function scanItemEnd(int $from, array $segments): int
	{
		if (!is_file($this->absPath) || $from < 0) {
			return -1;
		}
		if ($this->rawFh === null) {
			$this->rawFh = @fopen($this->absPath, 'rb');
			if (!$this->rawFh) {
				return -1;
			}
		}
		if ($from !== $this->rawBufStart + $this->rawBufPos) {
			// скан не продолжение текущего буфера — сброс (close/смена листа)
			$this->rawBuf = '';
			$this->rawBufStart = $from;
			$this->rawBufPos = 0;
		}
		$size = (int)@filesize($this->absPath);
		if ($from >= $size) {
			return -1;
		}
		$depth = count($segments);
		$leaf = $segments[$depth - 1];
		$need = $depth - 1;
		$parents = array_slice($segments, 0, $need);
		// стек сеется родителями пути: $from — внутри их контекста
		// (сразу после закрывающего тега предыдущего элемента листа)
		$stack = $parents;
		$openCnt = 0; // размер стека в момент входа в элемент листа
		$needMore = false; // тег не завершен в буфере — докачать данные
		while (true) {
			if ($this->rawBufPos > 0) {
				// потребленное в начале буфера отбрасывается
				$this->rawBuf = substr($this->rawBuf, $this->rawBufPos);
				$this->rawBufStart += $this->rawBufPos;
				$this->rawBufPos = 0;
			}
			if ($this->rawBuf === '' || $needMore) {
				// в буфере нет данных или тег не завершен — читаем следующий чанк
				if (ftell($this->rawFh) < $this->rawBufStart) {
					fseek($this->rawFh, $this->rawBufStart);
				}
				$chunk = fread($this->rawFh, 65536);
				if ($chunk === false || $chunk === '') {
					return -1;
				}
				$this->rawBuf .= $chunk;
				$needMore = false;
			}
			$buf = (string)substr($this->rawBuf, $this->rawBufPos);
			$bufStart = $this->rawBufStart + $this->rawBufPos;
			$bufLen = strlen($buf);
			$i = 0;
			while ($i < $bufLen) {
				$lt = strpos($buf, '<', $i);
				if ($lt === false) {
					// '<' в буфере нет — весь буфер потреблен
					$i = $bufLen;
					break;
				}
				// тег начинается с '<' — ищем конец '>' с учетом кавычек
				$tagEnd = $this->findTagEnd($buf, $lt, $bufLen);
				if ($tagEnd === -1) {
					// тег не завершен в буфере — докачиваем следующий чанк
					$i = $lt;
					$needMore = true;
					break;
				}
				$first = $buf[$lt + 1];
				if ($first === '!' || $first === '?') {
					// CDATA / комментарий / DOCTYPE / PI — содержимое не содержит
					// разметки: пропускаем целиком (CDATA может включать '<')
					if (substr($buf, $lt, 9) === '<![CDATA[') {
						$end = strpos($buf, ']]>', $lt + 9);
						if ($end === false) {
							$i = $lt;
							$needMore = true;
							break;
						}
						$i = $end + 3;
						continue;
					}
					$i = $tagEnd + 1;
					continue;
				}
				if ($first === '/') {
					// закрывающий тег — снимаем элемент со стека
					$name = $this->tagToken(substr($buf, $lt, $tagEnd - $lt + 1));
					if ($name !== '') {
						for ($k = count($stack) - 1; $k >= 0; $k--) {
							if ($stack[$k] === $name) {
								$stack = array_slice($stack, 0, $k);
								break;
							}
						}
						// стек уронился ниже уровня элемента листа — его тег закрыт
						if ($openCnt > 0 && count($stack) < $openCnt) {
							return $this->rawCursor($bufStart + $tagEnd + 1);
						}
					}
					$i = $tagEnd + 1;
					continue;
				}
				$name = $this->tagToken(substr($buf, $lt, $tagEnd - $lt + 1));
				$isSelfClose = $tagEnd > 0 && $buf[$tagEnd - 1] === '/';
				$cnt = count($stack);
				// матчинг пути листа по суффиксу стека (как в seekNextItem)
				if ($name !== '' && $name === $leaf
					&& $cnt >= $need
					&& ($need === 0 || !array_diff_assoc($parents, array_slice($stack, -$need)))
				) {
					// элемент листа начался (самозакрытый — сразу конец)
					if ($isSelfClose) {
						return $this->rawCursor($bufStart + $tagEnd + 1);
					}
					$openCnt = $cnt + 1;
				}
				if (!$isSelfClose) {
					$stack[] = $name;
				}
				$i = $tagEnd + 1;
			}
			// буфер исчерпан (тег не завершен / разметки нет) — докачиваем
			$this->rawBufPos += $i;
		}
	}

	/**
	 * Успешное завершение скана: курсор лексера переносится на новую позицию
	 * (необработанный хвост буфера сохраняется — следующий вызов продолжится
	 * без чтения файла)
	 */
	protected function rawCursor(int $end): int
	{
		$skip = $end - $this->rawBufStart;
		$this->rawBufPos = max(0, $skip);
		return $end;
	}/**
	 * Имя тега из строки '<name ...' / '</name ...' (без namespace-префикса —
	 * как localName в XMLReader).
	 */
	protected function tagToken(string $tag): string
	{
		$len = strlen($tag);
		$i = 1;
		if ($i < $len && $tag[$i] === '/') $i++;
		$start = $i;
		while ($i < $len) {
			$c = $tag[$i];
			if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n" || $c === '>' || $c === '/') {
				break;
			}
			$i++;
		}
		$name = substr($tag, $start, $i - $start);
		// namespace-префикс ('c:offer' -> 'offer') — XMLReader матчит по localName
		$colon = strrpos($name, ':');
		if ($colon !== false) {
			$name = substr($name, $colon + 1);
		}
		return strtolower($name);
	}

	/**
	 * Поиск закрывающего '>' тега с учетом кавычек в атрибутах
	 * (<category name="a>b">). Возвращает индекс '>' или -1 (тег не завершен).
	 */
	protected function findTagEnd(string $buf, int $lt, int $bufLen): int
	{
		$i = $lt + 1;
		$quote = '';
		while ($i < $bufLen) {
			$cand = $bufLen;
			if ($quote === '') {
				$gt = strpos($buf, '>', $i);
				if ($gt !== false && $gt < $cand) { $cand = $gt; }
				$q1 = strpos($buf, '"', $i);
				if ($q1 !== false && $q1 < $cand) { $cand = $q1; $quote = '"'; }
				$q2 = strpos($buf, "'", $i);
				if ($q2 !== false && $q2 < $cand) { $cand = $q2; $quote = "'"; }
				if ($cand === $bufLen) return -1;
				if ($cand === $gt) return $cand;
			} else {
				$q = strpos($buf, $quote, $i);
				if ($q === false) return -1;
				$cand = $q;
				$quote = '';
			}
			$i = $cand + 1;
		}
		return -1;
	}

	/**
	 * Открытие reader-потока с заданной байтовой позиции файла:
	 * URI 'awzseek://<offset>|<path>|<parents>' — stream-wrapper 'awzseek'
	 * проксирует файл с fseek на offset и оборачивает фрагмент в виртуальные
	 * предки (parents) — иначе повторяющиеся элементы листа для libxml
	 * выглядят как несколько корней документа («Extra content» — fatal).
	 * Курсор корректен для однобайтовых кодировок: raw-смещения совпадают с позициями
	 * в XMLReader-потоке.
	 *
	 * @param int $offset байтовое смещение стартового тега элемента
	 * @param array $segments путь листа (последний сегмент — сам элемент)
	 */
	protected function openStreamAt(int $offset, array $segments = [])
	{
		if (!is_file($this->absPath) || $offset < 0) {
			return null;
		}
		self::registerSeekStream();
		$parents = array_slice($segments, 0, count($segments) - 1);
		$parentsUri = '';
		foreach ($parents as $parent) {
			$parentsUri .= '|' . rawurlencode((string)$parent);
		}
		// не-UTF-8 файл: кодировка передается в поток (виртуальный header
		// дополняется декларацией) — без нее libxml декодирует cp1251-поток
		// как UTF-8, парсер падает на первой кириллической букве и курсор
		// сбрасывался: каждый хит проматывал файл с самого начала
		$encUri = '';
		$enc = $this->detectEncoding();
		if ($enc !== '' && !in_array(strtoupper($enc), ['UTF-8', 'UTF8'], true)) {
			$encUri = '|enc:' . rawurlencode($enc);
		}
		$reader = new \XMLReader();
		if (!$reader->open('awzseek://' . $offset . '|' . str_replace('\\', '/', $this->absPath) . $parentsUri . $encUri, null, LIBXML_NOWARNING | LIBXML_NOERROR)) {
			return null;
		}
		$this->elemStack = [];
		$this->readerEof = false;
		return $reader;
	}

	/**
	 * Кодировка файла из декларации <?xml encoding="..."?> (первый Кб).
	 * Пусто — декларации нет (по умолчанию UTF-8).
	 */
	protected function detectEncoding(): string
	{
		$fh = @fopen($this->absPath, 'rb');
		if (!$fh) {
			return '';
		}
		$head = (string)fread($fh, 1024);
		fclose($fh);
		if (preg_match('/encoding\s*=\s*["\']([^"\']+)["\']/i', $head, $m)) {
			return trim($m[1]);
		}
		return '';
	}

	/**
	 * Регистрация stream-wrapper 'awzseek' (один раз).
	 */
	protected static function registerSeekStream(): void
	{
		if (!in_array('awzseek', stream_get_wrappers(), true)) {
			stream_wrapper_register('awzseek', FastXmlReaderSeekStream::class);
		}
	}
}

/**
 * Stream-wrapper 'awzseek': прокси к файлу с fseek на заданное байтовое
 * смещение. URI: awzseek://<offset>|<path>|<parent1>|<parent2>...
 * Фрагмент файла с середины не является валидным XML документом
 * (повторяющиеся элементы листа выглядят для libxml как несколько корней —
 * fatal «Extra content at the end of document»). Поэтому поток оборачивается
 * в виртуальных предков элемента листа: header открывает родительские теги
 * (<shop><offers>...). Закрывающие теги предков в данных файла уже есть
 * (читаем до EOF) — footer не нужен, дублирование close-тегов ломает libxml.
 */
class FastXmlReaderSeekStream
{
	/** @var resource|null */
	public $context;

	/** @var resource|null */
	private $fh = null;

	/** @var string открывающие теги виртуальных предков (header) */
	private string $header = '';

	/** @var int сколько байт header уже отдано */
	private int $hpos = 0;

	/** @var int смещение в файле (для url_stat) */
	private int $offset = 0;

	public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
	{
		// awzseek://<offset>|<path>|<parent1>|<parent2>...
		$uri = substr($path, strlen('awzseek://'));
		$pos = strpos($uri, '|');
		if ($pos === false) {
			return false;
		}
		$offset = (int)substr($uri, 0, $pos);
		$rest = substr($uri, $pos + 1);
		$parents = [];
		// последний сегмент '|' в URI отсутствует: путь файла не содержит '|',
		// родители кодируются rawurlencode — декодируем все сегменты после пути
		$parts = explode('|', $rest);
		$basePath = array_shift($parts);
		$encoding = '';
		foreach ($parts as $part) {
			$name = rawurldecode($part);
			// сегмент 'enc:<кодировка>' — декларация кодировки для header
			if (str_starts_with($part, 'enc:')) {
				$encoding = rawurldecode(substr($part, 4));
				continue;
			}
			if ($name !== '') {
				$parents[] = $name;
			}
		}
		if (!is_file($basePath)) {
			return false;
		}
		$fh = fopen($basePath, 'rb');
		if (!$fh) {
			return false;
		}
		if ($offset > 0) {
			fseek($fh, $offset);
		}
		$opens = '';
		// не-UTF-8 файл: декларация кодировки в виртуальном header —
		// libxml корректно декодирует поток с середины файла
		if ($encoding !== '') {
			$opens .= '<?xml version="1.0" encoding="' . htmlspecialchars($encoding, ENT_QUOTES) . '"?>';
		}
		foreach ($parents as $parent) {
			$opens .= '<' . $parent . '>';
		}
		$this->header = $opens;
		$this->offset = $offset;
		$this->hpos = 0;
		$this->fh = $fh;
		return true;
	}

	public function stream_read(int $count): string
	{
		if ($this->fh === null) {
			return '';
		}
		if ($this->hpos < strlen($this->header)) {
			$s = substr($this->header, $this->hpos, $count);
			$this->hpos += strlen($s);
			return $s;
		}
		return (string)fread($this->fh, $count);
	}

	public function stream_eof(): bool
	{
		if ($this->fh === null) {
			return true;
		}
		if ($this->hpos < strlen($this->header)) {
			return false;
		}
		return feof($this->fh);
	}

	public function stream_close(): void
	{
		if ($this->fh !== null) {
			fclose($this->fh);
			$this->fh = null;
		}
	}

	/**
	 * XMLReader требует url_stat для URI источника —
	 * возвращаем stat целевого файла (размер с вычетом смещения).
	 */
	public function url_stat(string $path, int $flags): array|false
	{
		$uri = substr($path, strlen('awzseek://'));
		$pos = strpos($uri, '|');
		if ($pos === false) {
			return false;
		}
		$offset = (int)substr($uri, 0, $pos);
		$rest = substr($uri, $pos + 1);
		$basePath = explode('|', $rest)[0];
		if (!is_file($basePath)) {
			return false;
		}
		$st = @stat($basePath);
		if ($st === false) {
			return false;
		}
		if ($offset > 0) {
			$st['size'] = max(0, (int)$st['size'] - $offset);
		}
		return $st;
	}

	public function stream_stat(): array|false
	{
		if ($this->fh === null) {
			return false;
		}
		return @fstat($this->fh) ?: false;
	}
}