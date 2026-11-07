<?php
namespace Awz\Allxls\Helper;

/**
 * Быстрый потоковый читатель JSON (побайтово, файл не грузится в память).
 *
 * «Лист» — это повторяющийся элемент JSON по заданному пути произвольной глубины,
 * например items->item, categories->section, shop->offers->offer.
 * Пути передаются в конструктор списком строк ('items->item', 'shop->offers->offer').
 *
 * Поддерживаются обе типовые структуры:
 *  - вложенный контейнер: {"shop":{"offers":{"item":[...]}}} (путь shop->offers->item);
 *  - массив под родителем: {"shop":{"offers":[...]}} — каждый объект массива
 *    трактуется как элемент листа (путь shop->offers->offer либо shop->offers->offers);
 *  - корневой массив объектов: [{...},...] — путь по ключам внутри элементов.
 *
 * Строка листа = плоская карта колонок (как у FastXmlReader):
 *  - скалярное поле           -> ключ = имя поля (name, price)
 *  - вложенный объект         -> ключи родитель_поле (image_url)
 *  - массив скаляров          -> значения склеиваются через разделитель (", ")
 *  - массив объектов/смешанный-> JSON-агрегат под ключом поля
 *  - null/bool                -> '' / 'true' / 'false'
 *
 * Два режима работы:
 *  1) readSheetsHead($maxItems) — разовое чтение шапки (union ключей) и сэмплов всех листов.
 *  2) Пошаговый режим с сохранением состояния: openSheet() -> readNextRow() ->
 *     getState()/setState(). Состояние — индекс листа, число прочитанных элементов
 *     и байтовая позиция курсора (см. ниже).
 *
 * Байтовый курсор (как у CSV/XML): после каждого прочитанного элемента листа
 * запоминается байтовая позиция файла сразу после его закрывающего '}' — она
 * хранится в состоянии ('bytePos') и между хитами чтение продолжается с этой
 * позиции (fseek) без промотки файла с начала. JSON-структура ASCII-прозрачна
 * ({,},[,],:,,",\ — однобайтовые в UTF-8/CP1251), поэтому курсор корректен для
 * любых однобайтовых кодировок.
 *
 * Сам элемент листа декодится штатным json_decode (C-level, быстро); лексер
 * сканера — собственный строковый/структурный проход по чанкам файла.
 */
class FastJsonReader {

	/** @var string абсолютный путь к файлу */
	private string $absPath;

	/** @var array[] список листов: [['NAME'=>..,'PATH'=>..,'SEGMENTS'=>[]],...] */
	private array $sheets = [];

	/** @var array состояние пошагового чтения */
	private array $state = [
		'sheetIndex' => -1,
		'lastRow'    => 0,
		'bytePos'    => 0, // байт сразу после '}' последнего обработанного элемента (0 — с начала файла)
		'done'       => false,
	];

	/** @var array порядок ключей колонок для выравнивания строк (пусто — union первых N элементов) */
	private array $keys = [];

	/** @var int[] кеш итоговых количеств элементов по листам */
	private array $totalRowsCache = [];

	/** @var string разделитель повторяющихся значений */
	private string $repeatDelim = ', ';

	// === сканер (персистентен на лист в рамках хита) ===

	/** @var resource|null хэндл файла */
	private $fh = null;

	/** @var string буфер чтения (buf[0] — байт bufStart файла) */
	private string $buf = '';

	/** @var int байтовое смещение buf[0] в файле */
	private int $bufStart = 0;

	/** @var int позиция скана внутри буфера */
	private int $bufPos = 0;

	/** @var bool EOF файла */
	private bool $eof = false;

	/** @var int байт, с которого нельзя уплотнять буфер (захват элемента в процессе); -1 — нет */
	private int $holdAbs = -1;

	/**
	 * Стек контейнеров: ['t'=>'o'|'a', 'key'=>string, 'match'=>int,
	 * 'leafArray'=>bool, 'pkey'=>string, 'pmatch'=>int]
	 * @var array[]
	 */
	private array $frames = [];

	/** ожидание: 0 — значение, 1 — ключ объекта, 2 — ':' */
	private int $expect = 0;

	/** @var bool прочитан ключ-лист — следующее значение является элементом листа */
	private bool $leafWait = false;

	/** @var string[] сегменты пути текущего листа */
	private array $segments = [];

	/** @var int индекс листового сегмента в segments */
	private int $leafIdx = -1;

	public function __construct(string $absPath, array $paths = [])
	{
		$this->absPath = $absPath;
		foreach ($paths as $path) {
			$segments = FastXmlReader::parsePath((string)$path);
			if (count($segments) < 1) {
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
		// сканер-состояние основного потока сохраняется: getTotalRows может
		// вызываться посреди чтения листа
		$bak = $this->scannerSnapshot();
		$count = 0;
		if ($this->startScan($sheetIndex, 0)) {
			while ($this->nextItem() !== null) {
				$count++;
			}
		}
		$this->closeScanner();
		$this->scannerRestore($bak);
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
		$this->closeScanner();
	}

	/**
	 * Открытие листа с сохраненной позиции.
	 * После успешного вызова сканер готов отдавать элементы начиная с state['lastRow']+1.
	 *
	 * Байтовый курсор: если в состоянии есть позиция (bytePos > 0) и она валидна
	 * (предыдущий байт — '}' или ']') — чтение продолжается сразу с нее (fseek),
	 * промотка с начала файла не нужна. Иначе — промотка с пропуском lastRow
	 * элементов сканером от начала файла.
	 *
	 * @param int $sheetIndex индекс листа (порядок в getSheets())
	 * @return bool
	 */
	public function openSheet(int $sheetIndex): bool
	{
		$this->closeScanner();
		if (!isset($this->sheets[$sheetIndex])) {
			return false;
		}
		// порядок ключей не задан явно — берем union шапки (первые 30 элементов)
		if (!$this->keys) {
			$this->keys = $this->sheetHeaderKeys($sheetIndex);
		}
		$this->segments = $this->sheets[$sheetIndex]['SEGMENTS'];
		$this->leafIdx = count($this->segments) - 1;
		// смена листа — байтовая позиция предыдущего листа не действует
		if ($this->state['sheetIndex'] !== $sheetIndex) {
			$this->state['bytePos'] = 0;
		}
		$bytePos = (int)$this->state['bytePos'];
		$size = (int)@filesize($this->absPath);
		$fh = @fopen($this->absPath, 'rb');
		if (!$fh) {
			return false;
		}
		if ($bytePos > 0 && $bytePos >= $size) {
			// сохраненная позиция за пределами файла — чтение завершено
			fclose($fh);
			$this->state['done'] = true;
			return false;
		}
		$valid = false;
		if ($bytePos > 0 && $bytePos < $size) {
			// валидность позиции: курсор хранится сразу после '}' / ']' элемента
			fseek($fh, $bytePos - 1);
			$prev = (string)fread($fh, 1);
			$valid = ($prev === '}' || $prev === ']');
		}
		$this->state['sheetIndex'] = $sheetIndex;
		if ($valid) {
			fseek($fh, $bytePos);
			$this->initScanner($fh, $bytePos);
			return true;
		}
		// фолбэк: чтение с начала файла с пропуском обработанных элементов сканером
		fclose($fh);
		$this->state['bytePos'] = 0;
		if (!$this->startScan($sheetIndex, 0)) {
			fclose($fh);
			return false;
		}
		for ($i = 0; $i < $this->state['lastRow']; $i++) {
			if ($this->nextItem() === null) {
				// позиция за пределами файла — чтение завершено
				$this->state['done'] = true;
				$this->closeScanner();
				return false;
			}
		}
		return true;
	}

	/**
	 * Чтение следующего элемента текущего листа.
	 *
	 * @return array ['row' => номер (1-based), 'data' => array|null] — data=null, элементов больше нет
	 */
	public function readNextRow(): array
	{
		if ($this->state['done'] || $this->fh === null || !isset($this->sheets[$this->state['sheetIndex']])) {
			return ['row' => $this->state['lastRow'] + 1, 'data' => null];
		}
		$it = $this->nextItem();
		if ($it === null) {
			$this->state['done'] = true;
			$this->closeScanner();
			return ['row' => $this->state['lastRow'] + 1, 'data' => null];
		}
		$this->state['lastRow']++;
		// байтовый курсор: позиция сразу после закрывающего '}' / ']' прочитанного
		// элемента — между хитами чтение продолжается с нее без промотки (как у CSV/XML)
		$this->state['bytePos'] = $it['end'];
		$data = $this->alignRow($it['data']);
		return ['row' => $this->state['lastRow'], 'data' => $data];
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
		$result = [];
		foreach ($this->sheets as $index => $sheet) {
			$keys = [];
			$matrix = []; // [itemId => [key => value]]
			$bak = $this->scannerSnapshot();
			if ($this->startScan($index, 0)) {
				$count = 0;
				while ($count < $maxItems && ($it = $this->nextItem()) !== null) {
					foreach (array_keys($it['data']) as $k) {
						if (!in_array($k, $keys, true)) $keys[] = $k;
					}
					$matrix[] = $it['data'];
					$count++;
				}
			}
			$this->closeScanner();
			$this->scannerRestore($bak);
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
		$keys = [];
		$bak = $this->scannerSnapshot();
		if ($this->startScan($sheetIndex, 0)) {
			$count = 0;
			while ($count < 30 && ($it = $this->nextItem()) !== null) {
				foreach (array_keys($it['data']) as $k) {
					if (!in_array($k, $keys, true)) $keys[] = $k;
				}
				$count++;
			}
		}
		$this->closeScanner();
		$this->scannerRestore($bak);
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

	// ===================== сканер =====================

	/**
	 * Снимок состояния сканера (для вложенных сканов getTotalRows/readSheetsHead)
	 */
	protected function scannerSnapshot(): array
	{
		return [
			'fh' => $this->fh, 'buf' => $this->buf, 'bufStart' => $this->bufStart,
			'bufPos' => $this->bufPos, 'eof' => $this->eof, 'holdAbs' => $this->holdAbs,
			'frames' => $this->frames, 'expect' => $this->expect, 'leafWait' => $this->leafWait,
			'segments' => $this->segments, 'leafIdx' => $this->leafIdx,
		];
	}

	protected function scannerRestore(array $bak): void
	{
		$this->fh = $bak['fh']; $this->buf = $bak['buf']; $this->bufStart = $bak['bufStart'];
		$this->bufPos = $bak['bufPos']; $this->eof = $bak['eof']; $this->holdAbs = $bak['holdAbs'];
		$this->frames = $bak['frames']; $this->expect = $bak['expect']; $this->leafWait = $bak['leafWait'];
		$this->segments = $bak['segments']; $this->leafIdx = $bak['leafIdx'];
	}

	/**
	 * Запуск скана листа с байтовой позиции $from (файл открывается заново)
	 */
	protected function startScan(int $sheetIndex, int $from): bool
	{
		if (!isset($this->sheets[$sheetIndex]) || !is_file($this->absPath)) {
			return false;
		}
		$this->segments = $this->sheets[$sheetIndex]['SEGMENTS'];
		$this->leafIdx = count($this->segments) - 1;
		$fh = @fopen($this->absPath, 'rb');
		if (!$fh) {
			return false;
		}
		if ($from > 0) {
			fseek($fh, $from);
		}
		$this->initScanner($fh, $from);
		return true;
	}

	/**
	 * Инициализация сканера с позиции: буфер/стек сбрасываются.
	 * При $from > 0 контекст сеется родителями пути (seedFrames) — вызывается
	 * отдельно из openSheet.
	 */
	protected function initScanner($fh, int $from): void
	{
		$this->fh = $fh;
		$this->buf = '';
		$this->bufStart = $from;
		$this->bufPos = 0;
		$this->eof = false;
		$this->holdAbs = -1;
		$this->frames = [];
		$this->expect = 0;
		$this->leafWait = false;
		if ($from > 0 && $this->leafIdx >= 0) {
			$this->seedFrames();
		}
	}

	/**
	 * Сев фреймов стека при возобновлении с байтовой позиции внутри контейнера
	 * листа: родители пути — открытые объекты, последний — массив-контейнер
	 * листа (leafArray: элементы массива — строки листа напрямую).
	 * Такой сея работает и для {"items":{"item":[..]}} (массив — значение
	 * листового ключа), и для {"items":[..]} (массив — значение родительского
	 * ключа): в обоих случаях элементы массива — строки листа.
	 */
	protected function seedFrames(): void
	{
		$d = count($this->segments);
		$this->frames = [];
		if ($d === 1) {
			// одиночный сегмент (товары в корне): корневой контейнер +
			// массив листа (элементы массива — строки напрямую)
			$this->frames[] = ['t' => 'o', 'key' => '', 'match' => -1, 'leafArray' => false, 'pkey' => '', 'pmatch' => -1];
			$this->frames[] = ['t' => 'a', 'key' => '', 'match' => -1, 'leafArray' => true, 'pkey' => '', 'pmatch' => -1];
			$this->expect = 0;
			$this->leafWait = false;
			return;
		}
		for ($i = 0; $i <= $d - 2; $i++) {
			$this->frames[] = [
				't' => 'o', 'key' => (string)$this->segments[$i], 'match' => $i,
				'leafArray' => false, 'pkey' => '', 'pmatch' => -1,
			];
		}
		$this->frames[] = [
			't' => 'a', 'key' => (string)$this->segments[$d - 2], 'match' => $d - 2,
			'leafArray' => true, 'pkey' => '', 'pmatch' => -1,
		];
		$this->expect = 0;
		$this->leafWait = false;
	}

	protected function closeScanner(): void
	{
		if ($this->fh !== null) {
			fclose($this->fh);
			$this->fh = null;
		}
		$this->buf = '';
		$this->bufStart = 0;
		$this->bufPos = 0;
		$this->eof = false;
		$this->holdAbs = -1;
		$this->frames = [];
		$this->expect = 0;
		$this->leafWait = false;
	}

	/**
	 * Дозапись чанка в буфер. Потребленный префикс уплотняется, но не дальше
	 * holdAbs (захват элемента в процессе — срез должен остаться в буфере).
	 */
	protected function fill(): bool
	{
		if ($this->fh === null || $this->eof) {
			return false;
		}
		$keepFrom = $this->bufPos;
		if ($this->holdAbs >= 0) {
			$keepFrom = min($keepFrom, $this->holdAbs - $this->bufStart);
			if ($keepFrom < 0) $keepFrom = 0;
		}
		if ($keepFrom > 0) {
			$this->buf = substr($this->buf, $keepFrom);
			$this->bufStart += $keepFrom;
			$this->bufPos -= $keepFrom;
		}
		$chunk = fread($this->fh, 262144);
		if ($chunk === false || $chunk === '') {
			$this->eof = true;
			return false;
		}
		$this->buf .= $chunk;
		return true;
	}

	/**
	 * Абсолютная байтовая позиция курсора сканера
	 */
	protected function pos(): int
	{
		return $this->bufStart + $this->bufPos;
	}

	/**
	 * Следующий значимый символ (без пробелов) БЕЗ потребления; '' — EOF
	 */
	protected function peekc(): string
	{
		while (true) {
			while ($this->bufPos >= strlen($this->buf)) {
				if (!$this->fill()) return '';
			}
			$c = $this->buf[$this->bufPos];
			if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
				$this->bufPos++;
				continue;
			}
			return $c;
		}
	}

	/**
	 * Чтение строки JSON (открывающая кавычка УЖЕ потреблена).
	 * Возвращает декодированное значение (escapes раскрываются json_decode).
	 */
	protected function readString(): string
	{
		$startAbs = $this->pos();
		while (true) {
			while ($this->bufPos >= strlen($this->buf)) {
				if (!$this->fill()) return '';
			}
			$q = strpos($this->buf, '"', $this->bufPos);
			if ($q === false) {
				$this->bufPos = strlen($this->buf);
				continue;
			}
			// экранированная кавычка (нечетное число \ перед ней)
			$bs = 0;
			$i = $q - 1;
			while ($i >= 0 && $this->buf[$i] === '\\') { $bs++; $i--; }
			if ($bs % 2 === 1) {
				$this->bufPos = $q + 1;
				continue;
			}
			$start = $startAbs - $this->bufStart;
			$raw = substr($this->buf, $start, $q - $start);
			$this->bufPos = $q + 1;
			$dec = json_decode('"'.$raw.'"', true);
			return is_string($dec) ? $dec : $raw;
		}
	}

	/**
	 * Скалярный токен (число/true/false/null): читается до разделителя , } ]
	 * (первый символ УЖЕ потреблен peekc-веткой — начало = текущая позиция минус 1)
	 */
	protected function readScalarToken(string $first): string
	{
		$tok = $first;
		while (true) {
			while ($this->bufPos >= strlen($this->buf)) {
				if (!$this->fill()) return $tok;
			}
			$c = $this->buf[$this->bufPos];
			if ($c === ',' || $c === '}' || $c === ']' || $c === '{' || $c === '[' || $c === '"'
				|| $c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
				return $tok;
			}
			$tok .= $c;
			$this->bufPos++;
		}
	}

	/**
	 * Пропуск структурного значения (объект/массив) целиком.
	 * Открывающая скобка УЖЕ потреблена.
	 */
	protected function skipStructured(): void
	{
		$depth = 1;
		while (true) {
			while ($this->bufPos >= strlen($this->buf)) {
				if (!$this->fill()) return;
			}
			$c = $this->buf[$this->bufPos++];
			if ($c === '"') {
				$this->readString();
				continue;
			}
			if ($c === '{' || $c === '[') {
				$depth++;
			} elseif ($c === '}' || $c === ']') {
				$depth--;
				if ($depth <= 0) return;
			}
		}
	}

	/**
	 * Захват структурного значения (элемента листа) от открывающей скобки
	 * (УЖЕ потреблена) до закрывающей включительно.
	 * Возвращает [raw, endAbsExclusive]; при обрыве файла — что успели.
	 */
	protected function captureStructured(): array
	{
		$startAbs = $this->pos() - 1;
		$this->holdAbs = $startAbs;
		$depth = 1;
		$complete = false;
		while (true) {
			while ($this->bufPos >= strlen($this->buf)) {
				if (!$this->fill()) break 2;
			}
			$c = $this->buf[$this->bufPos++];
			if ($c === '"') {
				$this->readString();
				continue;
			}
			if ($c === '{' || $c === '[') {
				$depth++;
			} elseif ($c === '}' || $c === ']') {
				$depth--;
				if ($depth <= 0) {
					$complete = true;
					break;
				}
			}
		}
		$this->holdAbs = -1;
		$endAbs = $this->pos();
		$raw = substr($this->buf, $startAbs - $this->bufStart, $endAbs - $startAbs);
		if (!$complete && $this->eof) {
			// файл оборван — элемент некорректен
			return ['', $endAbs];
		}
		return [$raw, $endAbs];
	}

	/**
	 * Извлечение следующего элемента листа сканером.
	 *
	 * @return array|null ['data' => [key => value], 'end' => байт после закрывающей скобки] или null (EOF)
	 */
	protected function nextItem(): ?array
	{
		while (true) {
			$c = $this->peekc();
			if ($c === '') {
				return null;
			}
			$this->bufPos++;

			if ($this->expect === 1) {
				// ожидание ключа объекта
				if ($c === '}') {
					$this->popFrame();
					continue;
				}
				if ($c !== '"') {
					continue; // мусор вне структуры — пропускаем
				}
				$key = $this->readString();
				$this->expect = 2;
				$this->onKey($key);
				continue;
			}
			if ($this->expect === 2) {
				// ожидание ':'
				if ($c === ':') {
					$this->expect = 0;
				}
				continue;
			}

			// ожидание значения
			if ($c === '{') {
				if ($this->leafWait) {
					// листовый объект захвачен — внутри объекта дальше ждём ключ
					$this->leafWait = false;
					$this->expect = 1;
					return $this->itemFromCapture();
				}
				$top = $this->frames ? $this->frames[count($this->frames) - 1] : null;
				if ($top === null) {
					// корневой объект
					$this->frames[] = ['t' => 'o', 'key' => '', 'match' => -1, 'leafArray' => false, 'pkey' => '', 'pmatch' => -1];
					$this->expect = 1;
					continue;
				}
				if ($top['t'] === 'a') {
					if (!empty($top['leafArray'])) {
						// элемент массива-листа — сам объект строка листа
						return $this->itemFromCapture();
					}
					$nj = (int)$top['match'] + 1;
					if ($top['match'] >= 0 && $nj === $this->leafIdx) {
						// массив под родителем: элемент = листовый объект
						return $this->itemFromCapture();
					}
					if ($top['match'] >= 0 && $nj < count($this->segments)) {
						// виртуальный фрейм: элемент массива трактуется как объект
						// со следующим сегментом пути — ключи внутри матчатся глубже
						$this->frames[] = ['t' => 'o', 'key' => (string)$this->segments[$nj], 'match' => $nj, 'leafArray' => false, 'pkey' => '', 'pmatch' => -1];
						$this->expect = 1;
						continue;
					}
					// массив вне пути: корневой — сканируем внутри, вложенный — пропускаем
					if (count($this->frames) === 1) {
						if ($this->leafIdx === 0) {
							// одиночный сегмент пути: элементы корневого
							// массива — строки листа ([{"id":10},...])
							return $this->itemFromCapture();
						}
						$this->frames[] = ['t' => 'o', 'key' => '', 'match' => -1, 'leafArray' => false, 'pkey' => '', 'pmatch' => -1];
						$this->expect = 1;
						continue;
					}
					$this->skipStructured();
					continue;
				}
				// объект-значение ключа: фрейм с ключом и его матчем
				$this->frames[] = ['t' => 'o', 'key' => (string)$top['pkey'], 'match' => (int)$top['pmatch'], 'leafArray' => false, 'pkey' => '', 'pmatch' => -1];
				$this->expect = 1;
				continue;
			}
			if ($c === '[') {
				if ($this->leafWait) {
					// листовый ключ — массив: элементы напрямую строки листа
					$this->leafWait = false;
					$this->frames[] = ['t' => 'a', 'key' => '', 'match' => -1, 'leafArray' => true, 'pkey' => '', 'pmatch' => -1];
					$this->expect = 0;
					continue;
				}
				$top = $this->frames ? $this->frames[count($this->frames) - 1] : null;
				if ($top === null) {
					// корневой массив
					$this->frames[] = ['t' => 'a', 'key' => '', 'match' => -1, 'leafArray' => false, 'pkey' => '', 'pmatch' => -1];
					$this->expect = 0;
					continue;
				}
				if ($top['t'] === 'a') {
					// вложенный массив — элементы не матчатся: пропускаем целиком
					$this->skipStructured();
					continue;
				}
				// массив-значение ключа: если ключ совпал с сегментом пути —
				// фрейм хранит матч (элементы массива продолжают путь)
				$this->frames[] = ['t' => 'a', 'key' => (string)$top['pkey'], 'match' => (int)$top['pmatch'], 'leafArray' => false, 'pkey' => '', 'pmatch' => -1];
				$this->expect = 0;
				continue;
			}
			if ($c === '}') {
				$this->popFrame();
				continue;
			}
			if ($c === ']') {
				$this->popFrame();
				continue;
			}
			if ($c === ',') {
				continue; // разделитель на позиции значения (обрыв/свой сев) — пропускаем
			}
			if ($c === '"') {
				$s = $this->readString();
				if ($this->leafWait) {
					$this->leafWait = false;
					$this->expect = 1;
					return $this->scalarItem($s);
				}
				$top = $this->frames ? $this->frames[count($this->frames) - 1] : null;
				if ($top !== null && $top['t'] === 'a'
					&& (!empty($top['leafArray'])
						|| ($this->leafIdx === 0 && count($this->frames) === 1))) {
					// элемент массива — строка листа (массив продолжается)
					return $this->scalarItem($s);
				}
				// обычное значение объекта/массива — к ожиданию ключа/значения
				$this->expect = ($top !== null && $top['t'] === 'o') ? 1 : 0;
				continue;
			}
			// скаляр (число/true/false/null)
			$tok = $this->readScalarToken($c);
			if ($this->leafWait) {
				$this->leafWait = false;
				$this->expect = 1;
				return $this->scalarItem($tok);
			}
			$top = $this->frames ? $this->frames[count($this->frames) - 1] : null;
			if ($top !== null && $top['t'] === 'a'
				&& (!empty($top['leafArray'])
					|| ($this->leafIdx === 0 && count($this->frames) === 1))) {
				return $this->scalarItem($tok);
			}
			$this->expect = ($top !== null && $top['t'] === 'o') ? 1 : 0;
		}
	}

	/**
	 * Обработка прочитанного ключа объекта: матчинг по пути (суффикс стека
	 * ключей — как в FastXmlReader). Матч выставляется на верхнем фрейме
	 * (pkey/pmatch), ключ-лист поднимает leafWait.
	 */
	protected function onKey(string $key): void
	{
		$idx = count($this->frames) - 1;
		$this->frames[$idx]['pkey'] = $key;
		$this->frames[$idx]['pmatch'] = -1;
		// S — ключи открытых объектных фреймов (текущий объект исключен:
		// его собственный ключ лежит во фрейме, ключи ЧИТАЮТСЯ внутри него)
		$S = [];
		foreach ($this->frames as $f) {
			if ($f['t'] === 'o' && (string)$f['key'] !== '') {
				$S[] = (string)$f['key'];
			}
		}
		$maxIdx = count($this->segments) - 1;
		$k = mb_strtolower($key);
		for ($j = min($maxIdx, count($S)); $j >= 0; $j--) {
			if ((string)$this->segments[$j] !== $k) {
				continue;
			}
			if ($j > 0) {
				$tail = array_slice($S, -$j);
				if ($tail !== array_slice($this->segments, 0, $j)) {
					continue;
				}
			}
			$this->frames[$idx]['pmatch'] = $j;
			if ($j === $this->leafIdx) {
				$this->leafWait = true;
			}
			break;
		}
	}

	/**
	 * Закрытие контейнера: фрейм снимается, ожидание — по новому верхнему
	 * контейнеру (объект -> ключ, массив -> значение)
	 */
	protected function popFrame(): void
	{
		array_pop($this->frames);
		if (!$this->frames) {
			$this->expect = 0;
			return;
		}
		$top = $this->frames[count($this->frames) - 1];
		$this->expect = ($top['t'] === 'o') ? 1 : 0;
	}

	/**
	 * Элемент из захваченного среза: decode + flatten в плоскую карту
	 */
	protected function itemFromCapture(): array
	{
		[$raw, $end] = $this->captureStructured();
		$data = $this->decodeItem($raw);
		return ['data' => $data, 'end' => $end];
	}

	/**
	 * Скалярный элемент (массив скаляров под листовым ключом):
	 * колонка = имя листового сегмента, курсор — текущая позиция
	 */
	protected function scalarItem(string $value): array
	{
		$key = $this->makeKey([(string)$this->segments[$this->leafIdx]]);
		return ['data' => [$key => $value], 'end' => $this->pos()];
	}

	/**
	 * Декодирование среза элемента: json_decode с фолбэком на cp1251
	 */
	protected function decodeItem(string $raw): array
	{
		$raw = preg_replace('/^﻿/', '', $raw) ?? $raw;
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			$converted = @iconv('CP1251', 'UTF-8//IGNORE', $raw);
			if (is_string($converted)) {
				$decoded = json_decode($converted, true);
			}
		}
		if (!is_array($decoded)) {
			return [];
		}
		$values = [];
		if (array_is_list($decoded)) {
			// срез — массив (скаляры/объекты): агрегат под именем листового сегмента
			$key = $this->makeKey([(string)$this->segments[$this->leafIdx]]);
			$this->flatten($decoded, $key, $values);
			return $values;
		}
		foreach ($decoded as $k => $v) {
			$key = $this->makeKey([(string)$k]);
			if (is_array($v)) {
				$this->flatten($v, $key, $values);
			} else {
				$values[$key] = $this->scalarToStr($v);
			}
		}
		return $values;
	}

	/**
	 * Плоская карта значений (как у FastXmlReader):
	 * объект -> рекурсивно родитель_поле, массив скаляров -> склейка
	 * разделителем, массив объектов/смешанный -> JSON-агрегат.
	 */
	protected function flatten($value, string $prefix, array &$out): void
	{
		if (!is_array($value)) {
			$out[$prefix] = $this->scalarToStr($value);
			return;
		}
		if ($value === []) {
			$out[$prefix] = '';
			return;
		}
		$allScalar = true;
		foreach ($value as $v) {
			if (is_array($v)) {
				$allScalar = false;
				break;
			}
		}
		if (array_is_list($value) && $allScalar) {
			$out[$prefix] = implode($this->repeatDelim, array_map([$this, 'scalarToStr'], $value));
			return;
		}
		if (array_is_list($value)) {
			// массив объектов/смешанный — JSON-агрегат (как warehouse_json у XML)
			$out[$prefix] = json_encode($value, JSON_UNESCAPED_UNICODE);
			return;
		}
		foreach ($value as $k => $v) {
			$key = $this->makeKey($prefix !== '' ? [$prefix, (string)$k] : [(string)$k]);
			if (is_array($v)) {
				$this->flatten($v, $key, $out);
			} else {
				$out[$key] = $this->scalarToStr($v);
			}
		}
	}

	protected function scalarToStr($v): string
	{
		if ($v === null) return '';
		if (is_bool($v)) return $v ? 'true' : 'false';
		return (string)$v;
	}

	/**
	 * Ключ колонки: склейка сегментов "_", нижний регистр,
	 * не-[A-Za-z0-9] -> '_' (как makeKey в FastXmlReader)
	 * @param string[] $parts
	 */
	protected function makeKey(array $parts): string
	{
		foreach ($parts as $i => $part) {
			$parts[$i] = strtolower(preg_replace('/[^A-Za-z0-9]/', '_', (string)$part));
		}
		$result = trim(implode('_', array_filter($parts, function ($v) { return $v !== ''; })), '_');
		return str_replace('__', '_', $result);
	}
}
