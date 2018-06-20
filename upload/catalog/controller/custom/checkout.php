<?php

class ControllerCustomCheckout extends Controller {
    const PASSWORD = 'atwyld9q14';

    public function index()
    {
        $this->load->language('custom/checkout');

        $data = [
            'code' => false,
            'cart_edit_action' => $this->url->link('checkout/cart/edit', 'language=' . $this->config->get('config_language')),
            'checkout_action' => $this->url->link('custom/checkout/confirm', 'language=' . $this->config->get('config_language')),
        ];

        $this->injectProducts($data);
        $this->injectTotals($data);
        $this->injectPaymentMethods($data);

        if ($this->cart->hasProducts()) {
            if (!$this->cart->hasStock() && (!$this->config->get('config_stock_checkout') || $this->config->get('config_stock_warning'))) {
                $data['error_warning'] = $this->language->get('error_stock');
            } elseif (isset($this->session->data['error'])) {
                $data['error_warning'] = $this->session->data['error'];

                unset($this->session->data['error']);
            } else {
                $data['error_warning'] = '';
            }

            if (isset($this->session->data['success'])) {
                $data['success'] = $this->session->data['success'];

                unset($this->session->data['success']);
            } else {
                $data['success'] = '';
            }
        }

        $data['footer'] = $this->load->controller('common/footer');
        $data['header'] = $this->load->controller('common/header');

        $this->response->setOutput($this->load->view('custom/checkout', $data));
    }

    public function confirm()
    {
        $this->load->language('custom/confirm');

        $postFields = $this->getCustomerFields();
        foreach ($postFields as $key => $value) {
            $this->request->post[$key] = $value;
        }

        $errors = $this->checkCustomerFields($postFields);

        $json = [];

        if (!empty($errors)) {
            $json['errors'] = $errors;
        } else {
            $this->findOrCreateCustomer($postFields);

            $this->customer->login($postFields['email'], $postFields['password']);

            $this->session->data['order_id'] = $this->createOrder($postFields);

            $json['payment_content'] = $this->load->controller('extension/payment/' . $postFields['payment_method']);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput(json_encode($json));
    }

    private function getCustomerFields()
    {
        $password = self::PASSWORD;
        $telephone = $this->post('telephone');
        $login = preg_replace('/\D/', '', $telephone);

        $countryId = $this->config->get('config_country_id');
        $zoneId = $this->config->get('config_zone_id');

        return [
            'firstname' => $this->post('firstname', '-'),
            'lastname' => $this->post('lastname', '-'),
            'email' => $login,
            'telephone' => $telephone,
            'address_1' => $this->post('address_1', '-'),
            'address_2' => $this->post('address_2'),
            'city' => $this->post('city', '-'),
            'country_id' => $this->post('country_id', $countryId),
            'postcode' => $this->post('postcode', '-'),
            'zone_id' => $this->post('zone_id', $zoneId),
            'password' => $this->post('password', $password),
            'company' => $this->post('company'),
            'payment_method' => $this->post('payment_method'),
        ];
    }

    private function post($key, $default = null)
    {
        $value = null;

        if (array_key_exists($key, $this->request->post)) {
            $value = trim($this->request->post[$key]);
        }

        return empty($value) ? $default : $value;
    }

    private function checkCustomerFields($fields)
    {
        $errors = [];

        if (!isset($fields['telephone'])
            || (utf8_strlen($fields['telephone']) < 7)
            || (utf8_strlen($fields['telephone']) > 32)) {
            $errors['telephone'] = $this->language->get('error_telephone');
        }

        return $errors;
    }

    private function injectProducts(&$data)
    {
        $products = $this->cart->getProducts();

        $data['products'] = [];

        foreach ($products as $product) {
            $product_total = 0;

            foreach ($products as $product_2) {
                if ($product_2['product_id'] == $product['product_id']) {
                    $product_total += $product_2['quantity'];
                }
            }

            if ($product['minimum'] > $product_total) {
                $data['error_warning'] = sprintf($this->language->get('error_minimum'), $product['name'], $product['minimum']);
            }

            if ($product['image']) {
                $image = $this->model_tool_image->resize($product['image'], $this->config->get('theme_' . $this->config->get('config_theme') . '_image_cart_width'), $this->config->get('theme_' . $this->config->get('config_theme') . '_image_cart_height'));
            } else {
                $image = '';
            }

            $option_data = array();

            foreach ($product['option'] as $option) {
                if ($option['type'] != 'file') {
                    $value = $option['value'];
                } else {
                    $upload_info = $this->model_tool_upload->getUploadByCode($option['value']);

                    if ($upload_info) {
                        $value = $upload_info['name'];
                    } else {
                        $value = '';
                    }
                }

                $option_data[] = array(
                    'name'  => $option['name'],
                    'value' => (utf8_strlen($value) > 20 ? utf8_substr($value, 0, 20) . '..' : $value)
                );
            }

            // Display prices
            if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
                $unit_price = $this->tax->calculate($product['price'], $product['tax_class_id'], $this->config->get('config_tax'));

                $price = $this->currency->format($unit_price, $this->session->data['currency']);
                $total = $this->currency->format($unit_price * $product['quantity'], $this->session->data['currency']);
            } else {
                $price = false;
                $total = false;
            }

            $recurring = '';

            if ($product['recurring']) {
                if ($product['recurring']['trial']) {
                    $recurring = sprintf($this->language->get('text_trial_description'), $this->currency->format($this->tax->calculate($product['recurring']['trial_price'] * $product['quantity'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']), $product['recurring']['trial_cycle'], $frequencies[$product['recurring']['trial_frequency']], $product['recurring']['trial_duration']) . ' ';
                }

                if ($product['recurring']['duration']) {
                    $recurring .= sprintf($this->language->get('text_payment_description'), $this->currency->format($this->tax->calculate($product['recurring']['price'] * $product['quantity'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']), $product['recurring']['cycle'], $frequencies[$product['recurring']['frequency']], $product['recurring']['duration']);
                } else {
                    $recurring .= sprintf($this->language->get('text_payment_cancel'), $this->currency->format($this->tax->calculate($product['recurring']['price'] * $product['quantity'], $product['tax_class_id'], $this->config->get('config_tax')), $this->session->data['currency']), $product['recurring']['cycle'], $frequencies[$product['recurring']['frequency']], $product['recurring']['duration']);
                }
            }

            $data['products'][] = array(
                'cart_id'   => $product['cart_id'],
                'thumb'     => $image,
                'name'      => $product['name'],
                'model'     => $product['model'],
                'option'    => $option_data,
                'recurring' => $recurring,
                'quantity'  => $product['quantity'],
                'stock'     => $product['stock'] ? true : !(!$this->config->get('config_stock_checkout') || $this->config->get('config_stock_warning')),
                'reward'    => ($product['reward'] ? sprintf($this->language->get('text_points'), $product['reward']) : ''),
                'price'     => $price,
                'total'     => $total,
                'href'      => $this->url->link('product/product', 'language=' . $this->config->get('config_language') . '&product_id=' . $product['product_id'])
            );
        }
    }

    private function injectTotals(&$data)
    {
        // Totals
        $this->load->model('setting/extension');

        $totals = array();
        $taxes = $this->cart->getTaxes();
        $total = 0;

        // Because __call can not keep var references so we put them into an array.
        $total_data = array(
            'totals' => &$totals,
            'taxes'  => &$taxes,
            'total'  => &$total
        );

        // Display prices
        if ($this->customer->isLogged() || !$this->config->get('config_customer_price')) {
            $sort_order = array();

            $results = $this->model_setting_extension->getExtensions('total');

            foreach ($results as $key => $value) {
                $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
            }

            array_multisort($sort_order, SORT_ASC, $results);

            foreach ($results as $result) {
                if ($this->config->get('total_' . $result['code'] . '_status')) {
                    $this->load->model('extension/total/' . $result['code']);

                    // We have to put the totals in an array so that they pass by reference.
                    $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
                }
            }

            $sort_order = array();

            foreach ($totals as $key => $value) {
                $sort_order[$key] = $value['sort_order'];
            }

            array_multisort($sort_order, SORT_ASC, $totals);
        }

        $data['totals'] = array();

        foreach ($totals as $total) {
            $data['totals'][] = array(
                'title' => $total['title'],
                'text'  => $this->currency->format($total['value'], $this->session->data['currency'])
            );
        }
    }

    private function injectPaymentMethods(&$data)
    {
        // Totals
        $totals = array();
        $taxes = $this->cart->getTaxes();
        $total = 0;

        // Because __call can not keep var references so we put them into an array.
        $total_data = array(
            'totals' => &$totals,
            'taxes'  => &$taxes,
            'total'  => &$total
        );

        $this->load->model('setting/extension');

        $sort_order = array();

        $results = $this->model_setting_extension->getExtensions('total');

        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);

                // We have to put the totals in an array so that they pass by reference.
                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
            }
        }

        // Payment Methods
        $method_data = array();

        $this->load->model('setting/extension');
        $this->load->model('account/address');

        $paymentAddress = $this->model_account_address->getAddress($this->customer->getAddressId());

        $results = $this->model_setting_extension->getExtensions('payment');

        $recurring = $this->cart->hasRecurringProducts();

        foreach ($results as $result) {
            if ($this->config->get('payment_' . $result['code'] . '_status')) {
                $this->load->model('extension/payment/' . $result['code']);

                $method = $this->{'model_extension_payment_' . $result['code']}->getMethod($paymentAddress, $total);

                if ($method) {
                    if ($recurring) {
                        if (property_exists($this->{'model_extension_payment_' . $result['code']}, 'recurringPayments') && $this->{'model_extension_payment_' . $result['code']}->recurringPayments()) {
                            $method_data[$result['code']] = $method;
                        }
                    } else {
                        $method_data[$result['code']] = $method;
                    }
                }
            }
        }

        $sort_order = array();

        foreach ($method_data as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $method_data);

        $data['payment_methods'] = $method_data;
    }

    private function findOrCreateCustomer($fields)
    {
        $email = $fields['email'];

        $this->load->model('account/customer');
        $this->load->model('account/address');

        $customersCount = (int) $this->model_account_customer->getTotalCustomersByEmail($email);

        if ($customersCount === 0) {
            $customer_id = $this->model_account_customer->addCustomer($fields);

            $address_id = $this->model_account_address->addAddress($customer_id, $fields);

            // Set the address as default
            $this->model_account_customer->editAddressId($customer_id, $address_id);

            // Clear any previous login attempts for unregistered accounts.
            $this->model_account_customer->deleteLoginAttempts($email);
        }
    }

    private function createOrder($fields)
    {
        $order_data = array();

        $totals = array();
        $taxes = $this->cart->getTaxes();
        $total = 0;

        // Because __call can not keep var references so we put them into an array.
        $total_data = array(
            'totals' => &$totals,
            'taxes'  => &$taxes,
            'total'  => &$total
        );

        $this->load->model('setting/extension');

        $sort_order = array();

        $results = $this->model_setting_extension->getExtensions('total');

        foreach ($results as $key => $value) {
            $sort_order[$key] = $this->config->get('total_' . $value['code'] . '_sort_order');
        }

        array_multisort($sort_order, SORT_ASC, $results);

        foreach ($results as $result) {
            if ($this->config->get('total_' . $result['code'] . '_status')) {
                $this->load->model('extension/total/' . $result['code']);

                // We have to put the totals in an array so that they pass by reference.
                $this->{'model_extension_total_' . $result['code']}->getTotal($total_data);
            }
        }

        $sort_order = array();

        foreach ($totals as $key => $value) {
            $sort_order[$key] = $value['sort_order'];
        }

        array_multisort($sort_order, SORT_ASC, $totals);

        $order_data['totals'] = $totals;

        $order_data['invoice_prefix'] = $this->config->get('config_invoice_prefix');
        $order_data['store_id'] = $this->config->get('config_store_id');
        $order_data['store_name'] = $this->config->get('config_name');

        if ($order_data['store_id']) {
            $order_data['store_url'] = $this->config->get('config_url');
        } else {
            $order_data['store_url'] = HTTP_SERVER;
        }

        $this->load->model('account/customer');

        $customer_info = $this->model_account_customer->getCustomer($this->customer->getId());

        $order_data['customer_id'] = $this->customer->getId();
        $order_data['customer_group_id'] = $customer_info['customer_group_id'];
        $order_data['firstname'] = $customer_info['firstname'];
        $order_data['lastname'] = $customer_info['lastname'];
        $order_data['email'] = $customer_info['email'];
        $order_data['telephone'] = $customer_info['telephone'];
        $order_data['custom_field'] = json_decode($customer_info['custom_field'], true);

        $order_data['payment_firstname'] = $order_data['firstname'];
        $order_data['payment_lastname'] = $order_data['lastname'];
        $order_data['payment_company'] = $fields['company'];
        $order_data['payment_address_1'] = $fields['address_1'];
        $order_data['payment_address_2'] = $fields['address_2'];
        $order_data['payment_country_id'] = $fields['country_id'];
        $order_data['payment_zone_id'] = $fields['zone_id'];
        $order_data['payment_city'] = $fields['city'];
        $order_data['payment_postcode'] = $fields['postcode'];
        $order_data['payment_zone'] = '';
        $order_data['payment_country'] = '';
        $order_data['payment_address_format'] = '';
        $order_data['payment_custom_field'] = [];

        $order_data['payment_method'] = isset($fields['payment_method']) ? $fields['payment_method'] : null;
        $order_data['payment_code'] = $order_data['payment_method'];

        if ($this->cart->hasShipping()) {
            $order_data['shipping_firstname'] = $order_data['firstname'];
            $order_data['shipping_lastname'] = $order_data['lastname'];
            $order_data['shipping_company'] = $fields['company'];
            $order_data['shipping_address_1'] = $fields['address_1'];
            $order_data['shipping_address_2'] = $fields['address_2'];
            $order_data['shipping_country_id'] = $fields['country_id'];
            $order_data['shipping_zone_id'] = $fields['zone_id'];
            $order_data['shipping_city'] = $fields['city'];
            $order_data['shipping_postcode'] = $fields['postcode'];
            $order_data['shipping_zone'] = '';
            $order_data['shipping_country'] = '';
            $order_data['shipping_address_format'] = '';
            $order_data['shipping_custom_field'] = [];
            $order_data['shipping_method'] = '';
            $order_data['shipping_code'] = '';
        } else {
            $order_data['shipping_firstname'] = '';
            $order_data['shipping_lastname'] = '';
            $order_data['shipping_company'] = '';
            $order_data['shipping_address_1'] = '';
            $order_data['shipping_address_2'] = '';
            $order_data['shipping_country_id'] = '';
            $order_data['shipping_zone_id'] = '';
            $order_data['shipping_city'] = '';
            $order_data['shipping_postcode'] = '';
            $order_data['shipping_zone'] = '';
            $order_data['shipping_country'] = '';
            $order_data['shipping_address_format'] = '';
            $order_data['shipping_custom_field'] = [];
            $order_data['shipping_method'] = '';
            $order_data['shipping_code'] = '';
        }

        $order_data['products'] = array();

        foreach ($this->cart->getProducts() as $product) {
            $option_data = array();

            foreach ($product['option'] as $option) {
                $option_data[] = array(
                    'product_option_id'       => $option['product_option_id'],
                    'product_option_value_id' => $option['product_option_value_id'],
                    'option_id'               => $option['option_id'],
                    'option_value_id'         => $option['option_value_id'],
                    'name'                    => $option['name'],
                    'value'                   => $option['value'],
                    'type'                    => $option['type']
                );
            }

            $order_data['products'][] = array(
                'product_id' => $product['product_id'],
                'name'       => $product['name'],
                'model'      => $product['model'],
                'option'     => $option_data,
                'download'   => $product['download'],
                'quantity'   => $product['quantity'],
                'subtract'   => $product['subtract'],
                'price'      => $product['price'],
                'total'      => $product['total'],
                'tax'        => $this->tax->getTax($product['price'], $product['tax_class_id']),
                'reward'     => $product['reward']
            );
        }

        $order_data['comment'] = '';
        $order_data['total'] = $total_data['total'];

        $order_data['affiliate_id'] = 0;
        $order_data['commission'] = 0;
        $order_data['marketing_id'] = 0;
        $order_data['tracking'] = '';

        $order_data['language_id'] = $this->config->get('config_language_id');
        $order_data['currency_id'] = $this->currency->getId($this->session->data['currency']);
        $order_data['currency_code'] = $this->session->data['currency'];
        $order_data['currency_value'] = $this->currency->getValue($this->session->data['currency']);
        $order_data['ip'] = $this->request->server['REMOTE_ADDR'];

        if (!empty($this->request->server['HTTP_X_FORWARDED_FOR'])) {
            $order_data['forwarded_ip'] = $this->request->server['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($this->request->server['HTTP_CLIENT_IP'])) {
            $order_data['forwarded_ip'] = $this->request->server['HTTP_CLIENT_IP'];
        } else {
            $order_data['forwarded_ip'] = '';
        }

        if (isset($this->request->server['HTTP_USER_AGENT'])) {
            $order_data['user_agent'] = $this->request->server['HTTP_USER_AGENT'];
        } else {
            $order_data['user_agent'] = '';
        }

        if (isset($this->request->server['HTTP_ACCEPT_LANGUAGE'])) {
            $order_data['accept_language'] = $this->request->server['HTTP_ACCEPT_LANGUAGE'];
        } else {
            $order_data['accept_language'] = '';
        }

        $this->load->model('checkout/order');

        return $this->model_checkout_order->addOrder($order_data);
    }
}