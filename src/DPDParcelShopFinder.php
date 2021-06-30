<?php namespace MCS;

use Exception;
use Soapclient;
use SoapFault;
use SOAPHeader;

class DPDParcelShopFinder
{

    const TEST_PARCEL_SHOP_FINDER_WSDL = 'https://public-ws-stage.dpd.com/services/ParcelShopFinderService/V5_0';
    const PARCEL_SHOP_FINDER_WSDL      = 'https://public-ws.dpd.com/services/ParcelShopFinderService/V5_0';

    const SOAPHEADER_URL = 'http://dpd.com/common/service/types/Authentication/2.0';

    protected $environment;
    protected $authorisation;

    /**
     * @var array
     */
    private $coordinates = [];

    /**
     * @var int|null
     */
    private $limit = 100;

    /**
     * @var array
     */
    private $parcelShops = [];

    public function __construct(DPDAuthorisation $authorisationObject, $wsdlCache = true)
    {
        $this->authorisation = $authorisationObject->authorisation;
        $this->environment   = [
            'wsdlCache' => $wsdlCache,
            'parcelShopWsdl'  => ($this->authorisation['staging'] ? self::TEST_PARCEL_SHOP_FINDER_WSDL : self::PARCEL_SHOP_FINDER_WSDL),
        ];
    }

    /**
     * @param float $latitude
     * @param float $longitude
     * @return $this
     */
    public function setCoordinates($latitude, $longitude)
    {
        $this->coordinates = compact('latitude', 'longitude');

        return $this;
    }

    /**
     * @param int $limit
     * @return $this
     */
    public function setLimit($limit)
    {
        $this->limit = $limit;

        return $this;
    }


    /**
     * Submit the parcel to the DPD webservice
     */
    public function submit()
    {

        if (!empty($this->coordinates)) {
            $this->environment['parcelShopWsdl'] .= '/findParcelShopsByGeoData';
        }

        $this->environment['parcelShopWsdl'] .= '?wsdl';


        if ($this->environment['wsdlCache']) {
            $soapParams = [
                'cache_wsdl' => WSDL_CACHE_BOTH
            ];
        } else {
            $soapParams = [
                'cache_wsdl' => WSDL_CACHE_NONE,
                'exceptions' => true
            ];
        }

        try {

            $client = new Soapclient($this->environment['parcelShopWsdl'], $soapParams);
            $header = new SOAPHeader(self::SOAPHEADER_URL, 'authentication', $this->authorisation['token']);
            $client->__setSoapHeaders($header);

            if (!empty($this->coordinates)) {
                $response = $client->findParcelShopsByGeoData(
                    array_merge(
                        $this->coordinates,
                        [
                            'limit' => $this->limit
                        ]
                    )
                );
            }


            if (!empty($response->parcelShop)) {
                $this->parcelShops = $response->parcelShop;
            }

        } catch (SoapFault $e) {
            throw new Exception($e->faultstring, 0, $e);
        }

    }

    /**
     * @return \stdClass[]
     */
    public function getParcelShops()
    {
        return $this->parcelShops;
    }

}
