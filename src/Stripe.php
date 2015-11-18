<?php

/**
 * Stripe payment Driver
 *
 * @package     Nails
 * @subpackage  driver-order-payment-stripe
 * @category    Driver
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\OrderPayment\Driver;

use Nails\OrderPayment\Driver\Base;

class Stripe extends Base
{
    protected $sLabel = 'Stripe';

    // --------------------------------------------------------------------------

    /**
     * Returns the driver's configurable options
     * @return array
     */
    public function getConfig(){
        return array();
    }

    // --------------------------------------------------------------------------

    /**
     * Configures the driver using the saved values from getConfig();
     */
    public function setConfig($aConfig){
        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Take a payment
     * @return boolean
     */
    public function charge(){
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     * @return boolean
     */
    public function refund(){
        return true;
    }
}
