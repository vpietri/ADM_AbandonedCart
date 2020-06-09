<?php

class ADM_AbandonedCart_Model_Followup extends Mage_Core_Model_Abstract
{
    protected $_trackerObject;

    const XML_PATH_EMAIL_IDENTITY        = 'abandonedcart/general/email_identity';
    const XML_PATH_EMAIL_COPY_TO         = 'abandonedcart/general/email_copy_to';

    const XML_PATH_TEST_MODE             = 'abandonedcart/general/test_mode';
    const XML_PATH_TEST_EMAIL            = 'abandonedcart/general/test_email';

    const ENTITY                         = 'abandonedcart';

    const EMAIL_EVENT_NAME               = 'send_followup';

    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'abandoned_cart_followup';

    protected function _construct()
    {
        $this->_init('adm_abandonedcart/followup');
        return parent::_construct();
    }

    /**
     *
     * @return int
     */
    public function registerAbandonedCart()
    {
        $delays = Mage::helper('adm_abandonedcart')->getOffsetDelays();
        if($delays) {
            $minDelay = 100000;
            $maxDelay = 0;
            foreach($delays as $delay) {
                $minDelay = min($minDelay, $delay);
                $maxDelay = max($maxDelay, $delay);
            }

            if($maxDelay==$minDelay) {
                $maxDelay = 2*$minDelay;
            }
            return $this->getResource()->registerAbandonedCart($minDelay, $maxDelay);
        } else {
            return 0;
        }

    }

    public function tryToRestoreCart($restoreCode='')
    {
        $tracker = $this->getTrackerObject();
        $return = array();

        if(!$this->getId()) {
            $return['error'] = true;
            $return['message'] = 'Unknown followup';
            return $return;
        } elseif(empty($restoreCode) or $this->getSecretCode()!=$restoreCode) {
            $return['error'] = true;
            $return['message'] = 'Restore code is invalid';
            $tracker->setEventError(ADM_AbandonedCart_Model_Tracker::ERR_WRONG_CODE);
        } elseif ($this->_getSession()->isLoggedIn()) {
            if ($this->getCustomerId() == $this->_getSession()->getCustomerId()) {

                if($this->_getCurrentQuoteId()==$this->getQuoteId()) {
                    $tracker->setEventSuccess(ADM_AbandonedCart_Model_Tracker::OK_LOG_USER_WITH_CART);
                } else {
                    $return['error'] = true;
                    $return['message'] = 'Cannot restore current cart';
                    $tracker->setEventError(ADM_AbandonedCart_Model_Tracker::ERR_USER_LOGGED);
                }

            } else {
                $return['error'] = true;
                $return['message'] = 'Cart to restore do not belong to current user';
                $tracker->setEventError(ADM_AbandonedCart_Model_Tracker::ERR_WRONG_USER_LOGGED);
            }
        } else {
            if ($this->getCustomerId()){
                $customer = Mage::getModel('customer/customer')->load($this->getCustomerId());
                //TODO: Check config to know what to do
                if ($customer->getId()) {
                    //TODO: Detect if cart is still the same
                    Mage::getSingleton('customer/session')->setCustomerAsLoggedIn($customer);
                    $tracker->setEventSuccess(ADM_AbandonedCart_Model_Tracker::OK_LOG_USER);
                }
            } elseif ($this->getQuoteId()){
                //TODO: Detect if cart is still the same
                $quote = Mage::getModel('sales/quote')->load($this->getQuoteId());
                if ($quote) {
                    Mage::getSingleton('checkout/session')->replaceQuote($quote);
                    $tracker->setEventSuccess(ADM_AbandonedCart_Model_Tracker::OK_RESTORE_CART);
                }
            } else {
                $tracker->setEventError(ADM_AbandonedCart_Model_Tracker::ERR_NO_INFO);
                $return['error'] = true;
                $return['message'] = 'No info';
            }
        }

//         var_dump($this->_getSession()->isLoggedIn(), $return);
//         exit;


        $this->save();

        return $return;
    }

    public function getRedirectUrl()
    {
        if(!$this->hasData('redirect_url') and $this->_getCurrentQuoteId()) {
            return 'checkout/cart';
        } else {
            return $this->getData('redirecturl');
        }
    }

    protected function _getSession()
    {
        return Mage::getSingleton('customer/session');
    }

    protected function _getCurrentQuoteId()
    {
        $checkoutSession = Mage::getSingleton('checkout/session');
        if($checkoutSession->hasQuote()) {
            return $checkoutSession->getQuoteId();
        } else {
            return false;
        }
    }


    public function sendMail($force=false)
    {
        $mailSent = false;
        $error    = false;

        $templateId = $force ? Mage::getStoreConfig('abandonedcart/admin/template') : $this->_getTemplate();
        if(empty($templateId)) {
            $error = ADM_AbandonedCart_Model_Tracker::ERR_NO_TEMPLATE;
        }

        $quote = Mage::getModel('sales/quote')->setStore(Mage::app()->getStore($this->getStoreId()))
            ->load($this->getQuoteId());

        if(!$quote->getId()) {
            $error = ADM_AbandonedCart_Model_Tracker::ERR_NO_QUOTE;
        }


        if(!$force and !$this->getQuoteStillActive()) {
            $error = ADM_AbandonedCart_Model_Tracker::ERR_NO_QUOTE_ACTIVE;
        }

        if(!$error) {
            try {
                $mailSent = $this->_sendMail($templateId, $quote);
            } catch (Exception $e) {
                Mage::logException($e);
            }
        }

        $this->setMailSent($mailSent, $error);

        return $this->save();
    }

    /**
     *
     *
     */
    public function _sendMail($templateId, $quote)
    {
        $mailSent = false;

        $tpl = Mage::getModel('core/email_template');

        // Get the destination email addresses to send copies to
        $bcc = $this->_getEmails(self::XML_PATH_EMAIL_COPY_TO);
        if($bcc) {
            $tpl->addBcc($bcc);
        }

        // Start store emulation process
        /** @var $appEmulation Mage_Core_Model_App_Emulation */
        $appEmulation = Mage::getSingleton('core/app_emulation');
        $initialEnvironmentInfo = $appEmulation->startEnvironmentEmulation($this->getStoreId());

        $tplVars = array('followup'  => $this,
                          'quote' => $quote
                );

        try {
            $mailTo = $this->_getMailTo();
            if(empty($mailTo)) {
                throw new Exception('Empty mail');
            }

            $tpl->setDesignConfig(array('area'=>'frontend', 'store'=>$this->getStoreId()))
            ->sendTransactional(
                    $templateId,
                    Mage::getStoreConfig(self::XML_PATH_EMAIL_IDENTITY, $this->getStoreId()),
                    $mailTo,
                    Mage::helper('customer')->getFullCustomerName($quote),
                    $tplVars
            );

        } catch (Exception $exception) {
            // Stop store emulation process
            $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);
            throw $exception;
        }

        $mailSent = $tpl->getSentSuccess();

        // Stop store emulation process
        $appEmulation->stopEnvironmentEmulation($initialEnvironmentInfo);

        return $mailSent;
    }

    protected function _getMailTo()
    {
        $mailTo = $this->getCustomerEmail();
        if( Mage::getStoreConfigFlag(self::XML_PATH_TEST_MODE, $this->getStoreId())) {
            $mailTest = trim(Mage::getStoreConfig(self::XML_PATH_TEST_EMAIL, $this->getStoreId()));
            if(!empty($mailTest)) {
                return explode(';', $mailTest);
            } else {
                return false;
            }
        }
        return $mailTo;
    }

    protected function _getEmails($configPath)
    {
        $data = Mage::getStoreConfig($configPath, $this->getStoreId());
        if (!empty($data)) {
            return explode(';', $data);
        }
        return false;
    }


    protected function _getTemplate()
    {
        return Mage::helper('adm_abandonedcart')->getConfigByOffset('template', $this->getOffset()+1, $this->getStoreId());
    }

    public function setMailSent($sent = false, $error= false)
    {
        $this->setData('mail_sent', $sent);
        if ($sent) {
            $this->getTrackerObject()->setEvent(ADM_AbandonedCart_Model_Tracker::OK_SEND_MAIL);
        } else {
            if(!$error) {
                $error = ADM_AbandonedCart_Model_Tracker::ERR_SEND_DATA;
            }
            $this->getTrackerObject()->setEventError($error);
        }

        return $this;
    }

    public function getRestoreUrl()
    {
        /** @var $store Mage_Core_Model_Store */
        $store = Mage::app()->getStore($this->getStoreId());
        $params = array('id'=>$this->getId(), 'offset'=>$this->getOffset()+1, 'restore_code'=>$this->getSecretCode(), '_nosid'=>true);
        $params = array_merge($params, Mage::helper('adm_abandonedcart')->getUtmParams($this->getStoreId()));


        return $store->getUrl('abandonedcart/restore/cart', $params);
    }


    /**
     * @return ADM_AbandonedCart_Model_Tracker
     */
    public function getTrackerObject()
    {
         if (empty($this->_trackerObject)) {
             $this->_trackerObject = Mage::getModel('adm_abandonedcart/tracker');
             $this->_trackerObject->setFollowup($this);
             $this->_trackerObject->setStatus(ADM_AbandonedCart_Model_Tracker::PENDING);
         }

         return $this->_trackerObject;
    }

    /**
     * Processing object before save data
     *
     * @return Mage_Core_Model_Abstract
     */
    protected function _beforeSave()
    {
        if($this->getMailSent()) {
            $this->setMailScheduledAt($this->_getNextDate());
            $this->setOffset($this->getOffset()+1);
        }

        $tracker = $this->getTrackerObject();

        $tracker->setOffset($this->getOffset())->save();

        if($tracker->getTrackCode()) {
            $this->setStatus($tracker->getStatus());
        }

        return parent::_beforeSave();
    }

    protected function _getNextDate()
    {
        $delayCurrent = Mage::helper('adm_abandonedcart')->getConfigByOffset('delay', $this->getOrigData('offset'), $this->getStoreId());
        $delayNext    = Mage::helper('adm_abandonedcart')->getConfigByOffset('delay', $this->getOrigData('offset')+1, $this->getStoreId());

        $delayReal = $delayNext-$delayCurrent;

        return Mage::app()->getLocale()->date($this->getMailScheduledAt())
            ->add(max($delayReal, 0), Zend_Date::HOUR)  //Make sure not to add negative delays
            ->toString(Varien_Date::DATETIME_INTERNAL_FORMAT);
    }

}
