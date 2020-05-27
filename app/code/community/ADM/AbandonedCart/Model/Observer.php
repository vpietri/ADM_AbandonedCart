<?php
class ADM_AbandonedCart_Model_Observer
{
    /**
     *
     * @param Mage_Cron_Model_Schedule $schedule
     */
    public function registerAbandonedCartAndSendFirstMail(Mage_Cron_Model_Schedule $schedule)
    {
        if(!Mage::helper('adm_abandonedcart')->isEnabled()) {
            return false;
        }
        
        $this->deleteInvalidFollowups();

        $affectedRows = Mage::getModel('adm_abandonedcart/followup')->registerAbandonedCart();

        $collection =  Mage::getModel('adm_abandonedcart/followup')->getCollection()
            ->filterAbandonedCartByOffset();

        $limit = Mage::helper('adm_abandonedcart')->getMailToSendLimit();
        if($limit) {
            $collection->getSelect()->limit($limit);
        }

        if($collection->getSize()>0) {
            foreach ($collection as $followup) {
                $followup->sendMail();
            }
        }

        return $this;
    }
    
    private function deleteInvalidFollowups()
    {
        //Delete all followups where the quote is no longer abandoned. Thit is those cases where the quote was updated
        //after the date when it was marked as abandoned or when it was converted into an order
        $followupsToDelete = Mage::getModel('adm_abandonedcart/followup')->getCollection();
        $select = $followupsToDelete->getSelect();
        $select->join(
            array('quote'=>$followupsToDelete->getTable('sales/quote')),
            'main_table.quote_id=quote.entity_id',
            array()
        );
        $select->where('quote.updated_at > main_table.abandoned_at OR quote.is_active = 0');

        /** @var ADM_AbandonedCart_Model_Followup $followupToDelete */
        foreach ($followupsToDelete as $followupToDelete) {
            $followupToDelete->delete();
        }
    }
}
