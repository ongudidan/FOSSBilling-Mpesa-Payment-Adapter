# FOSSBilling M-Pesa Payment Adapter

A robust, single-file payment adapter for [FOSSBilling](https://fossbilling.org/) that enables Safaricom M-Pesa STK Push (Lipa Na M-Pesa) payments.

## Features

-   **Consolidated Implementation**: Handles both STK Push initiation and callbacks within a single file.
-   **No External Endpoints**: Uses FOSSBilling's standard `ipn.php` for all communication.
-   **Wallet Recharge Fix**: Specifically handles "Add Funds" invoices to ensure the wallet balance increases correctly without double-accounting.
-   **STK Push Support**: Sends a payment prompt directly to the customer's mobile phone.
-   **Robust Logging**: Integrates with FOSSBilling's debug system for easy troubleshooting.

## Prerequisites

-   FOSSBilling version 0.5.x or higher.
-   PHP 8.x
-   The `ongudidan/mpesa-sdk` package installed in your FOSSBilling environment.

## Installation

1.  **Install the SDK**:
    In your FOSSBilling root directory, run:
    ```bash
    composer require ongudidan/mpesa-sdk
    ```

2.  **Upload the Adapter**:
    Download [Mpesa.php](Mpesa.php) and place it in your FOSSBilling installation at:
    `library/Payment/Adapter/Mpesa.php`

3.  **Logo (Optional)**:
    Place an M-Pesa logo (65x30px) in `data/assets/payment_gateways/mpesa.png`.

## Configuration

1.  Log in to your FOSSBilling **Admin Panel**.
2.  Navigate to **System** -> **Payment Gateways**.
3.  Click the **New Payment Gateway** tab and select **M-Pesa**.
4.  Enter your M-Pesa API credentials:
    -   **Consumer Key / Secret**: From the Safaricom Daraja Portal.
    -   **Business ShortCode**: Your Paybill or Till number.
    -   **Passkey**: Lipa Na M-Pesa Online passkey.
    -   **Test Mode**: Enable for Sandbox testing.

## Important Notes

### Callback URL
Ensure your FOSSBilling **System URL** is publicly accessible. M-Pesa's API cannot send callbacks to `localhost`. You can check this in `config.php`:
```php
'url' => 'https://your-domain.com/',
```

### Wallet Recharges
This adapter includes specialized logic for FOSSBilling's internal "Add Funds" invoices. It detects the `TYPE_DEPOSIT` item and marks the invoice as paid without deducting the newly added funds from the balance, ensuring the user's wallet actually increases.

## Troubleshooting

-   **"Invalid CallBackURL"**: Make sure your site is HTTPS and publicly accessible.
-   **"Failed to initiate"**: Check your Consumer Key/Secret and ensure the `shortcode` matches the one provided by Safaricom for your chosen environment.
-   **Debug Logs**: Enable `DEBUG` in `config.php` to see detailed logs in `data/log/php_error.log`.

## License
Apache-2.0
# FOSSBilling-Mpesa-Payment-Adapter
# FOSSBilling-Mpesa-Payment-Adapter
