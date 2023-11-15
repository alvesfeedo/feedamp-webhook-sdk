<?php

namespace FeedonomicsWebHookSDK\services;

use FeedonomicsWebHookSDK\services\ConversionUtils;


class ShopifyClient
{

    const MARKETPLACE_NAME = 'Marketplace Name';
    const MARKETPLACE_NAME_AND_ORDER_NUMBER = 'Marketplace Name + Marketplace Order Number';
    const MARKETPLACE_ORDER_NUMBER = 'Marketplace Order Number';
    const MARKETPLACE_FULFILL_PLACE_HOLDER = 'MARKETPLACE FULFILLED - DO NOT FULFILL';

    const SOURCE_NAME_VALUES = [
        self::MARKETPLACE_NAME,
        self::MARKETPLACE_NAME_AND_ORDER_NUMBER,
        self::MARKETPLACE_ORDER_NUMBER
    ];


    /**
     * @param array $order
     * @param array $config
     * @return array
     */
    public function generate_place_order_payload(array $order, array $config)
    {
        $customer_email = $order['customer_email'] ?? '';
        if (empty($customer_email)) {
            $filtered_customer_name = preg_replace("/[^A-Za-z]/", "", $order['shipping_full_name']);
            $customer_email = $filtered_customer_name . "_" . $config['dummy_customer_email'];
        }

        $marketing_opt_in = filter_var($order['marketing_opt_in'] ?? null, FILTER_VALIDATE_BOOLEAN);

        $customer_phone = $this->format_phone_number($order['customer_phone'] ?? '');
        $shipping_phone = $order['shipping_phone'] ?? '';

        $marketplace_fulfilled = $order['marketplace_fulfilled'] ?? false;

        $note_attributes = [];
        $order_note = "Marketplace: {$order['marketplace_name']}" . PHP_EOL . "Order Number: {$order['mp_order_number']}";
        $note_attributes['Marketplace'] = $order['marketplace_name'];
        $note_attributes['Order Number'] = $order['mp_order_number'];

        if ($order['is_amazon_prime'] ?? false) {
            $amazon_prime_string = $order['is_amazon_prime'] ? 'True' : 'False';
            $order_note .= PHP_EOL . "Amazon Prime: {$amazon_prime_string}";
            $note_attributes['Amazon Prime'] = $amazon_prime_string;
        }
        if ($config['add_customer_order_number_to_notes'] || $config['use_note_attributes']) {
            $customer_order_number = $order['customer_order_number'] ?? '';
            $order_note .= PHP_EOL . "Customer Order Number: {$customer_order_number}";

            if ($customer_order_number || $config['add_empty_info']) {
                $note_attributes['Customer Order Number'] = $order['customer_order_number'];
            }
        }
        if ($config['add_delivery_notes_to_notes'] || $config['use_note_attributes']) {
            $delivery_note = $order['delivery_notes'] ?? '';
            $order_note .= PHP_EOL . "Delivery Notes: {$delivery_note}";

            if ($delivery_note || $config['add_empty_info']) {
                $note_attributes['Delivery Notes'] = $order['delivery_notes'];
            }
        }

        if ($config['include_marketplace_promo_note'] || $config['use_note_attributes']) {
            $marketplace_promotion_amount = $order['marketplace_promotion_amount'] ?? 0.00;
            $marketplace_promotion_name = $order['marketplace_promotion_name'] ?? '';
            $order_note .= PHP_EOL . 'Marketplace Sponsored Discount: ' . $marketplace_promotion_name . ' $' . $marketplace_promotion_amount;

            if ($marketplace_promotion_amount || $config['add_empty_info']) {
                $note_attributes['Marketplace Sponsored Discount'] = $marketplace_promotion_name . ' $' . $marketplace_promotion_amount;
            }
        }

        switch ($config['source_name_format']) {
            case self::MARKETPLACE_NAME:
                $source_name = $order['marketplace_name'];
                break;
            case self::MARKETPLACE_ORDER_NUMBER:
                $source_name = $order['mp_order_number'];
                break;
            case self::MARKETPLACE_NAME_AND_ORDER_NUMBER:
                $source_name = $order['marketplace_name'] . ' ' . $order['mp_order_number'];
                break;
        }

        if ($marketplace_fulfilled) {
            $order_note .= PHP_EOL . self::MARKETPLACE_FULFILL_PLACE_HOLDER;
            $note_attributes['Marketplace Fulfilled'] = self::MARKETPLACE_FULFILL_PLACE_HOLDER;
            $address2 = '';
            $customer_phone = '';
            $shipping_phone = "";
            $customer_email = "MARKETPLACE_FULFILLED" . "_" . $config['dummy_customer_email'];
        } else {
            $address2 = empty($order['shipping_address3'])
                ? $order['shipping_address2']
                : "{$order['shipping_address2']}, {$order['shipping_address3']}";
        }
        $shopify_order = [
            'currency' => $order['currency'] ?? '',
            'email' => $customer_email,
            'buyer_accepts_marketing' => $marketing_opt_in,
            'phone' => $customer_phone,
            'note' => $order_note,
            'shipping_address' => [
                'address1' => $marketplace_fulfilled ? self::MARKETPLACE_FULFILL_PLACE_HOLDER : $order['shipping_address1'],
                'address2' => $address2,
                'city' => $marketplace_fulfilled ? '' : $order['shipping_city'],
                'phone' => $shipping_phone,
                'zip' => $order['shipping_postal_code'],
                'province_code' => ConversionUtils::convert_usa_state_to_2_chars($order['shipping_state']),
                'country_code' => ConversionUtils::convert_country_code_to_ISO2($order['shipping_country_code']),
                'name' => $marketplace_fulfilled ? self::MARKETPLACE_FULFILL_PLACE_HOLDER : $order['shipping_full_name'],
            ],
            'source_name' => $source_name,

            // The 3 parameters below prevent Shopify from sending out their own notifications
            'send_receipt' => false,
            'send_fulfillment_receipt' => false,
            'suppress_notifications' => true,

            'line_items' => [],
            'shipping_lines' => [],
        ];
        if ($config['use_note_attributes']) {
            $shopify_order['note'] = '';
            $shopify_order['note_attributes'] = $note_attributes;
        }

        $order_has_complete_billing_info = $this->is_order_billing_info_complete($order);
        if ($order_has_complete_billing_info) {
            $shopify_order['billing_address'] = [
                'address1' => $marketplace_fulfilled ? self::MARKETPLACE_FULFILL_PLACE_HOLDER : $order['billing_address1'],
                'city' => $marketplace_fulfilled ? '' : $order['billing_city'],
                'phone' => $order['billing_phone'] ?? '',
                'zip' => $order['billing_postal_code'],
                'province_code' => ConversionUtils::convert_usa_state_to_2_chars($order['billing_state']),
                'country_code' => ConversionUtils::convert_country_code_to_ISO2($order['billing_country_code']),
                'name' => $marketplace_fulfilled ? self::MARKETPLACE_FULFILL_PLACE_HOLDER : $order['billing_full_name'],
            ];

            if ($marketplace_fulfilled) {
                $billing_address_line_2 = '';
            } else {
                $billing_address_line_2 = $order['billing_address3'] && $order['billing_address2'] ?
                    "{$order['billing_address2']}, {$order['billing_address3']}" :
                    ($order['billing_address2'] ?? null);
            }

            if ($billing_address_line_2) {
                $shopify_order['billing_address']['address2'] = $billing_address_line_2;
            }
        }

        if ($order['order_tags'] ?? '') {
            $shopify_order['tags'] = $order['order_tags'];
        }

        if ($order['customer_tags'] ?? '') {
            $shopify_order['customer'] = ['tags' => $order['customer_tags']];
        }

        $total_tax = 0.0;
        $total_amount = 0.0;
        $total_shipping_cost = 0.0;
        $total_shipping_tax = 0.0;
        $total_discounts = 0.0;
        $total_shipping_discounts = 0.0;
        $discount_names = [];
        $shipping_discount_names = [];

        foreach ($order['order_lines'] as $order_line) {
            $quantity = isset($order_line['quantity']) ? (int)$order_line['quantity'] : 0;
            $unit_price = isset($order_line['unit_price']) ? (float)$order_line['unit_price'] : 0.0;
            $sales_tax = isset($order_line['sales_tax']) ? (float)$order_line['sales_tax'] : 0.0;

            $taxable = isset($order_line['sales_tax']) && (float)$order_line['sales_tax'] > 0;

            $total_amount += $unit_price * $quantity;
            $total_amount += $sales_tax;

            $line_item = [
                'price' => $unit_price,
                'requires_shipping' => true,
                'quantity' => $quantity,
                'variant_id' => $order_line['sku'],
                'taxable' => $taxable,
            ];

            if ($marketplace_fulfilled) {
                $line_item['requires_shipping'] = false;
                $line_item['fulfillment_status'] = 'fulfilled';
            }
            if ($config['use_mp_product_name']) {
                $line_item['title'] = $order_line['product_name'];
            }

            if ($taxable) {
                $tax_rate = ($unit_price > 0) && ($quantity > 0)
                    ? $sales_tax / $quantity / $unit_price
                    : 0.0;

                $tax_line = [
                    'price' => $sales_tax,
                    'title' => 'Sales Tax',
                    'rate' => (float)number_format($tax_rate, 4, '.', ''),
                ];

                $line_item['tax_lines'] = [
                    $tax_line
                ];

                $total_tax += $sales_tax;
            }

            $shopify_order['line_items'][] = $line_item;

            $shipping_method = $order_line['shipping_method'] ?? '';
            $shipping_method_mapped = $config['shipping_method_map'][$shipping_method] ?? $shipping_method;

            $shipping_taxable = isset($order_line['shipping_tax']) && (float)$order_line['shipping_tax'] > 0.0;
            $shipping_tax = isset($order_line['shipping_tax']) ? (float)$order_line['shipping_tax'] : 0.0;
            $shipping_price = isset($order_line['shipping_price']) ? (float)$order_line['shipping_price'] : 0.0;

            $total_amount += $shipping_price;
            $total_amount += $shipping_tax;

            $discount_exists = isset($order_line['discount']) && (float)($order_line['discount']) != 0;
            if ($discount_exists) {
                $discount = abs((float)$order_line['discount']) ?? 0.0;
                $total_amount -= $discount;
                $total_discounts += $discount;
                $discount_name = $order_line['discount_name'] ?? 'discount';
                $discount_names[$discount_name] = true;
            }

            $shipping_discount_exists = isset($order_line['shipping_discount']) && (float)($order_line['shipping_discount']) != 0;
            if ($shipping_discount_exists) {
                $shipping_discount = abs((float)$order_line['shipping_discount']) ?? 0.0;
                $total_amount -= $shipping_discount;
                $total_shipping_discounts += $shipping_discount;
                $shipping_discount_name = $order_line['shipping_discount_name'] ?? 'shipping_discount';
                $shipping_discount_names[$shipping_discount_name] = true;

                if ($config['deduct_shipping_discount_from_shipping_price']) {
                    $shipping_price -= $shipping_discount;
                }
            }

            $total_shipping_cost += $shipping_price;

            $shipping_line = [
                'code' => $shipping_method_mapped,
                'price' => $shipping_price,
                'title' => $shipping_method_mapped,
            ];

            if ($shipping_taxable) {
                $shipping_tax_rate = $shipping_price > 0
                    ? $shipping_tax / $shipping_price
                    : 0.0;

                $shipping_line['tax_lines'] = [
                    [
                        'price' => $shipping_tax,
                        'title' => 'Sales Tax',
                        'rate' => (float)number_format($shipping_tax_rate, 4, '.', ''),
                    ]
                ];

                $total_shipping_tax += $shipping_tax;
                $total_tax += $shipping_tax;
            }

            $shopify_order['shipping_lines'][] = $shipping_line;
        }

        if (!$config['deduct_shipping_discount_from_shipping_price']) {
            $total_discounts += $total_shipping_discounts;
            $discount_names += $shipping_discount_names;
        }

        if ($total_discounts >= 0.01) {
            $discount_code = implode(', ', array_keys($discount_names));
            $shopify_order['discount_codes'] = [[
                'amount' => $total_discounts,
                'code' => $discount_code
            ]];
        }

        if ($config['aggregate_shipping_lines']) {
            $total_shipping_line = [
                'code' => $shopify_order['shipping_lines'][0]['code'],
                'price' => $total_shipping_cost,
                'title' => $shopify_order['shipping_lines'][0]['title'],
            ];

            if ($total_tax > 0.0) {
                $total_shipping_tax_line = [
                    'price' => $total_shipping_tax,
                    'title' => 'Sales Tax',
                    'rate' => 0.0,
                ];

                $total_excluding_shipping_and_tax = $total_amount - $total_shipping_cost - $total_tax;
                $tax_excluding_shipping_tax = $total_tax - $total_shipping_tax;
                $sales_tax_rate = ($total_excluding_shipping_and_tax > 0.0) ?
                    (float)number_format($tax_excluding_shipping_tax / $total_excluding_shipping_and_tax, 4, '.', '') :
                    0.0;

                if (!empty($total_shipping_tax_line['price'])) {
                    $shipping_tax_rate = ($total_shipping_line['price'] > 0.0) ?
                        (float)number_format($total_shipping_tax_line['price'] / $total_shipping_line['price'], 4, '.', '') :
                        0.0;

                    if (abs($shipping_tax_rate - $sales_tax_rate) < 0.01) {
                        $tax_rate = $sales_tax_rate;
                    } else {
                        $tax_rate = $shipping_tax_rate;
                    }


                    $total_shipping_tax_line['rate'] = $tax_rate;

                    $total_shipping_line['tax_lines'][] = $total_shipping_tax_line;
                }

                foreach ($shopify_order['line_items'] as $key => $line_item) {
                    $line_item_tax_rate = isset($line_item['tax_lines']) ?
                        $line_item['tax_lines'][0]['rate'] :
                        0.0;
                    if (abs($line_item_tax_rate - $sales_tax_rate) < 0.01) {
                        $shopify_order['line_items'][$key]['tax_lines'][0]['rate'] = $sales_tax_rate;
                    }
                }
            }

            unset($shopify_order['shipping_lines']);
            $shopify_order['shipping_lines'] = [
                $total_shipping_line
            ];

        }

        $shopify_order['total_tax'] = number_format($total_tax, 2, '.', '');

        $using_transactions = $config['transactions'] === true;
        $total_is_zero = $total_amount == 0;
        $currency_not_set = $shopify_order['currency'] == '';

        // Shopify forbids transactions with a 0.00 amount
        if ($using_transactions && !$total_is_zero) {
            $shopify_order['transactions'] = [
                [
                    'amount' => (float)number_format($total_amount, 2, '.', ''),
                    'kind' => 'sale',
                    'status' => 'success',
                    'currency' => $order['currency'],
                    'gateway' => $config['transaction_gateway'],
                ],
            ];
        }

        // Shopify requires a currency for orders, but some marketplaces (e.g. Amazon) don't set currency if total is 0
        // Don't apply default currency if there was an actual total - could be bad data
        if ($total_is_zero && $currency_not_set) {
            $shopify_order['currency'] = $config['default_currency'];
        }

        $shopify_order['inventory_behaviour'] = $marketplace_fulfilled ? 'bypass' : 'decrement_obeying_policy';
        return $shopify_order;
    }

    /**
     * @param string $number
     * @return string
     */
    private function format_phone_number($number)
    {
        if (is_null($number)) {
            return $number;
        }

        // Take out Extension
        $number = preg_replace("/(\s|)ex.*/", '', $number);
        $number = preg_replace('/[^\d]/', '', $number);

        if (preg_match('/(([0-9])(\d{3})|(^\d{3}))?(\d{3})(\d{4})\d*/', $number, $matches)) {
            if (!$matches[4]) {
                $number = $matches[2] . $matches[3] . '-' . $matches[5] . '-' . $matches[6];
            } else {
                $number = $matches[4] . '-' . $matches[5] . '-' . $matches[6];
            }
        }

        if ($number == '000-000-0000') {
            $number = '';
        }

        return $number;
    }

    /**
     * @param array $order
     * @return bool
     */
    private function is_order_billing_info_complete(array $order)
    {
        // If any one of these is empty, order doesn't have full billing info
        return !(
            empty($order['billing_full_name'])
            || empty($order['billing_address1'])
            || empty($order['billing_city'])
            || empty($order['billing_state'])
            || empty($order['billing_postal_code'])
            || empty($order['billing_country_code'])
        );
    }

    /**
     * @param $store_id
     * @param $access_token
     * @param $client
     * @param $payload
     * @return array
     */
    public function place_order($store_id, $access_token, $client, $payload )
    {
        $url = "https://{$store_id}.myshopify.com/admin'/api/2023-01/orders.json";
        $headers = [
            'Content-Type' => 'application/json',
            'X-Shopify-Access-Token' => $access_token
        ];
        $options = [
            'headers' => $headers,
            'json' => $payload
        ];
        try {
            $client_response = $client->post($url, $options);
            $response_data = ChannelResponse::generate_successful_response($client_response);
        } catch (\Exception $e) {
            $response_data = ChannelResponse::generate_error_response($e);
        }
        return $response_data;
    }

    public function is_error_response($response)
    {
        $http_response = $response['response_code'] ?? 0;
        return $http_response < 200 || $http_response > 300;
    }

    /**
     * @param array $configs
     * @return array
     */
    public function get_place_order_configs(?array $configs){
        $defaults = [
            'add_delivery_notes_to_notes' => false,
            'add_empty_info' => false,
            'aggregate_shipping_lines' => false,
            'add_customer_order_number_to_notes' => false,
            'deduct_shipping_discount_from_shipping_price' => false,
            'default_currency' => 'USD',
            'dummy_customer_email' => 'dummy_customer_override@example.com',
            'include_marketplace_promo_note' => false,
            'shipping_method_map' => [],
            'source_name_format' => 'Marketplace Name + Marketplace Order Number',
            'transaction_gateway' => '',
            'transactions' => false,
            'use_mp_product_name' => true,
            'use_note_attributes' => false
        ];
        if(!$configs) {
            return $defaults;
        }
        return array_merge($defaults, $configs);
    }

}