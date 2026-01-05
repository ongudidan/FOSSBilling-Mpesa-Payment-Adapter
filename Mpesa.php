<?php

/**
 * Copyright 2022-2025 FOSSBilling
 * SPDX-License-Identifier: Apache-2.0.
 *
 * @copyright FOSSBilling (https://www.fossbilling.org)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache-2.0
 */

use Mpesa\Mpesa as MpesaSDK;

class Payment_Adapter_Mpesa implements FOSSBilling\InjectionAwareInterface
{
    protected ?Pimple\Container $di = null;

    private MpesaSDK $mpesa;

    public function setDi(Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?Pimple\Container
    {
        return $this->di;
    }

    public function __construct(private $config)
    {
        // Validate required configuration
        if ($this->config['test_mode']) {
            if (!isset($this->config['test_consumer_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'M-Pesa', ':missing' => 'Test Consumer Key'], 4001);
            }
            if (!isset($this->config['test_consumer_secret'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'M-Pesa', ':missing' => 'Test Consumer Secret'], 4001);
            }
            if (!isset($this->config['test_shortcode'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'M-Pesa', ':missing' => 'Test Business ShortCode'], 4001);
            }
            if (!isset($this->config['test_passkey'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'M-Pesa', ':missing' => 'Test Lipa Na M-Pesa Passkey'], 4001);
            }
        } else {
            if (!isset($this->config['consumer_key'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'M-Pesa', ':missing' => 'Consumer Key'], 4001);
            }
            if (!isset($this->config['consumer_secret'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'M-Pesa', ':missing' => 'Consumer Secret'], 4001);
            }
            if (!isset($this->config['shortcode'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'M-Pesa', ':missing' => 'Business ShortCode'], 4001);
            }
            if (!isset($this->config['passkey'])) {
                throw new Payment_Exception('The ":pay_gateway" payment gateway is not fully configured. Please configure the :missing', [':pay_gateway' => 'M-Pesa', ':missing' => 'Lipa Na M-Pesa Passkey'], 4001);
            }
        }

        $this->mpesa = new MpesaSDK();
    }

    public static function getConfig()
    {
        return [
            'supports_one_time_payments' => true,
            'description' => 'M-Pesa is a mobile money service that allows you to receive payments from customers via STK Push (Lipa Na M-Pesa). Get your API credentials from Safaricom Daraja Portal.',
            'logo' => [
                'logo' => 'mpesa.png',
                'height' => '30px',
                'width' => '65px',
            ],
            'form' => [
                'consumer_key' => [
                    'text',
                    [
                        'label' => 'Live Consumer Key:',
                    ],
                ],
                'consumer_secret' => [
                    'text',
                    [
                        'label' => 'Live Consumer Secret:',
                    ],
                ],
                'shortcode' => [
                    'text',
                    [
                        'label' => 'Live Business ShortCode:',
                    ],
                ],
                'passkey' => [
                    'text',
                    [
                        'label' => 'Live Lipa Na M-Pesa Passkey:',
                    ],
                ],
                'test_consumer_key' => [
                    'text',
                    [
                        'label' => 'Test Consumer Key:',
                        'required' => false,
                    ],
                ],
                'test_consumer_secret' => [
                    'text',
                    [
                        'label' => 'Test Consumer Secret:',
                        'required' => false,
                    ],
                ],
                'test_shortcode' => [
                    'text',
                    [
                        'label' => 'Test Business ShortCode:',
                        'required' => false,
                    ],
                ],
                'test_passkey' => [
                    'text',
                    [
                        'label' => 'Test Lipa Na M-Pesa Passkey:',
                        'required' => false,
                    ],
                ],
            ],
        ];
    }

    public function getHtml($api_admin, $invoice_id, $subscription)
    {
        $invoiceModel = $this->di['db']->load('Invoice', $invoice_id);

        return $this->_generateForm($invoiceModel);
    }

    public function getInvoiceTitle(Model_Invoice $invoice)
    {
        $invoiceItems = $this->di['db']->getAll('SELECT title from invoice_item WHERE invoice_id = :invoice_id', [':invoice_id' => $invoice->id]);

        $params = [
            ':id' => sprintf('%05s', $invoice->nr),
            ':serie' => $invoice->serie,
            ':title' => $invoiceItems[0]['title'],
        ];
        $title = __trans('Payment for invoice :serie:id [:title]', $params);
        if ((is_countable($invoiceItems) ? count($invoiceItems) : 0) > 1) {
            $title = __trans('Payment for invoice :serie:id', $params);
        }

        return $title;
    }

    public function logError($errorMessage, Model_Transaction $tx)
    {
        $tx->txn_status = 'failed';
        $tx->error = $errorMessage;
        $tx->status = 'error';
        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        if (DEBUG) {
            error_log('M-Pesa Error: ' . $errorMessage);
        }

        throw new Exception($tx->error);
    }

    public function processTransaction($api_admin, $id, $data, $gateway_id)
    {
        $tx = $this->di['db']->getExistingModelById('Transaction', $id);

        // 0. Detect if this is a status check from the browser (polling)
        if (isset($data['get']['action']) && $data['get']['action'] == 'check') {
            $invoiceId = $data['get']['invoice_id'] ?? null;
            if ($invoiceId) {
                $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId);

                // Find the latest transaction for this invoice and gateway
                $txLast = $this->di['db']->findOne('Transaction', 'invoice_id = :invoice_id AND gateway_id = :gateway_id ORDER BY id DESC', [
                    ':invoice_id' => $invoice->id,
                    ':gateway_id' => $gateway_id
                ]);

                $response = [
                    'paid' => ($invoice->status == 'paid'),
                    'error' => null
                ];

                if ($txLast && $txLast->status == 'error') {
                    $response['error'] = $txLast->error ?? 'Transaction failed';
                }

                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
            }
            return;
        }

        // 1. Detect if this is an STK Initiation request from the browser
        if (isset($data['get']['action']) && $data['get']['action'] == 'initiate') {
            try {
                // Get JSON input from the raw post data
                $input = json_decode($data['http_raw_post_data'], true);
                if (!$input) {
                    throw new Exception('Invalid JSON input for STK initiation');
                }

                $phoneNumber = $input['phone_number'];
                $amount = $input['amount'];
                $invoiceId = $input['invoice_id'];

                // Load invoice to verify
                $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId);
                $invoiceService = $this->di['mod_service']('Invoice');
                $payableAmount = $invoiceService->getTotalWithTax($invoice);

                // Setup M-Pesa SDK for initiation
                $consumerKey = ($this->config['test_mode']) ? $this->config['test_consumer_key'] : $this->config['consumer_key'];
                $consumerSecret = ($this->config['test_mode']) ? $this->config['test_consumer_secret'] : $this->config['consumer_secret'];
                $shortcode = ($this->config['test_mode']) ? $this->config['test_shortcode'] : $this->config['shortcode'];
                $passkey = ($this->config['test_mode']) ? $this->config['test_passkey'] : $this->config['passkey'];
                $environment = ($this->config['test_mode']) ? 'sandbox' : 'live';

                $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
                $payGateway = $this->di['db']->getExistingModelById('PayGateway', $gateway_id);
                $callbackUrl = $payGatewayService->getCallbackUrl($payGateway, $invoice);

                // Run STK Push
                $response = $this->mpesa->STKPushSimulation([
                    'BusinessShortCode' => $shortcode,
                    'LipaNaMpesaPasskey' => $passkey,
                    'TransactionType' => 'CustomerPayBillOnline',
                    'Amount' => (int) $payableAmount,
                    'PartyA' => $phoneNumber,
                    'PartyB' => $shortcode,
                    'PhoneNumber' => $phoneNumber,
                    'CallBackURL' => $callbackUrl,
                    'AccountReference' => 'Invoice' . $invoice->id,
                    'TransactionDesc' => 'Payment for Invoice #' . $invoice->id,
                    'Remarks' => 'FOSSBilling Payment',
                    'environment' => $environment,
                    'consumer_key' => $consumerKey,
                    'consumer_secret' => $consumerSecret
                ]);

                $res = json_decode($response, true);

                // Log the initiation
                if (DEBUG) {
                    error_log('M-Pesa STK Initiation for Invoice #' . $invoiceId . ': ' . $response);
                }

                // If successful, we return the data to the browser
                if (isset($res['ResponseCode']) && $res['ResponseCode'] === '0') {
                    $tx->txn_status = 'initiated';
                    $tx->note = 'STK Push initiated for ' . $phoneNumber;
                    $this->di['db']->store($tx);

                    header('Content-Type: application/json');
                    echo $response;
                    exit;
                } else {
                    $errorMsg = $res['errorMessage'] ?? $res['ResponseDescription'] ?? 'STK Push failed';
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => $errorMsg]);
                    exit;
                }

            } catch (Exception $e) {
                if (DEBUG) {
                    error_log('M-Pesa Initiation Error: ' . $e->getMessage());
                }
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
                exit;
            }
        }

        // 2. Handle standard M-Pesa Callbacks (IPN)
        // Use the invoice ID associated with the transaction or else fallback to the ID passed via GET.
        if ($tx->invoice_id) {
            $invoice = $this->di['db']->getExistingModelById('Invoice', $tx->invoice_id);
        } else {
            $invoiceId = $data['get']['invoice_id'] ?? null;
            if (!$invoiceId) {
                $this->logError('Invoice ID missing in callback', $tx);
                return;
            }
            $invoice = $this->di['db']->getExistingModelById('Invoice', $invoiceId);
            $tx->invoice_id = $invoice->id;
        }

        // Get callback data from M-Pesa
        $callbackData = $this->mpesa->getDataFromCallback();
        if (!$callbackData) {
            // Check raw post data from ipn.php
            $callbackData = $data['http_raw_post_data'];
        }

        if (!$callbackData) {
            $this->logError('No callback data received from M-Pesa', $tx);
            return;
        }

        // Decode the callback data
        $callback = json_decode($callbackData, true);

        // Handle STK Push callback
        if (isset($callback['Body']['stkCallback'])) {
            $stkCallback = $callback['Body']['stkCallback'];
            $resultCode = $stkCallback['ResultCode'];
            $resultDesc = $stkCallback['ResultDesc'];

            $tx->txn_status = $resultDesc;

            // Check if payment was successful
            if ($resultCode == 0) {
                // Payment successful
                $callbackMetadata = $stkCallback['CallbackMetadata']['Item'];

                $amount = 0;
                $mpesaReceiptNumber = '';
                $phoneNumber = '';

                // Extract payment details
                foreach ($callbackMetadata as $item) {
                    if ($item['Name'] == 'Amount') {
                        $amount = $item['Value'];
                    }
                    if ($item['Name'] == 'MpesaReceiptNumber') {
                        $mpesaReceiptNumber = $item['Value'];
                    }
                    if ($item['Name'] == 'PhoneNumber') {
                        $phoneNumber = $item['Value'];
                    }
                }

                $tx->txn_id = $mpesaReceiptNumber;
                $tx->amount = $amount;
                $tx->currency = 'KES'; // M-Pesa uses Kenyan Shillings

                $bd = [
                    'amount' => $tx->amount,
                    'description' => 'M-Pesa transaction ' . $mpesaReceiptNumber,
                    'type' => 'transaction',
                    'rel_id' => $tx->id,
                ];

                // Only pay the invoice if the transaction hasn't been processed
                if ($tx->status !== 'processed') {
                    // Instance the services we need
                    $clientService = $this->di['mod_service']('client');
                    $invoiceService = $this->di['mod_service']('Invoice');

                    // Update the account balance
                    $client = $this->di['db']->getExistingModelById('Client', $invoice->client_id);
                    $clientService->addFunds($client, $bd['amount'], $bd['description'], $bd);

                    // Now pay the invoice / batch pay if there's no invoice associated with the transaction
                    if ($tx->invoice_id) {
                        // Check if this is a deposit invoice (wallet recharge)
                        $invoiceItems = $this->di['db']->find('InvoiceItem', 'invoice_id = :id', [':id' => $invoice->id]);
                        $isDeposit = false;
                        foreach ($invoiceItems as $item) {
                            if ($item->type == \Model_InvoiceItem::TYPE_DEPOSIT) {
                                $isDeposit = true;
                                break;
                            }
                        }

                        if ($isDeposit) {
                            $invoiceService->markAsPaid($invoice, false);
                        } else {
                            $invoiceService->payInvoiceWithCredits($invoice);
                        }
                    } else {
                        $invoiceService->doBatchPayWithCredits(['client_id' => $client->id]);
                    }

                    $tx->status = 'processed';
                }
            } else {
                // Payment failed
                $tx->error = $resultDesc;
                $tx->status = 'error';

                // Update invoice notes with failure reason
                if ($invoice) {
                    $note = "\nM-Pesa Selection: " . $resultDesc . " (at " . date('Y-m-d H:i:s') . ")";
                    $invoice->notes .= $note;
                    $this->di['db']->store($invoice);
                }
            }
        } else {
            $this->logError('Invalid callback format received from M-Pesa', $tx);
            return;
        }

        $tx->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($tx);

        // Send success response to M-Pesa
        $this->mpesa->finishTransaction();
    }

    protected function _generateForm(Model_Invoice $invoice): string
    {
        // Get configuration based on test mode
        $consumerKey = ($this->config['test_mode']) ? $this->config['test_consumer_key'] : $this->config['consumer_key'];
        $consumerSecret = ($this->config['test_mode']) ? $this->config['test_consumer_secret'] : $this->config['consumer_secret'];
        $shortcode = ($this->config['test_mode']) ? $this->config['test_shortcode'] : $this->config['shortcode'];
        $passkey = ($this->config['test_mode']) ? $this->config['test_passkey'] : $this->config['passkey'];
        $environment = ($this->config['test_mode']) ? 'sandbox' : 'live';

        $invoiceService = $this->di['mod_service']('Invoice');
        $amount = $invoiceService->getTotalWithTax($invoice);
        $title = $this->getInvoiceTitle($invoice);

        $payGatewayService = $this->di['mod_service']('Invoice', 'PayGateway');
        $payGateway = $this->di['db']->findOne('PayGateway', 'gateway = "Mpesa"');
        $callbackUrl = $payGatewayService->getCallbackUrl($payGateway, $invoice);

        // Prepare buyer phone number for the input field (remove 254 or leading 0 to show only the suffix)
        $buyerPhone = preg_replace('/[^0-9]/', '', $invoice->buyer_phone ?? '');
        if (substr($buyerPhone, 0, 3) === '254') {
            $buyerPhone = substr($buyerPhone, 3);
        } elseif (substr($buyerPhone, 0, 1) === '0') {
            $buyerPhone = substr($buyerPhone, 1);
        }

        $form = '<div class="mpesa-payment-form">
                <style>
                    .mpesa-payment-form {
                        max-width: 500px;
                        margin: 20px auto;
                        padding: 20px;
                        border: 1px solid #ddd;
                        border-radius: 8px;
                        background: #f9f9f9;
                        position: relative;
                    }
                    .mpesa-payment-form h4 {
                        color: #333;
                        margin-bottom: 15px;
                    }
                    .mpesa-payment-form .mpesa-logo {
                        text-align: center;
                        margin-bottom: 20px;
                    }
                    .mpesa-payment-form .form-group {
                        margin-bottom: 15px;
                    }
                    .mpesa-payment-form label {
                        display: block;
                        margin-bottom: 5px;
                        font-weight: bold;
                        color: #555;
                    }
                    .mpesa-payment-form .input-group {
                        display: flex;
                        align-items: center;
                    }
                    .mpesa-payment-form .input-group-prepend {
                        background: #eee;
                        border: 1px solid #ccc;
                        border-right: none;
                        padding: 0 15px;
                        border-radius: 4px 0 0 4px;
                        font-family: monospace;
                        font-weight: bold;
                        color: #555;
                        font-size: 16px;
                        height: 40px;
                        box-sizing: border-box;
                        display: flex;
                        align-items: center;
                        white-space: nowrap;
                    }
                    .mpesa-payment-form input[type="text"] {
                        flex: 1;
                        padding: 10px;
                        border: 1px solid #ccc;
                        border-radius: 0 4px 4px 0;
                        font-size: 16px;
                        height: 40px;
                        box-sizing: border-box;
                    }
                    .mpesa-payment-form .btn-mpesa {
                        width: 100%;
                        padding: 12px;
                        background: #00a651;
                        color: white;
                        border: none;
                        border-radius: 4px;
                        font-size: 16px;
                        font-weight: bold;
                        cursor: pointer;
                    }
                    .mpesa-payment-form .btn-mpesa:hover {
                        background: #008d43;
                    }
                    .mpesa-payment-form .btn-mpesa:disabled {
                        background: #ccc;
                        cursor: not-allowed;
                    }
                    .mpesa-payment-form .alert {
                        padding: 12px;
                        margin-bottom: 15px;
                        border-radius: 4px;
                    }
                    .mpesa-payment-form .alert-danger {
                        background: #f8d7da;
                        color: #721c24;
                        border: 1px solid #f5c6cb;
                    }
                    .mpesa-payment-form .alert-success {
                        background: #d4edda;
                        color: #155724;
                        border: 1px solid #c3e6cb;
                    }
                    .mpesa-payment-form .alert-info {
                        background: #d1ecf1;
                        color: #0c5460;
                        border: 1px solid #bee5eb;
                    }
                    .mpesa-payment-form .loading {
                        position: absolute;
                        top: 0;
                        left: 0;
                        right: 0;
                        bottom: 0;
                        background: rgba(255, 255, 255, 0.8);
                        display: flex;
                        flex-direction: column;
                        align-items: center;
                        justify-content: center;
                        z-index: 10;
                        border-radius: 8px;
                        text-align: center;
                    }
                    .mpesa-payment-form .spinner {
                        border: 4px solid #f3f3f3;
                        border-top: 4px solid #00a651;
                        border-radius: 50%;
                        width: 50px;
                        height: 50px;
                        animation: spin 1s linear infinite;
                        margin-bottom: 20px;
                    }
                    @keyframes spin {
                        0% { transform: rotate(0deg); }
                        100% { transform: rotate(360deg); }
                    }
                </style>

                <div class="mpesa-logo">
                    <h4>Pay with M-Pesa</h4>
                </div>

                <div id="mpesa-error-message" class="alert alert-danger" style="display:none;"></div>
                <div id="mpesa-success-message" class="alert alert-success" style="display:none;"></div>
                <div id="mpesa-info-message" class="alert alert-info" style="display:none;"></div>
                
                <div class="loading" id="mpesa-loading" style="display:none;">
                    <div class="spinner"></div>
                    <p>Processing payment...</p>
                </div>

                <div class="mpesa-form-content" id="mpesa-form-content">
                    <div class="form-group">
                        <label>Amount to Pay:</label>
                        <p style="font-size: 20px; font-weight: bold; color: #00a651;">KES :amount</p>
                    </div>

                    <div class="form-group">
                        <label>Description:</label>
                        <p>:description</p>
                    </div>
                    
                    <div class="form-group">
                        <label for="mpesa-phone">M-Pesa Phone Number:</label>
                        <div class="input-group">
                            <div class="input-group-prepend">254</div>
                            <input type="text" 
                                   id="mpesa-phone" 
                                   class="form-control" 
                                   placeholder="712345678" 
                                   value=":buyer_phone"
                                   maxlength="10"
                                   required>
                        </div>
                        <small class="form-text text-muted">Enter phone number (e.g. 07XXXXXXXX or 7XXXXXXXX)</small>
                    </div>

                    <button id="mpesa-submit" class="btn-mpesa">
                        Pay with M-Pesa
                    </button>
                </div>

                <script>
                    (function() {
                        const submitBtn = document.getElementById(\'mpesa-submit\');
                        const phoneInput = document.getElementById(\'mpesa-phone\');
                        const errorDiv = document.getElementById(\'mpesa-error-message\');
                        const successDiv = document.getElementById(\'mpesa-success-message\');
                        const infoDiv = document.getElementById(\'mpesa-info-message\');
                        const loadingDiv = document.getElementById(\'mpesa-loading\');
                        const formContent = document.getElementById(\'mpesa-form-content\');

                        function showError(message) {
                            errorDiv.textContent = message;
                            errorDiv.style.display = \'block\';
                            successDiv.style.display = \'none\';
                            infoDiv.style.display = \'none\';
                        }

                        function showSuccess(message) {
                            successDiv.textContent = message;
                            successDiv.style.display = \'block\';
                            errorDiv.style.display = \'none\';
                            infoDiv.style.display = \'none\';
                        }

                        function showInfo(message) {
                            infoDiv.textContent = message;
                            infoDiv.style.display = \'block\';
                            errorDiv.style.display = \'none\';
                            successDiv.style.display = \'none\';
                        }

                        function hideMessages() {
                            errorDiv.style.display = \'none\';
                            successDiv.style.display = \'none\';
                            infoDiv.style.display = \'none\';
                        }

                        submitBtn.addEventListener(\'click\', function(e) {
                            e.preventDefault();
                            
                            let inputPhone = phoneInput.value.trim();

                            // Remove leading 0 if present
                            if (inputPhone.startsWith(\'0\')) {
                                inputPhone = inputPhone.substring(1);
                            }
                            
                            // Combine with 254 prefix
                            const phoneNumber = \'254\' + inputPhone;
                            
                            // Validate phone number (254 + 9 digits)
                            const phoneRegex = /^254[0-9]{9}$/;
                            if (!phoneRegex.test(phoneNumber)) {
                                showError(\'Please enter a valid M-Pesa phone number.\');
                                return;
                            }
                            
                            // Show loading overlay
                            hideMessages();
                            loadingDiv.style.display = \'flex\';
                            submitBtn.disabled = true;
                            
                            // Initiate STK Push
                            fetch(\':stkInitUrl\', {
                                method: \'POST\',
                                headers: {
                                    \'Content-Type\': \'application/json\',
                                },
                                body: JSON.stringify({
                                    phone_number: phoneNumber,
                                    amount: :raw_amount,
                                    invoice_id: :invoice_id,
                                    gateway_id: :gateway_id
                                })
                            })
                            .then(response => response.json())
                            .then(data => {
                                loadingDiv.style.display = \'none\';
                                submitBtn.disabled = false;
                                
                                if (data.ResponseCode === \'0\' || data.success) {
                                    showSuccess(\'Payment request sent successfully!\');
                                    showInfo(\'Please check your phone and enter your M-Pesa PIN to complete the payment. You will be redirected once payment is confirmed.\');
                                    
                                    // Update loading message
                                    document.querySelector(\'.loading p\').textContent = \'Waiting for PIN entry...\';
                                    loadingDiv.style.display = \'flex\';

                                    // Poll for status every 3 seconds
                                    const pollInterval = setInterval(function() {
                                        fetch(\':stkInitUrl\'.replace(\'action=initiate\', \'action=check\'))
                                        .then(response => response.json())
                                        .then(checkData => {
                                            if (checkData.paid) {
                                                clearInterval(pollInterval);
                                                showSuccess(\'Payment confirmed! Redirecting...\');
                                                window.location.href = \':redirectUrl\';
                                            } else if (checkData.error) {
                                                clearInterval(pollInterval);
                                                loadingDiv.style.display = \'none\';
                                                showError(checkData.error);
                                            }
                                        })
                                        .catch(err => console.error(\'Polling error:\', err));
                                    }, 3000);

                                    // Stop polling after 60 seconds (failsafe)
                                    setTimeout(function() {
                                        if (loadingDiv.style.display !== \'none\') {
                                            clearInterval(pollInterval);
                                            loadingDiv.style.display = \'none\';
                                            showError(\'Payment session timed out. If you already entered your PIN, please wait a moment for the account to update.\');
                                        }
                                    }, 60000);
                                } else {
                                    const errorMsg = data.errorMessage || data.ResponseDescription || data.error || \'Failed to initiate payment. Please try again.\';
                                    showError(errorMsg);
                                }
                            })
                            .catch(error => {
                                loadingDiv.style.display = \'none\';
                                formContent.style.display = \'block\';
                                submitBtn.disabled = false;
                                showError(\'An error occurred while processing your payment. Please try again.\');
                                console.error(\'Error:\', error);
                            });
                        });
                    })();
                </script>
            </div>';

        $bindings = [
            ':amount' => number_format($amount, 2),
            ':raw_amount' => $amount,
            ':description' => htmlspecialchars($title),
            ':buyer_phone' => $buyerPhone,
            ':shortcode' => $shortcode,
            ':passkey' => $passkey,
            ':callbackUrl' => $callbackUrl,
            ':stkInitUrl' => SYSTEM_URL . 'ipn.php?gateway_id=' . $payGateway->id . '&invoice_id=' . $invoice->id . '&action=initiate',
            ':invoice_id' => $invoice->id,
            ':gateway_id' => $payGateway->id,
            ':environment' => $environment,
            ':consumer_key' => $consumerKey,
            ':consumer_secret' => $consumerSecret,
            ':redirectUrl' => $this->di['tools']->url('invoice/' . $invoice->hash),
        ];

        return strtr($form, $bindings);
    }
}
