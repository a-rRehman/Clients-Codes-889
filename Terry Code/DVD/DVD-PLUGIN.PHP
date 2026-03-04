<?php
/**
 * Plugin Name: WooCommerce - DVD Film Product Type
 * Description: Adds "DVD" (simple clone) and force-applies Virtual (no shipping UI), Taxable + Standard tax class, In stock on save. Adds DVD SEO helpers (visible) + Yoast sync + forced categories.
 * Version:     1.0.2
 * Author:      Rehman
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const DVD_SLUG = 'dvd_film';

/** 1) Add product type to the classic editor dropdown */
add_filter( 'product_type_selector', function( $types ) {
	$types[ DVD_SLUG ] = __( 'DVD', 'woocommerce' );
	return $types;
}, PHP_INT_MAX );

/** 2) Map product type slug -> class */
add_filter( 'woocommerce_product_class', function( $classname, $product_type ) {
	if ( $product_type === DVD_SLUG ) return 'WC_Product_DVD_Film';
	return $classname;
}, 10, 2 );

/** 3) Declare product class after Woo loads */
add_action( 'woocommerce_loaded', function() {
	if ( class_exists( 'WC_Product_Simple' ) && ! class_exists( 'WC_Product_DVD_Film' ) ) {
		class WC_Product_DVD_Film extends WC_Product_Simple {
			public function get_type() { return DVD_SLUG; }
		}
	}
});

/** Ensure add-to-cart appears on single DVD product pages */
add_action( 'woocommerce_' . DVD_SLUG . '_add_to_cart', function() {
	wc_get_template( 'single-product/add-to-cart/simple.php' );
});

/** Ensure add-to-cart URL behaves like simple */
add_filter( 'woocommerce_product_add_to_cart_url', function( $url, $product ) {
	if ( $product && $product->get_type() === DVD_SLUG ) {
		return $product->is_purchasable() && $product->is_in_stock()
			? remove_query_arg( 'added-to-cart', add_query_arg( 'add-to-cart', $product->get_id() ) )
			: get_permalink( $product->get_id() );
	}
	return $url;
}, 10, 2 );

/** 4) Data store mapping (same as simple) */
add_filter( 'woocommerce_data_stores', function( $stores ) {
	$stores[ 'product-' . DVD_SLUG ] = 'WC_Product_Data_Store_CPT';
	return $stores;
});

/** 5) Ensure anything visible for 'simple' is also visible for 'dvd_film' */
add_filter( 'woocommerce_product_data_tabs', function( $tabs ) {
	foreach ( $tabs as $key => $tab ) {
		$classes = isset( $tab['class'] ) ? (array) $tab['class'] : array();
		if ( in_array( 'show_if_simple', $classes, true ) ) {
			$tabs[ $key ]['class'][] = 'show_if_dvd_film';
		}
	}
	return $tabs;
}, 1000 );

/** Sync admin UI show classes */
add_action( 'admin_footer-post.php',     'dvd_sync_show_classes', 1000 );
add_action( 'admin_footer-post-new.php', 'dvd_sync_show_classes', 1000 );
function dvd_sync_show_classes() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->post_type !== 'product' ) return;
	?>
	<script>
	jQuery(function($){
		$('.show_if_simple, .options_group.show_if_simple').addClass('show_if_dvd_film');
		$('#general_product_data, #inventory_product_data').addClass('show_if_dvd_film');

		var $type = $('#product-type');
		if ($type.length) {
			$type.trigger('change');
			$(document.body).trigger('woocommerce-product-type-change', [ $type.val() ]);
		}
	});
	</script>
	<?php
}

/** 6) Enforce settings on save (DVD only) */
function dvd_apply_defaults_on_object( WC_Product $product ) {
	if ( ! $product instanceof WC_Product ) return;
	if ( $product->get_type() !== DVD_SLUG ) return;

	// Behave like TVOD: no shipping UI
	$product->set_virtual( true );

	// Taxable + Standard (Standard is empty string in Woo)
	$product->set_tax_status( 'taxable' );
	$product->set_tax_class( '' );

	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
}
add_action( 'woocommerce_before_product_object_save',   'dvd_apply_defaults_on_object', PHP_INT_MAX );
add_action( 'woocommerce_admin_process_product_object', 'dvd_apply_defaults_on_object', PHP_INT_MAX );

/** 7) Final safety net meta update */
add_action( 'save_post_product', function( $post_id, $post, $update ) {
	$product = wc_get_product( $post_id );
	if ( ! $product || $product->get_type() !== DVD_SLUG ) return;

	update_post_meta( $post_id, '_virtual', 'yes' );
	update_post_meta( $post_id, '_tax_status', 'taxable' );
	update_post_meta( $post_id, '_tax_class', '' ); // Standard
	update_post_meta( $post_id, '_stock_status', 'instock' );
	update_post_meta( $post_id, '_manage_stock', 'no' );
}, PHP_INT_MAX, 3 );

/**
 * 8) Admin UI defaults + hide shipping tab + SEO autofill
 * IMPORTANT: WooCommerce resets fields after product-type change, so we re-apply after that event.
 */
add_action( 'admin_footer-post.php',     'dvd_forced_defaults_js', 1001 );
add_action( 'admin_footer-post-new.php', 'dvd_forced_defaults_js', 1001 );
function dvd_forced_defaults_js() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->post_type !== 'product' ) return;
	?>
	<script>
	jQuery(function($){

		function isDVD(){
			return $('#product-type').val() === '<?php echo DVD_SLUG; ?>';
		}

		function applyDefaults(){
			if ( isDVD() ) {

				// Virtual on, lock it (so shipping disappears)
				$('#_virtual').prop('checked', true).trigger('change').prop('disabled', true);

				// Hide shipping tab and panel
				$('.woocommerce_product_data_tabs li#general_product_data_shipping').hide();
				$('#shipping_product_data').hide();

			} else {

				$('#_virtual').prop('disabled', false);

				$('.woocommerce_product_data_tabs li#general_product_data_shipping').show();
				$('#shipping_product_data').show();
			}
		}

		// Force tax fields AFTER WooCommerce product-type scripts run
		function forceTaxFields(){
			if ( !isDVD() ) return;

			var $taxStatus = $('select[name="_tax_status"]');
			var $taxClass  = $('select[name="_tax_class"]');

			if ($taxStatus.length) {
				$taxStatus.val('taxable').trigger('change').prop('disabled', true);
			}

			// Standard is empty string in Woo
			if ($taxClass.length) {
				$taxClass.val('').trigger('change').prop('disabled', true);
			}
		}

		// Auto-fill SEO Title from Product Title (only if blank)
		function autoFillSeoTitle(){
			if ( !isDVD() ) return;

			var $title = $('#title');
			var $seo   = $('#_dvd_seo_title_override');
			if ( !$title.length || !$seo.length ) return;

			if ( ($seo.val() || '').trim() !== '' ) return;

			var filmName = ($title.val() || '').trim();
			if ( filmName === '' ) return;

			$seo.val(filmName + ' DVD %%page%% %%sep%% %%sitename%%');
		}

		// Auto-fill Summary from Product Description (classic editor #content), only if blank
		function autoFillSummary(){
			if ( !isDVD() ) return;

			var $sum = $('#_dvd_seo_one_line_summary');
			if ( !$sum.length ) return;

			if ( ($sum.val() || '').trim() !== '' ) return;

			var desc = '';
			if ( $('#content').length ) desc = $('#content').val() || '';

			desc = (desc || '')
				.replace(/<[^>]*>/g,'')
				.replace(/\s+/g,' ')
				.trim();

			if ( !desc ) return;

			$sum.val(desc.substring(0, 200));
		}

		function runAll(){
			applyDefaults();
			forceTaxFields();
			autoFillSeoTitle();
			autoFillSummary();
		}

		// Initial run (slightly delayed so Woo scripts finish)
		setTimeout(runAll, 120);

		// Product type change
		$('#product-type').on('change', function(){
			setTimeout(runAll, 200);
		});

		// Woo event
		$(document.body).on('woocommerce-product-type-change', function(){
			setTimeout(runAll, 200);
		});

		// Title typing
		$('#title').on('input', function(){
			autoFillSeoTitle();
		});

		// Description typing (classic editor)
		$('#content').on('input', function(){
			autoFillSummary();
		});

	});
	</script>
	<?php
}

/**
 * 9) DVD SEO fields (admin UI) + Yoast sync + forced categories
 */

/** A) Add 2 fields to DVD product edit screen (VISIBLE) */
add_action( 'woocommerce_product_options_general_product_data', function() {

	echo '<div class="options_group show_if_dvd_film">';

	woocommerce_wp_text_input( array(
		'id'          => '_dvd_seo_title_override',
		'label'       => __( 'DVD SEO Title (optional)', 'woocommerce' ),
		'desc_tip'    => true,
		'description' => __( 'Leave blank to auto-generate: [Film Name] DVD %%page%% %%sep%% %%sitename%%', 'woocommerce' ),
	) );

	woocommerce_wp_textarea_input( array(
		'id'          => '_dvd_seo_one_line_summary',
		'label'       => __( 'DVD SEO One-line Summary (optional)', 'woocommerce' ),
		'desc_tip'    => true,
		'description' => __( 'Leave blank to auto-fill from product description (first 200 chars). Used in meta description.', 'woocommerce' ),
	) );

	echo '</div>';

}, 100 );

/** B) Save those 2 fields */
add_action( 'woocommerce_admin_process_product_object', function( $product ) {
	if ( ! $product || $product->get_type() !== DVD_SLUG ) return;

	$title_override = isset($_POST['_dvd_seo_title_override']) ? wc_clean( wp_unslash($_POST['_dvd_seo_title_override']) ) : '';
	$summary        = isset($_POST['_dvd_seo_one_line_summary']) ? wc_clean( wp_unslash($_POST['_dvd_seo_one_line_summary']) ) : '';

	$product->update_meta_data( '_dvd_seo_title_override', $title_override );
	$product->update_meta_data( '_dvd_seo_one_line_summary', $summary );
}, PHP_INT_MAX );

/** Helper: safe substring (mbstring optional) */
function dvd_safe_substr( $text, $start, $length ) {
	if ( function_exists( 'mb_substr' ) ) return mb_substr( $text, $start, $length );
	return substr( $text, $start, $length );
}

/** C) On save, write Yoast SEO fields and force categories (DVD only) */
add_action( 'save_post_product', function( $post_id, $post, $update ) {

	$product = wc_get_product( $post_id );
	if ( ! $product || $product->get_type() !== DVD_SLUG ) return;

	$film_name      = $product->get_name();
	$title_override = get_post_meta( $post_id, '_dvd_seo_title_override', true );
	$summary        = get_post_meta( $post_id, '_dvd_seo_one_line_summary', true );

	// 1) SEO Title: [Film Name] DVD %%page%% %%sep%% %%sitename%%
	$seo_title = trim( (string) $title_override );
	if ( $seo_title === '' ) {
		$seo_title = $film_name . ' DVD %%page%% %%sep%% %%sitename%%';
	}

	// 2) Meta Description: Buy %%title%% DVD. [ONE LINE SUMMARY ABOUT THE FILM]
	$meta_desc = 'Buy %%title%% DVD.';

	$summary = trim( (string) $summary );
	if ( $summary === '' ) {
		$raw_desc   = (string) $product->get_description();
		$clean_desc = wp_strip_all_tags( $raw_desc );
		$clean_desc = preg_replace( '/\s+/', ' ', $clean_desc );
		$clean_desc = trim( $clean_desc );

		if ( $clean_desc !== '' ) {
			$summary = trim( dvd_safe_substr( $clean_desc, 0, 200 ) );
		}
	}

	if ( $summary !== '' ) {
		$meta_desc .= ' ' . $summary;
	}

	// 3) Update Yoast fields only if Yoast is present
	if ( defined('WPSEO_VERSION') || class_exists('WPSEO_Meta') ) {
		update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
	}

	// 4) Force categories: Cinema and Cinema - DVD (merge, do not remove existing)
	// If your category is "Cinema – DVD" (EN DASH), change the second string to match exactly.
	$required_names = array( 'Cinema', 'Cinema - DVD' );
	$required_ids   = array();

	foreach ( $required_names as $name ) {
		$term = get_term_by( 'name', $name, 'product_cat' );
		if ( $term && ! is_wp_error( $term ) ) {
			$required_ids[] = (int) $term->term_id;
		}
	}

	if ( ! empty( $required_ids ) ) {
		$current_ids = $product->get_category_ids();
		$merged      = array_values( array_unique( array_merge( $current_ids, $required_ids ) ) );
		wp_set_object_terms( $post_id, $merged, 'product_cat' );
	}

}, PHP_INT_MAX, 3 );

/**
 * FIX: WooCommerce admin layout breaking due to narrow options panel
 */
add_action( 'admin_head', function () {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen ) return;

	if ( ! in_array( $screen->post_type, array( 'product', 'shop_coupon' ), true ) ) return;

	echo '<style>
		#woocommerce-coupon-data .wc-metaboxes-wrapper,
		#woocommerce-coupon-data .woocommerce_options_panel,
		#woocommerce-product-data .wc-metaboxes-wrapper,
		#woocommerce-product-data .woocommerce_options_panel {
			float: right;
			width: 80%;
		}
	</style>';
});