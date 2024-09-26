<?php
/**
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac;

use FacturaScripts\Core\Base\CronClass;
use FacturaScripts\Plugins\Fnac\CronJob\FnacSyncShopOrders;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class Cron extends CronClass
{
    public function run()
    {
        if ($this->isTimeForJob(FnacSyncShopOrders::JOB_NAME, FnacSyncShopOrders::JOB_PERIOD)) {
            FnacSyncShopOrders::run();
            $this->jobDone(FnacSyncShopOrders::JOB_NAME);
        }
    }
}