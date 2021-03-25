<?php

namespace Nails\Invoice\Driver\Payment\Stripe\Settings;

use Nails\Common\Helper\Form;
use Nails\Common\Interfaces;
use Nails\Common\Service\FormValidation;
use Nails\Components\Setting;
use Nails\Currency;
use Nails\Factory;

/**
 * Class Stripe
 *
 * @package Nails\Invoice\Driver\Payment\Stripe\Settings
 */
class Stripe implements Interfaces\Component\Settings
{
    const KEY_LABEL                       = 'sLabel';
    const KEY_STATEMENT_DESCRIPTOR        = 'sStatementDescriptor';
    const KEY_ENABLE_STRIPE_RECEIPT_EMAIL = 'bEnableStripeReceiptEmail';
    const KEY_TEST_PUBLIC                 = 'sKeyTestPublic';
    const KEY_TEST_PRIVATE                = 'sKeyTestSecret';
    const KEY_LIVE_PUBLIC                 = 'sKeyLivePublic';
    const KEY_LIVE_PRIVATE                = 'sKeyLiveSecret';

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function getLabel(): string
    {
        return 'Stripe';
    }

    // --------------------------------------------------------------------------

    /**
     * @inheritDoc
     */
    public function get(): array
    {
        /** @var Setting $oLabel */
        $oLabel = Factory::factory('ComponentSetting');
        $oLabel
            ->setKey(static::KEY_LABEL)
            ->setLabel('Label')
            ->setInfo('The name of the provider, as seen by customers.')
            ->setDefault('Stripe')
            ->setValidation([
                FormValidation::RULE_REQUIRED,
            ]);

        /** @var Setting $oStatementDescriptor */
        $oStatementDescriptor = Factory::factory('ComponentSetting');
        $oStatementDescriptor
            ->setKey(static::KEY_STATEMENT_DESCRIPTOR)
            ->setLabel('Statement Descriptor')
            ->setInfo('The text shown on the customer\'s statement. You can sub in <code>{{INVOICE_REF}}</code> for the invoice reference.')
            ->setDefault('INV #{{INVOICE_REF}}')
            ->setMaxLength(22)
            ->setValidation([
                FormValidation::RULE_REQUIRED,
                FormValidation::rule(FormValidation::RULE_MAX_LENGTH, 22),
            ]);

        /** @var Setting $oEnableStripeReceiptEmail */
        $oEnableStripeReceiptEmail = Factory::factory('ComponentSetting');
        $oEnableStripeReceiptEmail
            ->setKey(static::KEY_ENABLE_STRIPE_RECEIPT_EMAIL)
            ->setType(Form::FIELD_BOOLEAN)
            ->setLabel('Enable Stripe Receipt Email')
            ->setInfo('If enabled, the <code>receipt_email</code> parameter is sent along with the charge request, which will normally trigger a receipt from Stripe')
            ->setDefault(false);

        /** @var Setting $oKeyPublicTest */
        $oKeyPublicTest = Factory::factory('ComponentSetting');
        $oKeyPublicTest
            ->setKey(static::KEY_TEST_PUBLIC)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Publishable Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Test');

        /** @var Setting $oKeyPrivateTest */
        $oKeyPrivateTest = Factory::factory('ComponentSetting');
        $oKeyPrivateTest
            ->setKey(static::KEY_TEST_PRIVATE)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Secret Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Test');

        /** @var Setting $oKeyPublicLive */
        $oKeyPublicLive = Factory::factory('ComponentSetting');
        $oKeyPublicLive
            ->setKey(static::KEY_LIVE_PUBLIC)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Publishable Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Live');

        /** @var Setting $oKeyPrivateLive */
        $oKeyPrivateLive = Factory::factory('ComponentSetting');
        $oKeyPrivateLive
            ->setKey(static::KEY_LIVE_PRIVATE)
            ->setType(Form::FIELD_PASSWORD)
            ->setLabel('Secret Key')
            ->setEncrypted(true)
            ->setFieldset('API Keys - Live');

        return [
            $oLabel,
            $oStatementDescriptor,
            $oEnableStripeReceiptEmail,
            $oKeyPublicTest,
            $oKeyPrivateTest,
            $oKeyPublicLive,
            $oKeyPrivateLive,
        ];
    }
}
