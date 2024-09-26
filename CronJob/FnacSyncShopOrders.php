<?php
/**
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac\CronJob;

use FacturaScripts\Core\Base\Calculator;
use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Base\Utils;
use FacturaScripts\Core\DataSrc\Almacenes;
use FacturaScripts\Core\Model\Base\ModelCore;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Lib\ExportManager;
use FacturaScripts\Dinamic\Model\Cliente;
use FacturaScripts\Dinamic\Model\FacturaCliente;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Dinamic\Model\LogMessage;
use FacturaScripts\Dinamic\Model\Pais;
use FacturaScripts\Dinamic\Model\Variante;
use FacturaScripts\Plugins\Fnac\Lib\FnacApiClient;
use FacturaScripts\Plugins\Fnac\Lib\FnacVies;
use FacturaScripts\Plugins\Fnac\Model\FnacShop;
use XMLWriter;

/**
 * @author Carlos Garcia Gomez      <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
final class FnacSyncShopOrders
{
    use SaveEchoTrait;

    const JOB_NAME = 'fnac-sync-shop-orders';
    const JOB_PERIOD = '2 hours';
    const SERIEB_MAX_AMOUNT = 400;

    /** @var int */
    private static $founds;

    /** @var int */
    private static $new_invoices;

    /** @var Impuesto[] */
    private static $taxes;

    public static function run(): void
    {
        echo "\n* Sincronizando tiendas Fnac ... ";

        $dataBase = new DataBase();

        // recorremos todas las tiendas activas
        $fnacShop = new FnacShop();
        $where = [new DataBaseWhere('activa', true),];
        foreach ($fnacShop->all($where, [], 0, 0) as $shop) {

            self::echo("\n- Tienda: " . $shop->nombre . ' ... ');

            // obtenemos los últimos 500 pedidos
            $api = $shop->api();
            $orders = self::get_last_orders($api, $shop);

            self::$founds = self::$new_invoices = 0;
            self::echo("\n" . $shop->nombre . ': ' . count($orders) . ' elementos encontrados...');

            // procesamos los pedidos
            foreach ($orders as $item) {

                if (false === isset($item['order_detail'][0])) {
                    $item['order_detail'] = [$item['order_detail']];
                }

                // iniciamos la transacción
                $dataBase->beginTransaction();

                if (false === self::sync_factura($shop, $item)) {

                    // deshacemos los cambios
                    $dataBase->rollback();
                    self::echo("\n-ERROR-SYNC-FACTURA:" . $item['order_id'] . '-');
                    break;
                }

                // guardamos los cambios
                $dataBase->commit();
            }

            self::echo("\n" . self::$new_invoices . ' facturas nuevas y ' . self::$founds . " facturas previamente encontradas.\n\n");
        }

        self::saveEcho(self::JOB_NAME);
    }

    private static function error(string $message)
    {
        self::echo($message . "\n");

        $newLog = new LogMessage();
        $newLog->channel = self::JOB_NAME;
        $newLog->message = $message;
        $newLog->level = 'error';
        $newLog->save();
    }

    private static function find_factura(string $numero2): FacturaCliente
    {
        $factura = new FacturaCliente();
        $where = [new DataBaseWhere('numero2', $numero2)];
        $factura->loadFromCode('', $where);
        return $factura;
    }

    private static function get_cliente(array $item, string $nif): Cliente
    {
        $firstname = $item['client_firstname'] ?? '';
        $lastname = $item['client_lastname'] ?? '';
        $nombre = Utils::noHtml($firstname . ' ' . $lastname);
        $phone = $item["billing_address"]['phone'] ?? '';

        $cliente = new Cliente();
        if (empty($nif)) {
            $where = [
                new DataBaseWhere('nombre', $nombre),
                new DataBaseWhere('telefono1', $phone),
            ];
        } else {
            $where = [new DataBaseWhere('cifnif', $nif)];
        }
        if ($cliente->loadFromCode('', $where)) {
            return $cliente;
        }

        $companyFirst = $item['billing_address']['firstname'] ?? '';
        $companyLast = $item['billing_address']['lastname'] ?? '';
        $companyName = Utils::noHtml($companyFirst . ' ' . $companyLast);

        // si no existe, lo creamos
        $cliente->cifnif = $nif;
        $cliente->nombre = $nombre;
        $cliente->razonsocial = empty($companyName) ? $nombre : $companyName;
        $cliente->telefono1 = $phone;

        if (false === empty($item['billing_address']['mobile'])) {
            $cliente->telefono2 = $item['billing_address']['mobile'];
        }

        if ($cliente->save()) {
            self::echo("C");
        }

        return $cliente;
    }

    private static function get_direccion(array $item, int $len = 100): string
    {
        $direccion = $item['billing_address']['address1'];
        return mb_strlen($direccion) < $len ? $direccion : mb_substr($direccion, 0, $len);
    }

    private static function get_last_orders(FnacApiClient $api, FnacShop $shop): array
    {
        $offset = 1;
        $orders = [];

        do {
            self::echo('*');

            $xml = new XMLWriter();
            $xml->openMemory();
            $xml->startDocument("1.0", "utf-8");
            $xml->startElementNs(
                null,
                "orders_query",
                "http://www.fnac.com/schemas/mp-dialog.xsd"
            );
            $xml->writeAttribute("partner_id", $shop->partnerid);
            $xml->writeAttribute("shop_id", $shop->shopid);
            $xml->writeAttribute("key", $shop->apikey);
            $xml->writeElementNs(
                null,
                "paging",
                null,
                $offset
            );

            // fechas
            $xml->startElementNs(
                null,
                "date",
                null
            );
            $xml->writeAttribute("type", "CreatedAt");
            $xml->writeElementNs(
                null,
                "min",
                null,
                date('Y-m-d\TH:i:s', strtotime($shop->fechaini))
            );
            $xml->endElement();

            // estados
            $xml->startElementNs(
                null,
                "states",
                null
            );
            $xml->writeElementNs(
                null,
                "state",
                null,
                "Shipped"
            );
            $xml->writeElementNs(
                null,
                "state",
                null,
                "Refunded"
            );
            $xml->writeElementNs(
                null,
                "state",
                null,
                "Received"
            );
            $xml->endElement();

            $xml->endElement();
            $xml->endDocument();

            // obtenemos los pedidos
            // cada página trae 100 pedidos máximo por defecto
            $response = $api->do_post("orders_query", $xml->outputMemory());
            if (empty($response)) {
                break;
            }

            // leemos el xml y lo convertimos en array
            $items = json_decode(json_encode(simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
            if (empty($items['order'])) {
                break;
            }

            // recorremos los pedidos y los añadimos al array
            foreach ($items['order'] as $item) {
                // si tenemos una fecha de fin, comprobamos que el pedido sea anterior
                if (!empty($shop->fechafin) && strtotime($item['created_at']) > strtotime($shop->fechafin)) {
                    continue;
                }

                array_unshift($orders, $item);
            }

            // si el offset es menor que el total de páginas, incrementamos el offset
            if ($offset < $items['total_paging']) {
                $offset++;
            } else {
                break;
            }

            // esperamos 1 segundo para no saturar la API
            sleep(1);
        } while (true);

        return $orders;
    }

    private static function get_pais(array $item): Pais
    {
        $pais_model = new Pais();
        if ($pais_model->loadFromCode($item['billing_address']['country'])) {
            return $pais_model;
        }

        // save country
        $pais_model->codiso = $pais_model->codpais = $item['billing_address']['country'];
        $pais_model->nombre = $item['billing_address']['country'];
        $pais_model->save();
        return $pais_model;
    }

    private static function get_tax(float $value): Impuesto
    {
        if (!isset(self::$taxes)) {
            $impuesto_model = new Impuesto();
            self::$taxes = $impuesto_model->all([], [], 0, 0);
        }

        foreach (self::$taxes as $tax) {
            if ($tax->iva == $value) {
                return $tax;
            }
        }

        return new Impuesto();
    }

    protected static function getTotal(array $item): float
    {
        $total = 0;
        foreach ($item['order_detail'] as $detail) {
            $total += (float)$detail['price'] * (float)$detail['quantity'];
        }

        $total += (float)$item['order_detail'][0]['shipping_price'];
        return $total;
    }

    private static function new_factura(FnacShop $shop, array $item, Cliente $cliente): bool
    {
        // Si la factura ya existe, saltamos
        $found = self::find_factura($item['order_id']);
        if ($found->exists()) {
            self::echo("-");
            self::$founds++;
            return self::send_factura($shop, $item, $found);
        }

        $factura = new FacturaCliente();
        $factura->codagente = $shop->codagente;
        $factura->codalmacen = $shop->codalmacen;
        $factura->codpago = $shop->codpago;
        $factura->codserie = empty($cliente->cifnif) && floatval($item['total_price']) <= self::SERIEB_MAX_AMOUNT ?
            $shop->codserieb : $shop->codserie;
        $factura->idempresa = Almacenes::get($shop->codalmacen)->idempresa;

        $factura->cifnif = $cliente->cifnif;
        $factura->ciudad = $item['billing_address']['city'];
        $factura->codcliente = $cliente->codcliente;
        $factura->codpais = self::get_pais($item)->codpais;
        $factura->codpostal = $item['billing_address']['zipcode'];
        $factura->direccion = self::get_direccion($item);
        $factura->nombrecliente = $cliente->razonsocial;
        $factura->observaciones = $factura->numero2 = $item['order_id'];

        // asignamos la fecha del pedido, si se puede
        self::set_fecha_factura($factura, date(ModelCore::DATE_STYLE, strtotime($item['created_at'])));

        if (false === $factura->save()) {
            return false;
        }

        self::echo("F");
        foreach ($item['order_detail'] as $line) {
            if (false === self::new_linea_factura($shop, $line, $factura)) {
                return false;
            }
        }

        if (false === self::new_linea_factura_envio($shop, $item['order_detail'][0], $factura)) {
            return false;
        }

        $docLines = $factura->getLines();
        if (false === Calculator::calculate($factura, $docLines, true)) {
            return false;
        }

        // pagamos los recibos
        foreach ($factura->getReceipts() as $recibo) {
            $recibo->pagado = true;
            if (false === $recibo->save()) {
                return false;
            }
        }

        // comprobaciones
        if (abs($factura->total - (float)$item['total_price']) > 0.01) {
            self::error('ERROR EN EL TOTAL DEL PEDIDO ' . $item['order_id'] . ': ' . ($factura->total - (float)$item['total_price']));
            return false;
        }

        // recargamos la factura
        $factura->loadFromCode($factura->primaryColumnValue());

        // la marcamos como emitida
        foreach ($factura->getAvailableStatus() as $status) {
            if (false === $status->editable) {
                $factura->editable = $status->editable;
                $factura->idestado = $status->idestado;
                if (false === $factura->save()) {
                    return false;
                }
                break;
            }
        }

        self::$new_invoices++;
        return self::send_factura($shop, $item, $factura);
    }

    private static function new_factura_rectificativa(FnacShop $shop, array $item, Cliente $cliente): bool
    {
        $original = self::find_factura($item['order_id']);
        if (false === $original->exists()) {
            self::error('ERROR:FACTURA-ORIGINAL-NO-ENCONTRADA');
            return false;
        }

        if (!empty($original->codigorect) || count($original->getRefunds()) > 0) {
            self::$founds++;
            return true;
        }

        // nueva factura rectificativa a partir de la original
        $factura = clone $original;
        $factura->codigo = null;
        $factura->codigorect = $original->codigo;
        $factura->codserie = empty($cliente->cifnif) && floatval($item['total_price']) <= self::SERIEB_MAX_AMOUNT ?
            $shop->codserierb : $shop->codserier;
        $factura->idasiento = null;
        $factura->idfactura = null;
        $factura->idfacturarect = $original->idfactura;

        // asignamos el estado predeterminado
        foreach ($factura->getAvailableStatus() as $status) {
            if ($status->predeterminado) {
                $factura->editable = $status->editable;
                $factura->idestado = $status->idestado;
                break;
            }
        }

        // asignamos ejercicio y fecha
        if (false === $factura->setDate($fecha = date('d-m-Y'), date('H:i:s'))) {
            return false;
        }

        $factura->numero = null;
        $docLines = [];
        if (false === Calculator::calculate($factura, $docLines, true)) {
            return false;
        }

        // recargamos la factura porque era eun clonado y así ya tenemos todos los datos bien
        $factura->loadFromCode($factura->primaryColumnValue());

        self::echo("R");
        foreach ($original->getLines() as $linea) {
            $new_linea = clone $linea;
            $new_linea->idfactura = $factura->idfactura;
            $new_linea->idlinea = null;
            $new_linea->cantidad *= -1;
            $new_linea->pvpsindto *= -1;
            $new_linea->pvptotal *= -1;
            if (false === $new_linea->save()) {
                return false;
            }
            self::echo("L");
        }

        $docLines = $factura->getLines();
        if (false === Calculator::calculate($factura, $docLines, true)) {
            return false;
        }

        // pagamos los recibos
        foreach ($factura->getReceipts() as $recibo) {
            $recibo->pagado = true;
            if (false === $recibo->save()) {
                return false;
            }
        }

        // recargamos la factura
        $factura->loadFromCode($factura->primaryColumnValue());

        // la marcamos como emitida
        foreach ($factura->getAvailableStatus() as $status) {
            if (false === $status->editable) {
                $factura->editable = $status->editable;
                $factura->idestado = $status->idestado;
                if (false === $factura->save()) {
                    return false;
                }
                break;
            }
        }

        self::$new_invoices++;
        return self::send_factura($shop, $item, $factura);
    }

    private static function new_linea_factura(FnacShop $shop, array $line, FacturaCliente $factura): bool
    {
        $variant = new Variante();
        $where = [new DataBaseWhere('referencia', $line['offer_seller_id'])];
        if ($variant->loadFromCode('', $where)) {
            $new_line = $factura->getNewProductLine($variant->referencia);
        } else {
            $new_line = $factura->getNewLine();
        }

        $new_line->descripcion = $line['product_name'];
        $new_line->cantidad = (float)$line['quantity'];

        $impuesto = $shop->getImpuesto();
        $new_line->iva = $impuesto->iva;
        $new_line->codimpuesto = $impuesto->codimpuesto;

        // Los precios son con impuestos incluidos, recalculamos el pvpunitario
        $new_line->pvpunitario = (100 * (float)$line['price']) / (100 + $new_line->iva);

        $new_line->pvpsindto = $new_line->pvptotal = $new_line->pvpunitario * $new_line->cantidad;
        if (false === $new_line->save()) {
            return false;
        }

        self::echo("L");
        return true;
    }

    private static function new_linea_factura_envio(FnacShop $shop, array $line, FacturaCliente $factura): bool
    {
        if (empty($line['shipping_price'])) {
            return true;
        }

        $variant = new Variante();
        $where = [new DataBaseWhere('referencia', $line['offer_seller_id'])];
        if ($variant->loadFromCode('', $where)) {
            $new_line = $factura->getNewProductLine($variant->referencia);
        } else {
            $new_line = $factura->getNewLine();
        }

        $new_line->descripcion = 'Envío';
        $new_line->cantidad = 1;

        $impuesto = $shop->getImpuesto();
        $new_line->iva = $impuesto->iva;
        $new_line->codimpuesto = $impuesto->codimpuesto;

        // Los precios son con impuestos incluidos, recalculamos el pvpunitario
        $new_line->pvpunitario = (100 * (float)$line['shipping_price']) / (100 + $new_line->iva);

        $new_line->pvpsindto = $new_line->pvptotal = $new_line->pvpunitario * $new_line->cantidad;
        if (false === $new_line->save()) {
            return false;
        }

        self::echo("L");
        return true;
    }

    private static function save_address(Cliente $cliente, array $item): void
    {
        $address = $cliente->getDefaultAddress();
        if (empty($address->direccion)) {
            $address->ciudad = $item['billing_address']['city'];
            $address->codpais = self::get_pais($item)->codpais;
            $address->codpostal = $item['billing_address']['zipcode'];
            $address->direccion = self::get_direccion($item);
            if ($address->save()) {
                self::echo("D");
            }
        }
    }

    private static function send_factura(FnacShop $shop, array $item, FacturaCliente $factura): bool
    {
        if (false === $shop->enviarfacturas || $factura->femail) {
            return true;
        }

        // generamos el pdf
        $filename = 'factura_' . $factura->codigo . '.pdf';
        $filePath = FS_FOLDER . '/MyFiles/' . $filename;
        $pdf = new ExportManager();
        $pdf->newDoc('PDF', $filename, 0, $shop->langcode ?? '');
        $pdf->addBusinessDocPage($factura);
        if (file_put_contents($filePath, $pdf->getDoc()) === false) {
            self::echo("\n-Error al generar el PDF de la factura " . $factura->codigo . "\n");
            return false;
        }

        $xml = new XMLWriter();
        $xml->openMemory();
        $xml->startDocument("1.0", "utf-8");
        $xml->startElementNs(
            null,
            "messages_update",
            "http://www.fnac.com/schemas/mp-dialog.xsd"
        );
        $xml->writeAttribute("partner_id", $shop->partnerid);
        $xml->writeAttribute("shop_id", $shop->shopid);
        $xml->writeAttribute("key", $shop->apikey);

        // message
        $xml->startElementNs(
            null,
            "message",
            null
        );
        $xml->writeAttribute("action", "create");
        $xml->writeAttribute("id", $item['order_id']);
        $xml->writeElementNs(
            null,
            "message_to",
            null,
            'CLIENT'
        );
        $xml->writeElementNs(
            null,
            "message_subject",
            null,
            'order_information'
        );
        $xml->writeElementNs(
            null,
            "message_description",
            null,
            'Attached invoice'
        );
        $xml->writeElementNs(
            null,
            "message_type",
            null,
            'ORDER'
        );

        $xml->startElementNs(
            null,
            "message_file",
            null
        );
        $xml->writeElementNs(
            null,
            "filename",
            null,
            $filename
        );
        $xml->writeElementNs(
            null,
            "data",
            null,
            base64_encode(file_get_contents($filePath))
        );
        $xml->writeElementNs(
            null,
            "message_filetype",
            null,
            'TYPE_INVOICE'
        );
        $xml->endElement(); // end file

        $xml->endElement(); // end message

        $xml->endElement();
        $xml->endDocument();

        // enviamos el mensaje
        $response = $shop->api()->do_post("messages_update", $xml->outputMemory());

        // eliminamos el pdf creado
        unlink($filePath);

        if (empty($response)) {
            return false;
        }

        // leemos el xml y lo convertimos en array
        $items = json_decode(json_encode(simplexml_load_string($response, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
        if (false === isset($items['@attributes']['status'])
            || $items['@attributes']['status'] !== 'OK') {
            return false;
        }

        // guardamos la fecha de envío
        $factura->femail = date(ModelCore::DATE_STYLE);
        return $factura->save();
    }

    private static function set_fecha_factura(FacturaCliente $invoice, string $date): void
    {
        // comprobamos si ya hay facturas posteriores a esta fecha en la misma serie y empresa
        $where = [
            new DataBaseWhere('codserie', $invoice->codserie),
            new DataBaseWhere('idempresa', $invoice->idempresa),
            new DataBaseWhere('fecha', $date, '>')
        ];
        $orderBy = ['fecha' => 'DESC'];
        foreach ($invoice->all($where, $orderBy) as $factura) {
            $date = $factura->fecha;
            break;
        }

        // asignamos la fecha
        $invoice->setDate($date, $invoice->hora);
    }

    private static function sync_factura(FnacShop $shop, array $item): bool
    {
        if (empty($item['order_id'])) {
            return true;
        }

        if ($shop->filtercountry && $item['billing_address']['country'] != $shop->filtercountry) {
            // continue
            self::echo("\n-skip:country:" . $item['billing_address']['country'] . '-');
            return true;
        }

        $cifnif = $item['billing_address']['nif'] ?? '';
        $item['total_price'] = self::getTotal($item);
        if (empty($cifnif) && $item['total_price'] > self::SERIEB_MAX_AMOUNT) {
            // continue
            return true;
        }

        // comprobamos importe mínimo sin cif/nif
        if (empty($cifnif) && $item['total_price'] < floatval($shop->mintotalsin)) {
            self::echo("\n-EXCLUDE(1)-");
            return true;
        }

        // comprobamos importe máximo sin cif/nif
        if (empty($cifnif) && $item['total_price'] > floatval($shop->maxtotalsin)) {
            self::echo("\n-EXCLUDE(2)-");
            return true;
        }

        // comprobamos importe mínimo con cif/nif
        if ($cifnif && $item['total_price'] < floatval($shop->mintotalcon)) {
            self::echo("\n-EXCLUDE(3)-");
            return true;
        }

        // comprobamos importe máximo con cif/nif
        if ($cifnif && $item['total_price'] > floatval($shop->maxtotalcon)) {
            self::echo("\n-EXCLUDE(4)-");
            return true;
        }

        // comprobamos el VIES
        $vies_exclude = FnacVies::exclude($cifnif, self::get_pais($item)->codiso, $shop->solovies);
        if ($vies_exclude == -1) {
            self::error("\n-ERROR-CONSULTANDO-VIES-" . $cifnif . '-');
            return true;
        } elseif ($vies_exclude == 1) {
            return true;
        }

        switch ($item['state']) {
            case 'Refunded':
                $cliente = self::get_cliente($item, $cifnif);
                return self::new_factura($shop, $item, $cliente) && self::new_factura_rectificativa($shop, $item, $cliente);

            case 'Received':
            case 'Shipped':
                $cliente = self::get_cliente($item, $cifnif);
                return self::new_factura($shop, $item, $cliente);

            default:
                self::echo('-' . $item['state'] . '-');
                return true;
        }
    }
}