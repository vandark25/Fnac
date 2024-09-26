<?php
/**
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac\Lib;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class FnacVies
{
    const URL = "https://factura.city/vat-info";

    /**
     * Comprueba si el CIF/NIF debe ser excluido.
     * Devuelve 1 si hay que excluir, 0 si no y -1 si hay un error.
     */
    public static function exclude(string &$cifnif, string $codiso, ?string $mode): int
    {
        if (empty($mode)) {
            // no excluimos nada
            return 0;
        }

        $data = FnacCifNif::getVatInfoCity($cifnif, $codiso);
        if (isset($data['message'])) {
            return -1;
        }

        // es válido
        if ($data['vies']) {
            // es válido ¿Lo excluimos?
            if ($mode == 'EV') {
                echo "\nExcluimos '$cifnif' por ser válido en VIES. ";
                return 1;
            }

            // es válido, pero no lo excluimos
            return 0;
        }

        // no es válido ¿Lo excluimos?
        if ($mode == 'EI') {
            echo "\nExcluimos '$cifnif' por no ser válido en VIES. ";
            return 1;
        }

        // no es válido, pero no lo excluimos
        return 0;
    }
}