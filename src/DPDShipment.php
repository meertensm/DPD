<?php namespace MCS;

use Exception;
use Soapclient;
use SoapFault;
use SOAPHeader;

class DPDShipment{

    protected $environment;

    protected $authorisation;

    protected $predictCountries = [
        'BE', 'NL', 'DE', 'AT', 
        'PL', 'FR', 'PT', 'GB', 
        'LU', 'EE', 'CH', 'IE', 
        'SK', 'LV', 'SI', 'LT', 
        'CZ', 'HU'
    ];

    protected $storeOrderMessage = [
        'printOptions' => [
            'paperFormat' => null,
            'printerLanguage' => null
        ],
        'order' => [
            'generalShipmentData' => [
                'sendingDepot' => null,
                'product' => null,
                'mpsCustomerReferenceNumber1' => null,
                'mpsCustomerReferenceNumber2' => null,
                'sender' => [
                    'name1' => null,
                    'name2' => null,
                    'street' => null,
                    'houseNo' => null,
                    'state' => null,
                    'country' => null,
                    'zipCode' => null,
                    'city' => null,
                    'email' => null,
                    'phone' => null,
                    'gln' => null,
                    'contact' => null,
                    'fax' => null,
                    'customerNumber' => null,
                ],
                'recipient' => [           
                    'name1' => null,         
                    'name2' => null,       
                    'street' => null,       
                    'houseNo' => null, 
                    'state' => null,
                    'country' => null,
                    'gln' => null,
                    'zipCode' => null,
                    'customerNumber' => null,
                    'contact' => null,        
                    'phone' => null,                 
                    'fax' => null,                 
                    'email' => null,            
                    'city' => null,       
                    'comment' => null 
                ]
            ],
            'cod'                   => [
                'amount'            => null,
                'currency'          => null,
                'inkasso'           => null,
                'purpose'           => null,
                'bankCode'          => null,
                'bankName'          => null,
                'bankAccountNumber' => null,
                'bankAccountHolder' => null,
                'iban'              => null,
                'bic'               => null,
            ],
            'parcels' => [],
            'productAndServiceData' => [
                'saturdayDelivery' => false,
                'orderType' => 'consignment'
            ]
        ]
    ];

    protected $trackingLanguage = null;
    protected $label = null;
    protected $airWayBills = [];

    const TEST_SHIP_WSDL = 'https://public-ws-stage.dpd.com/services/ShipmentService/V3_1?wsdl';
    const SHIP_WSDL = 'https://public-ws.dpd.com/services/ShipmentService/V3_1?wsdl';
    const SOAPHEADER_URL = 'http://dpd.com/common/service/types/Authentication/2.0';
    const TRACKING_URL = 'https://tracking.dpd.de/parcelstatus?locale=:lang&query=:awb';

    /**
     * @param object  DPDAuthorisation    $authorisationObject 
     * @param boolean [$wsdlCache         = true]
     */
    public function __construct(DPDAuthorisation $authorisationObject, $wsdlCache = true)
    {
        $this->authorisation = $authorisationObject->authorisation;
        $this->environment = [
            'wsdlCache' => $wsdlCache,
            'shipWsdl'  => ($this->authorisation['staging'] ? self::TEST_SHIP_WSDL : self::SHIP_WSDL),
        ];   
        $this->storeOrderMessage['order']['generalShipmentData']['sendingDepot'] = $this->authorisation['token']->depot;
    }


    /**
     * Add a parcel to the shipment
     * @param array $array
     */
    public function addParcel($array)
    {
        if (!isset($array['weight']) or !isset($array['height']) or !isset($array['length']) or !isset($array['width'])){
            throw new Exception('Parcel array not complete');      
        }
        $volume = str_pad((string) ceil($array['length']), 3, '0', STR_PAD_LEFT); 
        $volume .= str_pad((string) ceil($array['width']), 3, '0', STR_PAD_LEFT); 
        $volume .= str_pad((string) ceil($array['height']), 3, '0', STR_PAD_LEFT); 

        $this->storeOrderMessage['order']['parcels'][] = [
            'volume' => $volume,
            'weight' => (int) ceil($array['weight'] / 10)
        ];

    }

    /**
     * Submit the parcel to the DPD webservice
     */
    public function submit()
    {

        if (isset($this->storeOrderMessage['order']['productAndServiceData']['predict'])){
            if (!in_array(strtoupper($this->storeOrderMessage['order']['generalShipmentData']['recipient']['country']), $this->predictCountries)){
                throw new Exception('Predict service not available for this destination');     
            }
        }
        if (count($this->storeOrderMessage['order']['parcels']) === 0){
            throw new Exception('Create at least 1 parcel');     
        }
        
        if ($this->environment['wsdlCache']){
            $soapParams = [
                'cache_wsdl' => WSDL_CACHE_BOTH
            ];
        }
        else{
            $soapParams = [
                'cache_wsdl' => WSDL_CACHE_NONE,
                'exceptions' => true
            ];
        }
        
        try{

            $client = new Soapclient($this->environment['shipWsdl'], $soapParams);
            $header = new SOAPHeader(self::SOAPHEADER_URL, 'authentication', $this->authorisation['token']);
            $client->__setSoapHeaders($header);
            $response = $client->storeOrders($this->storeOrderMessage);

            if (isset($response->orderResult->shipmentResponses->faults)){
                throw new Exception($response->orderResult->shipmentResponses->faults->message);   
            }

            $this->label = $response->orderResult->parcellabelsPDF;
            unset($response->orderResult->parcellabelsPDF);

            if (is_array($response->orderResult->shipmentResponses->parcelInformation)){
                foreach($response->orderResult->shipmentResponses->parcelInformation as $parcelResponse){
                    $this->airWayBills[] = [
                        'airWayBill' => $parcelResponse->parcelLabelNumber,
                        'trackingLink' => strtr(self::TRACKING_URL, [
                            ':awb' => $parcelResponse->parcelLabelNumber,
                            ':lang' => $this->trackingLanguage
                        ])
                    ];
                }
            }
            else{
                $this->airWayBills[] = [
                    'airWayBill' => $response->orderResult->shipmentResponses->parcelInformation->parcelLabelNumber,
                    'trackingLink' => strtr(self::TRACKING_URL, [
                        ':awb' => $response->orderResult->shipmentResponses->parcelInformation->parcelLabelNumber,
                        ':lang' => $this->trackingLanguage
                    ])
                ];    
            }
        }
        catch (SoapFault $e)
        {
         throw new Exception($e->faultstring);   
        }

    }

    /**
     * Enable DPD's B2C service. Only allowed for countries in protected $predictCountries
     * @param array $array
     *  'channel' => email|telephone|sms,
     *  'value' => emailaddress or phone number,
     *  'language' => EN
     */
    public function setPredict($array)
    {

        if (!isset($array['channel']) or !isset($array['value']) or !isset($array['language'])){
            throw new Exception('Predict array not complete');      
        }

        switch (strtolower($array['channel'])) {
            case 'email':
                $array['channel'] = 1;
                if (!filter_var($array['value'], FILTER_VALIDATE_EMAIL)) {
                    throw new Exception('Predict email address not valid');      
                }
                break;
            case 'telephone':
                $array['channel'] = 2;
                if (empty($array['value'])){
                    throw new Exception('Predict value (telephone) empty');      
                }
                break;
            case 'sms':
                $array['channel'] = 3;
                if (empty($array['value'])){
                    throw new Exception('Predict value (sms) empty');      
                }
                break;
            default:
                throw new Exception('Predict channel not allowed');   
        }

        if (ctype_alpha($array['language']) && strlen($array['language']) === 2){
            $array['language'] = strtoupper($array['language']);        
        }
        $this->storeOrderMessage['order']['productAndServiceData']['predict'] = $array;
    }

    /**
     * Set the shipment's Cash On Delivery (COD) values (German: Nachnahme)
     * @param array $array see protected $storeOrderMessage
     */
    public function setCashOnDelivery ($array)
    {
        $this->storeOrderMessage['order']['cod'] = $array;
    }

    /**
     * Get an array with parcelnumber and trackinglink for each package
     * @return array
     */
    public function getParcelResponses()
    {
     return $this->airWayBills;   
    }

    /**
     * Set the general shipmentdata
     * @param array $array see protected $storeOrderMessage
     */
    public function setGeneralShipmentData($array)
    {
     $this->storeOrderMessage['order']['generalShipmentData'] = array_merge($this->storeOrderMessage['order']['generalShipmentData'], $array);
    }

    /**
     * Enable saturday delivery
     * @param boolean $bool default false
     */
    public function setSaturdayDelivery($bool)
    {
     $this->storeOrderMessage['order']['productAndServiceData']['saturdayDelivery'] = $bool; 
    }

    /**
     * Set the shipment's sender
     * @param array $array see protected $storeOrderMessage
     */
    public function setSender($array)
    {
     $array['customerNumber'] = $this->authorisation['customerNumber'];
     $array['city'] = strtoupper($array['city']);
     $this->storeOrderMessage['order']['generalShipmentData']['sender'] = array_merge($this->storeOrderMessage['order']['generalShipmentData']['sender'], $array);
    }

    /**
     * Set the shipment's receiver
     * @param array $array see protected $storeOrderMessage
     */
    public function setReceiver($array)
    {
     $this->storeOrderMessage['order']['generalShipmentData']['recipient'] = array_merge($this->storeOrderMessage['order']['generalShipmentData']['recipient'], $array);
    }

    /**
     * Set the printoptions
     * @param array $array see protected $storeOrderMessage
     */
    public function setPrintOptions($printoptions)
    {
     $this->storeOrderMessage['printOptions'] = array_merge($this->storeOrderMessage['printOptions'], $printoptions);
    }
    
    /**
     * Set the language for the track & trace link
     * @param string $language format: en_EN
     */
    public function setTrackingLanguage($language)
    {
     $this->trackingLanguage = $language;  
    }
    
    /**
     * Get's the shipment label pdf as a string
     * @return string
     */
    public function getLabels()
    {
     return $this->label;   
    }

}
