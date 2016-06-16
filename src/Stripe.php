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
    const CUSTOMER_TABLE = NAILS_DB_PREFIX . 'driver_invoice_stripe_customer';

    // --------------------------------------------------------------------------

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
    ) {

        $oChargeResponse = Factory::factory('ChargeResponse', 'nailsapp/module-invoice');

        try {

            //  Set the API key to use
            $this->setApiKey();

            //  Get any meta data to pass along to Stripe
            $aMetaData = $this->extractMetaData($oInvoice, $oCustomData);

            if (!empty($oInvoice->customer->billing_email)) {
                $sReceiptEmail = $oInvoice->customer->billing_email;
            } else {
                $sReceiptEmail = $oInvoice->customer->email;
            }

            //  Prep the statement descriptor
            $sStatementDescriptor = $this->getSetting('sStatementDescriptor');
            $sStatementDescriptor = str_replace('{{INVOICE_REF}}', $oInvoice->ref, $sStatementDescriptor);

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
                    'receipt_email' => $sReceiptEmail,
                    'metadata'      => $aMetaData,
                    'statement_descriptor' => substr('INVOICE #' . $oInvoice->ref, 0, 22),
                    'expand' => array(
                        'balance_transaction'
                    )
                )
            );

            if ($oStripeResponse->paid) {

                $oChargeResponse->setStatusComplete();
                $oChargeResponse->setTxnId($oStripeResponse->id);
                $oChargeResponse->setFee($oStripeResponse->balance_transaction->fee);

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

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     * @param  string    $sTxnId       The original transaction's ID
     * @param  integer   $iAmount      The amount to refund
     * @param  string    $sCurrency    The currency in which to refund
     * @param  \stdClass $oCustomData  The custom data object
     * @param  string    $sReason      The refund's reason
     * @param  \stdClass $oPayment     The payment object
     * @param  \stdClass $oInvoice     The invoice object
     * @return \Nails\Invoice\Model\RefundResponse
     */
    public function refund(
        $sTxnId,
        $iAmount,
        $sCurrency,
        $oCustomData,
        $sReason,
        $oPayment,
        $oInvoice
    ) {

        $oRefundResponse = Factory::factory('RefundResponse', 'nailsapp/module-invoice');

        try {

            //  Set the API key to use
            $this->setApiKey();

            //  Get any meta data to pass along to Stripe
            $aMetaData       = $this->extractMetaData($oInvoice, $oCustomData);
            $oStripeResponse = \Stripe\Refund::create(
                array(
                    'charge'   => $sTxnId,
                    'amount'   => $iAmount,
                    'metadata' => $aMetaData,
                    'expand' => array(
                        'balance_transaction'
                    )
                )
            );

            $oRefundResponse->setStatusComplete();
            $oRefundResponse->setTxnId($oStripeResponse->id);
            $oRefundResponse->setFee($oStripeResponse->balance_transaction->fee * -1);

        } catch (\Stripe\Error\ApiConnection $e) {

            //  Network problem, perhaps try again.
            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (\Stripe\Error\InvalidRequest $e) {

            //  You screwed up in your programming. Shouldn't happen!
            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (\Stripe\Error\Api $e) {

            //  Stripe's servers are down!
            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (\Exception $e) {

            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'An error occurred while executing the request.'
            );
        }

        return $oRefundResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Set the appropriate API Key to use
     */
    protected function setApiKey()
    {

        if (Environment::is('PRODUCTION')) {

            $sApiKey = $this->getSetting('sKeyLiveSecret');

        } else {

            $sApiKey = $this->getSetting('sKeyTestSecret');
        }

        if (empty($sApiKey)) {
            throw new DriverException('Missing Stripe API Key.', 1);
        }

        \Stripe\Stripe::setApiKey($sApiKey);
    }

    // --------------------------------------------------------------------------

    /**
     * Extract the meta data from the invoice and custom data objects
     * @param  \stdClass $oInvoice    The invoice object
     * @param  \stdClass $oCustomData The custom data object
     * @return array
     */
    protected function extractMetaData($oInvoice, $oCustomData)
    {
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

        return $aCleanMetaData;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the stripe reference for a customer if one exists
     * @param $iCustomerId integer The customer ID to retrieve for
     * @return integer|null
     */
    protected function getStripeCustomerId($iCustomerId)
    {
        //  Check to see if we already know the customer's Stripe reference
        $oDb = Factory::service('Database');
        $oDb->where('customer_id', $iCustomerId);
        $oCustomer = $oDb->get(self::CUSTOMER_TABLE)->row();

        return !empty($oCustomer->stripe_id) ? $oCustomer->stripe_id : null;
    }

    // --------------------------------------------------------------------------

    /**
     * Adds a payment source to a customer
     * @param $iCustomerId integer The customer ID to associate the payment source with
     * @param $aSourceData array   The payment source data to pass to Stripe
     */
    public function addPaymentSource($iCustomerId, $aSourceData)
    {
        //  Check to see if we already know the customer's Stripe reference
        $sStripeCustomerId = $this->getStripeCustomerId($iCustomerId);
        if (empty($sStripeCustomerId)) {
            //  @todo - create a new Stripe customer
        }

        //  @todo - Save the payment source against the customer
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of payment sources from Stripe
     * @param $iCustomerId integer The customer ID to retrieve for
     * @return array
     */
    public function getPaymentSources($iCustomerId)
    {
        $sStripeCustomerId = $this->getStripeCustomerId($iCustomerId);
        if (empty($sStripeCustomerId)) {
            return array();
        }

        //  Query Stripe
        //  @todo - query Stripe for the customer's payment sources
        return array();
    }
}
