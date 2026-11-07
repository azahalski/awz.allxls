<?php
namespace Awz\Allxls;

use Bitrix\Main\Diag\Logger as DiagLogger;

class Logger
{
    public static $requestId;
    const MODULE_ID = 'awz.allxls';

    /** @var object|null PSR-логгер (создается один раз) */
    private static $loggerInstance = null;
    /** @var bool|null null — DiagLogger еще не проверялся, true — недоступен (fallback) */
    private static $fallback = null;

    public static function info(string $message, array $context = []): void
    {
        self::log('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::log('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::log('error', $message, $context);
    }

    public static function debug(string $message, array $context = []): void
    {
        self::log('debug', $message, $context);
    }

    private static function log(string $level, string $message, array $context = []): void
    {
        if (!self::$requestId) {
            self::$requestId = \Bitrix\Main\Security\Random::getString(16);
        }
        $contextStr = !empty($context) ? ' | Context: ' . json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        try {
            if (self::$fallback !== true) {
                if (self::$loggerInstance === null) {
                    // создание PSR-логгера один раз на процесс
                    self::$loggerInstance = DiagLogger::create(static::MODULE_ID, [null]);
                }
                self::$loggerInstance->log(
                    $level,
                    self::$requestId.' | '.date("c").' | '.$message.$contextStr."\n",
                    array_merge(['module' => self::MODULE_ID], $context)
                );
                self::$fallback = false;
                return;
            }
        } catch (\Exception $e) {
            self::$fallback = true;
        } catch (\Throwable $e) {
            self::$fallback = true;
        }
        // fallback: debug в CEventLog не пишем (шумно и дорого — запись в БД на каждую строку)
        if ($level === 'debug') {
            return;
        }
        $severityMap = [
            'info' => 'INFO',
            'warning' => 'WARNING',
            'error' => 'ERROR'
        ];
        $auditTypeMap = [
            'info' => 'AWZ_ALLXLS_INFO',
            'warning' => 'AWZ_ALLXLS_WARNING',
            'error' => 'AWZ_ALLXLS_ERROR'
        ];
        $severity = $severityMap[$level] ?? 'INFO';
        $auditType = $auditTypeMap[$level] ?? 'AWZ_ALLXLS_INFO';
        try {
            \CEventLog::Add([
                'SEVERITY' => $severity,
                'AUDIT_TYPE_ID' => $auditType,
                'MODULE_ID' => self::MODULE_ID,
                'DESCRIPTION' => $message . $contextStr
            ]);
        } catch (\Throwable $e) {
            // логирование не должно ломать основной поток
        }
    }
}