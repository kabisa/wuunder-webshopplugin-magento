<?php

class Wuunder_WuunderConnector_Helper_Data extends Mage_Core_Helper_Abstract
{
    const WUUNERCONNECTOR_LOG_FILE = 'wuunder.log';
    const XPATH_DEBUG_MODE = 'wuunderconnector/connect/debug_mode';
    const MIN_PHP_VERSION = '5.3.0';
    public $tblPrfx;


    function __construct()
    {
        $this->tblPrfx = (string)Mage::getConfig()->getTablePrefix();
    }

    public function log($message, $level = null, $file = null, $forced = false, $isError = false)
    {
        if ($isError === true && !$this->isExceptionLoggingEnabled() && !$forced) {
            return $this;
        } elseif ($isError !== true && !$this->isLoggingEnabled() && !$forced) {
            return $this;
        }

        if (is_null($level)) {
            $level = Zend_Log::DEBUG;
        }

        if (is_null($file)) {
            $file = static::WUUNERCONNECTOR_LOG_FILE;
        }

        Mage::log($message, $level, $file, $forced);

        return $this;
    }

    private function isLoggingEnabled()
    {
        if (version_compare(phpversion(), self::MIN_PHP_VERSION, '<')) {
            return false;
        }

        $debugMode = $this->getDebugMode();
        if ($debugMode > 0) {
            return true;
        }

        return false;
    }

    private function getDebugMode()
    {
        if (Mage::registry('wuunderconnector_debug_mode') !== null) {
            return Mage::registry('wuunderconnector_debug_mode');
        }

        $debugMode = (int)Mage::getStoreConfig(self::XPATH_DEBUG_MODE, Mage_Core_Model_App::ADMIN_STORE_ID);
        Mage::register('wuunderconnector_debug_mode', $debugMode);
        return $debugMode;
    }

    public function getAPIHost($storeId)
    {
        $test_mode = Mage::getStoreConfig('wuunderconnector/connect/testmode', $storeId);

        if ($test_mode == 1) {
            $apiUrl = 'https://api-staging.wearewuunder.com/api/';
//            $apiUrl = 'https://api-playground.wearewuunder.com/api/';
        } else {
            $apiUrl = 'https://api.wearewuunder.com/api/';
        }

        return $apiUrl;
    }

    private function getAPIKey($storeId)
    {
        $test_mode = Mage::getStoreConfig('wuunderconnector/connect/testmode', $storeId);

        if ($test_mode == 1) {
            $apiKey = Mage::getStoreConfig('wuunderconnector/connect/api_key_test', $storeId);
//            $apiKey = "pN2XAviEVCRgTsRPU3xWNOp4_4npbv8L";
        } else {
            $apiKey = Mage::getStoreConfig('wuunderconnector/connect/api_key_live', $storeId);
        }

        return $apiKey;
    }


    public function getWuunderOptions()
    {
        return array(
            'header' => 'Wuunder',
            'index' => 'wuunder_options',
            'type' => 'text',
            'width' => '40px',
            'renderer' => 'Wuunder_WuunderConnector_Block_Adminhtml_Order_Renderer_WuunderIcons',
            'filter' => false,
            'sortable' => false,
        );
    }

    /**
     * Retrieve shipment data from the wuunder database table
     *
     * @param $orderId
     * @return array
     */
    public function getShipmentInfo($orderId)
    {
        $shipment = Mage::getModel('wuunderconnector/wuundershipment');
        $shipment->load(intval($orderId), 'order_id');

        if ($shipment) {
            $returnArray = array(
                'shipment_id' => $shipment->getShipmentId(),
                'label_id' => $shipment->getLabelId(),
                'label_url' => $shipment->getLabelUrl(),
                'booking_url' => $shipment->getBookingUrl(),
                'booking_token' => $shipment->getBookingToken()
            );
        } else {
            $returnArray = array(
                'shipment_id' => '',
                'label_id' => '',
                'booking_url' => '',
                'booking_token' => ''
            );
        }

        return $returnArray;
    }

    /**
     * Generates data array with total weight (Sum of the weights of all products), and largest dimensions
     *
     * @param $orderId
     * @return array
     */
    public function getInfoFromOrder($orderId)
    {
        $weightUnit = Mage::getStoreConfig('wuunderconnector/magentoconfig/weight_units');
        // Get Magento order
        $orderInfo = Mage::getModel('sales/order')->load($orderId);
        $totalWeight = 0;
        $maxLength = 0;
        $maxWidth = 0;
        $maxHeight = 0;

        $order = Mage::getModel('sales/order')->load($orderId);
        $storeId = $order->getStoreId();

        // Get total weight from ordered items
        foreach ($orderInfo->getAllItems() AS $orderedItem) {
            // Calculate weight
            if (intval($this->getProductAttribute($storeId, $orderedItem->getProductId(), "length")) > $maxLength) {
                $maxLength = intval($this->getProductAttribute($storeId, $orderedItem->getProductId(), "length"));
            }
            if (intval($this->getProductAttribute($storeId, $orderedItem->getProductId(), "width")) > $maxWidth) {
                $maxWidth = intval($this->getProductAttribute($storeId, $orderedItem->getProductId(), "width"));
            }
            if (intval($this->getProductAttribute($storeId, $orderedItem->getProductId(), "height")) > $maxHeight) {
                $maxHeight = intval($this->getProductAttribute($storeId, $orderedItem->getProductId(), "height"));
            }

            if ($orderedItem->getWeight() > 0) {
                if ($weightUnit === 'kg') {
                    $productWeight = round($orderedItem->getQtyOrdered() * $orderedItem->getWeight() * 1000);
                } else {
                    $productWeight = round($orderedItem->getQtyOrdered() * $orderedItem->getWeight());
                }

                $totalWeight += $productWeight;
            }
        }
        return array(
            'total_weight' => $totalWeight,
            'length' => $maxLength,
            'width' => $maxWidth,
            'height' => $maxHeight
        );
    }

    private function getProductAttribute($storeId, $productId, $attributeCode)
    {
        return Mage::getResourceModel('catalog/product')->getAttributeRawValue($productId, $attributeCode, $storeId);
    }

    /**
     * Send curl request with shipment data to fetch booking url
     * @param $infoArray
     * @return array
     */
    public function processLabelInfo($infoArray)
    {
        // Fetch order
        $order = Mage::getModel('sales/order')->load($infoArray['order_id']);
        $storeId = $order->getStoreId();

        // Get configuration
        $booking_token = uniqid();
        $infoArray['booking_token'] = $booking_token;

        $apiUrl = $this->getAPIHost($storeId) . 'bookings';
        $apiKey = $this->getAPIKey($storeId);

        // Combine wuunder info and order data
        $wuunderJsonData = json_encode($this->buildWuunderData($infoArray, $order, $booking_token));

        // Setup API connection
        $cc = curl_init($apiUrl);
        $this->log('API connection established');

        curl_setopt($cc, CURLOPT_HTTPHEADER,
            array('Authorization: Bearer ' . $apiKey, 'Content-type: application/json'));
        curl_setopt($cc, CURLOPT_POST, 1);
        curl_setopt($cc, CURLOPT_POSTFIELDS, $wuunderJsonData);
        curl_setopt($cc, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($cc, CURLOPT_VERBOSE, 1);
        curl_setopt($cc, CURLOPT_HEADER, 1);

        // Execute the cURL, fetch the XML
        $result = curl_exec($cc);
        $header_size = curl_getinfo($cc, CURLINFO_HEADER_SIZE);
        $header = substr($result, 0, $header_size);
        preg_match("!\r\n(?:Location|URI): *(.*?) *\r\n!i", $header, $matches);

        // Close connection
        curl_close($cc);

        if (count($matches) >= 2) {
            $url = $matches[1];
            $infoArray['booking_url'] = $url;

            // Create or update wuunder_shipment
            if (!$this->saveWuunderShipment($infoArray)) {
                $this->log("Something went wrong with saving wuunder shipment booking");
                return array(
                    'error' => true,
                    'message' => 'Unable to create / update wuunder_shipment for order ' . $infoArray['order_id']
                );
            }
        } else {
            $this->log("Something went wrong:");
            $this->log($apiUrl);
            $this->log($header);
            $this->log($result);
            return array(
                'error' => true,
                'message' => 'Unable to create / update wuunder_shipment for order ' . $infoArray['order_id']
            );
        }

        Mage::helper('wuunderconnector/data')->log('API response string: ' . $result);

        if (empty($url)) {
            return array(
                'error' => true,
                'message' => 'Er ging iets fout bij het booken van het order. Controleer de logging.',
                'booking_url' => ""
            );
        } else {
            return array(
                'error' => false,
                'booking_url' => $url
            );
        }
    }

    /**
     * Save shipment data to existing wuunder shipment according to an orderid and booking token
     *
     * @param $wuunderApiResult
     * @param $orderId
     * @param $booking_token
     * @return bool
     */
    public function processDataFromApi($wuunderApiResult, $orderId, $booking_token)
    {
        $shipment = Mage::getModel('wuunderconnector/wuundershipment');
        $shipment->load(intval($orderId), 'order_id');
        if (!$shipment) {
            return false;
        }

        if ($shipment->getBookingToken() !== $booking_token) {
            return false;
        }

        $shipment->setLabelId($wuunderApiResult['id']);
        $shipment->setLabelUrl($wuunderApiResult['label_url']);
        $shipment->setLabelTtUrl($wuunderApiResult['track_and_trace_url']);
        $shipment->save();
        return true;
    }

    public function processTrackingDataFromApi($carrierCode, $trackingCode, $orderId, $bookingToken) {
        $shipment = Mage::getModel('wuunderconnector/wuundershipment');
        $shipment->load(intval($orderId), 'order_id');
        if (!$shipment) {
            return false;
        }
        if ($shipment->getBookingToken() !== $bookingToken) {
            return false;
        }

        $shipment->setCarrierTrackingCode($trackingCode);
        $shipment->setCarrierCode($carrierCode);
        $shipment->save();
        return true;
    }

    public function buildWuunderData($infoArray, $order, $bookingToken)
    {
        Mage::helper('wuunderconnector/data')->log("Building data object for api.");
        $shippingAddress = $order->getShippingAddress();

        $shippingLastname = $shippingAddress->lastname;

        if (!empty($shippingAddress->middlename)) {
            $shippingLastname = $shippingAddress->middlename . ' ' . $shippingLastname;
        }

        // Get full address, strip enters/newlines etc
        $addressLine = trim(preg_replace('/\s+/', ' ', $shippingAddress->street));

        // Split address in 3 parts
        $addressParts = $this->addressSplitter($addressLine);
        $streetName = $addressParts['streetName'];
        $houseNumber = $addressParts['houseNumber'] . $addressParts['houseNumberSuffix'];

        // Fix DPD parcelshop first- and lastname override fix
        $firstname = $shippingAddress->firstname;
        $lastname = $shippingLastname;
        $company = $shippingAddress->company;

        $customerAdr = array(
            'business' => $company,
            'email_address' => ($order->getCustomerEmail() !== '' ? $order->getCustomerEmail() : $shippingAddress->email),
            'family_name' => $lastname,
            'given_name' => $firstname,
            'locality' => $shippingAddress->city,
            'phone_number' => $infoArray['phone_number'],
            'street_name' => $streetName,
            'house_number' => $houseNumber,
            'zip_code' => $shippingAddress->postcode,
            'country' => $shippingAddress->country_id
        );

        $webshopAdr = array(
            'business' => Mage::getStoreConfig('wuunderconnector/connect/company'),
            'email_address' => Mage::getStoreConfig('wuunderconnector/connect/email'),
            'family_name' => Mage::getStoreConfig('wuunderconnector/connect/lastname'),
            'given_name' => Mage::getStoreConfig('wuunderconnector/connect/firstname'),
            'locality' => Mage::getStoreConfig('wuunderconnector/connect/city'),
            'phone_number' => Mage::getStoreConfig('wuunderconnector/connect/phone'),
            'street_name' => Mage::getStoreConfig('wuunderconnector/connect/streetname'),
            'house_number' => Mage::getStoreConfig('wuunderconnector/connect/housenumber'),
            'zip_code' => Mage::getStoreConfig('wuunderconnector/connect/zipcode'),
            'country' => Mage::getStoreConfig('wuunderconnector/connect/country')
        );

        $orderAmountExclVat = round(($order->getGrandTotal() - $order->getTaxAmount() - $order->getShippingAmount()) * 100);
        if ($orderAmountExclVat <= 0) {
            $orderAmountExclVat = 2500;
        }

        // Load product image for first ordered item
        $image = null;
        $orderedItems = $order->getAllVisibleItems();
        if (count($orderedItems) > 0) {
            foreach ($orderedItems AS $orderedItem) {
                $_product = Mage::getModel('catalog/product')->load($orderedItem->getProductId());
                try {
                    $base64Image = base64_encode(file_get_contents(Mage::helper('catalog/image')->init($_product,
                        'image')));
                } catch (Exception $e) {
                    //Do nothing, base64image is already NULL
                }
                if (!empty($base64Image)) {
                    // Break after first image
                    $image = $base64Image;
                    break;
                }
            }
        }

        $shipping_method = $order->getShippingMethod();
        $preferredServiceLevel = "";
        $shippingMethodCount = 5;
        for ($i = 1; $i <= $shippingMethodCount; $i++) {
            if ($shipping_method === Mage::getStoreConfig('wuunderconnector/connect/filterconnect' . $i . '_value')) {
                $preferredServiceLevel = Mage::getStoreConfig('wuunderconnector/connect/filterconnect' . $i . '_name');
                break;
            }
        }

        $description = $infoArray['description'];
        $picture = $image;
        if (file_exists("app/code/community/Wuunder/WuunderConnector/Override/override.php")) {
            require_once("app/code/community/Wuunder/WuunderConnector/Override/override.php");
            if (isset($overrideShippingDescription)) {
                $description = $overrideShippingDescription;
            }
            if (isset($overrideShippingImage) && file_exists("app/code/community/Wuunder/WuunderConnector/Override/" . $overrideShippingImage)) {
                $picture = base64_encode(file_get_contents("app/code/community/Wuunder/WuunderConnector/Override/" . $overrideShippingImage));
            }
        }


        $parcelshopId = null;
        if ($order->getShippingMethod() == 'wuunderparcelshop_wuunderparcelshop') {
            $parcelshopId =Mage::helper('wuunderconnector/parcelshophelper')->getParcelshopIdForQuote($order->getQuoteId());
        }

        $sourceObj = array(
            "product" => "Magento 1 extension",
            "version" => array(
                "build" => "4.1.0",
                "plugin" => "4.0"
            ),
            "platform" => array(
                "name" => "Magento",
                "build" => Mage::getVersion()
            )
        );

        $staticDescription = Mage::getStoreConfig('wuunderconnector/connect/order_description');

        if (!empty($staticDescription))
            $description = $staticDescription;

        return array(
            'description' => $description,
            'picture' => $picture,
            'customer_reference' => $order->getIncrementId(),
            'value' => $orderAmountExclVat,
            'kind' => $infoArray['packing_type'],
            'length' => $infoArray['length'],
            'width' => $infoArray['width'],
            'height' => $infoArray['height'],
            'weight' => $infoArray['weight'],
            'delivery_address' => $customerAdr,
            'pickup_address' => $webshopAdr,
            'preferred_service_level' => $preferredServiceLevel,
            'parcelshop_id' => $parcelshopId,
            'source' => $sourceObj,
            'redirect_url' => Mage::getUrl('adminhtml') . 'sales_order?label_order=' . $infoArray['order_id'],
            'webhook_url' => Mage::getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB) . 'wuunderconnector/webhook/call/order_id/' . $infoArray['order_id'] . "/token/" . $bookingToken
        );
    }

    /*
     * Save shipment data to wuunder database table
     */
    public function saveWuunderShipment($infoArray)
    {
        // Check if wuunder_shipment already exists
        $shipment = Mage::getModel('wuunderconnector/wuundershipment');
        $shipment->load(intval($infoArray['order_id']), 'order_id');

        if ($shipment && $shipment->getShipmentId() > 0) {
            $shipment->setOrderId($infoArray['order_id']);
            $shipment->setBookingUrl($infoArray['booking_url']);
            $shipment->setBookingToken($infoArray['booking_token']);
        } else {
            $shipment->setData(array(
                "order_id" => $infoArray['order_id'],
                "booking_url" => $infoArray['booking_url'],
                "booking_token" => $infoArray['booking_token']
            ));
        }

        try {
            $shipment->save();
        } catch (Mage_Core_Exception $e) {
            $this->log('ERROR saveWuunderShipment : ' . $e);
            return false;
        }
        return true;
    }

    public function getWuunderShipment($id)
    {
        try {
            //check for a label id
            $shipment = Mage::getModel('wuunderconnector/wuundershipment');
            $shipment->load(intval($id), 'label_id');


            if ($shipment) {
                return $shipment;
            } else {
                return false;
            }
        } catch (Exception $e) {
            $this->log('ERROR getWuunderShipment : ' . $e);
            return false;
        }
    }

    public function addressSplitter($address, $address2 = null, $address3 = null)
    {

        if (!isset($address)) {
            return false;
        }

        if (isset($address2) && $address2 != '' && isset($address3) && $address3 != '') {

            $result['streetName'] = $address;
            $result['houseNumber'] = $address2;
            $result['houseNumberSuffix'] = $address3;

        } else {
            if (isset($address2) && $address2 != '') {

                $result['streetName'] = $address;

                // Pregmatch pattern, dutch addresses
                $pattern = '#^([0-9]{1,5})([a-z0-9 \-/]{0,})$#i';

                preg_match($pattern, $address2, $houseNumbers);

                $result['houseNumber'] = $houseNumbers[1];
                $result['houseNumberSuffix'] = (isset($houseNumbers[2])) ? $houseNumbers[2] : '';

            } else {

                // Pregmatch pattern, dutch addresses
                $pattern = '#^([a-z0-9 [:punct:]\']*) ([0-9]{1,5})([a-z0-9 \-/]{0,})$#i';

                preg_match($pattern, $address, $addressParts);

                $result['streetName'] = isset($addressParts[1]) ? $addressParts[1] : $address;
                $result['houseNumber'] = isset($addressParts[2]) ? $addressParts[2] : "";
                $result['houseNumberSuffix'] = (isset($addressParts[3])) ? $addressParts[3] : '';
            }
        }

        return $result;
    }

    public function getAddressFromQuote()
    {
        $address = Mage::getSingleton('checkout/session')->getQuote()->getShippingAddress();

        $addressToInsert = $address->getStreet(1) . " ";
        if ($address->getStreet(2)) {
            $addressToInsert .= $address->getStreet(2) . " ";
        }

        $addressToInsert .= $address->getPostcode() . " " . $address->getCity() . " " . $address->getCountry();

        return $addressToInsert;
    }

    public function getOneStepValidationField($html)
    {
        if ($this->getIsOnestepCheckout() && Mage::helper('wuunderconnector/parcelshophelper')->checkIfParcelShippingIsSelected($html)) {
            $quote_id = Mage::getSingleton('checkout/session')->getQuote()->getEntityId();
            $parcelshopId = Mage::helper('wuunderconnector/parcelshophelper')->getParcelshopIdForQuote($quote_id);
            return '<input id="onestepValidationField" class="validate-text required-entry" value="' . $parcelshopId . '">';
        }
        return '';
    }



    /**
     * Calculates total weight of a shipment.
     *
     * @param $shipment
     * @return int
     */
    public function calculateTotalShippingWeight($shipment)
    {
        $weight = 0;
        $shipmentItems = $shipment->getAllItems();
        foreach ($shipmentItems as $shipmentItem) {
            $orderItem = $shipmentItem->getOrderItem();
            if (!$orderItem->getParentItemId()) {
                $weight = $weight + ($shipmentItem->getWeight() * $shipmentItem->getQty());
            }
        }
        return $weight;
    }

    /**
     * Check if on Onestepcheckout page or if Onestepcheckout is the refferer
     *
     * @return bool
     */
    public function getIsOnestepCheckout()
    {
        if (strpos(Mage::helper("core/url")->getCurrentUrl(),
                'onestep') !== false || strpos(Mage::app()->getRequest()->getHeader('referer'),
                'onestepcheckout') !== false
        ) {
            return true;
        }
        return false;
    }

    /**
     * Return our custom js when the check for onestepcheckout returns true.
     *
     * @return string
     */
    public function getOnestepCheckoutJs()
    {
        if ($this->getIsOnestepCheckout()) {
            return 'wuunder/onestepcheckout.js';
        }
        return '';
    }
}
