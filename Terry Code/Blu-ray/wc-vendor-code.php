<?php


/* -------------------------
 * REQUIRED CATEGORY SLUGS (BLU-RAY)
 * ------------------------- */
function bluray_get_required_cat_ids() {
    $slugs = array('cinema', 'cinema-bluray');
    $ids = array();

    foreach ($slugs as $slug) {
        $term = get_term_by('slug', $slug, 'product_cat');
        if ($term && !is_wp_error($term)) {
            $ids[] = (int) $term->term_id;
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

/* -------------------------
 * Add Blu-ray to WC Vendors product selector
 * ------------------------- */
add_filter('wcv_product_type_selector', function ($types) {
    $types['bluray_film'] = __('Blu-ray', 'your-textdomain');
    return $types;
}, 20);

/* -------------------------
 * WC Vendors Save Logic (Blu-ray)
 * ------------------------- */
add_action('wcv_save_product', function ($post_id) {

    $raw_type = $_POST['product-type'] ?? $_POST['product_type'] ?? '';
    if ($raw_type === '') return;

    $product_type = sanitize_title(wp_unslash($raw_type));
    if ($product_type !== 'bluray_film') return;

    // Force product type
    wp_set_object_terms($post_id, 'bluray_film', 'product_type', false);

    // Core settings
    update_post_meta($post_id, '_virtual', 'yes');
    update_post_meta($post_id, '_tax_status', 'taxable');
    update_post_meta($post_id, '_tax_class', ''); // Standard
    update_post_meta($post_id, '_manage_stock', 'no');
    update_post_meta($post_id, '_stock_status', 'instock');

    $title_override = wc_clean($_POST['bluray_seo_title_override'] ?? '');
    $summary        = wc_clean($_POST['bluray_seo_one_line_summary'] ?? '');

    $product   = wc_get_product($post_id);
    $film_name = $product ? $product->get_name() : get_the_title($post_id);

    $raw_desc = wp_strip_all_tags($product ? $product->get_description() : '');
    $raw_desc = preg_replace('/\s+/', ' ', trim($raw_desc));

    if ($summary === '' && $raw_desc !== '') {
        $summary = function_exists('mb_substr')
            ? mb_substr($raw_desc, 0, 200)
            : substr($raw_desc, 0, 200);
    }

    update_post_meta($post_id, '_bluray_seo_title_override', $title_override);
    update_post_meta($post_id, '_bluray_seo_one_line_summary', $summary);

    $seo_title = $title_override !== ''
        ? $title_override
        : $film_name . ' Blu-ray %%page%% %%sep%% %%sitename%%';

    $meta_desc = 'Buy %%title%% Blu-ray.';
    if ($summary !== '') {
        $meta_desc .= ' ' . $summary;
    }

    if (class_exists('WPSEO_Meta')) {
        update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
    }

    // Force required categories
    $required = bluray_get_required_cat_ids();
    if ($required) {
        $current = $product ? $product->get_category_ids() : array();
        wp_set_object_terms(
            $post_id,
            array_unique(array_merge($current, $required)),
            'product_cat'
        );
    }

}, 10);

/* -------------------------
 * WC Vendors Frontend JS (Blu-ray)
 * ------------------------- */
add_action('wp_enqueue_scripts', function () {

    if (is_admin()) return;

    wp_register_script('bluray-inline', '', array('jquery'), null, true);
    wp_enqueue_script('bluray-inline');

    wp_localize_script('bluray-inline', 'BLURAYCFG', array(
        'requiredCatIds' => bluray_get_required_cat_ids(),
    ));

    wp_add_inline_script('bluray-inline', <<<JS
jQuery(function ($) {

  function clean(s){
    return (s || '').replace(/<[^>]*>/g,'').replace(/\\s+/g,' ').trim();
  }

  function isBluray(){
    return $('#product-type, #product_type, select[name="product-type"], select[name="product_type"]')
      .first().val() === 'bluray_film';
  }

  function ensureHiddenFields(){
    if ($('#bluray_seo_title_override').length === 0) {
      $('<input>', {
        type: 'hidden',
        name: 'bluray_seo_title_override',
        id: 'bluray_seo_title_override'
      }).appendTo('form');
    }

    if ($('#bluray_seo_one_line_summary').length === 0) {
      $('<textarea>', {
        name: 'bluray_seo_one_line_summary',
        id: 'bluray_seo_one_line_summary'
      }).hide().appendTo('form');
    }
  }

  function autoFill(){
    if (!isBluray()) return;

    ensureHiddenFields();

    const title = clean($('#title, input[name="post_title"]').first().val());
    const desc  = clean($('#content, textarea[name="post_content"]').first().val());

    if (title && !$('#bluray_seo_title_override').val()) {
      $('#bluray_seo_title_override')
        .val(title + ' Blu-ray %%page%% %%sep%% %%sitename%%');
    }

    if (desc && !$('#bluray_seo_one_line_summary').val()) {
      $('#bluray_seo_one_line_summary').val(desc.substring(0,200));
    }
  }

  function lockCategories(){
    (BLURAYCFG.requiredCatIds || []).forEach(function(id){
      const cb = $('input[name="product_cat[]"][value="' + id + '"]');
      cb.prop('checked', true).prop('disabled', true)
        .closest('li,label,.wcv-field').hide();
    });
  }

  autoFill();
  lockCategories();

  $(document).on('input change', '#title, #content, #product-type, #product_type', autoFill);

});
JS);
});