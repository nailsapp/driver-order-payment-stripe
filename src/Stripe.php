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

use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Resource;
use Nails\Environment;
use Nails\Factory;
use Nails\Invoice\Constants;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\DriverException;
use Nails\Invoice\Factory\ChargeResponse;
use Nails\Invoice\Factory\CompleteResponse;
use Nails\Invoice\Factory\RefundResponse;
use Nails\Invoice\Factory\ScaResponse;
use Nails\Invoice\Model\Source;
use stdClass;
use Stripe\Account;
use Stripe\BalanceTransaction;
use Stripe\CountrySpec;
use Stripe\Customer;
use Stripe\Error\Api;
use Stripe\Error\ApiConnection;
use Stripe\Error\Card;
use Stripe\Error\InvalidRequest;
use Stripe\PaymentIntent;
use Stripe\Refund;

/**
 * Class Stripe
 *
 * @package Nails\Invoice\Driver\Payment
 */
class Stripe extends PaymentBase
{
    const PAYMENT_INTENT_STATUS_REQUIRES_CONFIRMATION   = 'requires_confirmation';
    const PAYMENT_INTENT_STATUS_REQUIRES_ACTION         = 'requires_action';
    const PAYMENT_INTENT_STATUS_REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
    const PAYMENT_INTENT_STATUS_SUCCEEDED               = 'succeeded';

    //  (Pablo - 2019-07-24) - Support for legacy Stirpe APIs
    const PAYMENT_INTENT_STATUS_REQUIRES_SOURCE        = 'requires_source';
    const PAYMENT_INTENT_STATUS_REQUIRES_SOURCE_ACTION = 'requires_source_action';

    // --------------------------------------------------------------------------

    /**
     * Whether the driver has attemped to fetch supported currencies
     *
     * @var bool
     */
    private static $bFetchedSupportedCurrencies = false;

    /**
     * The supported currencies for this configuration
     *
     * @var stirng[]|null
     */
    private static $aSupportedCurrencies = null;

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver is available to be used against the selected invoice
     *
     * @param stdClass $oInvoice The invoice being charged
     *
     * @return bool
     */
    public function isAvailable($oInvoice): bool
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the currencies which this driver supports, it will only be presented
     * when attempting to pay an invoice in a supported currency
     *
     * @return string[]|null
     */
    public function getSupportedCurrencies(): ?array
    {
        if (!self::$bFetchedSupportedCurrencies) {

            //  So we don't try again
            self::$bFetchedSupportedCurrencies = true;

            try {

                $this->setApiKey();
                $oAccount = Account::retrieve($this->getApiKey());
                $oCountry = CountrySpec::retrieve($oAccount->country);

                self::$aSupportedCurrencies = array_map('strtoupper', $oCountry->supported_payment_currencies);

            } catch (\Exception $e) {
                //  @todo (Pablo - 2019-08-01) - shout about this?
            }
        }

        return self::$aSupportedCurrencies;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver uses a redirect payment flow or not.
     *
     * @return bool
     */
    public function isRedirect(): bool
    {
        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the payment fields the driver requires, use static::PAYMENT_FIELDS_CARD for basic credit
     * card details.
     *
     * @return mixed
     */
    public function getPaymentFields()
    {
        return [
            [
                'key'      => 'token',
                'label'    => 'Card Details',
                'required' => true,
                'id'       => 'stripe-elements-' . md5($this->getSlug()),
            ],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Returns any assets to load during checkout
     *
     * @return array
     */
    public function getCheckoutAssets(): array
    {
        return [
            [
                'https://js.stripe.com/v3',
                null,
                'JS',
            ],
            [
                'checkout.min.js?' . implode('&', [
                    'hash=' . urlencode(md5($this->getSlug())) . '',
                    'key=' . urlencode($this->getApiKey('public')) . '',
                ]),
                $this->getSlug(),
                'JS',
            ],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Initiate a payment
     *
     * @param int      $iAmount      The payment amount
     * @param string   $sCurrency    The payment currency
     * @param stdClass $oData        The driver data object
     * @param stdClass $oCustomData  The custom data object
     * @param string   $sDescription The charge description
     * @param stdClass $oPayment     The payment object
     * @param stdClass $oInvoice     The invoice object
     * @param string   $sSuccessUrl  The URL to go to after successful payment
     * @param string   $sErrorUrl    The URL to go to after failed payment
     *
     * @return ChargeResponse
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
        $sErrorUrl
    ): ChargeResponse {

        /** @var ChargeResponse $oChargeResponse */
        $oChargeResponse = Factory::factory('ChargeResponse', Constants::MODULE_SLUG);

        try {

            //  Set the API key to use
            $this->setApiKey();

            //  Generate the request data
            $aRequestData = $this->getRequestData(
                $iAmount,
                $sCurrency,
                $oData,
                $oCustomData,
                $sDescription,
                $oInvoice
            );

            //  Create the intent
            $oPaymentIntent = PaymentIntent::create($aRequestData);

            //  (Pablo - 2019-07-24) - Support for legacy Stirpe APIs
            $bRequiresAction = in_array($oPaymentIntent->status, [
                self::PAYMENT_INTENT_STATUS_REQUIRES_ACTION,
                self::PAYMENT_INTENT_STATUS_REQUIRES_SOURCE_ACTION,
            ]);

            if ($bRequiresAction && $oPaymentIntent->next_action->type == 'use_stripe_sdk') {

                $oChargeResponse->setIsSca([
                    'id' => $oPaymentIntent->id,
                ]);

                return $oChargeResponse;

            } elseif ($oPaymentIntent->status !== self::PAYMENT_INTENT_STATUS_SUCCEEDED) {
                throw new DriverException('Invalid PaymentIntent status', 500);
            }

            $oCharge             = reset($oPaymentIntent->charges->data);
            $oBalanceTransaction = BalanceTransaction::retrieve($oCharge->balance_transaction);

            $oChargeResponse->setStatusComplete();
            $oChargeResponse->setTxnId($oCharge->id);
            $oChargeResponse->setFee($oBalanceTransaction->fee);

        } catch (ApiConnection $e) {

            //  Network problem, perhaps try again.
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (InvalidRequest $e) {

            //  You screwed up in your programming. Shouldn't happen!
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (Api $e) {

            //  Stripe's servers are down!
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (Card $e) {

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
     * Returns an arrya of request data for a PaymentIntent request
     *
     * @param int      $iAmount      The payment amount
     * @param string   $sCurrency    The payment currency
     * @param stdClass $oData        The driver data object
     * @param stdClass $oCustomData  The custom data object
     * @param string   $sDescription The charge description
     * @param stdClass $oInvoice     The invoice object
     *
     * @return array
     * @throws DriverException
     * @throws FactoryException
     * @throws ModelException
     */
    protected function getRequestData(
        $iAmount,
        $sCurrency,
        $oData,
        $oCustomData,
        $sDescription,
        $oInvoice
    ): array {

        //  Get any meta data to pass along to Stripe
        $aMetaData = $this->extractMetaData($oInvoice, $oCustomData);

        //  Prep the statement descriptor
        $sStatementDescriptor = $this->getSetting('sStatementDescriptor');
        $sStatementDescriptor = str_replace('{{INVOICE_REF}}', $oInvoice->ref, $sStatementDescriptor);

        $aRequestData = [
            'amount'               => $iAmount,
            'currency'             => $sCurrency,
            'confirmation_method'  => 'manual',
            'confirm'              => true,
            'description'          => $sDescription,
            'metadata'             => $aMetaData,
            'statement_descriptor' => substr($sStatementDescriptor, 0, 22),
        ];

        if ($this->getSetting('bEnableStripeReceiptEmail')) {
            if (!empty($oInvoice->customer->billing_email)) {
                $aRequestData['receipt_email'] = $oInvoice->customer->billing_email;
            } else {
                $aRequestData['receipt_email'] = $oInvoice->customer->email;
            }
        }

        if (property_exists($oCustomData, 'source_id')) {

            /**
             * The customer is checking out using a saved payment source
             */
            /** @var Source $oSourceModel */
            $oSourceModel = Factory::model('Source', Constants::MODULE_SLUG);
            $oSource      = $oSourceModel->getById($oCustomData->source_id);
            if (empty($oSource)) {
                throw new DriverException('Invalid source ID supplied.');
            }

            $aSourceData = json_decode($oSource->data, JSON_OBJECT_AS_ARRAY) ?? [];
            $sSourceId   = getFromArray('source_id', $aSourceData);
            $sCustomerId = getFromArray('customer_id', $aSourceData);

            if (empty($sSourceId)) {
                throw new DriverException('Could not acertain the "source_id" from the Source object.');
            } elseif (empty($sCustomerId)) {
                throw new DriverException('Could not acertain the "customer_id" from the Source object.');
            }

            $aRequestData['payment_method'] = $sSourceId;
            $aRequestData['customer']       = $sCustomerId;

        } elseif (property_exists($oCustomData, 'token')) {

            /**
             * The customer is checking out using a Stripe token
             */
            $aRequestData['payment_method_data'] = [
                'type' => 'card',
                'card' => [
                    'token' => $oCustomData->token,
                ],
            ];

        } elseif (property_exists($oCustomData, 'stripe_source_id') && property_exists($oCustomData, 'stripe_customer_id')) {

            /**
             * Dev has passed explicit stripe source and customer IDs
             */
            $aRequestData['payment_method'] = $oCustomData->stripe_source_id;
            $aRequestData['customer']       = $oCustomData->stripe_customer_id;

        } else {
            throw new DriverException('Must provide `token` or `source_id`.');
        }

        return $aRequestData;
    }

    // --------------------------------------------------------------------------

    /**
     * Handles any SCA requests
     *
     * @param ScaResponse $oScaResponse The SCA Response object
     * @param array       $aData        Any saved SCA data
     * @param string      $sSuccessUrl  The URL to redirect to after authorisation
     *
     * @return ScaResponse
     * @throws DriverException
     */
    public function sca(ScaResponse $oScaResponse, array $aData, string $sSuccessUrl): ScaResponse
    {
        $iPaymentIntentId = getFromArray('id', $aData);
        if (empty($iPaymentIntentId)) {
            throw new DriverException('Missing Payment Intent ID');
        }

        $this->setApiKey();
        $oPaymentIntent = PaymentIntent::retrieve($iPaymentIntentId);
        if (empty($oPaymentIntent)) {
            throw new DriverException('Invalid Payment Intent ID');
        }

        // --------------------------------------------------------------------------

        //  If the SCA request has already succeeded then bail early
        if ($oPaymentIntent->status === self::PAYMENT_INTENT_STATUS_SUCCEEDED) {
            return $this->scaComplete($oScaResponse, $oPaymentIntent);
        }

        // --------------------------------------------------------------------------

        switch ($oPaymentIntent->status) {

            /**
             * These statuses indicate that the SCA has not been processed and that
             * the client must perform the 3rd party authorisation. We'll redirect
             * to Stripe to let that happen.
             */
            case self::PAYMENT_INTENT_STATUS_REQUIRES_ACTION:
            case self::PAYMENT_INTENT_STATUS_REQUIRES_SOURCE_ACTION:

                $oPaymentIntent = $oPaymentIntent->confirm([
                    'return_url' => $sSuccessUrl,
                ]);

                if ($oPaymentIntent->status === self::PAYMENT_INTENT_STATUS_REQUIRES_ACTION) {
                    $sUrl = $oPaymentIntent->next_action->redirect_to_url->url;
                } else {
                    $sUrl = $oPaymentIntent->next_source_action->authorize_with_url->url;
                }

                $oScaResponse
                    ->setIsRedirect(true)
                    ->setRedirectUrl($sUrl);

                break;

            /**
             * This status indicates that the SCA has been authorised but needs confirmed again.
             */
            case self::PAYMENT_INTENT_STATUS_REQUIRES_CONFIRMATION:

                try {

                    $oPaymentIntent = $oPaymentIntent->confirm([
                        'return_url' => $sSuccessUrl,
                    ]);
                    $this->scaComplete($oScaResponse, $oPaymentIntent);

                } catch (\Exception $e) {
                    $oScaResponse
                        ->setStatusFailed(
                            implode(' ', [
                                'Failed to authorise the payment.',
                                'Exception: ' . $e->getMessage(),
                                'Payment Intent ID: ' . $oPaymentIntent->id,
                                'Payment Intent status: ' . $oPaymentIntent->status,
                            ]),
                            $e->getCode() ?? '',
                            'Failed to authorise the payment.'
                        );
                }
                break;

            /**
             * Any other status is considered a failure.
             */
            default:
                $oScaResponse
                    ->setStatusFailed(
                        implode(' ', [
                            'Failed to authorise the payment.',
                            'Payment Intent ID: ' . $oPaymentIntent->id,
                            'Payment Intent status: ' . $oPaymentIntent->status,
                        ]),
                        '',
                        'Failed to authorise the payment.'
                    );
                break;
        }

        return $oScaResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Performs actions required once the SCA flow is complete
     *
     * @param ScaResponse   $oScaResponse   The SCA Response object
     * @param PaymentIntent $oPaymentIntent The Payment Intent object
     *
     * @return ScaResponse
     * @throws DriverException
     */
    protected function scaComplete(ScaResponse $oScaResponse, PaymentIntent $oPaymentIntent): ScaResponse
    {
        $oCharge = reset($oPaymentIntent->charges->data);
        if (empty($oCharge)) {
            throw new DriverException('No charges detected. Payment was not processed.');
        }

        //  Get the balance transaction
        $oBalanceTransaction = BalanceTransaction::retrieve($oCharge->balance_transaction);

        $oScaResponse
            ->setIsComplete(true)
            ->setTxnId($oCharge->id)
            ->setFee($oBalanceTransaction->fee);

        return $oScaResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the payment
     *
     * @param stdClass $oPayment  The Payment object
     * @param stdClass $oInvoice  The Invoice object
     * @param array    $aGetVars  Any $_GET variables passed from the redirect flow
     * @param array    $aPostVars Any $_POST variables passed from the redirect flow
     *
     * @return CompleteResponse
     */
    public function complete($oPayment, $oInvoice, $aGetVars, $aPostVars): CompleteResponse
    {
        /** @var CompleteResponse $oCompleteResponse */
        $oCompleteResponse = Factory::factory('CompleteResponse', Constants::MODULE_SLUG);
        $oCompleteResponse->setStatusComplete();
        return $oCompleteResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     *
     * @param string   $sTxnId      The original transaction's ID
     * @param int      $iAmount     The amount to refund
     * @param string   $sCurrency   The currency in which to refund
     * @param stdClass $oCustomData The custom data object
     * @param string   $sReason     The refund's reason
     * @param stdClass $oPayment    The payment object
     * @param stdClass $oInvoice    The invoice object
     *
     * @return RefundResponse
     */
    public function refund($sTxnId, $iAmount, $sCurrency, $oCustomData, $sReason, $oPayment, $oInvoice): RefundResponse
    {
        /** @var RefundResponse $oRefundResponse */
        $oRefundResponse = Factory::factory('RefundResponse', Constants::MODULE_SLUG);

        try {

            //  Set the API key to use
            $this->setApiKey();

            //  Get any meta data to pass along to Stripe
            $aMetaData       = $this->extractMetaData($oInvoice, $oCustomData);
            $oStripeResponse = Refund::create(
                [
                    'charge'   => $sTxnId,
                    'amount'   => $iAmount,
                    'metadata' => $aMetaData,
                    'expand'   => [
                        'balance_transaction',
                    ],
                ]
            );

            $oRefundResponse->setStatusComplete();
            $oRefundResponse->setTxnId($oStripeResponse->id);
            $oRefundResponse->setFee($oStripeResponse->balance_transaction->fee * -1);

        } catch (ApiConnection $e) {

            //  Network problem, perhaps try again.
            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (InvalidRequest $e) {

            //  You screwed up in your programming. Shouldn't happen!
            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (Api $e) {

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
    protected function setApiKey(): void
    {
        \Stripe\Stripe::setApiKey($this->getApiKey());
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the correct API key for the environment
     *
     * @return string
     */
    protected function getApiKey(string $sType = 'secret'): string
    {
        if (Environment::is(Environment::ENV_PROD)) {
            $sApiKey = $this->getSetting('sKeyLive' . ucfirst(strtolower($sType)));
        } else {
            $sApiKey = $this->getSetting('sKeyTest' . ucfirst(strtolower($sType)));
        }

        if (empty($sApiKey)) {
            throw new DriverException('Missing Stripe API Key.', 1);
        }

        return $sApiKey;
    }

    // --------------------------------------------------------------------------

    /**
     * Extract the meta data from the invoice and custom data objects
     *
     * @param stdClass $oInvoice    The invoice object
     * @param stdClass $oCustomData The custom data object
     *
     * @return array
     */
    protected function extractMetaData($oInvoice, $oCustomData): array
    {
        //  Store any custom meta data; Stripe allows up to 20 key value pairs with key
        //  names up to 40 characters and values up to 500 characters.

        //  In practice only 18 custom key can be defined
        $aMetaData = [
            'invoiceId'  => $oInvoice->id,
            'invoiceRef' => $oInvoice->ref,
        ];

        if (!empty($oCustomData->metadata)) {
            $aMetaData = array_merge($aMetaData, (array) $oCustomData->metadata);
        }

        $aCleanMetaData = [];
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
     * Creates a new payment source, returns a semi-populated source resource
     *
     * @param \Nails\Invoice\Resource\Source $oResource The Resouce object to update
     * @param array                          $aData     Data passed from the caller
     *
     * @throws DriverException
     */
    public function createSource(
        \Nails\Invoice\Resource\Source &$oResource,
        array $aData
    ): void {

        $sSourceId   = getFromArray('stripe_source_id', $aData);
        $sCustomerId = getFromArray('stripe_customer_id', $aData);

        if (empty($sSourceId)) {
            throw new DriverException('"stripe_source_id" must be supplied when creating a Stripe payment source.');
        }

        $this->setApiKey();

        if (empty($sCustomerId)) {
            $oStripeCustomer = \Stripe\Customer::create();
        } else {
            $oStripeCustomer = \Stripe\Customer::retrieve($sCustomerId);
        }

        $oStripeSource = $oStripeCustomer->sources->create(['source' => $sSourceId]);
        $oExpiry       = new \DateTime(implode('-', [
            $oStripeSource->card->exp_year,
            $oStripeSource->card->exp_month,
            '01',
        ]));

        $oResource->brand     = $oStripeSource->card->brand;
        $oResource->last_four = $oStripeSource->card->last4;
        $oResource->expiry    = $oExpiry->format('Y-m-t H:i:s');
        $oResource->data      = json_encode([
            'source_id'   => $oStripeSource->id,
            'customer_id' => $oStripeCustomer->id,
        ]);
    }
}
