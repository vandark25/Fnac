<?php
/**
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Session;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\Impuesto;
use FacturaScripts\Plugins\Fnac\Lib\FnacApiClient;

/**
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class FnacShop extends ModelClass
{
    use ModelTrait;

    /** @var bool */
    public $activa;

    /** @var string */
    public $apikey;

    /** @var string */
    public $apiurl;

    /** @var string */
    public $creationdate;

    /** @var string */
    public $codalmacen;

    /** @var string */
    public $codagente;

    /** @var string */
    public $codimpuesto;

    /** @var string */
    public $codpago;

    /** @var string */
    public $codserie;

    /** @var string */
    public $codserieb;

    /** @var string */
    public $codserier;

    /** @var string */
    public $codserierb;

    /** @var bool */
    public $enviarfacturas;

    /** @var string */
    public $fechafin;

    /** @var string */
    public $fechaini;

    /** @var string */
    public $filtercountry;

    /** @var int */
    public $id;

    /** @var string */
    public $langcode;

    /** @var string */
    public $lastnick;

    /** @var string */
    public $lastupdate;

    /** @var int */
    public $maxtotalcon;

    /** @var int */
    public $maxtotalsin;

    /** @var int */
    public $mintotalcon;

    /** @var int */
    public $mintotalsin;

    /** @var string */
    public $nick;

    /** @var string */
    public $nombre;

    /** @var string */
    public $partnerid;

    /** @var string */
    public $shopid;

    /** @var string */
    public $solovies;

    public function api(): FnacApiClient
    {
        return new FnacApiClient($this);
    }

    public function clear()
    {
        parent::clear();
        $this->activa = false;
        $this->creationdate = Tools::dateTime();
        $this->enviarfacturas = false;
        $this->fechaini = Tools::date();
        $this->langcode = FS_LANG;
        $this->mintotalcon = 0;
        $this->mintotalsin = 0;
        $this->maxtotalcon = 0;
        $this->maxtotalsin = 0;
        $this->nick = Session::user()->nick;
    }

    public function getImpuesto(): Impuesto
    {
        $model = new Impuesto();
        if ($model->loadFromCode($this->codimpuesto)) {
            return $model;
        }

        $model->loadFromCode(Tools::settings('default', 'codimpuesto'));
        return $model;
    }

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'fnac_shops';
    }

    public function test(): bool
    {
        $this->apikey = Tools::noHtml($this->apikey);
        $this->apiurl = Tools::noHtml($this->apiurl);
        $this->filtercountry = Tools::noHtml($this->filtercountry);
        $this->lastnick = Tools::noHtml($this->lastnick);
        $this->nombre = Tools::noHtml($this->nombre);
        $this->nick = Tools::noHtml($this->nick);

        return parent::test();
    }

    public function url(string $type = 'auto', string $list = 'EditSettings?activetab=List'): string
    {
        return parent::url($type, $list);
    }

    protected function saveInsert(array $values = []): bool
    {
        $this->creationdate = Tools::dateTime();
        $this->lastnick = null;
        $this->lastupdate = null;
        $this->nick = Session::user()->nick;

        return parent::saveInsert($values);
    }

    protected function saveUpdate(array $values = []): bool
    {
        $this->lastupdate = Tools::dateTime();
        $this->lastnick = Session::user()->nick;

        return parent::saveUpdate($values);
    }
}