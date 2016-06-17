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
        $this->table       = NAILS_DB_PREFIX . 'driver_invoice_stripe_customer';
        $this->tablePrefix = 'disc';
    }
}
