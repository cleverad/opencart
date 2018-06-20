export class Checkout {
    constructor(formSelector) {
        this.$form = $(formSelector);
    }

    init() {
        this.$form.on('submit', (e) => {
            e.preventDefault();

            this.checkout(this.$form.serialize());
        });
    }

    checkout(data) {
        this._removeErrors();

        $.ajax({
            url: this.$form.attr('action'),
            method: 'post',
            data: data,
            beforeSend: () => {
                this.$form.find('button[type="submit"]').button('loading');
            },
            complete: () => {
                this.$form.find('button[type="submit"]').button('reset');
            },
            success: (response) => {
                if (response.errors) {
                    this._renderErrors(response.errors);
                } else if (response.payment_content) {
                    $('#payment_content').html(response.payment_content);
                    $('#payment_content').find('a').trigger('click');
                }
            }
        });
    }

    _removeErrors() {
        this.$form.find('.text-danger').remove();
    }

    _renderErrors(errors) {
        for (let attribute in errors) {
            let error = errors[attribute],
                $element = $('#checkout-' + attribute.replace('_', '-'));

            if ($element.parent().hasClass('input-group')) {
                $element.parent().after('<div class="text-danger">' + error + '</div>');
            } else {
                $element.after('<div class="text-danger">' + error + '</div>');
            }
        }
    }
}
