<?php
namespace Awz\Allxls\Cron;

/**
 * Агенты Bitrix: запуск импортов/экспортов по расписанию без crontab.
 *
 * Агент регистрируется при установке модуля (интервал 5 минут, периодический)
 * и вызывает тот же Runner, что и CLI-скрипт cron/run.php: профили с
 * CRON_ACTIVE=Y и наступившим временем запуска (DATE_NEXT_RUN), с той же
 * блокировкой в БД — двойной запуск одного профиля исключен.
 */
class Agents
{
    /** @var string имя функции агента (для регистрации) */
    public const AGENT_FUNCTION = '\\Awz\\Allxls\\Cron\\Agents::runAll();';

    /** @var int секунд без выполнения агента (NEXT_EXEC в прошлом) — агент считается отставшим */
    public const CRON_OVERDUE_SEC = 600;

    /**
     * Агент запуска всех профилей по расписанию.
     * @return string имя агента для повторного запуска
     */
    public static function runAll(): string
    {
        try {
            // скачивание/актуализация файлов внешних источников профилей
            // (кеш с TTL) до запуска прогонов
            \Awz\Allxls\Helper\ProfilesHelper::refreshExternalProfiles('agent');
            $runner = new Runner();
            // агент запускает только профили в режиме «агент Bitrix»;
            // профили в режиме crontab обрабатываются cron/run.php all
            $runner->execAll('agent');
        } catch (\Throwable $e) {
            // сбой агента не должен ломать его перезапуск
        }
        return self::AGENT_FUNCTION;
    }

    /**
     * Регистрация периодического агента (интервал 5 минут).
     * @return bool
     */
    public static function register(): bool
    {
        // Метод SetModuleID удален, так как его не существует в \CAgent
        $agent = new \CAgent;

        // Ищем существующий агент
        $existing = $agent->GetList(
            [],
            [
                'MODULE_ID' => 'awz.allxls',
                'NAME' => self::AGENT_FUNCTION
            ]
        );

        if ($existing && $existing->Fetch()) {
            return true;
        }

        // Регистрируем новый агент с правильным порядком аргументов
        return (bool)\CAgent::AddAgent(
            self::AGENT_FUNCTION, // name
            'awz.allxls',         // module
            'N',                  // periodic
            300,                  // interval
            '',                   // date_check
            'Y',                  // active
            '',                   // next_exec
            100,                  // sort
            false                 // user_id
        );
    }


    /**
     * Статус агента: зарегистрирован, активен, дубликаты, отставание.
     * Агент зависит от хитов сайта (или crontab для agent.php) и может
     * «отставать» — NEXT_EXEC в прошлом дольше CRON_OVERDUE_SEC.
     * @return array {exists, duplicates, active, next_exec, overdue}
     */
    public static function status(): array
    {
        $count = 0;
        $active = false;
        $nextExec = null;
        $res = (new \CAgent)->GetList([], [
            'MODULE_ID' => 'awz.allxls',
            'NAME' => self::AGENT_FUNCTION
        ]);
        while ($row = $res->Fetch()) {
            $count++;
            if (($row['ACTIVE'] ?? 'N') === 'Y') {
                $active = true;
                $ts = !empty($row['NEXT_EXEC']) ? (strtotime((string)$row['NEXT_EXEC']) ?: 0) : 0;
                if ($ts > 0 && ($nextExec === null || $ts < $nextExec)) $nextExec = $ts;
            }
        }
        $overdue = ($nextExec !== null && $nextExec < time() - self::CRON_OVERDUE_SEC);
        return [
            'exists' => $count > 0,
            'duplicates' => $count > 1,
            'active' => $active,
            'next_exec' => $nextExec ? date('d.m.Y H:i:s', $nextExec) : null,
            'overdue' => $overdue,
        ];
    }

    /**
     * Снятие агента.
     * @return bool
     */
    public static function unregister(): bool
    {
        $agent = new \CAgent;
        $rs = $agent->GetList([], ['MODULE_ID' => 'awz.allxls', 'NAME' => self::AGENT_FUNCTION]);
        while ($row = $rs->Fetch()) {
            \CAgent::Delete((int)$row['ID']);
        }
        return true;
    }
}
