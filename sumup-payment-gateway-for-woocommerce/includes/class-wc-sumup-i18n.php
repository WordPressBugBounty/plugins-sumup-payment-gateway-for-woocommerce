<?php
/**
 * Locale mapping for the SumUp Card Widget.
 *
 * @package SumUp_WooCommerce
 */

/** Maps WordPress locales to supported widget locales. */
final class WC_Sumup_I18n {
	/**
	 * Keep plugin translations in the WordPress locale; the widget has its own catalog.
	 *
	 * @param string $locale WordPress locale.
	 * @return string Supported widget locale, or British English when unavailable.
	 */
	public static function widget_locale( $locale ) {
		$parts    = explode( '-', str_replace( '_', '-', $locale ) );
		$language = strtolower( $parts[0] );
		$region   = isset( $parts[1] ) ? strtoupper( $parts[1] ) : '';
		$regions  = array(
			'bg' => array( 'BG' ),
			'cs' => array( 'CZ' ),
			'da' => array( 'DK' ),
			'de' => array( 'DE', 'AT', 'CH', 'LU' ),
			'el' => array( 'GR', 'CY' ),
			'en' => array( 'GB', 'AU', 'IE', 'MT', 'US' ),
			'es' => array( 'ES', 'CL' ),
			'et' => array( 'EE' ),
			'fi' => array( 'FI' ),
			'fr' => array( 'FR', 'BE', 'CH', 'LU' ),
			'hu' => array( 'HU' ),
			'it' => array( 'IT', 'CH' ),
			'lt' => array( 'LT' ),
			'lv' => array( 'LV' ),
			'nb' => array( 'NO' ),
			'nl' => array( 'NL', 'BE' ),
			'pt' => array( 'PT', 'BR' ),
			'pl' => array( 'PL' ),
			'sk' => array( 'SK' ),
			'sl' => array( 'SI' ),
			'sv' => array( 'SE' ),
		);

		if ( ! isset( $regions[ $language ] ) ) {
			// The widget does not currently provide Croatian or Romanian translations.
			return 'en-GB';
		}
		if ( 'es' === $language && 'CO' === $region ) {
			return 'es-CL';
		}
		return $language . '-' . ( in_array( $region, $regions[ $language ], true ) ? $region : $regions[ $language ][0] );
	}
}
