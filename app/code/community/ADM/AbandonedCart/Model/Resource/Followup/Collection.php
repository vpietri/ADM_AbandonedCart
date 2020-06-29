<?php

class ADM_AbandonedCart_Model_Resource_Followup_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct()
    {
        $this->_init('adm_abandonedcart/followup');
        return parent::_construct();
    }


    public function filterAbandonedCartByOffset()
    {
        $availableOffsets = Mage::helper('adm_abandonedcart')->getMaxOffset();

        $this->addFieldToFilter('status', array('lteq'=>0))
            ->addFieldToFilter('offset', array('lt'=>Mage::helper('adm_abandonedcart')->getMaxOffset()))
            ->addFieldToFilter('mail_scheduled_at', array('lteq' => Mage::getModel('core/date')->gmtDate()));

        $this->getSelect()->join(array('quote'=>$this->getTable('sales/quote')), 'main_table.quote_id=quote.entity_id', array('quote_still_active'=>'is_active'));

        return $this;
    }

}
