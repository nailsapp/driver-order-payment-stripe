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

use Exception;
use Nails\Common\Exception\FactoryException;
use Nails\Currency\Resource\Currency;
use Nails\Environment;
use Nails\Factory;
use Nails\Invoice\Constants;
use Nails\Invoice\Driver\PaymentBase;
use Nails\Invoice\Exception\DriverException;
use Nails\Invoice\Exception\ResponseException;
use Nails\Invoice\Factory\ChargeResponse;
use Nails\Invoice\Factory\CompleteResponse;
use Nails\Invoice\Factory\RefundResponse;
use Nails\Invoice\Factory\ScaResponse;
use Nails\Invoice\Resource;
use Stripe\Account;
use Stripe\BalanceTransaction;
use Stripe\CountrySpec;
use Stripe\Customer;
use Stripe\Error\Api;
use Stripe\Error\ApiConnection;
use Stripe\Error\Card;
use Stripe\Error\InvalidRequest;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use stdClass;

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
     * Whether the driver has attempted to fetch supported currencies
     *
     * @var bool
     */
    private static $bFetchedSupportedCurrencies = false;

    /**
     * The supported currencies for this configuration
     *
     * @var string[]|null
     */
    private static $aSupportedCurrencies = null;

    // --------------------------------------------------------------------------

    /**
     * Returns whether the driver is available to be used against the selected invoice
     *
     * @param Resource\Invoice $oInvoice The invoice being charged
     *
     * @return bool
     */
    public function isAvailable(Resource\Invoice $oInvoice): bool
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

            } catch (Exception $e) {
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
     * @throws DriverException
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
     * @param int                           $iAmount          The payment amount
     * @param Currency                      $oCurrency        The payment currency
     * @param stdClass                      $oData            An array of driver data
     * @param Resource\Invoice\Data\Payment $oPaymentData     The payment data object
     * @param string                        $sDescription     The charge description
     * @param Resource\Payment              $oPayment         The payment object
     * @param Resource\Invoice              $oInvoice         The invoice object
     * @param string                        $sSuccessUrl      The URL to go to after successful payment
     * @param string                        $sErrorUrl        The URL to go to after failed payment
     * @param bool                          $bCustomerPresent Whether the customer is present
     * @param Resource\Source|null          $oSource          The saved payment source to use
     *
     * @return ChargeResponse
     * @throws FactoryException
     * @throws ResponseException
     */
    public function charge(
        int $iAmount,
        Currency $oCurrency,
        stdClass $oData,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sDescription,
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        string $sSuccessUrl,
        string $sErrorUrl,
        bool $bCustomerPresent,
        Resource\Source $oSource = null
    ): ChargeResponse {

        /** @var ChargeResponse $oChargeResponse */
        $oChargeResponse = Factory::factory('ChargeResponse', Constants::MODULE_SLUG);

        try {

            //  Set the API key to use
            $this->setApiKey();

            //  Generate the request data
            $aRequestData = $this->getRequestData(
                $iAmount,
                $oCurrency,
                $oData,
                $oPaymentData,
                $sDescription,
                $oInvoice,
                $bCustomerPresent,
                $oSource
            );

            //  Create the intent
            $oPaymentIntent = PaymentIntent::create($aRequestData);

            //  (Pablo - 2019-07-24) - Support for legacy Stripe APIs
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

            $oChargeResponse
                ->setStatusComplete()
                ->setTransactionId($oCharge->id)
                ->setFee($oBalanceTransaction->fee);

        } catch (ApiConnectionException $e) {

            //  Network problem, perhaps try again.
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (InvalidRequestException $e) {

            //  You screwed up in your programming. Shouldn't happen!
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (ApiErrorException $e) {

            //  Stripe's servers are down!
            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (CardException $e) {

            //  Card was declined. Work out why.
            $aJsonBody = $e->getJsonBody();
            $aError    = $aJsonBody['error'];

            $oChargeResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The payment card was declined. ' . $aError['message']
            );

        } catch (Exception $e) {

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
     * Returns an array of request data for a PaymentIntent request
     *
     * @param int                           $iAmount          The payment amount
     * @param Currency                      $oCurrency        The payment currency
     * @param stdClass                      $oData            The driver data object
     * @param Resource\Invoice\Data\Payment $oPaymentData     The payment data object
     * @param string                        $sDescription     The charge description
     * @param Resource\Invoice              $oInvoice         The invoice object
     * @param bool                          $bCustomerPresent Whether the customer is present
     * @param Resource\Source|null          $oSource          The supplied payment source to charge
     *
     * @return array
     * @throws DriverException
     */
    protected function getRequestData(
        int $iAmount,
        Currency $oCurrency,
        stdClass $oData,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sDescription,
        Resource\Invoice $oInvoice,
        bool $bCustomerPresent,
        Resource\Source $oSource = null
    ): array {

        //  Get any meta data to pass along to Stripe
        $aMetaData = $this->extractMetaData($oInvoice, $oPaymentData);

        //  Prep the statement descriptor
        $sStatementDescriptor = $this->getSetting('sStatementDescriptor');
        $sStatementDescriptor = str_replace('{{INVOICE_REF}}', $oInvoice->ref, $sStatementDescriptor);

        $aRequestData = [
            'amount'               => $iAmount,
            'currency'             => $oCurrency->code,
            'confirmation_method'  => 'manual',
            'confirm'              => true,
            'description'          => $sDescription,
            'metadata'             => $aMetaData,
            'off_session'          => empty($bCustomerPresent),
            'statement_descriptor' => substr($sStatementDescriptor, 0, 22),
        ];

        if ($this->getSetting('bEnableStripeReceiptEmail')) {
            if (!empty($oInvoice->customer->billing_email)) {
                $aRequestData['receipt_email'] = $oInvoice->customer->billing_email;
            } else {
                $aRequestData['receipt_email'] = $oInvoice->customer->email;
            }
        }

        if (null !== $oSource) {

            /**
             * The customer is checking out using a saved payment source
             */
            $sSourceId   = getFromArray('source_id', (array) $oSource->data);
            $sCustomerId = getFromArray('customer_id', (array) $oSource->data);

            if (empty($sSourceId)) {
                throw new DriverException('Could not ascertain the "source_id" from the Source object.');
            } elseif (empty($sCustomerId)) {
                throw new DriverException('Could not ascertain the "customer_id" from the Source object.');
            }

            $aRequestData['payment_method'] = $sSourceId;
            $aRequestData['customer']       = $sCustomerId;

        } elseif (property_exists($oPaymentData, 'token')) {

            /**
             * The customer is checking out using a Stripe token
             */
            $aRequestData['payment_method_data'] = [
                'type' => 'card',
                'card' => [
                    'token' => $oPaymentData->token,
                ],
            ];

        } elseif (property_exists($oPaymentData, 'stripe_source_id') && property_exists($oPaymentData, 'stripe_customer_id')) {

            /**
             * Dev has passed explicit stripe source and customer IDs
             */
            $aRequestData['payment_method'] = $oPaymentData->stripe_source_id;
            $aRequestData['customer']       = $oPaymentData->stripe_customer_id;

        } else {
            throw new DriverException(
                'Must provide a payment source, `token` or `stripe_source_id` and `stripe_customer_id`.'
            );
        }

        return $aRequestData;
    }

    // --------------------------------------------------------------------------

    /**
     * Handles any SCA requests
     *
     * @param ScaResponse               $oScaResponse The SCA Response object
     * @param Resource\Payment\Data\Sca $oData        Any saved SCA data
     * @param string                    $sSuccessUrl  The URL to redirect to after authorisation
     *
     * @return ScaResponse
     * @throws DriverException
     * @throws ApiErrorException
     * @throws ResponseException
     */
    public function sca(
        ScaResponse $oScaResponse,
        Resource\Payment\Data\Sca $oData,
        string $sSuccessUrl
    ): ScaResponse {

        $iPaymentIntentId = $oData->id ?? null;
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

                if ($oPaymentIntent->status === Self::PAYMENT_INTENT_STATUS_SUCCEEDED) {
                    return $this->scaComplete($oScaResponse, $oPaymentIntent);

                } elseif ($oPaymentIntent->status === self::PAYMENT_INTENT_STATUS_REQUIRES_ACTION) {
                    $sUrl = $oPaymentIntent->next_action->redirect_to_url->url ?? null;

                } else {
                    $sUrl = $oPaymentIntent->next_source_action->authorize_with_url->url ?? null;
                }

                if (empty($sUrl)) {
                    $oScaResponse
                        ->setStatusFailed(
                            implode(' ', [
                                'Failed to ascertain redirect URL.',
                                '`next_action``: ' . json_encode($oPaymentIntent->next_action),
                                '`next_source_action``: ' . json_encode($oPaymentIntent->next_source_action),
                            ]),
                            '',
                            'Failed to authorise the payment.'
                        );

                } else {
                    $oScaResponse
                        ->setIsRedirect(true)
                        ->setRedirectUrl($sUrl);
                }

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

                } catch (Exception $e) {
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
                } catch (ResponseException $e) {
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
     * @throws ApiErrorException
     */
    protected function scaComplete(
        ScaResponse $oScaResponse, PaymentIntent $oPaymentIntent
    ): ScaResponse {
        $oCharge = reset($oPaymentIntent->charges->data);
        if (empty($oCharge)) {
            throw new DriverException('No charges detected. Payment was not processed.');
        }

        //  Get the balance transaction
        $oBalanceTransaction = BalanceTransaction::retrieve($oCharge->balance_transaction);

        $oScaResponse
            ->setStatusComplete()
            ->setTransactionId($oCharge->id)
            ->setFee($oBalanceTransaction->fee);

        return $oScaResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Complete the payment
     *
     * @param Resource\Payment $oPayment  The Payment object
     * @param Resource\Invoice $oInvoice  The Invoice object
     * @param array            $aGetVars  Any $_GET variables passed from the redirect flow
     * @param array            $aPostVars Any $_POST variables passed from the redirect flow
     *
     * @return CompleteResponse
     * @throws ResponseException
     * @throws FactoryException
     */
    public function complete(
        Resource\Payment $oPayment,
        Resource\Invoice $oInvoice,
        array $aGetVars,
        array $aPostVars
    ): CompleteResponse {
        /** @var CompleteResponse $oCompleteResponse */
        $oCompleteResponse = Factory::factory('CompleteResponse', Constants::MODULE_SLUG);
        $oCompleteResponse->setStatusComplete();
        return $oCompleteResponse;
    }

    // --------------------------------------------------------------------------

    /**
     * Issue a refund for a payment
     *
     * @param string                        $sTransactionId The original transaction's ID
     * @param int                           $iAmount        The amount to refund
     * @param Currency                      $oCurrency      The currency in which to refund
     * @param Resource\Invoice\Data\Payment $oPaymentData   The payment data object
     * @param string                        $sReason        The refund's reason
     * @param Resource\Payment              $oPayment       The payment object
     * @param Resource\Refund               $oRefund        The refund object
     * @param Resource\Invoice              $oInvoice       The invoice object
     *
     * @return RefundResponse
     * @throws FactoryException
     */
    public function refund(
        string $sTransactionId,
        int $iAmount,
        Currency $oCurrency,
        Resource\Invoice\Data\Payment $oPaymentData,
        string $sReason,
        Resource\Payment $oPayment,
        Resource\Refund $oRefund,
        Resource\Invoice $oInvoice
    ): RefundResponse {

        /** @var RefundResponse $oRefundResponse */
        $oRefundResponse = Factory::factory('RefundResponse', Constants::MODULE_SLUG);

        try {

            //  Set the API key to use
            $this->setApiKey();

            //  Get any meta data to pass along to Stripe
            $aMetaData       = $this->extractMetaData($oInvoice, $oPaymentData);
            $oStripeResponse = Refund::create(
                [
                    'charge'   => $sTransactionId,
                    'amount'   => $iAmount,
                    'metadata' => $aMetaData,
                    'expand'   => [
                        'balance_transaction',
                    ],
                ]
            );

            $oRefundResponse
                ->setStatusComplete()
                ->setTransactionId($oStripeResponse->id)
                ->setFee($oStripeResponse->balance_transaction->fee * -1);

        } catch (ApiConnectionException $e) {

            //  Network problem, perhaps try again.
            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (InvalidRequestException $e) {

            //  You screwed up in your programming. Shouldn't happen!
            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'The gateway rejected the request, you may wish to try again.'
            );

        } catch (ApiErrorException $e) {

            //  Stripe's servers are down!
            $oRefundResponse->setStatusFailed(
                $e->getMessage(),
                $e->getCode(),
                'There was a problem connecting to the gateway, you may wish to try again.'
            );

        } catch (Exception $e) {
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
     *
     * @throws DriverException
     */
    protected function setApiKey(): void
    {
        \Stripe\Stripe::setApiKey($this->getApiKey());
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the correct API key for the environment
     *
     * @param string $sType
     *
     * @return string
     * @throws DriverException
     */
    protected function getApiKey(
        string $sType = 'secret'
    ): string {

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
     * Extract the meta data from the invoice and payment data objects
     *
     * @param Resource\Invoice              $oInvoice     The invoice object
     * @param Resource\Invoice\Data\Payment $oPaymentData The payment data object
     *
     * @return array
     */
    protected function extractMetaData(
        Resource\Invoice $oInvoice,
        Resource\Invoice\Data\Payment $oPaymentData
    ): array {

        /**
         * Store any custom meta data; Stripe allows up to 20 key value pairs with key
         * names up to 40 characters and values up to 500 characters.
         * In practice only 18 custom key can be defined
         */

        $aMetaData = [
            'invoiceId'  => $oInvoice->id,
            'invoiceRef' => $oInvoice->ref,
        ];

        if (!empty($oPaymentData->metadata)) {
            $aMetaData = array_merge($aMetaData, (array) $oPaymentData->metadata);
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
     * @param Resource\Source $oResource The Resource object to update
     * @param array           $aData     Data passed from the caller
     *
     * @throws DriverException
     * @throws ApiErrorException
     */
    public function createSource(
        Resource\Source &$oResource,
        array $aData
    ): void {

        $sSourceId   = getFromArray('stripe_source_id', $aData);
        $sCustomerId = getFromArray('stripe_customer_id', $aData);

        if (empty($sSourceId)) {
            throw new DriverException('"stripe_source_id" must be supplied when creating a Stripe payment source.');
        }

        $this->setApiKey();

        if (empty($sCustomerId)) {
            $oStripeCustomer = $this->createCustomer();
        } else {
            $oStripeCustomer = $this->getCustomer($sCustomerId);
        }

        if (empty($oStripeCustomer)) {
            throw new DriverException('Failed to retrive Stripe customer object.');
        }

        $oStripeSource = Customer::createSource($oStripeCustomer->id, ['source' => $sSourceId]);

        if (empty($oStripeSource)) {
            throw new DriverException('Failed to create Stripe payment source.');
        }

        if ($oStripeSource instanceof \Stripe\Card) {

            $oExpiry = new \DateTime(implode('-', [
                $oStripeSource->exp_year,
                $oStripeSource->exp_month,
                '01',
            ]));

            $oResource->brand     = $oStripeSource->brand;
            $oResource->last_four = $oStripeSource->last4;

        } elseif ($oStripeSource instanceof \Stripe\Source) {

            if (empty($oStripeSource->card)) {
                throw new DriverException(
                    sprintf(
                        'Failed to save Stripe Source. Encountered %s, but missing card property.',
                        \Stripe\Source::class
                    )
                );
            }

            $oExpiry = new \DateTime(implode('-', [
                $oStripeSource->card->exp_year,
                $oStripeSource->card->exp_month,
                '01',
            ]));

            $oResource->brand     = $oStripeSource->card->brand;
            $oResource->last_four = $oStripeSource->card->last4;

        } else {
            throw new DriverException(
                sprintf(
                    'Failed to save Stripe Source. Unsupported response from Stripe, expected %s or %s got %s',
                    \Stripe\Card::class,
                    \Stripe\Source::class,
                    get_class($oStripeSource)
                )
            );
        }

        $oResource->expiry = $oExpiry->format('Y-m-t');
        $oResource->data   = (object) [
            'source_id'   => $oStripeSource->id,
            'customer_id' => $oStripeCustomer->id,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Updates a payment source on the gateway
     *
     * @param Resource\Source $oResource The Resource being updated
     *
     * @throws ApiErrorException
     * @throws DriverException
     * @throws Exception
     */
    public function updateSource(
        Resource\Source $oResource
    ): void {
        $this->setApiKey();
        $oExpiry = new \DateTime($oResource->expiry);
        Customer::updateSource(
            $oResource->data->customer_id,
            $oResource->data->source_id,
            array_filter([
                'name'      => $oResource->name,
                'exp_month' => $oExpiry->format('m'),
                'exp_year'  => $oExpiry->format('Y'),
            ])
        );
    }


    // --------------------------------------------------------------------------

    /**
     * Deletes a payment source from the gateway
     *
     * @param Resource\Source $oResource The Resource being deleted
     *
     * @throws DriverException
     * @throws ApiErrorException
     */
    public function deleteSource(
        Resource\Source $oResource
    ): void {
        $this->setApiKey();
        Customer::deleteSource(
            $oResource->data->customer_id,
            $oResource->data->source_id
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Convenience method for creating a new customer on the gateway
     *
     * @param array $aData The driver specific customer data
     *
     * @return \Stripe\Customer
     * @throws DriverException
     * @throws ApiErrorException
     */
    public function createCustomer(array $aData = []): \Stripe\Customer
    {
        $this->setApiKey();
        return \Stripe\Customer::create($aData);
    }

    // --------------------------------------------------------------------------

    /**
     * Convenience method for retrieving an existing customer from the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       Any driver specific data
     *
     * @return \Stripe\Customer
     * @throws DriverException
     * @throws ApiErrorException
     */
    public function getCustomer($mCustomerId, array $aData = []): \Stripe\Customer
    {
        $this->setApiKey();
        $oCustomer = \Stripe\Customer::retrieve($mCustomerId, $aData);

        //  Perform similar behaviour as if customer ID doesn't exist
        if ($oCustomer->isDeleted()) {
            throw new DriverException('Customer "' . $mCustomerId . '" is deleted.');
        }

        return $oCustomer;
    }

    // --------------------------------------------------------------------------

    /**
     * Convenience method for updating an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     * @param array $aData       The driver specific customer data
     *
     * @return \Stripe\Customer
     * @throws DriverException
     * @throws ApiErrorException
     */
    public function updateCustomer($mCustomerId, array $aData = []): \Stripe\Customer
    {
        $this->setApiKey();
        return \Stripe\Customer::update($mCustomerId, $aData);
    }

    // --------------------------------------------------------------------------

    /**
     * Convenience method for deleting an existing customer on the gateway
     *
     * @param mixed $mCustomerId The gateway's customer ID
     *
     * @throws DriverException
     * @throws ApiErrorException
     */
    public function deleteCustomer($mCustomerId): void
    {
        $this->setApiKey();
        $oCustomer = $this->getCustomer($mCustomerId);
        $oCustomer->delete();
    }
}
