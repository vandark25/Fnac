<?php
/**
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac;

use FacturaScripts\Core\Template\CronClass;
use FacturaScripts\Plugins\Fnac\CronJob\FnacSyncShopOrders;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class Cron extends CronClass
{
    public function run(): void
    {
        $this->job(FnacSyncShopOrders::JOB_NAME)
            ->every(FnacSyncShopOrders::JOB_PERIOD)
            ->withoutOverlapping()
            ->run(
                function (): void {
                    FnacSyncShopOrders::run();
                }
            );
    }
}
