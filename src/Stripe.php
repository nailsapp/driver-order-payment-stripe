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
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\DriverException;

class Stripe extends PaymentBase
{
    /**
     * Returns whether the driver uses a redirect payment flow or not.
     * @return boolean
     */
    public function isRedirect()
    {
        return false;
    }

    // --------------------------------------------------------------------------


    /**
     * Returns the payment fields the driver requires, use self::PAYMENT_FIELDS_CARD
     * for basic credit card details.
     * @return mixed
     */
    public function paymentFields()
    {
        return self::PAYMENT_FIELDS_CARD;
    }

    // --------------------------------------------------------------------------

    /**
     * Take a payment
     * @return \Nails\Invoice\Model\ChargeResponse
     */
    public function charge($aCard, $iAmount, $sCurrency)
    {
        $oResponse = Factory::factory('ChargeResponse', 'nailsapp/module-invoice');

        try {

            if (ENVIRONMENT === 'PRODUCTION') {

                $sApiKey = $this->getSetting('sKeyLiveSecret');

            } else {

                $sApiKey = $this->getSetting('sKeyTestSecret');
            }

            \Stripe\Stripe::setApiKey($sApiKey);

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

        } catch (\Stripe\Error\ApiConnection $e) {

            //  Network problem, perhaps try again.
            //  @todo log actual exception?
            throw new DriverException(
                'There was a problem connecting to Stripe, you may wish to try again. ',
                $e->getCode()
            );

        } catch (\Stripe\Error\InvalidRequest $e) {

            //  You screwed up in your programming. Shouldn't happen!
            //  @todo log actual exception?
            throw new DriverException($e->getMessage(), $e->getCode());

        } catch (\Stripe\Error\Api $e) {

            //  Stripe's servers are down!
            //  @todo log actual exception?
            throw new DriverException(
                'There was a problem connecting to Stripe, this is a temporary problem. You may wish to try again.',
                $e->getCode()
            );

        } catch (\Stripe\Error\Card $e) {

            //  Card was declined. Work out why.
            $aJsonBody = $e->getJsonBody();
            $aError    = $aJsonBody['error'];

            throw new DriverException(
                'The payment card was declined. ' . $aError['message'],
                $e->getCode()
            );

        } catch (\Exception $e) {

            throw new DriverException(
                $e->getMessage(),
                $e->getCode()
            );
        }

        return $oResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     * @return \Nails\Invoice\Model\RefundResponse
     */
    public function refund()
    {
        $oResponse = Factory::factory('RefundResponse', 'nailsapp/module-invoice');
        return $oResponse;
    }
}
