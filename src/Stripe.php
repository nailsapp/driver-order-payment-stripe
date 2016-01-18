<?php

/**
 * Stripe payment Driver
 *
 * @package     Nails
 * @subpackage  driver-invoice-stripe
 * @category    Driver
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Invoice\Driver;

use Nails\Factory;
use Nails\Invoice\Driver\Base;
use Nails\Invoice\Exception\DriverException;

class Stripe extends Base
{
    protected $sLabel = 'Stripe';

    //  API Keys
    protected $sKeyTestSecret;
    protected $sKeyTestPublic;
    protected $sKeyLiveSecret;
    protected $sKeyLivePublic;

    // --------------------------------------------------------------------------

    /**
     * Configures the driver
     * @return object
     */
    public function setConfig($aConfig) {
        parent::setConfig($aConfig);
        $this->sKeyTestSecret = !empty($aConfig['key_test_secret']) ? $aConfig['key_test_secret'] : null;
        $this->sKeyTestPublic = !empty($aConfig['key_test_public']) ? $aConfig['key_test_public'] : null;
        $this->sKeyLiveSecret = !empty($aConfig['key_live_secret']) ? $aConfig['key_live_secret'] : null;
        $this->sKeyLivePublic = !empty($aConfig['key_live_public']) ? $aConfig['key_live_public'] : null;
        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Take a payment
     * @return \Nails\Invoice\Model\ChargeResponse
     */
    public function charge($aCard, $iAmount, $sCurrency) {

        $oResponse = Factory::factory('ChargeResponse', 'nailsapp/module-invoice');

        try {

            if (ENVIRONMENT === 'PRODUCTION') {

                \Stripe\Stripe::setApiKey($this->sKeyLiveSecret);

            } else {

                \Stripe\Stripe::setApiKey($this->sKeyTestSecret);
            }

            $oStripeResponse = \Stripe\Charge::create(
                array(
                    'amount'   => $iAmount,
                    'currency' => $sCurrency,
                    'source'   => array(
                        'object'    => 'card',
                        'name'      => $aCard['name'],
                        'number'    => $aCard['number'],
                        'exp_month' => $aCard['exp_month'],
                        'exp_year'  => $aCard['exp_year'],
                        'cvc'       => $aCard['cvc']
                    )
                )
            );

            if ($oStripeResponse->status === 'paid') {
                $oResponse->setStatusOk();
                $oResponse->setTxnId($oStripeResponse->id);
            }

        } catch(\Exception $e) {

            throw new DriverException($e->getMessage(), $e->getCode());
        }

        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     * @return \Nails\Invoice\Model\RefundResponse
     */
    public function refund() {

        $oResponse = Factory::factory('RefundResponse', 'nailsapp/module-invoice');
        return $oResponse;
    }
}
