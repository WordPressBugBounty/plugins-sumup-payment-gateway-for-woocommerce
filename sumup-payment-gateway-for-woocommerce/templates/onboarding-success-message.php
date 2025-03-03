<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="sumup-onboarding-message">
	<span>
		<svg width="22" height="22" viewBox="0 0 22 22" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path d="M10.994 0C8.07714 0.00159188 5.28033 1.16163 3.21864 3.22501C1.15695 5.28838 -0.000795532 8.08614 4.1013e-07 11.003C0.000796352 13.9199 1.16007 16.717 3.22289 18.7792C5.2857 20.8415 8.08314 22 11 22C13.9169 22 16.7143 20.8415 18.7771 18.7792C20.8399 16.717 21.9992 13.9199 22 11.003C22.0008 8.08614 20.843 5.28838 18.7814 3.22501C16.7197 1.16163 13.9229 0.00159188 11.006 0H10.994ZM16.7671 7.64417L9.76333 15.6485C9.5915 15.8535 9.34528 15.9819 9.07884 16.0053C8.81239 16.0288 8.54755 15.9454 8.34255 15.7736C8.32154 15.7556 8.30153 15.7376 8.28252 15.7186L5.28088 12.7169C5.0948 12.5273 4.9906 12.2722 4.99072 12.0065C4.99072 11.7412 5.09614 11.4867 5.28378 11.2991C5.47141 11.1114 5.72591 11.006 5.99127 11.006C6.25694 11.0059 6.51204 11.1101 6.70166 11.2962L8.92287 13.5274L15.2263 6.36347C15.3578 6.20483 15.535 6.09064 15.7338 6.03649C15.9325 5.98234 16.1432 5.99088 16.337 6.06094C16.5307 6.13099 16.6981 6.25915 16.8163 6.42791C16.9345 6.59667 16.9977 6.79779 16.9973 7.00382C16.9962 7.23734 16.915 7.46341 16.7671 7.64417Z" fill="#018850" />
		</svg>
	</span>

	<div class="sumup-onboarding-message__content">
		<h4 class="sumup-onboarding-message__headline">
			<?php esc_html_e( 'SumUp account connected', 'sumup-payment-gateway-for-woocommerce' ); ?>
		</h4>
		<p class="sumup-onboarding-message__description">
			<?php esc_html_e( 'WooCommerce can now accept payments via SumUp', 'sumup-payment-gateway-for-woocommerce' ); ?>
		</p>
	</div>
	<div class="sumup-onboarding-disconnect__content">
	<a id="sumup-payment-settings-disconnect" class="sumup_modal-button sumup_modal-button--danger sumup__button sumup__button--disconnect" href="#" data-text="<?php esc_attr_e('Disconnect Account', 'sumup-payment-gateway-for-woocommerce'); ?>">
				<?php esc_html_e('Disconnect Account', 'sumup-payment-gateway-for-woocommerce'); ?>
			</a>
	</div>
</div>
