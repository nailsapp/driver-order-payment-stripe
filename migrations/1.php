<?php

/**
 * Migration:   0
 * Started:     16/06/2016
 * Finalised:
 *
 * @package     Nails
 * @subpackage  driver-invoice-stripe
 * @category    Database Migration
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Database\Migration\Nails\DriverInvoiceStripe;

use Nails\Common\Console\Migrate\Base;

class Migration0 extends Base
{
    /**
     * Execute the migration
     * @return Void
     */
    public function execute()
    {
        //  Migrate payment sources to the module
        $this->query('
            INSERT INTO `{{NAILS_DB_PREFIX}}invoice_source` (
                `customer_id`,
                `driver`,
                `data`,
                `label`,
                `brand`,
                `last_four`,
                `expiry`,
                `created`,
                `created_by`,
                `modified`,
                `modified_by`
            )
            SELECT
                ic.id,
                \'nails/driver-invoice-stripe\',
                CONCAT(\'{"source_id": "\', ss.stripe_id , \'","customer_id":"\', sc.stripe_id, \'"}\'),
                ss.brand,
                CONCAT(ss.brand, \' ending \', ss.last4),
                ss.last4,
                LAST_DAY(CONCAT(ss.exp_year, \'-\', ss.exp_month, \'-01\')),
                ss.created,
                ss.created_by,
                ss.modified,
                ss.modified_by
            FROM {{NAILS_DB_PREFIX}}driver_invoice_stripe_source ss
            LEFT JOIN {{NAILS_DB_PREFIX}}invoice_customer ic ON ss.customer_id = ic.id
            LEFT JOIN {{NAILS_DB_PREFIX}}driver_invoice_stripe_customer sc ON sc.customer_id = ic.id
        ');
        $this->query('DROP TABLE `{{NAILS_DB_PREFIX}}driver_invoice_stripe_source`;');
        $this->query('DROP TABLE `{{NAILS_DB_PREFIX}}driver_invoice_stripe_customer`;');
    }
}
