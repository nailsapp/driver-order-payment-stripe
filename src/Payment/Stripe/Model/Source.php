<?php

/**
 * This model interfaces with Stripe Customer Payment source objects
 *
 * @package     Nails
 * @subpackage  driver-invoice-stripe
 * @category    Model
 * @author      Nails Dev Team
 */

namespace Nails\Invoice\Driver\Payment\Stripe\Model;

use Nails\Common\Model\Base;

class Source extends Base
{
    /**
     * Construct the model
     */
    public function __construct()
    {
        parent::__construct();
        $this->table       = NAILS_DB_PREFIX . 'driver_invoice_stripe_source';
        $this->tablePrefix = 'diss';
    }
}
