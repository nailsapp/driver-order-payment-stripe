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

namespace Nails\Invoice\Driver\Payment;

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
     * Returns any data which should be POSTED to the endpoint as part of a redirect
     * flow; if empty a header redirect is used instead.
     * @return array
     */
    public function getRedirectPostData()
    {
        return array();
    }

    // --------------------------------------------------------------------------


    /**
     * Returns the payment fields the driver requires, use CARD for basic credit
     * card details.
     * @return mixed
     */
    public function getPaymentFields()
    {
        return 'CARD';
    }

    // --------------------------------------------------------------------------

    /**
     * Initiate a payment
     * @param  integer   $iAmount      The payment amount
     * @param  string    $sCurrency    The payment currency
     * @param  array     $aData        An array of driver data
     * @param  string    $sDescription The charge description
     * @param  \stdClass $oPayment     The payment object
     * @param  \stdClass $oInvoice     The invoice object
     * @param  string    $sSuccessUrl  The URL to go to after successfull payment
     * @param  string    $sFailUrl     The URL to go to after failed payment
     * @return \Nails\Invoice\Model\ChargeResponse
     */
    public function charge(
        $iAmount,
        $sCurrency,
        $aData,
        $sDescription,
        $oPayment,
        $oInvoice,
        $sSuccessUrl,
        $sFailUrl
    )
    {
        $oChargeResponse = Factory::factory('ChargeResponse', 'nailsapp/module-invoice');

        try {

            if (ENVIRONMENT === 'PRODUCTION') {

                $sApiKey = $this->getSetting('sKeyLiveSecret');

            } else {

                $sApiKey = $this->getSetting('sKeyTestSecret');
            }

            if (empty($sApiKey)) {
                throw new DriverException('Missing Stripe API Key.', 1);
            }

            \Stripe\Stripe::setApiKey($sApiKey);

            $oStripeResponse = \Stripe\Charge::create(
                array(
                    'amount'      => $iAmount,
                    'currency'    => $sCurrency,
                    'description' => $sDescription,
                    'source'      => array(
                        'object'    => 'card',
                        'name'      => $aData->name,
                        'number'    => $aData->number,
                        'exp_month' => $aData->exp->month,
                        'exp_year'  => $aData->exp->year,
                        'cvc'       => $aData->cvc
                    ),
                    'receipt_email' => !empty($oInvoice->user->id) ? $oInvoice->user->email : $oInvoice->user_email,
                    'metadata' => array(
                        'invoiceId'  => $oInvoice->id,
                        'invoiceRef' => $oInvoice->ref
                    ),
                    'statement_descriptor' => substr('INVOICE #' . $oInvoice->ref, 0, 22),
                )
            );

            if ($oStripeResponse->status === 'paid') {

                $oChargeResponse->setStatusOk();
                $oChargeResponse->setTxnId($oStripeResponse->id);

            } else {

                //  @todo: handle errors returned by the Stripe Client/API
                $oChargeResponse->setStatusFail(
                    null,
                    0,
                    'The gateway rejected the request, you may wish to try again.'
                );
            }

        } catch (\Stripe\Error\ApiConnection $e) {

            //  Network problem, perhaps try again.
            $oChargeResponse->setStatusFail(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (\Stripe\Error\InvalidRequest $e) {

            //  You screwed up in your programming. Shouldn't happen!
            $oChargeResponse->setStatusFail(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (\Stripe\Error\Api $e) {

            //  Stripe's servers are down!
            $oChargeResponse->setStatusFail(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (\Stripe\Error\Card $e) {

            //  Card was declined. Work out why.
            $aJsonBody = $e->getJsonBody();
            $aError    = $aJsonBody['error'];

            $oChargeResponse->setStatusFail(
                $e->getMessage(),
                $e->getCode(),
                'The payment card was declined. ' . $aError['message']
            );

        } catch (\Exception $e) {

            $oChargeResponse->setStatusFail(
                $e->getMessage(),
                $e->getCode(),
                'An error occurred while executing the request.'
            );
        }

        return $oChargeResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the payment
     * @param  array $aGetVars  Any $_GET variables passed from the redirect flow
     * @param  array $aPostVars Any $_POST variables passed from the redirect flow
     * @return \Nails\Invoice\Model\CompleteResponse
     */
    public function complete($aGetVars, $aPostVars)
    {
        $oCompleteResponse = Factory::factory('CompleteResponse', 'nailsapp/module-invoice');
        $oCompleteResponse->setStatusOk();
        return $oCompleteResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     * @return \Nails\Invoice\Model\RefundResponse
     */
    public function refund()
    {
        dumpanddie('Refund');
        $oChargeResponse = Factory::factory('RefundResponse', 'nailsapp/module-invoice');
        return $oChargeResponse;
    }
}
