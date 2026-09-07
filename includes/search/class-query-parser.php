<?php
namespace WPTS\Search;

use WPTS\Universal\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Parses user search queries into structured tokens (exact phrases, excluded terms, boolean clauses).
 */
class QueryParser {

	/**
	 * Parse query string into a structured query object.
	 *
	 * @return array{
	 *   raw: string,
	 *   cleaned: string,
	 *   phrases: string[],
	 *   positive_terms: string[],
	 *   excluded_terms: string[],
	 *   boolean_mode: string,
	 *   expanded_terms: string[]
	 * }
	 */
	public static function parse( string $query, bool $expand_synonyms = true ): array {
		$raw = trim( $query );
		if ( '' === $raw ) {
			return [
				'raw'            => '',
				'cleaned'        => '',
				'phrases'        => [],
				'positive_terms' => [],
				'excluded_terms' => [],
				'boolean_mode'   => 'AND',
				'expanded_terms' => [],
			];
		}

		// 1. Extract exact quoted phrases: "hello world" or 'hello world'
		$phrases = [];
		$without_phrases = preg_replace_callback( '/["\']([^"\']+)["\']/u', function ( $matches ) use ( &$phrases ) {
			$phrase = trim( $matches[1] );
			if ( '' !== $phrase ) {
				$phrases[] = $phrase;
			}
			return ' ';
		}, $raw ) ?? $raw;

		// 2. Detect boolean mode: if query contains explicit ' OR '
		$boolean_mode = ( preg_match( '/\bOR\b/u', $without_phrases ) ) ? 'OR' : 'AND';

		// 3. Remove standalone AND / OR words from token list
		$without_booleans = preg_replace( '/\b(AND|OR)\b/u', ' ', $without_phrases ) ?? $without_phrases;

		// 4. Split into remaining tokens
		$parts = preg_split( '/\s+/u', trim( $without_booleans ), -1, PREG_SPLIT_NO_EMPTY ) ?: [];

		$positive_terms = [];
		$excluded_terms = [];

		$next_is_excluded = false;

		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			// NOT operator: "NOT apple"
			if ( 'NOT' === strtoupper( $part ) ) {
				$next_is_excluded = true;
				continue;
			}

			// Prefix hyphen operator: "-apple"
			if ( 0 === strpos( $part, '-' ) && strlen( $part ) > 1 ) {
				$term = ltrim( $part, '-' );
				if ( '' !== $term ) {
					$excluded_terms[] = mb_strtolower( $term, 'UTF-8' );
				}
				continue;
			}

			if ( $next_is_excluded ) {
				$excluded_terms[] = mb_strtolower( $part, 'UTF-8' );
				$next_is_excluded = false;
				continue;
			}

			$positive_terms[] = mb_strtolower( $part, 'UTF-8' );
		}

		$positive_terms = array_values( array_unique( $positive_terms ) );
		$excluded_terms = array_values( array_unique( $excluded_terms ) );
		$phrases        = array_values( array_unique( $phrases ) );

		// 5. Expand synonyms if enabled
		$expanded_terms = $positive_terms;
		if ( $expand_synonyms && class_exists( '\WPTS\Search\Synonyms' ) ) {
			$expanded_terms = Synonyms::expand_terms( $positive_terms );
		}

		// 6. Build a cleaned query string
		$all_pos = array_merge( $phrases, $expanded_terms );
		$cleaned = trim( implode( ' ', $all_pos ) );

		return [
			'raw'            => $raw,
			'cleaned'        => $cleaned ?: $raw,
			'phrases'        => $phrases,
			'positive_terms' => $positive_terms,
			'excluded_terms' => $excluded_terms,
			'boolean_mode'   => $boolean_mode,
			'expanded_terms' => $expanded_terms,
		];
	}
}

