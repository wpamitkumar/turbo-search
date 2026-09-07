<?php
namespace WPTS\Search;

use WPTS\Universal\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Extracts and enriches indexable content across WooCommerce, PDF/Docs attachments, comments, taxonomies, and media.
 */
class ContentExtractor {

	/**
	 * Enrich a base document with WooCommerce fields, comments, attachment text, and taxonomy terms.
	 *
	 * @param array    $document Base document array.
	 * @param \WP_Post $post     The WordPress post object.
	 * @param array    $settings Plugin settings array.
	 * @return array Enriched document array.
	 */
	public static function enrich( array $document, \WP_Post $post, array $settings ): array {
		$post_id   = (int) $post->ID;
		$post_type = (string) $post->post_type;

		// 1. Taxonomy Terms Extraction (Categories, Tags, Custom Taxonomies)
		$tax_content = self::extract_taxonomies( $post_id, $post_type );
		if ( '' !== $tax_content ) {
			$document['taxonomies'] = $tax_content;
			$document['content']   .= ' ' . $tax_content;
		}

		// 2. WooCommerce Products Search (SKU, price, stock status, attributes)
		if ( 'product' === $post_type || 'product_variation' === $post_type ) {
			$wc_data = self::extract_woocommerce_data( $post_id );
			$document['sku']          = $wc_data['sku'];
			$document['price']        = $wc_data['price'];
			$document['stock_status'] = $wc_data['stock_status'];
			if ( '' !== $wc_data['text'] ) {
				$document['content'] .= ' ' . $wc_data['text'];
			}
		}

		// 3. Comments Search
		if ( ! empty( $settings['index_comments'] ) ) {
			$comments_text = self::extract_comments( $post_id );
			if ( '' !== $comments_text ) {
				$document['comments'] = $comments_text;
				$document['content'] .= ' ' . $comments_text;
			}
		}

		$index_attachments = ! isset( $settings['index_attachments'] ) || ! empty( $settings['index_attachments'] );

		// 4. Media Library Attachments Search (When indexing attachment post directly)
		if ( 'attachment' === $post_type ) {
			$media_text = self::extract_media_metadata( $post_id, $post );
			if ( '' !== $media_text ) {
				$document['content'] .= ' ' . $media_text;
			}
			if ( $index_attachments ) {
				$file_path = get_attached_file( $post_id );
				if ( $file_path && file_exists( $file_path ) ) {
					$mime     = (string) get_post_mime_type( $post_id );
					$doc_text = self::extract_text_from_file( $file_path, $mime );
					if ( '' !== $doc_text ) {
						$document['attached_docs'] = $doc_text;
						$document['content']      .= ' ' . $doc_text;
					}
				}
			}
		}

		// 5. PDF & Document text search attached or embedded in posts/pages/products
		if ( $index_attachments && 'attachment' !== $post_type ) {
			$attachment_text = self::extract_attached_documents_text( $post_id, (string) $post->post_content );
			if ( '' !== $attachment_text ) {
				$document['attached_docs'] = $attachment_text;
				$document['content']      .= ' ' . $attachment_text;
			}
		}

		return $document;
	}

	/**
	 * Extract taxonomy term names for a post.
	 */
	public static function extract_taxonomies( int $post_id, string $post_type ): string {
		$taxonomies = get_object_taxonomies( $post_type, 'names' );
		if ( empty( $taxonomies ) ) {
			return '';
		}

		$terms = wp_get_object_terms( $post_id, $taxonomies, [ 'fields' => 'names' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		return implode( ' ', array_map( 'sanitize_text_field', (array) $terms ) );
	}

	/**
	 * Extract WooCommerce SKU, price, stock status, attributes.
	 *
	 * @return array{sku: string, price: float, stock_status: string, text: string}
	 */
	public static function extract_woocommerce_data( int $post_id ): array {
		$sku           = (string) get_post_meta( $post_id, '_sku', true );
		$price         = (float)  get_post_meta( $post_id, '_price', true );
		$regular_price = (float)  get_post_meta( $post_id, '_regular_price', true );
		$sale_price    = (float)  get_post_meta( $post_id, '_sale_price', true );
		$stock_status  = (string) get_post_meta( $post_id, '_stock_status', true ) ?: 'instock';
		$on_sale       = $sale_price > 0 && $sale_price < $regular_price;

		$text_parts = [];
		if ( '' !== $sku ) {
			$text_parts[] = 'SKU: ' . $sku;
		}

		// Attributes
		$product_attributes = get_post_meta( $post_id, '_product_attributes', true );
		if ( is_array( $product_attributes ) ) {
			foreach ( $product_attributes as $attr ) {
				if ( ! empty( $attr['value'] ) ) {
					$text_parts[] = (string) $attr['value'];
				}
			}
		}

		return [
			'sku'           => $sku,
			'price'         => $price,
			'regular_price' => $regular_price,
			'sale_price'    => $sale_price,
			'on_sale'       => $on_sale,
			'stock_status'  => $stock_status,
			'text'          => implode( ' ', $text_parts ),
		];
	}

	/**
	 * Extract approved comments text and author names for a post.
	 */
	public static function extract_comments( int $post_id ): string {
		$comments = get_comments( [
			'post_id' => $post_id,
			'status'  => 'approve',
			'number'  => 50,
		] );

		if ( empty( $comments ) ) {
			return '';
		}

		$parts = [];
		foreach ( (array) $comments as $c ) {
			$author  = sanitize_text_field( $c->comment_author );
			$content = Utils::clean_text( (string) $c->comment_content );
			if ( '' !== $content ) {
				$parts[] = $author . ': ' . $content;
			}
		}

		return implode( ' ', $parts );
	}

	/**
	 * Extract metadata from media attachments.
	 */
	public static function extract_media_metadata( int $post_id, \WP_Post $post ): string {
		$parts = [];

		// Alt text
		$alt = get_post_meta( $post_id, '_wp_attachment_image_alt', true );
		if ( ! empty( $alt ) ) {
			$parts[] = sanitize_text_field( (string) $alt );
		}

		// Attached file basename
		$file = get_attached_file( $post_id );
		if ( $file ) {
			$parts[] = pathinfo( $file, PATHINFO_FILENAME );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Extract text from documents attached or linked to a post (PDF, TXT, CSV, DOCX, TSV, MD).
	 * Scans classic post_parent children, Gutenberg File blocks, HTML links, and ACF/meta fields.
	 */
	public static function extract_attached_documents_text( int $post_id, string $post_content = '' ): string {
		$found_files = [];

		// 1. Classic direct post_parent attachments
		$attachments = get_children( [
			'post_parent'    => $post_id,
			'post_type'      => 'attachment',
			'post_mime_type' => [
				'application/pdf',
				'text/plain',
				'text/csv',
				'text/tab-separated-values',
				'text/markdown',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/msword',
			],
			'numberposts'    => 30,
		] );

		if ( ! empty( $attachments ) ) {
			foreach ( $attachments as $att ) {
				$file_path = get_attached_file( $att->ID );
				if ( $file_path && file_exists( $file_path ) ) {
					$found_files[ $file_path ] = (string) get_post_mime_type( $att->ID );
				}
			}
		}

		// 2. Scan Gutenberg blocks & HTML content for linked/embedded documents
		if ( '' !== $post_content ) {
			// Gutenberg wp:file block comments: <!-- wp:file {"id":123,...} -->
			if ( preg_match_all( '/<!--\s+wp:file\s+(\{[^>]+\})\s+-->/i', $post_content, $block_matches ) ) {
				foreach ( $block_matches[1] as $json_str ) {
					$block_data = json_decode( $json_str, true );
					if ( ! empty( $block_data['id'] ) ) {
						$fpath = get_attached_file( (int) $block_data['id'] );
						if ( $fpath && file_exists( $fpath ) ) {
							$found_files[ $fpath ] = (string) get_post_mime_type( (int) $block_data['id'] );
						}
					}
				}
			}

			// Linked or embedded document URLs in href, src, data attributes
			if ( preg_match_all( '/(?:href|src|data)=["\']([^"\']+\.(?:pdf|docx|txt|csv|tsv|md|log))["\']/i', $post_content, $url_matches ) ) {
				$upload_info = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : null;
				foreach ( $url_matches[1] as $doc_url ) {
					// Try getting attachment ID
					$att_id = function_exists( 'attachment_url_to_postid' ) ? attachment_url_to_postid( $doc_url ) : 0;
					if ( $att_id ) {
						$fpath = get_attached_file( $att_id );
						if ( $fpath && file_exists( $fpath ) ) {
							$found_files[ $fpath ] = (string) get_post_mime_type( $att_id );
							continue;
						}
					}

					// Fallback to local upload directory path mapping
					if ( $upload_info && ! empty( $upload_info['baseurl'] ) && ! empty( $upload_info['basedir'] ) ) {
						if ( strpos( $doc_url, $upload_info['baseurl'] ) === 0 ) {
							$rel_path  = substr( $doc_url, strlen( $upload_info['baseurl'] ) );
							$full_path = rtrim( $upload_info['basedir'], '/\\' ) . '/' . ltrim( $rel_path, '/\\' );
							if ( file_exists( $full_path ) ) {
								$found_files[ $full_path ] = '';
							}
						}
					}
				}
			}
		}

		// 3. Scan post meta (ACF File fields, custom upload meta)
		$meta_data = get_post_meta( $post_id );
		if ( is_array( $meta_data ) ) {
			foreach ( $meta_data as $key => $values ) {
				if ( strpos( (string) $key, '_wpts_' ) === 0 ) continue;
				foreach ( (array) $values as $val ) {
					if ( is_numeric( $val ) && (int) $val > 0 ) {
						$mime = (string) get_post_mime_type( (int) $val );
						if ( strpos( $mime, 'pdf' ) !== false || strpos( $mime, 'document' ) !== false || strpos( $mime, 'text' ) !== false ) {
							$fpath = get_attached_file( (int) $val );
							if ( $fpath && file_exists( $fpath ) ) {
								$found_files[ $fpath ] = $mime;
							}
						}
					}
				}
			}
		}

		if ( empty( $found_files ) ) {
			return '';
		}

		$all_text = [];
		foreach ( $found_files as $file_path => $mime ) {
			$text = self::extract_text_from_file( $file_path, (string) $mime );
			if ( '' !== $text ) {
				$all_text[] = $text;
			}
		}

		return implode( ' ', $all_text );
	}

	/**
	 * Extract raw text from a local document file (PDF, DOCX, TXT, CSV, TSV, MD).
	 */
	public static function extract_text_from_file( string $file_path, string $mime = '' ): string {
		if ( ! is_readable( $file_path ) ) {
			return '';
		}

		$ext = strtolower( (string) pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// 1. Plain text, CSV, TSV, Markdown, Log files
		if ( in_array( $ext, [ 'txt', 'csv', 'tsv', 'md', 'log' ], true ) ||
			 in_array( $mime, [ 'text/plain', 'text/csv', 'text/tab-separated-values', 'text/markdown' ], true ) ) {
			$content = file_get_contents( $file_path, false, null, 0, 500000 ); // Read up to 500KB
			return Utils::clean_text( (string) $content );
		}

		// 2. PDF text extraction (decompressed streams, CMaps, + pdftotext)
		if ( 'pdf' === $ext || 'application/pdf' === $mime ) {
			return self::extract_pdf_text( $file_path );
		}

		// 3. DOCX text extraction (unzip word/document.xml)
		if ( 'docx' === $ext || 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' === $mime ) {
			if ( class_exists( '\ZipArchive' ) ) {
				$zip = new \ZipArchive();
				if ( true === $zip->open( $file_path ) ) {
					$xml = $zip->getFromName( 'word/document.xml' );
					$zip->close();
					if ( $xml ) {
						// Separate XML nodes with space before stripping tags
						$spaced_xml = str_replace( [ '</w:p>', '</w:r>', '</w:t>', '</w:tc>', '</w:tr>' ], ' ', $xml );
						return Utils::clean_text( $spaced_xml );
					}
				}
			}
		}

		return '';
	}

	/**
	 * Extract text from PDF using decompression, stream parsing, CMap decoding, and CLI fallback.
	 */
	public static function extract_pdf_text( string $file_path ): string {
		// 1. Try pdftotext CLI tool if available on server
		if ( function_exists( 'exec' ) && function_exists( 'escapeshellarg' ) ) {
			$check = @exec( 'which pdftotext 2>/dev/null' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.exec_exec, Generic.PHP.NoSilencedErrors.Discouraged
			if ( $check ) {
				$out = [];
				@exec( 'pdftotext -q -enc UTF-8 ' . escapeshellarg( $file_path ) . ' - 2>/dev/null', $out ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.exec_exec, Generic.PHP.NoSilencedErrors.Discouraged
				$cli_text = implode( ' ', $out );
				if ( '' !== trim( $cli_text ) ) {
					return Utils::clean_text( $cli_text );
				}
			}
		}

		$content = file_get_contents( $file_path, false, null, 0, 4000000 ); // Read up to 4MB
		if ( ! $content ) {
			return '';
		}

		$all_stream_text = [];
		$cmap            = [];

		// 2. Extract and parse all streams in the PDF
		$stream_count = preg_match_all( '/stream[\r\n]+([\s\S]*?)[\r\n]+endstream/m', $content, $stream_matches );
		if ( $stream_count && ! empty( $stream_matches[1] ) ) {
			foreach ( $stream_matches[1] as $raw_stream ) {
				$stream = $raw_stream;
				$decompressed = false;

				// Try various zlib decompression strategies
				$trimmed = ltrim( $stream, "\r\n\t " );
				if ( false === $decompressed ) $decompressed = @gzuncompress( $trimmed ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Raw PDF zlib stream trial decompression.
				if ( false === $decompressed ) $decompressed = @gzuncompress( $stream ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Raw PDF zlib stream trial decompression.
				if ( false === $decompressed ) $decompressed = @gzinflate( $trimmed ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Raw PDF zlib stream trial decompression.
				if ( false === $decompressed ) $decompressed = @gzinflate( $stream ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- Raw PDF zlib stream trial decompression.
				if ( false === $decompressed && strlen( $trimmed ) > 6 ) {
					$decompressed = @gzinflate( substr( $trimmed, 2, -4 ) ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
				}
				if ( false === $decompressed && function_exists( 'zlib_decode' ) ) {
					$decompressed = @zlib_decode( $trimmed ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
				}
				if ( false === $decompressed ) {
					// Check if uncompressed stream
					if ( strpos( $stream, 'BT' ) !== false || strpos( $stream, 'Tj' ) !== false || strpos( $stream, 'TJ' ) !== false ) {
						$decompressed = $stream;
					}
				}

				if ( false === $decompressed || ! is_string( $decompressed ) ) {
					continue;
				}

				// Check for /ToUnicode CMap mapping tables inside stream
				if ( strpos( $decompressed, 'beginbfchar' ) !== false ) {
					if ( preg_match_all( '/<([0-9a-fA-F]+)>\s+<([0-9a-fA-F]+)>/', $decompressed, $bf_matches, PREG_SET_ORDER ) ) {
						foreach ( $bf_matches as $bm ) {
							$from = strtoupper( $bm[1] );
							$to_hex = $bm[2];
							$to_bin = @hex2bin( $to_hex ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
							if ( $to_bin ) {
								$to_char = ( strlen( $to_hex ) >= 4 && function_exists( 'mb_convert_encoding' ) )
									? @mb_convert_encoding( $to_bin, 'UTF-8', 'UTF-16BE' ) // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
									: $to_bin;
								$cmap[ $from ] = $to_char;
							}
						}
					}
				}

				// Parse text operations from decompressed stream
				$stream_text = self::parse_pdf_stream_text( $decompressed, $cmap );
				if ( '' !== trim( $stream_text ) ) {
					$all_stream_text[] = $stream_text;
				}
			}
		}

		// 3. Fallback: Parse uncompressed BT ... ET blocks directly from file content
		if ( empty( $all_stream_text ) ) {
			$raw_block_text = self::parse_pdf_stream_text( $content, $cmap );
			if ( '' !== trim( $raw_block_text ) ) {
				$all_stream_text[] = $raw_block_text;
			}
		}

		// 4. Ultimate Raw String Matcher
		if ( empty( $all_stream_text ) ) {
			if ( preg_match_all( '/\(([a-zA-Z0-9\s.,;:\-_/@&#+()%]{3,})\)/', $content, $raw_matches ) ) {
				$raw_pieces = [];
				foreach ( $raw_matches[1] as $r_item ) {
					$raw_pieces[] = self::decode_pdf_literal_string( $r_item );
				}
				$all_stream_text[] = implode( ' ', $raw_pieces );
			}
		}

		$combined = implode( ' ', array_filter( $all_stream_text ) );
		return Utils::clean_text( $combined );
	}

	/**
	 * Parse text operators (TJ arrays, Tj strings, hex codes) inside a PDF stream preserving continuous words.
	 */
	private static function parse_pdf_stream_text( string $stream, array $cmap = [] ): string {
		$text = '';

		if ( preg_match_all( '/(?:\[([\s\S]*?)\]\s*TJ|\(((?:[^\\\\\)]|\\\\.)*)\)\s*(?:Tj|\'|\")|<([0-9a-fA-F\s]+)>\s*(?:Tj|\'|\"))/s', $stream, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				if ( ! empty( $m[1] ) ) {
					// TJ array: [(SK)-4(Y)] or [(5)-25(7) ( )-29(Room)]
					$arr = $m[1];
					if ( preg_match_all( '/\(((?:[^\\\\\)]|\\\\.)*)\)|<([0-9a-fA-F]+)>|([-\d.]+)/s', $arr, $tokens, PREG_SET_ORDER ) ) {
						foreach ( $tokens as $t ) {
							if ( isset( $t[1] ) && '' !== $t[1] ) {
								$text .= self::decode_pdf_literal_string( $t[1] );
							} elseif ( isset( $t[2] ) && '' !== $t[2] ) {
								$text .= self::decode_pdf_hex_string( $t[2], $cmap );
							} elseif ( isset( $t[3] ) && '' !== $t[3] ) {
								$num = (float) $t[3];
								// Large negative displacement in PDF coordinate space represents a space between words
								if ( $num <= -200 ) {
									$text .= ' ';
								}
							}
						}
					}
				} elseif ( isset( $m[2] ) && '' !== $m[2] ) {
					// ( ... ) Tj
					$text .= self::decode_pdf_literal_string( $m[2] );
				} elseif ( ! empty( $m[3] ) ) {
					// < ... > Tj
					$text .= self::decode_pdf_hex_string( $m[3], $cmap );
				}
			}
		}

		return $text;
	}

	/**
	 * Decode a PDF literal string with octal and character escape sequences.
	 */
	private static function decode_pdf_literal_string( string $str ): string {
		// Unescape octal numbers like \101 -> 'A', but guard against null byte (octdec 0)
		$str = preg_replace_callback( '/\\\\([0-7]{1,3})/', function ( $m ) {
			$val = octdec( $m[1] );
			if ( 0 === $val || ( $val < 32 && 10 !== $val && 13 !== $val && 9 !== $val ) ) {
				return ' ';
			}
			return chr( $val );
		}, $str );

		// Unescape standard PDF characters
		$str = str_replace(
			[ '\\n', '\\r', '\\t', '\\b', '\\f', '\\(', '\\)', '\\\\' ],
			[ "\n",  "\r",  "\t",  '',    '',    '(',   ')',   '\\' ],
			$str
		);

		return str_replace( "\0", '', $str );
	}

	/**
	 * Decode a PDF hexadecimal string with CMap or UTF-16BE / ASCII conversion.
	 */
	private static function decode_pdf_hex_string( string $hex, array $cmap = [] ): string {
		if ( ! empty( $cmap ) ) {
			$len = strlen( $hex );
			$decoded = '';
			for ( $i = 0; $i < $len; $i += 4 ) {
				$code = strtoupper( substr( $hex, $i, 4 ) );
				if ( isset( $cmap[ $code ] ) ) {
					$decoded .= $cmap[ $code ];
				}
			}
			if ( '' !== $decoded ) {
				return str_replace( "\0", '', $decoded );
			}
		}

		$bin = @hex2bin( $hex ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
		if ( ! $bin ) {
			return '';
		}

		$bin = str_replace( "\0", '', $bin );

		// Try UTF-16BE decoding
		if ( strlen( $hex ) >= 4 && function_exists( 'mb_convert_encoding' ) ) {
			$converted = @mb_convert_encoding( $bin, 'UTF-8', 'UTF-16BE' ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			if ( ! empty( $converted ) && preg_match( '/[\p{L}\p{N}]/u', $converted ) ) {
				return str_replace( "\0", '', $converted );
			}
		}

		// Standard ASCII
		$ascii = preg_replace( '/[^\x20-\x7E\p{L}\p{N}\s.,;:\-_]/u', ' ', $bin );
		if ( null === $ascii ) {
			$ascii = preg_replace( '/[^a-zA-Z0-9\s.,;:\-_]/', ' ', $bin );
		}
		return (string) $ascii;
	}
}


