<?php
/**
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Tools;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class EditFnacShop extends EditController
{
    public function getModelClassName(): string
    {
        return 'FnacShop';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'fnac-shop';
        $data['icon'] = 'fas fa-cart-arrow-down';
        return $data;
    }

    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);

        // cargamos la lista de idiomas en el widget invoice-language
        $columnLangCode = $this->views[$this->getMainViewName()]->columnForName('invoice-language');
        if ($columnLangCode && $columnLangCode->widget->getType() === 'select') {
            $langs = [];
            foreach (Tools::lang()->getAvailableLanguages() as $key => $value) {
                $langs[] = ['value' => $key, 'title' => $value];
            }

            $columnLangCode->widget->setValuesFromArray($langs, false, true);
        }
    }
}