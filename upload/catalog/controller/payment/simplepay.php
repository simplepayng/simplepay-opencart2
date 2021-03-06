<?php

class ControllerPaymentSimplePay extends Controller
{
    /**
     * Checkout page
     */
    public function index()
    {
        $this->language->load('payment/simplepay');

        $data['text_loading'] = $this->language->get('text_loading');

        $data['button_confirm'] = $this->language->get('button_confirm');

        $order_info = $this->model_checkout_order->getOrder($this->session->data['order_id']);

        $data['email'] = $order_info['email'];
        $data['phone'] = $order_info['telephone'];
        $data['order_id'] = $this->session->data['order_id'];
        $data['description'] = $this->config->get('simplepay_description');
        $data['address'] = $order_info['payment_address_1'] . ' ' . $order_info['payment_address_2'];
        $data['postal_code'] = $order_info['payment_postcode'];
        $data['city'] = $order_info['payment_city'];
        $data['country'] = $order_info['payment_iso_code_2'];
        $data['amount'] = $this->currency->format($order_info['total'], $order_info['currency_code'], $order_info['currency_value'], false);
        $data['currency'] = $order_info['currency_code'];
        $data['image'] = $this->config->get('simplepay_image');

        if ($this->config->get('simplepay_test')) {
            $data['key'] = $this->config->get('simplepay_public_test_key');
        } else {
            $data['key'] = $this->config->get('simplepay_public_live_key');
        }

        if (version_compare(VERSION, '2.2.0.0', '<')) {
            if (file_exists(DIR_TEMPLATE . $this->config->get('config_template') . '/template/payment/simplepay.tpl')) {
                return $this->load->view($this->config->get('config_template') . '/template/payment/simplepay.tpl', $data);
            } else {
                return $this->load->view('default/template/payment/simplepay.tpl', $data);
            }
        } else {
            return $this->load->view('payment/simplepay', $data);
        }
    }

    /**
     * Handle checkout token and verify simplepay transaction
     */
    public function send()
    {
        $this->load->model('checkout/order');

        $order_status_complete = 5;
        $order_status_failed = 10;

        if ($this->config->get('simplepay_test')) {
            $private_key = $this->config->get('simplepay_private_test_key');
        } else {
            $private_key = $this->config->get('simplepay_private_live_key');
        }

        // Verify SimplePay transaction
        $verified_transaction = $this->verify_transaction(
            $this->request->post['token'],
            $this->request->post['amount'],
            $this->request->post['currency'],
            $private_key);

        if ($verified_transaction['verified']) {
            $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $order_status_complete, 'Success', false);

            $json['success'] = $this->url->link('checkout/success');

        } else {
            $this->model_checkout_order->addOrderHistory($this->session->data['order_id'], $order_status_failed, 'Failed', false);

            $this->log->write('SimplePay Payment failed: ' . $verified_transaction['response']['response_code']);
            $json['error'] = $verified_transaction['response']['response_code'];
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function verify_transaction($token, $amount, $currency, $private_key)
    {
        $data = array(
            'token' => $token,
            'amount' => $amount,
            'currency' => $currency
        );
        $data_string = json_encode($data);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, 'https://checkout.simplepay.ng/v1/payments/verify/');
        curl_setopt($ch, CURLOPT_USERPWD, $private_key . ':');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data_string);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($data_string)
        ));

        $verify_count = 1;
        $response = $this->do_curl($ch);
        $verified = $this->valid_response($response);
        while (!$verified && $verify_count < 3) {
            $response = $this->do_curl($ch);
            $verified = $this->valid_response($response);
            $verify_count += 1;
        }

        curl_close($ch);

        return array(
            'verified' => $verified,
            'response' => $response['json_response']
        );
    }

    private function do_curl($ch)
    {
        $curl_response = curl_exec($ch);
        $curl_response = preg_split("/\r\n\r\n/", $curl_response);
        $response_content = $curl_response[1];

        return array(
            'response_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'json_response' => json_decode(chop($response_content), true)
        );
    }

    private function valid_response($response)
    {
        return $response['response_code'] == '200' ||
        $response['response_code'] == '201' ||
        $response['json_response']['response_code'] == '20000';
    }
}