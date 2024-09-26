<?php
/**
 * Copyright (C) 2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac\Lib;

use FacturaScripts\Core\Http;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class FnacCifNif
{
    const CITY_API_KEY = 'JlfX7c1VkMEQRKdbSfwT';

    public static function getVatInfoCity(string $cif, string $codiso): array
    {
        if (empty($cif)) {
            return [];
        }

        $request = Http::post('https://factura.city/PortalValidarVat', [
            'codiso' => $codiso,
            'query' => $cif,
            'apikey' => self::CITY_API_KEY,
        ]);

        if ($request->failed()) {
            return [];
        }

        return $request->json();
    }
}