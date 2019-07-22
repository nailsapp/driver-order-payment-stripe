class Checkout {
    constructor() {

        this.params = this.getParams('vendor/nails/driver-invoice-stripe/assets/js/checkout.min.js');

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

                //  @todo (Pablo - 2019-07-22) - Disable the form (prevent double submission)

                if (this.$input.val().length === 0) {

                    e.preventDefault();

                    this
                        .stripe
                        .createToken(this.card)
                        .then((result) => {
                            if (result.error) {
                                this.showError(result.error.message);
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

    // --------------------------------------------------------------------------

    /**
     * Returns the parameters passed to the script
     * @param {String} script_name The name of the script
     * @returns {{}}
     */
    getParams(script_name) {

        // Find all script tags
        var scripts = document.getElementsByTagName("script");

        // Look through them trying to find ourselves
        for (let i = 0; i < scripts.length; i++) {
            if (scripts[i].src.indexOf("/" + script_name) > -1) {
                // Get an array of key=value strings of params
                let pa = scripts[i].src.split("?").pop().split("&");

                // Split each key=value into array, the construct js object
                let p = {};
                for (let j = 0; j < pa.length; j++) {
                    let kv = pa[j].split("=");
                    p[kv[0]] = kv[1];
                }
                return p;
            }
        }

        // No scripts match
        return {};
    }
}

export default Checkout;
