# NoPayn Payment Plugin for Shopware 6

Accept Credit/Debit Cards, Apple Pay, Google Pay, and Vipps MobilePay in your Shopware 6 shop via NoPayn.

## Requirements

- Shopware 6.7+
- PHP 8.2+
- NoPayn merchant account ([manage.nopayn.io](https://manage.nopayn.io/))

## Installation

1. Clone or copy into `custom/plugins/NoPaynPayment/`:
   ```bash
   cd /path/to/shopware/custom/plugins
   git clone git@github.com:NoPayn/shopware.git NoPaynPayment
   ```
2. Install and activate:
   ```bash
   bin/console plugin:refresh
   bin/console plugin:install NoPaynPayment --activate
   bin/console cache:clear
   ```

## Configuration

1. Go to **Settings > Extensions > NoPayn Payment**
2. Enter your NoPayn API key (per sales channel if needed)
3. Toggle individual payment methods on or off
4. Save

## Payment Methods

| Checkout Name       | Technical Name         | NoPayn Identifier  |
|---------------------|------------------------|---------------------|
| Credit / Debit Card | `nopayn_credit_card`   | `credit-card`       |
| Apple Pay           | `nopayn_apple_pay`     | `apple-pay`         |
| Google Pay          | `nopayn_google_pay`    | `google-pay`        |
| Vipps MobilePay     | `nopayn_vipps_mobilepay` | `vipps-mobilepay` |

Each method can be enabled or disabled per sales channel from the plugin configuration.

## Payment Flow

1. Customer selects a payment method at checkout and places the order
2. Order is created with transaction status **in_progress**
3. Customer is redirected directly to the chosen payment method on the NoPayn payment page
4. After payment:
   - **Success**: customer returns, status verified via API, transaction set to **paid**, order set to **processing**
   - **Cancelled**: customer returns, transaction set to **cancelled**, order set to **cancelled**
   - **Expired** (5 min timeout): webhook fires, transaction and order set to **cancelled**
5. NoPayn sends a webhook for asynchronous status confirmation

## Order Status Mapping

| NoPayn Status | Transaction State | Order State |
|---------------|-------------------|-------------|
| `new`         | in_progress       | open        |
| `processing`  | in_progress       | open        |
| `completed`   | paid              | in_progress |
| `cancelled`   | cancelled         | cancelled   |
| `expired`     | cancelled         | cancelled   |
| `error`       | cancelled         | cancelled   |

## Webhook

The plugin registers a webhook endpoint at `/api/nopayn/webhook`. This URL is automatically sent to NoPayn when creating orders.

## Support

- **Developer**: [Cost+](https://costplus.io)
- **NoPayn API docs**: [dev.nopayn.io](https://dev.nopayn.io/)
- **Merchant portal**: [manage.nopayn.io](https://manage.nopayn.io/)
