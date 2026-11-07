<?php
namespace Awz\Allxls\Cron;

use Bitrix\Main\Type\DateTime;

/**
 * Парсер crontab-расписания (5 полей: мин час день_месяца месяц день_недели).
 * Поддерживается: '*', списки 'a,b,c', диапазоны 'a-b', шаги '*\x2Fn' и 'a-b\x2Fn' (n — шаг).
 * Алиасы: @hourly, @daily, @midnight, @weekly, @monthly, @yearly, @yearly.
 */
class Schedule
{
    /** @var array маппинг алиасов в crontab-выражения */
    protected const ALIASES = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    /**
     * Проверка корректности выражения расписания.
     * @param string $expr crontab-выражение
     * @return bool
     */
    public static function validate(string $expr): bool
    {
        return self::parse($expr) !== null;
    }

    /**
     * Расчет следующего запуска после указанного времени.
     * Гранулярность — 1 минута; максимальный горизонт поиска — 3 года.
     *
     * @param string $expr crontab-выражение
     * @param DateTime|null $from время, после которого ищется запуск (по умолчанию — сейчас)
     * @return DateTime|null null — выражение неверно
     */
    public static function getNextRun(string $expr, DateTime $from = null): ?DateTime
    {
        $parsed = self::parse($expr);
        if ($parsed === null) return null;
        [$minutes, $hours, $days, $months, $weekdays] = $parsed;

        if ($from === null) {
            $from = new DateTime();
        }
        // валидация дат: день месяца и день недели одновременно '*' или оба заданы — OR (как в crontab)
        $domRestricted = ($days !== null);
        $dowRestricted = ($weekdays !== null);

        $ts = $from->getTimestamp();
        // секунды округляем до следующей минуты
        $ts = $ts - ($ts % 60) + 60;

        $limit = $ts + 3 * 366 * 86400;
        $date = \DateTime::createFromFormat('U', (string)$ts);
        if ($date === false) return null;
        $tz = new \DateTimeZone('UTC');
        // работаем в локальном времени сервера: создаем дату от timestamp
        // и двигаем по полям локального времени
        $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        while ($date->getTimestamp() < $limit) {
            $y = (int)$date->format('Y');
            $m = (int)$date->format('n');
            $d = (int)$date->format('j');
            $w = (int)$date->format('w');

            if ($months !== null && !in_array($m, $months, true)) {
                // следующий месяц, 0:00
                $date->setDate($y, $m, 1);
                $date->setTime(0, 0, 0);
                $date->modify('+1 month');
                continue;
            }

            $domOk = ($days === null) || in_array($d, $days, true);
            $dowOk = ($weekdays === null) || in_array($w, $weekdays, true);
            $dayOk = ($domRestricted && $dowRestricted)
                ? ($domOk || $dowOk)
                : ($domOk && $dowOk);

            if (!$dayOk) {
                $date->setDate($y, $m, $d);
                $date->setTime(0, 0, 0);
                $date->modify('+1 day');
                continue;
            }

            $h = (int)$date->format('G');
            if ($hours !== null && !in_array($h, $hours, true)) {
                // следующий час, минута 0
                $date->setTime($h, 0, 0);
                $date->modify('+1 hour');
                continue;
            }

            $i = (int)$date->format('i');
            if ($minutes !== null && !in_array($i, $minutes, true)) {
                $date->setTime($h, $i, 0);
                $date->modify('+1 minute');
                continue;
            }

            return DateTime::createFromTimestamp($date->getTimestamp());
        }

        return null;
    }

    /**
     * Парсинг выражения в списки допустимых значений (в порядке полей).
     * null — поле не ограничено (любое значение).
     *
     * @param string $expr
     * @return array|null [minutes, hours, days, months, weekdays] либо null
     */
    protected static function parse(string $expr): ?array
    {
        // нормализация: неразрывные/unicode-пробелы → обычные, сжатие повторов
        $expr = preg_replace('/[\x{00A0}\x{2000}-\x{200B}\x{3000}]+/u', ' ', (string)$expr) ?? '';
        $expr = trim((string)$expr);
        if ($expr === '') return null;
        $lower = strtolower($expr);
        if (isset(self::ALIASES[$lower])) {
            $expr = self::ALIASES[$lower];
        }
        $parts = preg_split('/\s+/', $expr);
        if (count($parts) !== 5) return null;

        $ranges = [
            [0, 59], // минуты
            [0, 23], // часы
            [1, 31], // день месяца
            [1, 12], // месяц
            [0, 7],  // день недели (0 и 7 — воскресенье)
        ];

        $result = [];
        foreach ($parts as $idx => $part) {
            [$min, $max] = $ranges[$idx];
            if ($part === '*') {
                $result[$idx] = null;
                continue;
            }
            $values = [];
            foreach (explode(',', $part) as $chunk) {
                $chunk = trim($chunk);
                if ($chunk === '') return null;
                $step = 1;
                if (strpos($chunk, '/') !== false) {
                    [$chunk, $stepStr] = explode('/', $chunk, 2);
                    if (!preg_match('/^\d+$/', $stepStr) || (int)$stepStr < 1) return null;
                    $step = (int)$stepStr;
                }
                if ($chunk === '*' || $chunk === '') {
                    $start = $min;
                    $end = $max;
                } elseif (strpos($chunk, '-') !== false) {
                    $bounds = explode('-', $chunk, 2);
                    if (!preg_match('/^\d+$/', $bounds[0]) || !preg_match('/^\d+$/', $bounds[1])) return null;
                    $start = (int)$bounds[0];
                    $end = (int)$bounds[1];
                } else {
                    if (!preg_match('/^\d+$/', $chunk)) return null;
                    $start = (int)$chunk;
                    $end = ($step > 1) ? $max : $start;
                }
                if ($start < $min || $end > $max || $start > $end) return null;
                for ($v = $start; $v <= $end; $v += $step) {
                    $values[] = $v;
                }
            }
            if (empty($values)) return null;
            // день недели: 7 => 0 (воскресенье)
            if ($idx === 4) {
                $values = array_map(function ($v) { return $v === 7 ? 0 : $v; }, $values);
            }
            $result[$idx] = array_values(array_unique($values, SORT_NUMERIC));
        }

        return $result;
    }
}
