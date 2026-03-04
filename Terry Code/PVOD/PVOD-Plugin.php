<?php
/**
 * Plugin Name: WooCommerce - PVOD Film Product Type
 * Description: Adds "PVOD Film" (simple clone) and force-applies Virtual, Tax None/Zero rate, In stock, Sold individually on save. Adds PVOD SEO helpers + Yoast sync + forced categories.
 * Version:     1.0.5
 * Author:      Rehman
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const PVOD_SLUG = 'pvod_film';

/** 1) Add product type to the classic editor dropdown */
add_filter( 'product_type_selector', function( $types ) {
	$types[ PVOD_SLUG ] = __( 'PVOD Film', 'woocommerce' );
	return $types;
}, PHP_INT_MAX );

/** 2) Map product type slug -> class */
add_filter( 'woocommerce_product_class', function( $classname, $product_type ) {
	if ( $product_type === PVOD_SLUG ) {
		return 'WC_Product_PVOD_Film';
	}
	return $classname;
}, 10, 2 );

/** 3) Declare product class after Woo loads */
add_action( 'woocommerce_loaded', function() {
	if ( class_exists( 'WC_Product_Simple' ) && ! class_exists( 'WC_Product_PVOD_Film' ) ) {
		class WC_Product_PVOD_Film extends WC_Product_Simple {
			public function get_type() { return PVOD_SLUG; }
		}
	}
});

/** Ensure add-to-cart appears on single PVOD Film product pages */
add_action( 'woocommerce_' . PVOD_SLUG . '_add_to_cart', function() {
	wc_get_template( 'single-product/add-to-cart/simple.php' );
});

/** Ensure add-to-cart URL behaves like simple */
add_filter( 'woocommerce_product_add_to_cart_url', function( $url, $product ) {
	if ( $product && $product->get_type() === PVOD_SLUG ) {
		return $product->is_purchasable() && $product->is_in_stock()
			? remove_query_arg( 'added-to-cart', add_query_arg( 'add-to-cart', $product->get_id() ) )
			: get_permalink( $product->get_id() );
	}
	return $url;
}, 10, 2 );

/** 4) Data store mapping (same as simple) */
add_filter( 'woocommerce_data_stores', function( $stores ) {
	$stores[ 'product-' . PVOD_SLUG ] = 'WC_Product_Data_Store_CPT';
	return $stores;
});

/** 5) Ensure that anything visible for 'simple' is also visible for 'pvod_film' */
add_filter( 'woocommerce_product_data_tabs', function( $tabs ) {
	foreach ( $tabs as $key => $tab ) {
		$classes = isset( $tab['class'] ) ? (array) $tab['class'] : array();
		if ( in_array( 'show_if_simple', $classes, true ) ) {
			$tabs[ $key ]['class'][] = 'show_if_pvod_film';
		}
	}
	return $tabs;
}, 1000 );

add_action( 'admin_footer-post.php',     'pvod_sync_show_classes', 1000 );
add_action( 'admin_footer-post-new.php', 'pvod_sync_show_classes', 1000 );
function pvod_sync_show_classes() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->post_type !== 'product' ) return;
	?>
	<script>
	jQuery(function($){
		$('.show_if_simple, .options_group.show_if_simple').addClass('show_if_pvod_film');
		$('#general_product_data, #inventory_product_data').addClass('show_if_pvod_film');

		var $type = $('#product-type');
		if ($type.length) {
			$type.trigger('change');
			$(document.body).trigger('woocommerce-product-type-change', [ $type.val() ]);
		}
	});
	</script>
	<?php
}

/** 6) Enforce your settings on save */
function pvod_apply_defaults_on_object( WC_Product $product ) {
	if ( ! $product instanceof WC_Product ) return;
	if ( $product->get_type() !== PVOD_SLUG ) return;

	$product->set_virtual( true );

	$product->set_tax_status( 'none' );
	$product->set_tax_class( 'zero-rate' );

	$product->set_manage_stock( false );
	$product->set_stock_status( 'instock' );
	$product->set_sold_individually( true );
}
add_action( 'woocommerce_before_product_object_save',   'pvod_apply_defaults_on_object', PHP_INT_MAX );
add_action( 'woocommerce_admin_process_product_object', 'pvod_apply_defaults_on_object', PHP_INT_MAX );

/** 7) Final safety net meta update */
add_action( 'save_post_product', function( $post_id, $post, $update ) {
	$product = wc_get_product( $post_id );
	if ( ! $product || $product->get_type() !== PVOD_SLUG ) return;

	update_post_meta( $post_id, '_virtual', 'yes' );
	update_post_meta( $post_id, '_tax_status', 'none' );
	update_post_meta( $post_id, '_tax_class', 'zero-rate' );
	update_post_meta( $post_id, '_sold_individually', 'yes' );
	update_post_meta( $post_id, '_stock_status', 'instock' );
	update_post_meta( $post_id, '_manage_stock', 'no' );
}, PHP_INT_MAX, 3 );

/** Admin UI defaults + hide shipping tab + auto-fill SEO fields */
add_action( 'admin_footer-post.php',     'pvod_forced_defaults_js', 1001 );
add_action( 'admin_footer-post-new.php', 'pvod_forced_defaults_js', 1001 );
function pvod_forced_defaults_js() {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $screen || $screen->post_type !== 'product' ) return;
	?>
	<script>
	jQuery(function($){

		function isPVOD(){
			return $('#product-type').val() === '<?php echo PVOD_SLUG; ?>';
		}

		function applyDefaults(){
			if ( isPVOD() ) {

				$('#_virtual').prop('checked', true).trigger('change').prop('disabled', true);

				$('select[name="_tax_status"]').val('none').prop('disabled', true);
				$('select[name="_tax_class"]').val('zero-rate').prop('disabled', true);

				$('#_sold_individually').prop('checked', true).prop('disabled', true);

				$('.woocommerce_product_data_tabs li#general_product_data_shipping').hide();
				$('#shipping_product_data').hide();

			} else {

				$('#_virtual, #_sold_individually').prop('disabled', false);
				$('select[name="_tax_status"], select[name="_tax_class"]').prop('disabled', false);

				$('.woocommerce_product_data_tabs li#general_product_data_shipping').show();
				$('#shipping_product_data').show();
			}
		}

		// Auto-fill SEO Title from Product Title (only if blank)
		function autoFillSeoTitle(){
			if ( !isPVOD() ) return;

			var $title = $('#title');
			var $seo   = $('#_pvod_seo_title_override');
			if ( !$title.length || !$seo.length ) return;

			if ( ($seo.val() || '').trim() !== '' ) return;

			var filmName = ($title.val() || '').trim();
			if ( filmName === '' ) return;

			$seo.val('Watch ' + filmName + ' Movie Online %%page%% %%sep%% %%sitename%%');
		}

		// Auto-fill Summary from Product Description (classic editor #content), only if blank
		function autoFillSummary(){
			if ( !isPVOD() ) return;

			var $sum = $('#_pvod_seo_one_line_summary');
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
			autoFillSeoTitle();
			autoFillSummary();
		}

		// Initial run
		runAll();

		// Product type change
		$('#product-type').on('change', function(){
			setTimeout(runAll, 80);
		});

		$(document.body).on('woocommerce-product-type-change', function(){
			runAll();
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
 * 8) PVOD SEO fields (admin UI) + Yoast sync + forced categories
 */

/** A) Add 2 fields to PVOD product edit screen */
add_action( 'woocommerce_product_options_general_product_data', function() {
	echo '<div class="options_group show_if_pvod_film">';

	woocommerce_wp_text_input( array(
		'id'          => '_pvod_seo_title_override',
		'label'       => __( 'PVOD SEO Title (optional)', 'woocommerce' ),
		'desc_tip'    => true,
		'description' => __( 'Leave blank to auto-generate: Watch [Film Name] Movie Online %%page%% %%sep%% %%sitename%%', 'woocommerce' ),
	) );

	woocommerce_wp_textarea_input( array(
		'id'          => '_pvod_seo_one_line_summary',
		'label'       => __( 'PVOD SEO One-line Summary (optional)', 'woocommerce' ),
		'desc_tip'    => true,
		'description' => __( 'Auto-filled from product description (first 200 chars) if left blank. You can edit it.', 'woocommerce' ),
	) );

	echo '</div>';
}, 100 );

/** B) Save those 2 fields */
add_action( 'woocommerce_admin_process_product_object', function( $product ) {
	if ( ! $product || $product->get_type() !== PVOD_SLUG ) return;

	$title_override = isset($_POST['_pvod_seo_title_override']) ? wc_clean( wp_unslash($_POST['_pvod_seo_title_override']) ) : '';
	$summary        = isset($_POST['_pvod_seo_one_line_summary']) ? wc_clean( wp_unslash($_POST['_pvod_seo_one_line_summary']) ) : '';

	$product->update_meta_data( '_pvod_seo_title_override', $title_override );
	$product->update_meta_data( '_pvod_seo_one_line_summary', $summary );
}, PHP_INT_MAX );

/** Helper: safe substring (mbstring optional) */
function pvod_safe_substr( $text, $start, $length ) {
	if ( function_exists( 'mb_substr' ) ) return mb_substr( $text, $start, $length );
	return substr( $text, $start, $length );
}

/** C) On save, write Yoast SEO fields and force categories (PVOD only) */
add_action( 'save_post_product', function( $post_id, $post, $update ) {
	$product = wc_get_product( $post_id );
	if ( ! $product || $product->get_type() !== PVOD_SLUG ) return;

	$film_name      = $product->get_name();
	$title_override = get_post_meta( $post_id, '_pvod_seo_title_override', true );
	$summary        = get_post_meta( $post_id, '_pvod_seo_one_line_summary', true );

	// 1) SEO Title
	$seo_title = $title_override;
	if ( $seo_title === '' ) {
		$seo_title = 'Watch ' . $film_name . ' Movie Online %%page%% %%sep%% %%sitename%%';
	}

	// 2) Meta Description: default text + first 200 chars from product description + optional summary
	$meta_desc = 'Watch %%title%% movie online.';

	$raw_desc   = (string) $product->get_description();
	$clean_desc = wp_strip_all_tags( $raw_desc );
	$clean_desc = preg_replace( '/\s+/', ' ', $clean_desc );
	$clean_desc = trim( $clean_desc );

	$desc_snippet = '';
	if ( $clean_desc !== '' ) {
		$desc_snippet = trim( pvod_safe_substr( $clean_desc, 0, 200 ) );
	}

	if ( $desc_snippet !== '' ) {
		$meta_desc .= ' ' . $desc_snippet;
	}

	if ( $summary !== '' ) {
		$meta_desc .= ' ' . $summary;
	}

	// 3) Update Yoast fields only if Yoast is present
	if ( defined('WPSEO_VERSION') || class_exists('WPSEO_Meta') ) {
		update_post_meta( $post_id, '_yoast_wpseo_title', $seo_title );
		update_post_meta( $post_id, '_yoast_wpseo_metadesc', $meta_desc );
	}

	// 4) Force categories: Cinema and Cinema - PVOD (merge, do not remove existing)
	$required_names = array( 'Cinema', 'Cinema - PVOD' );
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



/* -------------------------
 * Show Regular Price for PVOD Film (WC Vendors Frontend)
 * ------------------------- */
add_filter('wcv_product_meta_tabs', function ($tabs) {

    // Ensure pricing tab exists
    if (!isset($tabs['general'])) {
        return $tabs;
    }

    // Enable regular price field
    if (!isset($tabs['general']['fields'])) {
        $tabs['general']['fields'] = array();
    }

    $tabs['general']['fields']['_regular_price'] = array(
        'label'       => __('Regular Price', 'woocommerce'),
        'type'        => 'number',
        'class'       => 'wc_input_price short',
        'placeholder' => '',
        'custom_attributes' => array(
            'step' => '0.01',
            'min'  => '0'
        )
    );

    return $tabs;

}, 20);
