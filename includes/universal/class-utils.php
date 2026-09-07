<?php
namespace WPTS\Universal;

defined( 'ABSPATH' ) || exit;

/**
 * Universal utility methods used across Turbo Search subsystems.
 */
class Utils {

	/**
	 * Clean and normalize arbitrary content for search indexing.
	 * Strips scripts, styles, shortcodes, HTML tags, and condenses whitespace.
	 */
	public static function clean_text( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		// Strip null bytes and non-printable control characters (keep tab \t, newline \n, carriage return \r)
		$text = str_replace( "\0", '', $text );
		$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text ) ?? $text;

		// Convert and sanitize invalid UTF-8 sequences
		if ( function_exists( 'wp_check_invalid_utf8' ) ) {
			$text = wp_check_invalid_utf8( $text, true );
		} elseif ( function_exists( 'mb_convert_encoding' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}

		$text = preg_replace( '/<!--.*?-->/s', ' ', $text ) ?? $text;
		$text = preg_replace( '#<script[^>]*>.*?</script>#is', ' ', $text ) ?? $text;
		if ( function_exists( 'strip_shortcodes' ) ) {
			$text = strip_shortcodes( $text );
		} else {
			$text = preg_replace( '/\[\/?\w+[^\]]*\]/', ' ', $text ) ?? $text;
		}

		if ( function_exists( 'wp_strip_all_tags' ) ) {
			$text = wp_strip_all_tags( $text );
		} else {
			$text = strip_tags( $text );
		}
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? preg_replace( '/\s+/', ' ', $text );
		return trim( (string) $text );
	}

	/**
	 * Tokenize a text string into normalized lowercase words.
	 *
	 * @return string[]
	 */
	public static function tokenize( string $text ): array {
		$clean = self::clean_text( $text );
		$lower = mb_strtolower( $clean, 'UTF-8' );
		// Split on non-alphanumeric unicode characters
		$words = preg_split( '/[^\p{L}\p{N}_\-]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? array_values( array_unique( $words ) ) : [];
	}

	/**
	 * Generate an intelligent context snippet around query terms.
	 */
	public static function generate_snippet( string $full_text, string $query, int $max_length = 160 ): string {
		$clean = self::clean_text( $full_text );
		if ( '' === $clean ) {
			return '';
		}

		if ( '' === trim( $query ) ) {
			return mb_substr( $clean, 0, $max_length, 'UTF-8' ) . ( mb_strlen( $clean, 'UTF-8' ) > $max_length ? '…' : '' );
		}

		$tokens = self::tokenize( $query );
		if ( empty( $tokens ) ) {
			return mb_substr( $clean, 0, $max_length, 'UTF-8' ) . ( mb_strlen( $clean, 'UTF-8' ) > $max_length ? '…' : '' );
		}

		// Find the earliest occurrence of any token
		$best_pos = -1;
		$clean_lower = mb_strtolower( $clean, 'UTF-8' );
		foreach ( $tokens as $token ) {
			$pos = mb_strpos( $clean_lower, $token, 0, 'UTF-8' );
			if ( false !== $pos && ( -1 === $best_pos || $pos < $best_pos ) ) {
				$best_pos = $pos;
			}
		}

		if ( -1 === $best_pos ) {
			return mb_substr( $clean, 0, $max_length, 'UTF-8' ) . ( mb_strlen( $clean, 'UTF-8' ) > $max_length ? '…' : '' );
		}

		// Window snippet around the best position
		$half = (int) floor( $max_length / 2 );
		$start = max( 0, $best_pos - $half );
		$snippet = mb_substr( $clean, $start, $max_length, 'UTF-8' );

		$prefix = ( $start > 0 ) ? '…' : '';
		$suffix = ( ( $start + $max_length ) < mb_strlen( $clean, 'UTF-8' ) ) ? '…' : '';

		return $prefix . trim( $snippet ) . $suffix;
	}

	/**
	 * Escape user input safely for regex.
	 */
	public static function escape_regex( string $term ): string {
		return preg_quote( $term, '/' );
	}

	/**
	 * Format bytes to human-readable size.
	 */
	public static function format_bytes( int $bytes, int $precision = 2 ): string {
		$units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
		$bytes = max( $bytes, 0 );
		$pow   = floor( ( $bytes ? log( $bytes ) : 0 ) / log( 1024 ) );
		$pow   = min( $pow, count( $units ) - 1 );
		$bytes /= ( 1 << ( 10 * $pow ) );
		return round( $bytes, $precision ) . ' ' . $units[ $pow ];
	}

	/**
	 * Anonymize an IP address (IPv4 mask /24, IPv6 mask /48 or SHA-256 hash).
	 */
	public static function anonymize_ip( string $ip ): string {
		if ( '' === $ip ) {
			return '';
		}

		// IPv4
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$parts = explode( '.', $ip );
			if ( count( $parts ) === 4 ) {
				$parts[3] = '0';
				return implode( '.', $parts );
			}
		}

		// IPv6
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false !== $packed ) {
				// Mask to /48
				$mask = str_repeat( "\xff", 6 ) . str_repeat( "\x00", 10 );
				return inet_ntop( $packed & $mask ) ?: '::';
			}
		}

		return hash( 'sha256', $ip . wp_salt( 'auth' ) );
	}
}

