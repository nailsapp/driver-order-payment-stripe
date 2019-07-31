import Utilities from './Utilities';

const DRIVER = 'nails/driver-invoice-stripe';

class Checkout {

    /**
     * Cosntruct Checkout
     */
    constructor() {

        this.params = Utilities.getParams('vendor/' + DRIVER + '/assets/js/checkout.min.js');
        this.hash = this.params.hash;
        this.key = this.params.key;

        this.prepareForm();
        this.bindEvents();
        this.registerValidator();
    }

    // --------------------------------------------------------------------------

    /**
     * Prepares the form
     */
    prepareForm() {
        //  Prepare the form
        //  Replace the input with a <div>
        let id = '#stripe-elements-' + this.hash;
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
        this.stripe = Stripe(this.key);
        this.elements = this.stripe.elements();

        // Create an instance of the card Element.
        this.card = this.elements.create('card');

        //  Bind to the DOM
        this.card.mount(id);
    }

    // --------------------------------------------------------------------------

    /**
     * Binds to user events
     */
    bindEvents() {
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
    }

    // --------------------------------------------------------------------------

    /**
     * Registers the validator
     */
    registerValidator() {
        this.$form
            .data('validators')
            .push({
                'slug': DRIVER,
                'instance': this
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Validates the form, and generates the card token
     * @param deferred
     */
    validate(deferred) {

        this
            .stripe
            .createToken(this.card)
            .then((result) => {

                if (result.error) {
                    deferred.reject(result.error.message);
                } else {
                    this.$input.val(result.token.id);
                    deferred.resolve();
                }
            });
    }

    // --------------------------------------------------------------------------

    /**
     * Shows the error field
     * @param error
     */
    showError(error) {
        this.$error.html(error);
        this.$elements.addClass('has-error');
    }

    // --------------------------------------------------------------------------

    /**
     * Hides the error field
     */
    hideError() {
        this.$error.html('');
        this.$elements.removeClass('has-error');
    }
}

export default Checkout;
