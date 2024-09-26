<?php
/**
 * Copyright (C) 2023 Carlos Garcia Gomez <carlos@facturascripts.com>
 */

namespace FacturaScripts\Plugins\Fnac\Lib;

use FacturaScripts\Dinamic\Model\LogMessage;
use FacturaScripts\Plugins\Fnac\Model\FnacShop;

/**
 * @author Carlos Garcia Gomez <carlos@facturascripts.com>
 */
class FnacApiClient
{
    /** @var LogMessage */
    protected $core_log;

    /** @var int */
    protected $httpCode;

    /** @var FnacShop */
    protected $shop;

    public function __construct(FnacShop $shop)
    {
        $this->shop = $shop;
        $this->core_log = new LogMessage();
    }

    public function do_get(string $what, string $idItem = ''): array
    {
        $resource = curl_init();
        if (empty($idItem)) {
            curl_setopt($resource, CURLOPT_URL, $this->shop->apiurl . '/' . $what);
        } else {
            curl_setopt($resource, CURLOPT_URL, $this->shop->apiurl . '/' . $what . '/' . $idItem);
        }
        return $this->process_curl($resource);
    }

    public function do_post(string $what, string $xml): string
    {
        $resource = curl_init();
        curl_setopt($resource, CURLOPT_URL, $this->shop->apiurl . '/' . $what);
        curl_setopt($resource, CURLOPT_POST, true);
        curl_setopt($resource, CURLOPT_POSTFIELDS, $xml);
        return $this->process_curl($resource);
    }

    public function do_put(string $what, array $value = []): array
    {
        $resource = curl_init();
        curl_setopt($resource, CURLOPT_URL, $this->shop->apiurl . '/' . $what);

        /// don't change for CURLOPT_PUT
        curl_setopt($resource, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($resource, CURLOPT_POSTFIELDS, http_build_query($value));

        return $this->process_curl($resource);
    }

    private function process_curl(&$resource): string
    {
        curl_setopt($resource, CURLOPT_TIMEOUT, 30);
        curl_setopt($resource, CURLOPT_RETURNTRANSFER, true);

        $result = curl_exec($resource);
        $error = curl_error($resource);
        if (!empty($error)) {
            echo $error;
        }

        $this->httpCode = curl_getinfo($resource, CURLINFO_HTTP_CODE);
        if (200 != $this->httpCode) {
            echo '--CURL HTTP code: ' . $this->httpCode . '--';
        }

        curl_close($resource);
        return $result;
    }
}