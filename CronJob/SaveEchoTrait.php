<?php
/**
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac\CronJob;

use FacturaScripts\Dinamic\Model\LogMessage;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
trait SaveEchoTrait
{
    /** @var string */
    private static $echo = '';

    protected static function echo(string $text): void
    {
        echo $text;
        self::$echo .= $text;
    }

    protected static function getEcho(): string
    {
        return self::$echo;
    }

    protected static function saveEcho(string $jobName): void
    {
        if (empty($jobName) || empty(self::$echo)) {
            return;
        }

        $log = new LogMessage();
        $log->channel = $jobName;
        $log->level = 'info';
        $log->message = self::$echo;
        $log->save();
    }
}