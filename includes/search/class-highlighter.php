<?php
namespace WPTS\Search;

use WPTS\Universal\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Intelligent full-content and snippet highlighter for search results.
 */
class Highlighter {

	/**
	 * Highlight query terms and phrases within a text string.
	 *
	 * @param string $text   Raw un-escaped text.
	 * @param string $query  Search query.
	 * @return string HTML safe text with <mark> tags.
	 */
	public static function highlight( string $text, string $query ): string {
		$clean_text = trim( $text );
		if ( '' === $clean_text || '' === trim( $query ) ) {
			return esc_html( $clean_text );
		}

		$parsed = QueryParser::parse( $query, false );
		$terms  = array_merge( $parsed['phrases'], $parsed['positive_terms'] );

		if ( empty( $terms ) ) {
			return esc_html( $clean_text );
		}

		// Sort terms by length descending so longer phrases match first
		usort( $terms, fn( $a, $b ) => mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' ) );

		$escaped_text = esc_html( $clean_text );

		foreach ( $terms as $term ) {
			$term_clean = trim( $term );
			if ( '' === $term_clean || mb_strlen( $term_clean, 'UTF-8' ) < 2 ) {
				continue;
			}

			$escaped_term = esc_html( $term_clean );
			$safe_regex   = Utils::escape_regex( $escaped_term );

			// Wrap in <mark> tag safely without replacing inside already-placed tags
			$escaped_text = preg_replace(
				'/(?<!<mark class="wpts-mark">)(' . $safe_regex . ')(?![^<]*<\/mark>)/iu',
				'<mark class="wpts-mark">$1</mark>',
				$escaped_text
			) ?? $escaped_text;
		}

		return $escaped_text;
	}

	/**
	 * Create an intelligent highlighted snippet from full content.
	 *
	 * @param string $content Full post content.
	 * @param string $excerpt Default post excerpt.
	 * @param string $query   Search query.
	 * @param int    $length  Max characters.
	 */
	public static function highlight_snippet( string $content, string $excerpt, string $query, int $length = 160 ): string {
		// If excerpt contains any matched token, use the excerpt
		$tokens = Utils::tokenize( $query );
		$use_excerpt = false;

		if ( '' !== trim( $excerpt ) ) {
			$excerpt_lower = mb_strtolower( $excerpt, 'UTF-8' );
			foreach ( $tokens as $t ) {
				if ( false !== mb_strpos( $excerpt_lower, $t, 0, 'UTF-8' ) ) {
					$use_excerpt = true;
					break;
				}
			}
		}

		if ( $use_excerpt ) {
			$snippet = function_exists( 'wp_trim_words' ) ? wp_trim_words( $excerpt, 30, '…' ) : $excerpt;
		} else {
			// Generate from full content
			$snippet = Utils::generate_snippet( $content, $query, $length );
			if ( '' === $snippet && '' !== $excerpt ) {
				$snippet = $excerpt;
			}
		}

		return self::highlight( $snippet, $query );
	}
}

