<?php
/**
 * Summary.
 *
 * @since Version 3 digits
 */
class WP_CLI_Product_Importer_Manage_Product {
	private static $api_url = 'https://dummyjson.com/products';
	/**
	 * Summary.
	 *
	 * Description.
	 *
	 * @since Version 3 digits
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'init' ) );
	}
	/**
	 * Initiate WP CLI commands
	 */
	public function init() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'start import-products', array( $this, 'import_products' ) );
			WP_CLI::add_command( 'start delete-products', array( $this, 'delete_products' ) );
		}
	}

	/**
	 * Fetch products from dummyjson.com/products
	 *
	 * @return void
	 */
	public function gs_fetch_product_from_api() {
		$response = wp_remote_get( self::$api_url );
		if ( is_wp_error( $response ) ) {
			\WP_CLI::error( 'Failed to fetch data from the API' );
			return array();
		}
		$response_body     = wp_remote_retrieve_body( $response );
		$response_body_obj = json_decode( $response_body, true );
		$product_arr       = $response_body_obj['products'];
		/**
		 * If no products found then return error
		 */
		if ( empty( $product_arr ) ) {
			\WP_CLI::error( 'No products found' );
			return array();
		}
		return $product_arr;
	}
	/**
	 * Check if product exists
	 *
	 * @param [string] $sku - product sku.
	 * @return void
	 */
	public function gs_check_product_exists( $sku ) {
		global $wpdb;
		/**
		 * get post id by meta key and value
		 */
		$product_exists = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sku' AND meta_value = %s", $sku ) );
		if ( $product_exists ) {
			return array(
				'product_id'     => $product_exists,
				'product_status' => true,
			);
		}
		return array(
			'product_id'     => false,
			'product_status' => false,
		);
	}
	/**
	 * Insert or update products in woocommerce
	 *
	 * @param [array] $product - product array.
	 * @return void
	 */
	public function gs_manage_products_in_woocommerce( $product ) {
		/**
		 * Check if product already exists
		 */
		$product_exists = $this->gs_check_product_exists( 'gs-dummy-' . $product['sku'] );
		if ( $product_exists['product_status'] ) {
			\WP_CLI::warning( '❗️ Product already exists: ' . $product['title'] );
			return false;
		} else {
			/**
			 * Insert new product
			 */
			\WP_CLI::log( 'Inserting new proudct where title is - : ' . $product['title'] );
			$product_id = wp_insert_post(
				array(
					'post_title'   => $product['title'],
					'post_content' => $product['description'],
					'post_status'  => 'publish',
					'post_type'    => 'product',
				)
			);
			/**
			 * if something wrong then return false
			 */
			if ( is_wp_error( $product_id ) || ! $product_id ) {
				\WP_CLI::warning( '❌ Failed to insert product: ' . $product['title'] );
				return false;
			}
			\WP_CLI::log( "✅ Product inserted. Now adding Meta for - {$product['title']}" );
			/**
			 * Set product price
			 */
			update_post_meta( $product_id, '_regular_price', $product['price'] );
			update_post_meta( $product_id, '_price', $product['price'] );
			/**
			 * Set product SKU
			 */
			update_post_meta( $product_id, '_sku', 'gs-dummy-' . $product['sku'] );
			/**
			 * Set stock
			 */
			if ( $product['stock'] > 0 ) {
				update_post_meta( $product_id, '_manage_stock', 'yes' );
				update_post_meta( $product_id, '_stock', $product['stock'] );
				update_post_meta( $product_id, '_stock_status', 'instock' );
			} else {
				update_post_meta( $product_id, '_manage_stock', 'no' );
				// update_post_meta( $product_id, '_stock_status', 'outofstock' );
			}
			// Add dimensions

			update_post_meta( $product_id, '_length', $product['dimensions']['depth'] ?? '0' );
			update_post_meta( $product_id, '_width', $product['dimensions']['width'] ?? '0' );
			update_post_meta( $product_id, '_height', $product['dimensions']['height'] ?? '0' );

			// Add weight
			update_post_meta( $product_id, '_weight', $product['weight'] ?? '0' );

			// Add brand as a custom meta field or taxonomy
			$brand = $product['brand'] ?? 'Unknown Brand';
			update_post_meta( $product_id, 'brand', sanitize_text_field( $brand ) );

			// Add warranty information as a custom meta field
			$warranty = $product['warrantyInformation'] ?? 'No warranty information provided.';
			update_post_meta( $product_id, 'warrantyInformation', sanitize_textarea_field( $warranty ) );

			// Add shipping information (custom tab)
			$shipping_info = $product['shippingInformation'] ?? 'No shipping information provided.';
			update_post_meta( $product_id, 'shipping_tab_information', sanitize_textarea_field( $shipping_info ) );

			// Return policy
			$return_policy = $product['returnPolicy'] ?? 'No return policy.';
			update_post_meta( $product_id, 'return_policy', sanitize_textarea_field( $return_policy ) );

			\WP_CLI::log( "✅ Meta information added for product - {$product['title']}" );

			// Add categories and tags
			if ( ! empty( $product['category'] ) ) {
				$cat_assign_status = wp_set_object_terms( $product_id, $product['category'], 'product_cat' );
				if ( is_wp_error( $cat_assign_status ) ) {
					\WP_CLI::warning( '❌ Failed to assign category: ' . $product['category'] );
				} else {
					\WP_CLI::log( '✅ Category assigned on - ' . $product['title'] );
				}
			}

			if ( ! empty( $product['tags'] ) ) {
				$tag_assign_status = wp_set_object_terms( $product_id, $product['tags'], 'product_tag' );
				if ( is_wp_error( $tag_assign_status ) ) {
					\WP_CLI::warning( '❌ Failed to assign tags: ' . $product['category'] );
				} else {
					\WP_CLI::log( '✅ Tag assigned on - ' . $product['title'] );
				}
			}

			/**
			 * Add featured image
			 */
			if ( ! empty( $product['thumbnail'] ) ) {
				$featured_image_id = $this->gs_download_image( $product['thumbnail'], $product_id );
				if ( $featured_image_id ) {
					set_post_thumbnail( $product_id, $featured_image_id, true );
					\WP_CLI::log( '✅ Featured image added on - ' . $product['title'] );
				}
			}

			/**
			 * Add product gallery images
			 */
			if ( ! empty( $product['images'] ) ) {
				$gallery_ids = array();
				foreach ( $product['images'] as $image_url ) {
					$image_id = $this->gs_download_image( $image_url, $product_id, true );
					if ( $image_id ) {
						$gallery_ids[] = $image_id;
					}
				}
				if ( ! empty( $gallery_ids ) ) {
					update_post_meta( $product_id, '_product_image_gallery', implode( ',', $gallery_ids ) );
					\WP_CLI::log( '✅ Gallery images added on - ' . $product['title'] );
				}
			}

			// Add categories
			// if (!empty($product['categories'])) {
			// $categories = [];
			// foreach ($product['categories'] as $category) {
			// $term = term_exists($category, 'product_cat');
			// if ($term) {
			// $categories[] = $term['term_id'];
			// } else {
			// $term = wp_insert_term($category, 'product_cat');
			// if (!is_wp_error($term)) {
			// $categories[] = $term['term_id'];
			// }
			// }
			// }
			// wp_set_object_terms($product_id, $categories, 'product_cat');
			// }

			// // Add tags
			// if (!empty($product['tags'])) {
			// $tags = [];
			// foreach ($product['tags'] as $tag) {
			// $term = term_exists($tag, 'product_tag');
			// if ($term) {
			// $tags[] = $term['term_id'];
			// } else {
			// $term = wp_insert_term($tag, 'product_tag');
			// if (!is_wp_error($term)) {
			// $tags[] = $term['term_id'];
			// }
			// }
			// }
			// wp_set_object_terms($product_id, $tags, 'product_tag');
			// }

			// Add reviews
			if ( ! empty( $product['reviews'] ) ) {
				foreach ( $product['reviews'] as $review ) {
					$this->gs_add_product_review( $product_id, $review );
				}
			}
			/**
			 * Log the info in the wp-cli
			 */
			\WP_CLI::success( 'Product inserted: ' . $product['title'] );
		}
	}
	/**
	 * Add product review
	 *
	 * @param [string] $product_id - product id.
	 * @param [array]  $review_data - review data.
	 */
	private function gs_add_product_review( $product_id, $review_data ) {
		$comment_data = array(
			'comment_post_ID'      => $product_id,
			'comment_author'       => $review_data['reviewerName'] ?? 'Anonymous',
			'comment_author_email' => $review_data['reviewerEmail'] ?? '',
			'comment_content'      => $review_data['comment'] ?? 'No review content provided.',
			'comment_type'         => 'review',
			'comment_date'         => $review_data['date'] ?? current_time( 'mysql' ),
			'comment_approved'     => 1,
		);

		$comment_id = wp_insert_comment( $comment_data );

		if ( ! is_wp_error( $comment_id ) ) {
			update_comment_meta( $comment_id, 'rating', $review_data['rating'] ?? 0 );
			\WP_CLI::log( "✅ Review added for product ID {$product_id} (Review ID: {$comment_id})" );
		} else {
			\WP_CLI::warning( "❌ Failed to add review for product ID {$product_id}" );
		}
	}
	/**
	 * Download image and attach to post
	 *
	 * @param [string] $image_url - image url to download.
	 * @param [string] $post_id - post id to attach image.
	 * @param boolean  $show_loading - show loading message.
	 * @return void
	 */
	private function gs_download_image( $image_url, $post_id, $show_loading = false ) {
		/**
		 * Show loading message
		 */
		if ( $show_loading ) {
			$this->gs_show_loading_message( 'Downloading image...' );
		}
		/**
		 * Download image from the URL
		 */
		$temp_file = download_url( $image_url );
		/**
		 * If failed to download image then return false and show warning in the wp-cli
		 */
		if ( is_wp_error( $temp_file ) ) {
			\WP_CLI::warning( "❌ Failed to download image: $image_url" );
			return false;
		}
		/**
		 * Prepare file info to attach image
		 */
		$file_info = array(
			'name'     => basename( $image_url ),
			'type'     => mime_content_type( $temp_file ),
			'tmp_name' => $temp_file,
			'error'    => 0,
			'size'     => filesize( $temp_file ),
		);
		/**
		 * Attach image to product
		 */
		$attachment_id = media_handle_sideload( $file_info, $post_id );
		/**
		 * Remove temp file
		 */
		@unlink( $temp_file );
		/**
		 * If failed to attach image then return false and show warning in the wp-cli
		 */
		if ( is_wp_error( $attachment_id ) ) {
			\WP_CLI::warning( "❌ Failed to attach image: $image_url" );
			return false;
		}
		/**
		 * If image attached successfully then show success message in the wp-cli
		 */
		if ( $show_loading ) {
			\WP_CLI::log( '✅ Image downloaded and attached successfully!' );
		}
		/**
		 * Return attachment id
		 */
		return $attachment_id;
	}
	/**
	 * Show loading message
	 *
	 * @param [string] $message - message to show.
	 * @return void
	 */
	private function gs_show_loading_message( $message ) {
		// Display a loading message with animation.
		$dots = '';
		for ( $i = 0; $i < 3; $i++ ) {
			$dots .= '.';
			\WP_CLI::line( "$message$dots" );
			sleep( 1 ); // Simulate loading delay.
		}
	}
	/**
	 * Main CLI function to import products.
	 *
	 * @return void
	 */
	public function import_products() {
		$product_arr = $this->gs_fetch_product_from_api();
		/**
		 * Loop through the products and insert or update in woocommerce
		 */
		if ( ! empty( $product_arr ) ) {
			foreach ( $product_arr as $product ) {
				if ( $product ) {
					$this->gs_manage_products_in_woocommerce( $product );
				}
			}
		}
	}
	/**
	 * Main CLI function to delete products.
	 */
	public function delete_products() {
		$product_arr = $this->gs_fetch_product_from_api();
		/**
		 * Loop through the products and delete from woocommerce
		 */
		if ( ! empty( $product_arr ) ) {
			foreach ( $product_arr as $product ) {
				if ( $product ) {
					$product_sku                  = 'gs-dummy-' . $product['sku'];
					$product_available_status_arr = $this->gs_check_product_exists( $product_sku );
					// dump($product_available_status_arr);
					$product_id                   = $product_available_status_arr['product_id'] ?? false;
					// if ( ! $product_id ) {
						// \WP_CLI::warning( '❗️ Invalid product ID.' );
						// break;
					// }
					if ( $product_available_status_arr['product_status'] ) {
						// Remove attached featured and gallery images.
						$this->gs_remove_attached_images( $product_id );
						$this->gs_delete_inserted_product( $product_available_status_arr['product_id'] );
					} else {
						\WP_CLI::warning( '❗️ Product not found: ' . $product['title'] );
					}
				}
			}
		}
	}
	/**
	 * Manage to remove attached images for a product
	 *
	 * @param [string] $product_id - product id.
	 */
	private function gs_remove_attached_images( $product_id ) {
		// Remove featured image
		$featured_image_id = get_post_thumbnail_id( $product_id );
		if ( $featured_image_id ) {
			wp_delete_attachment( $featured_image_id, true );
			\WP_CLI::log( "✅ Deleted featured image (ID: $featured_image_id) for product ID - $product_id." );
		}

		// Remove gallery images
		$gallery_image_ids = get_post_meta( $product_id, '_product_image_gallery', true );
		if ( ! empty( $gallery_image_ids ) ) {
			$gallery_image_ids = explode( ',', $gallery_image_ids );
			foreach ( $gallery_image_ids as $image_id ) {
				wp_delete_attachment( $image_id, true );
				\WP_CLI::log( "✅ Deleted gallery image (ID: $image_id) for product ID - $product_id." );
			}
		}
	}
	/**
	 * Delete inserted product
	 *
	 * @param [string] $product_id - product id.
	 * @return void
	 */
	public function gs_delete_inserted_product( $product_id ) {
		wp_delete_post( $product_id, true );
		\WP_CLI::success( 'Product deleted: ' . $product_id );
	}

}