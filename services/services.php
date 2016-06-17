<?php

return array(
    'models' => array(
        'Customer' => function () {
            if (class_exists('App\Invoice\Driver\Payment\Stripe\Model\Customer')) {
                return new App\Invoice\Driver\Payment\Stripe\Model\Customer();
            } else {
                return new Nails\Invoice\Driver\Payment\Stripe\Model\Customer();
            }
        },
        'Source' => function () {
            if (class_exists('App\Invoice\Driver\Payment\Stripe\Model\Source')) {
                return new App\Invoice\Driver\Payment\Stripe\Model\Source();
            } else {
                return new Nails\Invoice\Driver\Payment\Stripe\Model\Source();
            }
        }
    )
);
