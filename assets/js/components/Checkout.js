import Utilities from './Utilities';

class Checkout {
    constructor() {

        this.params = Utilities.getParams('vendor/nails/driver-invoice-stripe/assets/js/checkout.min.js');

        //  Prepare the form
        //  Replace the input with a <div>

        let id = '#stripe-elements-' + this.params.hash;
        let $original = $(id);

        this.$form = $('#js-invoice-main-form');
        this.$input = $('<input>').attr('type', 'hidden').attr('name', $original.attr('name'));
        this.$elements = $('<div>').attr('id', id.replace(/^#/, '')).addClass('input')
        this.$error = $('<p>').addClass('form__error');

        this
            .$group = $('<div>')
            .append(this.$input)
            .append(this.$elements)
            .append(this.$error);

        $original
            .replaceWith(this.$group);

        //  Build Stripe element
        this.stripe = Stripe(this.params.key);
        this.elements = this.stripe.elements();

        // Create an instance of the card Element.
        this.card = this.elements.create('card');

        //  Bind to the DOM
        this.card.mount(id);

        //  Show user errors
        this
            .card
            .addEventListener('change', (e) => {
                if (e.error) {
                    this.showError(e.error.message);
                } else {
                    this.hideError();
                }
            });

        //  Bind to form submission
        this
            .$form
            .on('submit', (e) => {

                if (this.$input.val().length === 0) {

                    e.preventDefault();
                    $('#js-invoice-pay-now')
                        .addClass('btn--working')
                        .prop('disabled', true);

                    this
                        .stripe
                        .createToken(this.card)
                        .then((result) => {

                            if (result.error) {

                                this.showError(result.error.message);
                                $('#js-invoice-pay-now')
                                    .removeClass('btn--working')
                                    .prop('disabled', false);

                            } else {

                                this.hideError()
                                this.$input.val(result.token.id);
                                this.$form.submit();
                            }
                        });
                }
            })
    }

    // --------------------------------------------------------------------------

    showError(error) {
        this.$error.html(error);
        this.$elements.addClass('has-error');
    }

    // --------------------------------------------------------------------------

    hideError() {
        this.$error.html('');
        this.$elements.removeClass('has-error');
    }
}

export default Checkout;
