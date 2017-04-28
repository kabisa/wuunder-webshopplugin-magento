<?php

class Wuunder_WuunderConnector_Model_Carrier_Wuunder extends Mage_Shipping_Model_Carrier_Abstract
    implements Mage_Shipping_Model_Carrier_Interface
{

    protected $_code = 'wuunder';

    public function isTrackingAvailable() {
        return true;
    }

    public function collectRates(Mage_Shipping_Model_Rate_Request $request)
    {
        Mage::log($request);

        if (!Mage::getStoreConfig('carriers/'.$this->_code.'/active')) {
            return false;
        }



        $handling = Mage::getStoreConfig('carriers/'.$this->_code.'/handling');
        $result = Mage::getModel('shipping/rate_result');
        $show = true;
        if($show){ // This if condition is just to demonstrate how to return success and error in shipping methods

            $method = Mage::getModel('shipping/rate_result_method');
            $method->setCarrier($this->_code);
            $method->setMethod($this->_code);
            $method->setCarrierTitle($this->getConfigData('title'));
            $method->setMethodTitle($this->getConfigData('name'));
            $method->setPrice($this->getConfigData('price'));
            $method->setCost($this->getConfigData('price'));
            $result->append($method);

        }else{
            $error = Mage::getModel('shipping/rate_result_error');
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->getConfigData('name'));
            $error->setErrorMessage($this->getConfigData('specificerrmsg'));
            $result->append($error);
        }
        return $result;
    }

    public function getAllowedMethods()
    {
        return array('wuunder'=>$this->getConfigData('name'));
    }

    public function getTrackingInfo($tracking)
    {
        $result = Mage::helper('wuunderconnector')->getWuunderShipment($tracking);
        Mage::log($result);
        $track = Mage::getModel('shipping/tracking_result_status');
        $track->setUrl($result)
            ->setTracking($tracking)
            ->setCarrierTitle($this->getConfigData('name'));
        return $track;
    }
}
