# FOSSBilling M-Pesa Payment Adapter (Standalone)

A premium, zero-dependency, single-file payment adapter for [FOSSBilling](https://fossbilling.org/) that enables Safaricom M-Pesa STK Push (Lipa Na M-Pesa) payments.

## Features

-   **Zero Dependencies**: Fully self-contained. No `composer install` or external SDKs required.
-   **Premium UI**: Includes a loading overlay with a spinner and "Waiting for PIN" status feedback.
-   **Real-time Polling**: Automatically polls the payment status every 3 seconds and redirects the user immediately upon confirmation.
-   **Intelligent Phone Input**: Features a fixed `254` prefix and handles `07...`, `01...`, or `254...` formats automatically.
-   **Robust Error Handling**: Detects user cancellations on the phone and handles transaction timeouts gracefully.
-   **Wallet Recharge Fix**: Specifically handles "Add Funds" invoices to ensure the wallet balance increases correctly without double-accounting.
-   **Native IPN Routing**: Uses FOSSBilling's standard `ipn.php` for all communication—no external initiation scripts needed.

## Prerequisites

-   FOSSBilling version 0.5.x or higher.
-   PHP 8.2 or higher.
-   A publicly accessible FOSSBilling URL (M-Pesa cannot reach `localhost`).

## Installation

1.  **Upload the Adapter**:
    Download [Mpesa.php](Mpesa.php) and place it in your FOSSBilling installation at:
    `library/Payment/Adapter/Mpesa.php`

2.  **Logo (Optional)**:
    Place an M-Pesa logo (65x30px) in `data/assets/payment_gateways/mpesa.png`.

## Configuration

1.  Log in to your FOSSBilling **Admin Panel**.
2.  Navigate to **System** -> **Payment Gateways**.
3.  Click the **New Payment Gateway** tab and select **M-Pesa**.
4.  Enter your M-Pesa API credentials:
    -   **Consumer Key / Secret**: From the Safaricom Daraja Portal.
    -   **Business ShortCode**: Your Paybill or Till number.
    -   **Passkey**: Lipa Na M-Pesa Online passkey (LNM Passkey).
    -   **Test Mode**: Enable for Sandbox testing.

## Important Notes

### Callback URL
Ensure your FOSSBilling **System URL** is publicly accessible and use HTTPS. M-Pesa's API cannot send callbacks to `localhost`. You can check this in `config.php`:
```php
'url' => 'https://your-domain.com/',
```

### Wallet Recharges
This adapter includes specialized logic for FOSSBilling's internal "Add Funds" invoices. It detects the `TYPE_DEPOSIT` item and marks the invoice as paid without deducting the newly added funds from the balance, ensuring the user's wallet actually increases.

## Troubleshooting

-   **"Invalid CallBackURL"**: Make sure your site is HTTPS and publicly accessible.
-   **"Failed to initiate"**: Check your Consumer Key/Secret and ensure the `shortcode` matches the environment (Sandbox vs Live).
-   **Debug Logs**: Enable `DEBUG` in `config.php` to see detailed logs in `data/log/php_error.log`.

## License
Apache-2.0
