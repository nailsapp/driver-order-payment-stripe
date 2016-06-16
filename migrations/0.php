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

namespace Nails\Database\Migration\Nailsapp\DriverInvoiceStripe;

use Nails\Common\Console\Migrate\Base;

class Migration0 extends Base
{
    /**
     * Execute the migration
     * @return Void
     */
    public function execute()
    {
        $this->query("
            CREATE TABLE `{{NAILS_DB_PREFIX}}driver_invoice_stripe_customer` (
                `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
                `customer_id` int(11) unsigned NOT NULL,
                `stripe_id` varchar(50) NOT NULL DEFAULT '',
                `created` datetime NOT NULL,
                `created_by` int(11) unsigned DEFAULT NULL,
                `modified` datetime NOT NULL,
                `modified_by` int(11) unsigned DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `created_by` (`created_by`),
                KEY `modified_by` (`modified_by`),
                KEY `customer_id` (`customer_id`),
                CONSTRAINT `{{NAILS_DB_PREFIX}}driver_invoice_stripe_customer_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}driver_invoice_stripe_customer_ibfk_3` FOREIGN KEY (`modified_by`) REFERENCES `{{NAILS_DB_PREFIX}}user` (`id`) ON DELETE SET NULL,
                CONSTRAINT `{{NAILS_DB_PREFIX}}driver_invoice_stripe_customer_ibfk_4` FOREIGN KEY (`customer_id`) REFERENCES `{{NAILS_DB_PREFIX}}invoice_customer` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
        ");
    }
}
