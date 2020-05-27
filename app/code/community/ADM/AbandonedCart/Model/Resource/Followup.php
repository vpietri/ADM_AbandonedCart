<?php

class ADM_AbandonedCart_Model_Resource_Followup extends Mage_Core_Model_Resource_Db_Abstract
{

    protected function _construct()
    {
        // Note that the id refers to the key field in your database table.
        $this->_init('adm_abandonedcart/followup', 'followup_id');
    }

    public function registerAbandonedCart($minDelay, $maxDelay)
    {

        $quotes = Mage::getModel('sales/quote')->getCollection()
            ->addFieldToFilter('main_table.is_active', 1)
            ->addFieldToFilter('main_table.items_count', array('gt'=>0))
            ->addFieldToFilter('main_table.customer_email', array('notnull' => true))
            ->addFieldToFilter('main_table.updated_at', array('lt'=>$this->_getDateSubTime($minDelay)))
            ->addFieldToFilter('main_table.updated_at', array('gt'=>$this->_getDateSubTime($maxDelay)));

        if(!$this->_getHelper()->followVirtual()) {
            $quotes->addFieldToFilter('main_table.is_virtual', 0);
        }

        $quotes->getSelect()->joinLeft(array('followup'=>$this->getMainTable()) , 'followup.quote_id=main_table.entity_id', array())
            ->where('followup.followup_id is null');


        Mage::dispatchEvent('adm_abandonedcart_quote_collection_load_before', array('collection' => $quotes));

        if($quotes->getSize()>0) {
            $folloupRows = array();
            foreach($quotes as $quote) {
                $folloupRows[] = array('quote_id'=>$quote->getId(),
                        'store_id'=>$quote->getStoreId(),
                        'abandoned_at'=>$quote->getUpdatedAt(),
                        'customer_id'=>$quote->getCustomerId(),
                        'customer_email'=>$quote->getCustomerEmail(),
                        'secret_code'=>md5(uniqid()),
                        'currency' => $quote->getQuoteCurrencyCode(),
                        'base_grand_total'=>$quote->getBaseGrandTotal(),
                        'coupon_code'=>$quote->getCouponCode(),
                        'mail_scheduled_at'=>Varien_Date::now()
                        );
            }

            $affectedRows = $this->_getWriteAdapter()->insertMultiple($this->getMainTable(),$folloupRows);
        } else {
            $affectedRows = 0;
        }

        return $affectedRows;

    }

    protected function _getDateSubTime($nbr, $type = Zend_Date::HOUR)
    {
        $date  = Mage::app()->getLocale()->date()
        ->setTimezone(Mage_Core_Model_Locale::DEFAULT_TIMEZONE)
        ->sub($nbr, $type)
        ->toString(Varien_Date::DATETIME_INTERNAL_FORMAT);

        return $date;
    }

    protected function _getDateAddTime($nbr, $type = Zend_Date::HOUR)
    {
        $date  = Mage::app()->getLocale()->date()
        ->setTimezone(Mage_Core_Model_Locale::DEFAULT_TIMEZONE)
        ->add($nbr, $type)
        ->toString(Varien_Date::DATETIME_INTERNAL_FORMAT);

        return $date;
    }

    protected function _getHelper()
    {
        return Mage::helper('adm_abandonedcart');
    }


}
