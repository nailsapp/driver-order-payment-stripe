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
        $this->table = NAILS_DB_PREFIX . 'driver_invoice_stripe_customer';
    }

    // --------------------------------------------------------------------------

    /**
     * Return a customer by its Stripe ID
     *
     * @param string $sStripeId The Stripe Customer ID to look up
     * @param array  $aData     Any additional data to pass in
     *
     * @return \stdClass|false
     * @throws \Nails\Common\Exception\ModelException
     */
    public function getByStripeId($sStripeId, array $aData = [])
    {
        return $this->getByColumn('stripe_id', $sStripeId, $aData, false);
    }

    // --------------------------------------------------------------------------

    /**
     * Return a customer by its internal Customer ID
     *
     * @param integer $iCustomerId The internal Customer ID to look up
     * @param array   $aData       Any additional data to pass in
     *
     * @return \stdClass|false
     * @throws \Nails\Common\Exception\ModelException
     */
    public function getByCustomerId($iCustomerId, array $aData = [])
    {
        return $this->getByColumn('customer_id', $iCustomerId, $aData, false);
    }
}
