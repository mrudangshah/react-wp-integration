<?php

use EEA_Stripe\Stripe;
use EEA_Stripe\Stripe_CardError;
use EEA_Stripe\Stripe_Charge;

/**
 *
 * EEG_Stripe_Onsite
 *
 * Just approves payments where billing_info[ 'credit_card' ] == 1.
 * If $billing_info[ 'credit_card' ] == '2' then its pending.
 * All others get refused
 *
 * @package            Event Espresso
 * @subpackage        espresso-stripe-gateway
 * @author            Event Espresso
 *
 */
class EEG_Stripe_Onsite extends EE_Onsite_Gateway
{

    protected $_publishable_key = null;

    protected $_secret_key = null;

    /**
     * All the currencies supported by this gateway. Add any others you like,
     * as contained in the esp_currency table
     * @var array
     */
    protected $_currencies_supported = EE_Gateway::all_currencies_supported;

    /**
     *
     * @param EEI_Payment $payment
     * @param array $billing_info
     * @return \EE_Payment|\EEI_Payment
     * @throws EE_Error
     */
    public function do_direct_payment($payment, $billing_info = null)
    {
        if (!$payment instanceof EEI_Payment) {
            $payment->set_gateway_response(__('Error. No associated payment was found.', 'event_espresso'));
            $payment->set_status($this->_pay_model->failed_status());
            return $payment;
        }
        $transaction = $payment->transaction();
        if (!$transaction instanceof EE_Transaction) {
            $payment->set_gateway_response(__('Could not process this payment because it has no associated transaction.', 'event_espresso'));
            $payment->set_status($this->_pay_model->failed_status());
            return $payment;
        }
        // If this merchant is using Stripe Connect we need a to use the connected account token.
        $payment_method = $transaction->payment_method();
        if (!$payment_method instanceof EE_Payment_Method) {
            $payment->set_gateway_response(
                esc_html__(
                    'Error. No payment method on this transaction, although we know its Stripe.',
                    'event_espresso'
                )
            );
            $payment->set_status($this->_pay_model->failed_status());
            return $payment;
        }
        $key = apply_filters(
            'FHEE__EEG_Stripe_Onsite__do_direct_payment__use_connected_account_token',
            $this->_secret_key,
            $transaction->payment_method()
        );
        // Set your secret key.
        Stripe::setApiKey($key);
        $stripe_data = array(
            'amount' => $this->prepare_amount_for_stripe($payment->amount()),

            'currency' => $payment->currency_code(),
            'card' => $billing_info['ee_stripe_token'],
            'description' => $billing_info['ee_stripe_prod_description']
        );
        $stripe_data = apply_filters('FHEE__EEG_Stripe_Onsite__do_direct_payment__stripe_data_array', $stripe_data, $payment, $transaction, $billing_info);

        // Create the charge on Stripe's servers - this will charge the user's card.
        try {
            $this->log(array('Stripe Request data:' => $stripe_data), $payment);
            $charge = Stripe_Charge::create($stripe_data);
        } catch (Stripe_CardError $error) {
            $payment->set_status($this->_pay_model->declined_status());
            $payment->set_gateway_response($error->getMessage());
            $this->log(array('Stripe Error occurred:' => $error), $payment);
            return $payment;
        } catch (Exception $exception) {
            $payment->set_status($this->_pay_model->failed_status());
            $payment->set_gateway_response($exception->getMessage());
            $this->log(array('Stripe Error occurred:' => $exception), $payment);
            return $payment;
        }

        $charge_array = $charge->__toArray(true);
        $this->log(array('Stripe charge:' => $charge_array), $payment);
        $payment->set_gateway_response($charge_array['status']);
        $payment->set_txn_id_chq_nmbr($charge_array['id']);
        $payment->set_details($charge_array);
        $payment->set_amount(floatval($this->prepare_amount_from_stripe($charge_array['amount'])));
        $payment->set_status($this->_pay_model->approved_status());
        return $payment;
    }

    /**
     * Gets the number of decimal places Stripe expects a currency to have.
     * See https://stripe.com/docs/currencies#charge-currencies for the list.
     *
     * @param string $currency Accepted currency.
     * @return int
     */
    public function get_stripe_decimal_places($currency = '')
    {
        if (!$currency) {
            $currency = EE_Registry::instance()->CFG->currency->code;
        }
        switch (strtoupper($currency)) {
            // Zero decimal currencies.
            case 'BIF':
            case 'CLP':
            case 'DJF':
            case 'GNF':
            case 'JPY':
            case 'KMF':
            case 'KRW':
            case 'MGA':
            case 'PYG':
            case 'RWF':
            case 'VND':
            case 'VUV':
            case 'XAF':
            case 'XOF':
            case 'XPF':
                return 0;
            default:
                return 2;
        }
    }


    /**
     * Converts an amount into the currency's subunits as expected by Stripe.
     * (Some currencies have no subunits, so leaves them in the currency's main units).
     * @param float $amount
     * @return int in the currency's smallest unit (e.g., pennies)
     */
    public function prepare_amount_for_stripe($amount)
    {
        return $amount * pow(10, $this->get_stripe_decimal_places());
    }


    /**
     * Converts an amount from Stripe (in the currency's subunits) to a
     * float as used by EE
     * @param $amount
     * @return float
     */
    public function prepare_amount_from_stripe($amount)
    {
        return $amount / pow(10, $this->get_stripe_decimal_places());
    }
}
