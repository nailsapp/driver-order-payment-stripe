<?php

return array(
    'models' => array(
        'Customer' => function () {
            if (class_exists('App\Invoice\Driver\Payment\Stripe\Customer')) {
                return new App\Invoice\Driver\Payment\Stripe\Customer();
            } else {
                return new Nails\Invoice\Driver\Payment\Stripe\Customer();
            }
        },
        'Source' => function () {
            if (class_exists('App\Invoice\Driver\Payment\Stripe\Source')) {
                return new App\Invoice\Driver\Payment\Stripe\Source();
            } else {
                return new Nails\Invoice\Driver\Payment\Stripe\Source();
            }
        }
    )
);
