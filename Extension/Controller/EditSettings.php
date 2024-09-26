<?php
/**
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac\Extension\Controller;

use Closure;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class EditSettings
{
    public function createViews(): Closure
    {
        return function () {
            $viewName = 'ListFnacShop';
            $this->addListView($viewName, 'FnacShop', 'fnac', 'fas fa-store');
            $this->views[$viewName]->addSearchFields(['nombre']);
            $this->views[$viewName]->addOrderBy(['nombre'], 'name', 1);
        };
    }
}
