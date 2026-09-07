<?php
namespace WPTS\Search;

use WPTS\Admin\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * VectorSearch - Hybrid Semantic / AI Vector Embeddings Engine.
 * Supports OpenAI, local Ollama, or Custom Embeddings APIs with Cosine Similarity ranking.
 */
class VectorSearch {

	private static ?self $instance = null;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function is_enabled(): bool {
		return (bool) Settings::get( 'enable_vector_search', false );
	}

	/**
	 * Generate an embedding vector for a given text string.
	 * Caches query vectors in transient / object cache to avoid repeated API requests.
	 *
	 * @return float[]|null Vector array of floats, or null on failure.
	 */
	public function get_embedding( string $text, bool $is_query = true ): ?array {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( '' === $text ) {
			return null;
		}

		// Check cache for queries
		$cache_key = 'wpts_emb_' . md5( $text );
		if ( $is_query ) {
			$cached = wp_cache_get( $cache_key, 'wpts_search' );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$provider = (string) Settings::get( 'vector_provider', 'openai' );
		$vector   = null;

		switch ( $provider ) {
			case 'ollama':
				$vector = $this->fetch_ollama_embedding( $text );
				break;

			case 'custom':
				$vector = $this->fetch_custom_embedding( $text );
				break;

			case 'openai':
			default:
				$vector = $this->fetch_openai_embedding( $text );
				break;
		}

		$vector = apply_filters( 'wpts_vector_embedding', $vector, $text, $is_query );

		if ( is_array( $vector ) && ! empty( $vector ) && $is_query ) {
			wp_cache_set( $cache_key, $vector, 'wpts_search', 86400 );
		}

		return $vector;
	}

	private function fetch_openai_embedding( string $text ): ?array {
		$api_key = (string) Settings::get( 'vector_api_key', '' );
		if ( empty( $api_key ) ) {
			return null;
		}

		$model    = (string) Settings::get( 'vector_model', 'text-embedding-3-small' );
		$response = wp_remote_post( 'https://api.openai.com/v1/embeddings', [
			'timeout' => 8,
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			],
			'body'    => wp_json_encode( [
				'model' => $model,
				'input' => $text,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $data['data'][0]['embedding'] ?? null;
	}

	private function fetch_ollama_embedding( string $text ): ?array {
		$endpoint = (string) Settings::get( 'vector_endpoint', 'http://localhost:11434/api/embeddings' );
		$model    = (string) Settings::get( 'vector_model', 'nomic-embed-text' );

		$response = wp_remote_post( $endpoint, [
			'timeout' => 6,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'model'  => $model,
				'prompt' => $text,
			] ),
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $data['embedding'] ?? null;
	}

	private function fetch_custom_embedding( string $text ): ?array {
		$endpoint = (string) Settings::get( 'vector_endpoint', '' );
		$api_key  = (string) Settings::get( 'vector_api_key', '' );
		if ( empty( $endpoint ) ) {
			return null;
		}

		$headers = [ 'Content-Type' => 'application/json' ];
		if ( ! empty( $api_key ) ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		$response = wp_remote_post( $endpoint, [
			'timeout' => 8,
			'headers' => $headers,
			'body'    => wp_json_encode( [ 'input' => $text ] ),
		] );

		if ( is_wp_error( $response ) ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		return $data['embedding'] ?? ( $data['data'][0]['embedding'] ?? null );
	}

	/**
	 * Compute cosine similarity between two float vectors.
	 */
	public static function cosine_similarity( array $vec_a, array $vec_b ): float {
		$count = count( $vec_a );
		if ( $count === 0 || $count !== count( $vec_b ) ) {
			return 0.0;
		}

		$dot_product = 0.0;
		$norm_a      = 0.0;
		$norm_b      = 0.0;

		for ( $i = 0; $i < $count; $i++ ) {
			$a = (float) $vec_a[ $i ];
			$b = (float) $vec_b[ $i ];

			$dot_product += $a * $b;
			$norm_a      += $a * $a;
			$norm_b      += $b * $b;
		}

		$denominator = sqrt( $norm_a ) * sqrt( $norm_b );
		return $denominator > 0 ? ( $dot_product / $denominator ) : 0.0;
	}

	/**
	 * Re-rank search hits using Hybrid Keyword + Semantic Vector scoring.
	 *
	 * @param array   $hits         Keyword search result hits.
	 * @param float[] $query_vector Query embedding vector.
	 * @param float   $vector_weight Weight of vector similarity (0.0 to 1.0).
	 */
	public function rerank( array $hits, array $query_vector, float $vector_weight = 0.3 ): array {
		if ( empty( $hits ) || empty( $query_vector ) ) {
			return $hits;
		}

		$keyword_weight = 1.0 - $vector_weight;

		foreach ( $hits as &$hit ) {
			$post_id = (int) ( $hit['post_id'] ?? 0 );
			$doc_vec = get_post_meta( $post_id, '_wpts_embedding', true );

			if ( is_array( $doc_vec ) && ! empty( $doc_vec ) ) {
				$cos_sim = self::cosine_similarity( $query_vector, $doc_vec );
				$old_rel = (float) ( $hit['relevance'] ?? 1.0 );
				$hit['vector_similarity'] = round( $cos_sim, 4 );
				$hit['relevance']         = round( ( $old_rel * $keyword_weight ) + ( ( $cos_sim * 10 ) * $vector_weight ), 2 );
			}
		}
		unset( $hit );

		// Sort descending by updated hybrid relevance
		usort( $hits, function ( $a, $b ) {
			return ( (float) ( $b['relevance'] ?? 0 ) <=> (float) ( $a['relevance'] ?? 0 ) );
		} );

		return $hits;
	}
}

