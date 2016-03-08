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
use Nails\Environment;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\DriverException;

class Stripe extends PaymentBase
{
    /**
     * Returns whether the driver is available to be used against the selected iinvoice
     * @return boolean
     */
    public function isAvailable()
    {
        return true;
    }

    // --------------------------------------------------------------------------

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
     * @param  \stdClass $oData        The driver data object
     * @param  \stdClass $oCustomData  The custom data object
     * @param  string    $sDescription The charge description
     * @param  \stdClass $oPayment     The payment object
     * @param  \stdClass $oInvoice     The invoice object
     * @param  string    $sSuccessUrl  The URL to go to after successfull payment
     * @param  string    $sFailUrl     The URL to go to after failed payment
     * @param  string    $sContinueUrl The URL to go to after payment is completed
     * @return \Nails\Invoice\Model\ChargeResponse
     */
    public function charge(
        $iAmount,
        $sCurrency,
        $oData,
        $oCustomData,
        $sDescription,
        $oPayment,
        $oInvoice,
        $sSuccessUrl,
        $sFailUrl,
        $sContinueUrl
    )
    {
        $oChargeResponse = Factory::factory('ChargeResponse', 'nailsapp/module-invoice');

        try {

            if (Environment::is('PRODUCTION')) {

                $sApiKey = $this->getSetting('sKeyLiveSecret');

            } else {

                $sApiKey = $this->getSetting('sKeyTestSecret');
            }

            if (empty($sApiKey)) {
                throw new DriverException('Missing Stripe API Key.', 1);
            }

            \Stripe\Stripe::setApiKey($sApiKey);


            //  Store any custom meta data; Stripe allows up to 20 key value pairs with key
            //  names up to 40 characters and values up to 500 characters.

            //  In practice only 18 custom key can be defined
            $aMetaData = array(
                'invoiceId'  => $oInvoice->id,
                'invoiceRef' => $oInvoice->ref
            );

            if (!empty($oCustomData->metadata)) {
                $aMetaData = array_merge($aMetaData, (array) $oCustomData->metadata);
            }

            $aCleanMetaData = array();
            $iCounter       = 0;

            foreach ($aMetaData as $sKey => $mValue) {

                if ($iCounter === 20) {
                    break;
                }

                $aCleanMetaData[substr($sKey, 0, 40)] = substr((string) $mValue, 0, 500);
                $iCounter++;
            }

            $oStripeResponse = \Stripe\Charge::create(
                array(
                    'amount'      => $iAmount,
                    'currency'    => $sCurrency,
                    'description' => $sDescription,
                    'source'      => array(
                        'object'    => 'card',
                        'name'      => $oData->name,
                        'number'    => $oData->number,
                        'exp_month' => $oData->exp->month,
                        'exp_year'  => $oData->exp->year,
                        'cvc'       => $oData->cvc
                    ),
                    'receipt_email' => !empty($oInvoice->customer->billing_email) ? $oInvoice->customer->billing_email : $oInvoice->customer->email,
                    'metadata'      => $aCleanMetaData,
                    'statement_descriptor' => substr('INVOICE #' . $oInvoice->ref, 0, 22),
                )
            );

            if ($oStripeResponse->paid) {

                $oChargeResponse->setStatusComplete();
                $oChargeResponse->setTxnId($oStripeResponse->id);

            } else {

                //  @todo: handle errors returned by the Stripe Client/API
                $oChargeResponse->setStatusFailed(
                    null,
                    0,
                    'The gateway rejected the request, you may wish to try again.'
                );
            }

        } catch (\Stripe\Error\ApiConnection $e) {

            //  Network problem, perhaps try again.
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (\Stripe\Error\InvalidRequest $e) {

            //  You screwed up in your programming. Shouldn't happen!
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (\Stripe\Error\Api $e) {

            //  Stripe's servers are down!
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (\Stripe\Error\Card $e) {

            //  Card was declined. Work out why.
            $aJsonBody = $e->getJsonBody();
            $aError    = $aJsonBody['error'];

            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The payment card was declined. ' . $aError['message']
            );

        } catch (\Exception $e) {

            $oChargeResponse->setStatusFailed(
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
     * @return \Nails\Invoice\Model\CompleteResponse
     */
    public function complete()
    {
        $oCompleteResponse = Factory::factory('CompleteResponse', 'nailsapp/module-invoice');
        $oCompleteResponse->setStatusComplete();
        return $oCompleteResponse;
    }
}
