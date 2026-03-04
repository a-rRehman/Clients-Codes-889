/* -------------------------
 * LOGGING
 * ------------------------- */
function pvod_log($message, $context = array()) {
    $uploads  = wp_upload_dir();
    $log_file = trailingslashit($uploads['basedir']) . 'pvod-log.log';

    $time = date('Y-m-d H:i:s');
    $ctx  = !empty($context) ? ' | ' . wp_json_encode($context) : '';
    $line = '[' . $time . '] ' . $message . $ctx . PHP_EOL;

    @file_put_contents($log_file, $line, FILE_APPEND);
}

/* -------------------------
 * Helpers
 * ------------------------- */
if ( ! function_exists('pvod_safe_substr') ) {
    function pvod_safe_substr( $text, $start, $length ) {
        return function_exists('mb_substr')
            ? mb_substr($text, $start, $length)
            : substr($text, $start, $length);
    }
}

/* -------------------------
 * REQUIRED CATEGORY SLUGS
 * ------------------------- */
function pvod_get_required_cat_ids() {

    $slugs = array('cinema', 'cinema-pvod');
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
 * Add PVOD Film type
 * ------------------------- */
add_filter('wcv_product_type_selector', function ($types) {

    $types['pvod_film'] = __('PVOD Film', 'your-textdomain');
    return $types;

}, 20);


/* -------------------------
 * Save logic
 * ------------------------- */
add_action('wcv_save_product', function ($post_id) {

    $raw_type = $_POST['product-type'] ?? $_POST['product_type'] ?? '';
    if ($raw_type === '') return;

    $product_type = sanitize_title(wp_unslash($raw_type));
    if ($product_type !== 'pvod_film') return;

    wp_set_object_terms($post_id, 'pvod_film', 'product_type', false);

    update_post_meta($post_id, '_virtual', 'yes');
    update_post_meta($post_id, '_sold_individually', 'yes');
    update_post_meta($post_id, '_tax_status', 'none');
    update_post_meta($post_id, '_tax_class', 'zero-rate');
    update_post_meta($post_id, '_manage_stock', 'no');
    update_post_meta($post_id, '_stock_status', 'instock');

    $title_override = wc_clean($_POST['pvod_seo_title_override'] ?? '');
    $summary        = wc_clean($_POST['pvod_seo_one_line_summary'] ?? '');

    $product   = wc_get_product($post_id);
    $film_name = $product ? $product->get_name() : get_the_title($post_id);

    $raw_desc = wp_strip_all_tags($product ? $product->get_description() : '');
    $raw_desc = preg_replace('/\s+/', ' ', trim($raw_desc));

    if ($summary === '' && $raw_desc !== '') {
        $summary = pvod_safe_substr($raw_desc, 0, 200);
    }

    update_post_meta($post_id, '_pvod_seo_title_override', $title_override);
    update_post_meta($post_id, '_pvod_seo_one_line_summary', $summary);

    $seo_title = $title_override !== ''
        ? $title_override
        : 'Watch ' . $film_name . ' Movie Online %%page%% %%sep%% %%sitename%%';

    $meta_desc = 'Watch %%title%% movie online.';

    if ($raw_desc !== '') {
        $meta_desc .= ' ' . pvod_safe_substr($raw_desc, 0, 200);
    }

    if ($summary !== '' && $summary !== $raw_desc) {
        $meta_desc .= ' ' . $summary;
    }

    if (class_exists('WPSEO_Meta')) {

        update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);

    }

    $required = pvod_get_required_cat_ids();

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
 * Front end JS
 * ------------------------- */
add_action('wp_enqueue_scripts', function () {

    if (is_admin()) return;

    wp_register_script('pvod-inline', '', array('jquery'), null, true);
    wp_enqueue_script('pvod-inline');

    wp_localize_script('pvod-inline', 'PVODCFG', array(
        'requiredCatIds' => pvod_get_required_cat_ids(),
    ));

wp_add_inline_script('pvod-inline', <<<JS

jQuery(function ($) {

function clean(s){
  return (s || '').replace(/<[^>]*>/g,'').replace(/\\s+/g,' ').trim();
}

function isPVOD(){
  return $('#product-type, #product_type, select[name="product-type"], select[name="product_type"]')
    .first().val() === 'pvod_film';
}

function ensureHiddenFields(){

  if ($('#pvod_seo_title_override').length === 0) {
    $('<input>', {
      type: 'hidden',
      name: 'pvod_seo_title_override',
      id: 'pvod_seo_title_override'
    }).appendTo('form');
  }

  if ($('#pvod_seo_one_line_summary').length === 0) {
    $('<textarea>', {
      name: 'pvod_seo_one_line_summary',
      id: 'pvod_seo_one_line_summary'
    }).hide().appendTo('form');
  }

}

function autoFill(){

  if (!isPVOD()) return;

  ensureHiddenFields();

  const title = clean($('#title, input[name="post_title"]').first().val());
  const desc  = clean($('#content, textarea[name="post_content"]').first().val());

  if (title && !$('#pvod_seo_title_override').val()) {

    $('#pvod_seo_title_override')
      .val('Watch ' + title + ' Movie Online %%page%% %%sep%% %%sitename%%');

  }

  if (desc && !$('#pvod_seo_one_line_summary').val()) {

    $('#pvod_seo_one_line_summary').val(desc.substring(0,200));

  }

}

function lockCategories(){

  (PVODCFG.requiredCatIds || []).forEach(function(id){

    const cb = $('input[name="product_cat[]"][value="' + id + '"]');

    cb.prop('checked', true)
      .prop('disabled', true)
      .closest('li,label,.wcv-field')
      .hide();

  });

}


/* -------------------------
 * Fix WC Vendors pricing UI
 * ------------------------- */
function forceShowPricing(){

  if (!isPVOD()) return;

  $('.wcv-product-accordion').removeClass('hide-all');

  const general = $('#general');

  general.removeClass('is_hidden')
         .css('display','block')
         .show();

  const block = general.find('.show_if_simple.show_if_external').first();

  if (block.length) {

      block.removeClass('is_hidden')
           .css('display','block')
           .show();

      block.find('.is_hidden').removeClass('is_hidden').show();

  }

  $('#_regular_price').removeClass('is_hidden').show();
  $('#_sale_price').removeClass('is_hidden').show();

}


/* -------------------------
 * Hide From / To schedule
 * ------------------------- */
function hideSaleSchedule(){

  $('#general .sale_price_dates_fields').hide();

}


/* -------------------------
 * Run all fixes
 * ------------------------- */
function runAll(){

  autoFill();
  lockCategories();
  forceShowPricing();
  hideSaleSchedule();

}


runAll();

$(document).on(
  'input change',
  '#title,#content,#product-type,#product_type,select[name="product-type"],select[name="product_type"]',
  function(){
    runAll();
  }
);


/* Mutation observer to stop WC Vendors rehiding fields */

const target = document.querySelector('#general');

if (target && window.MutationObserver) {

  const observer = new MutationObserver(function(){

      forceShowPricing();
      hideSaleSchedule();

  });

  observer.observe(target,{
      attributes:true,
      childList:true,
      subtree:true
  });

}

});
JS
);

});