<?php

/**
 * TwittPay - FOSSBilling payment adapter
 * ---------------------------------------------------------------------------
 * getHtml()             creates the payment and shows the Pay button
 * processTransaction()  runs on the IPN URL, for both the webhook and the return
 *
 * Every payment is verified against the API before an invoice is touched. The
 * return URL carries a status, but anybody can type a URL, so it is never
 * believed on its own.
 *
 * @version 1.0.0
 */
class Payment_Adapter_TwittPay extends Payment_AdapterAbstract implements \FOSSBilling\InjectionAwareInterface
{
    private $config = [];

    protected ?\Pimple\Container $di;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function __construct($config)
    {
        $this->config = $config;

        if (empty($this->config['api_key'])) {
            throw new Payment_Exception(
                'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                [':pay_gateway' => 'TwittPay', ':missing' => 'Brand Key']
            );
        }

        if (empty($this->config['api_url'])) {
            throw new Payment_Exception(
                'The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing',
                [':pay_gateway' => 'TwittPay', ':missing' => 'Endpoint URL']
            );
        }

        if (empty($this->config['currency_rate'])) {
            $this->config['currency_rate'] = 120;
        }
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'supports_subscriptions'     => false,
            'description'                => 'Accept bKash, Nagad, Rocket, Upay and card payments through your own TwittPay gateway.',
            'logo'                       => [
                'logo'   => 'twittpay/logo.png',
                'height' => '50px',
                'width'  => '50px',
            ],
            'form' => [
                'api_url' => [
                    'text', [
                        'label'       => 'Endpoint URL:',
                        'description' => 'Your own gateway address, for example https://checkout.twittpay.com',
                        'required'    => true,
                    ],
                ],
                'api_key' => [
                    'text', [
                        'label'       => 'Brand Key:',
                        'description' => 'From your gateway dashboard, under Brands.',
                        'required'    => true,
                    ],
                ],
                'currency_rate' => [
                    'text', [
                        'label'       => 'USD to BDT Rate:',
                        'description' => 'Used only when the invoice is not already in BDT. 1 USD = this many BDT.',
                        'required'    => false,
                        'value'       => '120',
                    ],
                ],
            ],
        ];
    }

    /**
     * Shown on the invoice. The payment is created here, so the customer only has
     * one click left to make.
     */
    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoice    = $api_admin->invoice_get(['id' => $invoice_id]);
        $paymentUrl = $this->createPayment($invoice);

        return $this->paymentForm($paymentUrl);
    }

    /**
     * The IPN endpoint. Called by the gateway's server, and also when the customer
     * comes back if FOSSBilling routes them through it.
     */
    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $transactionId = $this->transactionIdFrom($data);

        if ($transactionId === '') {
            throw new Payment_Exception('No transaction id received.');
        }

        $payment = $this->verifyPayment($transactionId);
        $status  = (isset($payment['status']) && is_string($payment['status']))
            ? strtoupper(trim($payment['status']))
            : '';

        if ($status === 'PENDING') {
            // The money has been sent and the merchant has not approved it yet.
            // The gateway calls this URL again with the answer, so the invoice is
            // left unpaid rather than marked failed.
            throw new Payment_Exception('The payment is being checked and will be applied once it clears.');
        }

        if ($status !== 'COMPLETED') {
            throw new Payment_Exception('Payment not completed.');
        }

        $meta = $this->decodeMetadata($payment);

        if (empty($meta['invoiceid'])) {
            throw new Payment_Exception('This payment carries no invoice reference.');
        }

        $invoice     = $this->di['db']->getExistingModelById('Invoice', $meta['invoiceid'], 'Invoice not found');
        $transaction = $this->di['db']->getExistingModelById('Transaction', $id, 'Transaction not found');

        // Record the invoice's own amount and currency, not the converted BDT.
        $amount   = isset($meta['invoice_amount']) ? $meta['invoice_amount'] : ($payment['amount'] ?? 0);
        $currency = !empty($meta['invoice_currency']) ? $meta['invoice_currency'] : 'BDT';
        $method   = !empty($payment['payment_method']) ? $payment['payment_method'] : 'TwittPay';

        $tx_data = [
            'id'          => $id,
            'invoice_id'  => $invoice->id,
            'txn_status'  => $status,
            'txn_id'      => $transactionId,
            'amount'      => $amount,
            'currency'    => $currency,
            'type'        => $method,
            'status'      => 'complete',
        ];

        $transactionService = $this->di['mod_service']('Invoice', 'Transaction');
        $transactionService->update($transaction, $tx_data);

        $bd = [
            'amount'      => $amount,
            'description' => $method . ' Transaction ID: ' . $transactionId,
            'type'        => 'transaction',
            'rel_id'      => $transaction->id,
        ];

        $client        = $this->di['db']->getExistingModelById('Client', $invoice->client_id, 'Client not found');
        $clientService = $this->di['mod_service']('client');
        $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

        $invoiceService = $this->di['mod_service']('Invoice');
        $invoiceService->payInvoiceWithCredits($invoice);
        $invoiceService->doBatchPayWithCredits(['client_id' => $invoice->client_id]);

        return true;
    }

    /** Create the payment and return the URL the customer has to be sent to. */
    private function createPayment($invoice)
    {
        $client   = $invoice['client'];
        $currency = strtoupper(trim((string) ($invoice['currency'] ?? 'BDT')));
        $total    = (float) $invoice['total'];

        // The public invoice page, by hash where FOSSBilling gives us one.
        $invoiceUrl = $this->di['tools']->url(
            'invoice/' . (!empty($invoice['hash']) ? $invoice['hash'] : $invoice['id'])
        );

        $data = [
            'cus_name'    => trim(($client['first_name'] ?? '') . ' ' . ($client['last_name'] ?? '')),
            'cus_email'   => !empty($client['email']) ? $client['email'] : 'default@gmail.com',
            'amount'      => number_format($this->toBdt($total, $currency), 2, '.', ''),
            'success_url' => $invoiceUrl,
            'cancel_url'  => !empty($this->config['cancel_url']) ? $this->config['cancel_url'] : $invoiceUrl,
            'webhook_url' => $this->config['notify_url'],
            'metadata'    => [
                'invoiceid'        => (string) $invoice['id'],
                'invoice_amount'   => number_format($total, 2, '.', ''),
                'invoice_currency' => $currency,
                'source'           => 'fossbilling',
            ],
        ];

        $response = $this->apiCall('/api/payment/create', $data);

        if (!empty($response['status']) && !empty($response['payment_url'])) {
            return $response['payment_url'];
        }

        throw new Payment_Exception('Failed to create payment: ' . ($response['message'] ?? 'unknown error'));
    }

    private function verifyPayment($transactionId)
    {
        $response = $this->apiCall('/api/payment/verify', ['transaction_id' => $transactionId]);

        // A miss answers status 0, a number. Only a real payment carries text.
        if (!isset($response['status']) || !is_string($response['status'])) {
            throw new Payment_Exception('The gateway does not know this transaction.');
        }

        return $response;
    }

    private function paymentForm($paymentUrl)
    {
        $url = htmlspecialchars($paymentUrl, ENT_QUOTES, 'UTF-8');

        $form = '<form action="' . $url . '" method="GET" id="payment_form">';
        $form .= '<input class="bb-button bb-button-submit" type="submit" value="Pay Now" id="payment_button"/>';
        $form .= '</form>';

        if (!empty($this->config['auto_redirect'])) {
            $form .= '<script>document.getElementById("payment_form").submit();</script>';
        }

        return $form;
    }

    /**
     * The id can arrive on the URL, in the webhook's form body, or in a JSON body.
     * FOSSBilling hands the request over in $data['get'] and $data['post'].
     */
    private function transactionIdFrom($data)
    {
        foreach (['get', 'post'] as $bag) {
            if (empty($data[$bag]) || !is_array($data[$bag])) {
                continue;
            }

            foreach (['transactionId', 'transaction_id'] as $key) {
                if (!empty($data[$bag][$key])) {
                    return trim((string) $data[$bag][$key]);
                }
            }
        }

        $raw = file_get_contents('php://input');

        if (!empty($raw)) {
            $body = json_decode($raw, true);

            if (is_array($body)) {
                foreach (['transactionId', 'transaction_id'] as $key) {
                    if (!empty($body[$key])) {
                        return trim((string) $body[$key]);
                    }
                }
            }
        }

        return '';
    }

    /** metadata comes back from verify as a JSON string. */
    private function decodeMetadata($verified)
    {
        if (!is_array($verified) || !isset($verified['metadata'])) {
            return [];
        }

        $meta = $verified['metadata'];

        if (is_array($meta)) {
            return $meta;
        }

        if (is_object($meta)) {
            return (array) $meta;
        }

        if (is_string($meta) && $meta !== '') {
            $decoded = json_decode($meta, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** The gateway charges BDT. Anything else is converted with the set rate. */
    private function toBdt($amount, $currency)
    {
        if ($currency === 'BDT') {
            return (float) $amount;
        }

        $rate = (float) $this->config['currency_rate'];

        if ($rate <= 0) {
            $rate = 1;
        }

        return (float) $amount * $rate;
    }

    /**
     * Scheme and host of the configured endpoint. Pasting the whole endpoint or a
     * trailing /api still works.
     */
    private function baseUrl()
    {
        $raw    = rtrim(trim((string) $this->config['api_url']), '/');
        $scheme = parse_url($raw, PHP_URL_SCHEME);
        $host   = parse_url($raw, PHP_URL_HOST);

        if (empty($host)) {
            $host = strtok(ltrim(preg_replace('#^[a-z]+://#i', '', $raw), '/'), '/');
        }

        if (empty($scheme)) {
            $scheme = 'https';
        }

        if (empty($host)) { $host = 'checkout.twittpay.com'; }
        return 'https://' . $host;
    }

    /** One POST to the API. JSON in, array out. */
    private function apiCall($endpoint, $payload)
    {
        // metadata has to arrive as a JSON object; a PHP list would encode as an
        // array and be rejected.
        if (isset($payload['metadata'])) {
            $payload['metadata'] = (object) $payload['metadata'];
        }

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->baseUrl() . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
                'Content-Type: application/json',
                'API-KEY: ' . trim((string) $this->config['api_key']),
            ],
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            throw new Payment_Exception('cURL error: ' . $error);
        }

        $result = json_decode($response, true);

        if (!is_array($result)) {
            throw new Payment_Exception('The gateway sent back something that is not JSON.');
        }

        return $result;
    }
}
