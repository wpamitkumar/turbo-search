<?php
namespace WPTS\Security;

defined( 'ABSPATH' ) || exit;

/**
 * Honeypot, bot detection, and rate limiting on search endpoints.
 */
class BotProtection {

	public const HONEYPOT_FIELD = '_wpts_hp';

	/**
	 * Validate whether the incoming search request is from a legitimate user or bot.
	 *
	 * @param \WP_REST_Request|null $request
	 * @return bool True if legitimate, false if bot/blocked.
	 */
	public static function is_allowed( ?\WP_REST_Request $request = null ): bool {
		$enabled = (bool) \WPTS\Admin\Settings::get( 'enable_honeypot', true );
		if ( ! $enabled ) {
			return true;
		}

		// 1. Honeypot check: if honeypot parameter is non-empty, it's a bot
		$hp_val = $request ? $request->get_param( self::HONEYPOT_FIELD ) : ( isset( $_REQUEST[ self::HONEYPOT_FIELD ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ self::HONEYPOT_FIELD ] ) ) : null );
		if ( ! empty( $hp_val ) ) {
			return false;
		}

		// 2. User agent checks
		$ua = (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		if ( '' !== $ua ) {
			$bot_signatures = [ 'curl', 'python-requests', 'scrapy', 'wget', 'httpclient', 'nikto', 'sqlmap', 'ahrefsbot' ];
			$ua_lower = strtolower( $ua );
			foreach ( $bot_signatures as $bot ) {
				if ( false !== strpos( $ua_lower, $bot ) ) {
					return false;
				}
			}
		}

		// 3. Burst Rate Limiting: max 60 searches per 60 seconds per IP
		$ip = sanitize_text_field( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		if ( '' !== $ip ) {
			$transient_key = 'wpts_rl_' . md5( $ip );
			$current_count = (int) get_transient( $transient_key );
			if ( $current_count >= 60 ) {
				return false;
			}
			set_transient( $transient_key, $current_count + 1, 60 );
		}

		return true;
	}
}
