=== SumUp Payment Gateway For WooCommerce ===
Contributors: sumup
Tags: sumup, payment gateway, woocommerce, payments, ecommerce
Requires at least: 5.0
Tested up to: 6.7.2
Requires PHP: 7.2
Stable tag: 2.7.5
License: GPL2
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The SumUp plugin for WooCommerce allows businesses to securely process payments online. Accept payments from customers using a range of payment methods.

== Description ==

Grow your business by accepting payments through SumUp in your WooCommerce store.

The SumUp plugin for WooCommerce offers consumers a seamless payment experience with their favourite payment methods in just a few steps.  The payments are processed through the SumUp payment platform, so you can see them alongside your in-store sales. It's affordable, easy to set up and use, and simply a better way to get paid.

= TAKE PAYMENTS =
* [No fixed costs. No binding contracts. Just a small % per transaction](https://sumup.co.uk/credit-card-processing-pricing/)
* Receive secure payments to your bank account within 3 days
* Find everything in one place in the SumUp Dashboard and App

= SUPPORTED PAYMENT METHODS =
* Accept different debit and credit cards: Visa, VPay, Mastercard, American Express, Diners Club, Discover
* Accept alternative payment methods: Apple Pay, Bancontact, Boleto, iDeal, PayPal & Sofort
* [Request access to Alternative Payment Methods here](https://developer.sumup.com/contact)

= STAY SECURE =
SumUp is authorised as a Payment Institution by the Financial Conduct Authority and is Europay, Mastercard and Visa (EMV) and PCI-DSS certified.
This ensures that payments are processed in accordance with the highest security standards.

= BE FLEXIBLE =
* SumUp processes in 16 currencies: Australian Dollar (AUD), Brazilian Real (BRL), Bulgarian Lev (BGN), Chilean Peso (CLP), Colombian Peso (COP), Czech Koruna (CZK), Danish Krone (DKK), Euro (EUR), Forint (HUF), Norwegian Krone (NOK), Pound Sterling (GBP), Romanian Leu (RON), Swedish Krona (SEK), Swiss Franc (CHF), US Dollar (USD), Zloty (PLZ)
* SumUp supports 22 languages: Bulgarian, Czech, Danish, Dutch, English, Estonian, Finnish, French, German, Greek, Hungarian, Italian, Latvian, Lithuanian, Norwegian, Polish, Portuguese, Romanian, Slovak, Slovenian, Spanish and Swedish

**Want to try it?**

= GET STARTED =
* Download the plugin
* Create a [free account](https://buy.sumup.com/en-gb/signup/create-account) or use [your existing one](https://me.sumup.com/)
* Verify your account and connect the plugin by using the “Connect Account” button
* [Contact our support team](https://developer.sumup.com/contact) for a test account or to enable necessary scopes when you are ready to accept payments

You're ready to go.

== Screenshots ==

1. The settings panel used to configure the gateway
2. The new simplified connection workflow with "Connect Account"
3. A checkout with SumUp

== Installation ==

= Automated installation =

Automatic installation is the easiest option, as WordPress will handle the file transfer and you won�t need to leave your web browser.
Note: Ensure the [WooCommerce plugin](https://wordpress.org/plugins/woocommerce/) is pre-installed prior to initiating the steps in this guide.

1. Install the plugin via the "Plugins" section in the Dashboard
1.1. Click on "Add new" and search for "SumUp Payment Gateway for WooCommerce"
1.2. Then click on the "Install Now" button
1.3. Click "Activate" to active the plugin
2. Follow the instructions on the SumUp pop-up window appearing in the plugin setting screen and use the “Connect Account” button to initiate the connection with your SumUp account

= Manual Installation =

The manual installation method involves downloading our plugin and uploading it to your web server via your favorite FTP application. WordPress contains [instructions on how to do this](https://wordpress.org/support/article/managing-plugins/#manual-plugin-installation).

== Frequently Asked Questions ==

= Does it work with debit and credit card? =

Yes. You'll be able to accept Visa, VPay, Mastercard, American Express, Diners Club, Discover cards.

= What currencies does the plugin support? =

We support 16 currencies with more being added.

= Which Alternative Payment Methods (APMs) are supported? =

At SumUp you can process online payments with Apple Pay, Bancontact, Boleto, iDeal, PayPal & Sofort. Read more about our APMs in our [official developer documentation](https://developer.sumup.com/online-payments/apm/introduction).

= How can I enable Alternative Payment Methods (APMs)? =

Our Support team will enable the APMs that are relevant to your business location. Reach out to us through [our contact form](https://developer.sumup.com/contact) for assistance.

= Where can I find documentation? =

You can find all the information you'll need on how to set up your plugin [here](https://developer.sumup.com/online-payments/plugins/woocommerce/).

= Where can I get support if needed? =

If you have any questions, please get in contact with us through our [contact form](https://developer.sumup.com/contact).

= Does this support both production mode and sandbox mode for testing? =

Yes. If you need a testing environment, please contact us through our [contact form](https://developer.sumup.com/contact).

== Changelog ==

= 2.7.5 =
* Fixed: Fixed a credential validation error in the onboarding flow.

= 2.7.4 =
* Improvement: Added structured error logging with mapped error codes.
* Improvement: Applied background security improvements.

= 2.7.3 =
* Improvement: Introduce mapped error logging.

= 2.7.2 =
* Fixed: Fixed a credential validation error in the onboarding flow
* Fixed: Fixed an issue with order validation
* Fixed: Improved the account connection and disconnection flows

= 2.7.1 =
* Fixed: Record settings on onboarding flow.

= 2.7.0 =
* Improvements: Updated onboarding flow.
* Fixed: Duplicate in the notes of orders.

= 2.6.9 =
* Improvements: Security for plugin integration with Sumup.

= 2.6.8 =
* Fixed: Show the updated images on the plugin page information.

= 2.6.7 =
* Improvements: Updated plugin page information.

= 2.6.6 =
* Improvements: Removed deprecated hooks from code.
* Fixed: Automatic redirect on checkout payment.

= 2.6.5 =
* Fixed: SumUp SDK loading conflict with certain themes.

= 2.6.4 =
* Improvements: Minor security update.

= 2.6.3 =
* Fixed: Script loading outside of checkout.
* Improved: Error messages.

= 2.6.2 =
* Fixed: Error when using Apple pay in the Woocommerce blocks checkout.

= 2.6.1 =
* Fixed: Create checkout woocommerce blocks error.

= 2.6.0 =
* Fixed: Onbarding does not work when site is in maintenance mode.

= 2.5.9 =
* Fixed: Visual and styling conflicts with other plugins/themes.

= 2.5.8 =
* Fixed: SumUp SDK import when using WooCommerce Blocks.

= 2.5.7 =
* Improvements: Added translation for the plugin to all supported locales.
* Fixed: Apple Pay redirect after checkout payment.
* Fixed: Order status updating to 'Completed' after payment for checkouts with Virtual and Downloadable products.

= 2.5.6 =
* Improvements: Support for Australian Dollar (AUD).

= 2.5.5 =
* Fixed: Warning PHP message.
* Fixed: Message diff currency appearing before update.

= 2.5.3 =
* Improvements: Added support for WooCommerce checkout blocks.
* Fixed: Warning message when there is a currency mismatch between WooCommerce and the SumUp account.
* Fixed: Pix payment appearing even when disabled in the plugin settings.

= 2.5.2 =
* Improvements: Support for Wordpress 6.5.2.
* Fixed: Critical error when saving settings.

= 2.5 =
* New: Onbording to connect with SumUp account.
* Improvements: Compatibility with WordPress 6.4.
* Fixed: Automatic redirect to success page without payment being processed.
* Fixed: Update order status after payment conclusion.

= 2.4.2 =
* Fixed: In some flows order status can be updated two times.
* Fixed: error to get country from checkout.
* Fixed: validation of credentials on settings.
* Improvements: add more details to logs.
* Improvements: compatibility with WordPress 6.4.

= 2.4.1 =
* Improvements: error message during setup.

= 2.4 =
* Improvements: do not hide the card widget on submit if has any invalid data.
* Improvements: flow to validate payments with redirect (like 3Ds).

= 2.3 =
* Improvements: credentials validation on plugin settings.

= 2.2 =
* Improvements: Update order status to cancelled when 3Ds validation failed.
* Improvements: Logs during checkout.

= 2.1 =
* Fixed: 3Ds payments redirect.
* Fixed: webhook order confirmation.
* Fixed: card widget close when clicked on it (modal disabled).

= 2.0 =
* New: Accept payments with alternative payment methods (Follow guides for enabling in your account)
* New: Accept card payments with installments in BR.
* New: Accept payments with Apple Pay.
* New: Support for WooCommerce stock management feature
* New: New user experience configuration: merchant can choose to open the payment option in a pop-up instead of the checkout page.
* Improvements: Display WooCommerce order Id on SumUp Sales History.
* Improvements: Added transaction code to order description on WooCommerce
* Improvements: Added checkout_id in order notes to improve customer support
* Improvements: New settings screen for easier setup
* Improvements: Multiple code maintenance improvements.
* Improvements: Support for Wordpress 6.3
* Improvements: Require PHP version 7.2 or greater.
* Fixed: Errors during checkout that caused duplicated payment.
* Fixed: Issues loading payment methods on checkout.
* Fixed: Issue with customer creation during checkout that caused duplicated payment.

= 1.2 =
* Changed: Checkout improvement.
* Changed: WooCommerce order id in description.

= 1.1 =
* New: Added new currencies.
* New: Checkout-id on payment form.
* Changed: Rephrase Error messages.

= 1.0 =
* Initial release.

== Upgrade Notice ==

= 2.7.5 =
* Fixed: Fixed a credential validation error in the onboarding flow.


