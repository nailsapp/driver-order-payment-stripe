<?php

/**
 * This model interfaces with Stripe Customer objects
 *
 * @package     Nails
 * @subpackage  driver-invoice-stripe
 * @category    Model
 * @author      Nails Dev Team
 */

namespace Nails\Invoice\Driver\Payment\Stripe\Model;

use Nails\Common\Model\Base;

class Customer extends Base
{
    /**
     * Construct the model
     */
    public function __construct()
    {
        parent::__construct();
        $this->table      = NAILS_DB_PREFIX . 'driver_invoice_stripe_customer';
        $this->tableAlias = 'disc';
    }

    // --------------------------------------------------------------------------

    /**
     * Return a customer by its Stripe ID
     * @param  string $sStripeId The Stripe customer ID to look up
     * @return \stdClass|false
     */
    public function getByStripeId($sStripeId)
    {
        $aResults = $this->getAll(
            0,
            1,
            array(
                'where' => array(
                    array($this->getTableAlias(true) . 'stripe_id', $sStripeId)
                )
            )
        );

        return count($aResults) === 1 ? $aResults[0] : false;
    }

    // --------------------------------------------------------------------------

    /**
     * Return a customer by its Customer ID
     * @param  string $iCustomerId The customer ID to look up
     * @return \stdClass|false
     */
    public function getByCustomerId($iCustomerId)
    {
        $aResults = $this->getAll(
            0,
            1,
            array(
                'where' => array(
                    array($this->getTableAlias(true) . 'customer_id', $iCustomerId)
                )
            )
        );

        return count($aResults) === 1 ? $aResults[0] : false;
    }
}
