<?php

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

if ( ! function_exists('pvod_parse_price') ) {
    function pvod_parse_price($value) {
        $value = (string) $value;
        $value = trim($value);
        if ($value === '') return null;

        // remove currency symbols and spaces, normalize comma to dot
        $value = preg_replace('/[^0-9,\.]/', '', $value);
        if ($value === '') return null;

        // if comma used as decimal separator (and no dot), convert
        if (strpos($value, ',') !== false && strpos($value, '.') === false) {
            $value = str_replace(',', '.', $value);
        } else {
            // remove thousand separators commas (e.g., 1,234.56)
            $value = str_replace(',', '', $value);
        }

        if (!is_numeric($value)) return null;
        return (float) $value;
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
 * Save logic (with min price enforcement)
 * ------------------------- */
add_action('wcv_save_product', function ($post_id) {

    $raw_type = $_POST['product-type'] ?? $_POST['product_type'] ?? '';
    if ($raw_type === '') return;

    $product_type = sanitize_title(wp_unslash($raw_type));
    if ($product_type !== 'pvod_film') return;

    // Enforce minimum regular price (server-side)
    $min_price = 4.99;
    $raw_regular = $_POST['_regular_price'] ?? '';
    $regular = pvod_parse_price(wp_unslash($raw_regular));

    if ($regular === null || $regular < $min_price) {
        // stop saving and show a friendly message
        wp_die(
            esc_html('PVOD Film requires a Regular Price of $4.99 or higher.'),
            esc_html('Invalid price'),
            array('response' => 400)
        );
    }

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
    if ($raw_desc !== '') $meta_desc .= ' ' . pvod_safe_substr($raw_desc, 0, 200);
    if ($summary !== '' && $summary !== $raw_desc) $meta_desc .= ' ' . $summary;

    if (class_exists('WPSEO_Meta')) {
        update_post_meta($post_id, '_yoast_wpseo_title', $seo_title);
        update_post_meta($post_id, '_yoast_wpseo_metadesc', $meta_desc);
    }

    $required = pvod_get_required_cat_ids();
    if ($required) {
        $current = $product ? $product->get_category_ids() : array();
        wp_set_object_terms($post_id, array_unique(array_merge($current, $required)), 'product_cat');
    }

}, 10);

/* -------------------------
 * Front end JS (NO UI) + Pricing visibility + Min price validation
 * ------------------------- */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;

    wp_register_script('pvod-inline', '', array('jquery'), null, true);
    wp_enqueue_script('pvod-inline');

    wp_localize_script('pvod-inline', 'PVODCFG', array(
        'requiredCatIds' => pvod_get_required_cat_ids(),
        'minRegularPrice' => 4.99,
        'minPriceMsg' => 'Regular Price must be $4.99 or higher for PVOD Film.',
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
      cb.prop('checked', true).prop('disabled', true)
        .closest('li,label,.wcv-field').hide();
    });
  }

  // Force-show General pricing block for PVOD
  function forceShowPricing(){
    if (!isPVOD()) return;

    $('.wcv-product-accordion').removeClass('hide-all');

    const general = $('#general');
    general.removeClass('is_hidden').css('display','block').show();

    const block = general.find('div.show_if_simple.show_if_external').first();
    if (block.length) {
      block.removeClass('is_hidden').css('display','block').show();
      block.find('.is_hidden').removeClass('is_hidden').show();
    }

    $('#_regular_price, #_sale_price, .wcv-price-input').removeClass('is_hidden').show();
  }

  // Hide From/To schedule fields
  function hideSaleSchedule(){
    $('#general .sale_price_dates_fields').hide();
  }

  // --- Min price validation for PVOD ---
  function parsePrice(val){
    val = String(val || '').trim();
    if (!val) return NaN;

    // remove currency and spaces
    val = val.replace(/[^0-9,\\.]/g,'');
    if (!val) return NaN;

    // handle comma decimal if no dot
    if (val.indexOf(',') !== -1 && val.indexOf('.') === -1) {
      val = val.replace(',', '.');
    } else {
      // remove thousands commas
      val = val.replace(/,/g,'');
    }

    const num = parseFloat(val);
    return isNaN(num) ? NaN : num;
  }

  function ensureMinPriceNotice(){
    let box = $('#pvod-min-price-notice');
    if (box.length) return box;

    box = $('<div/>', {
      id: 'pvod-min-price-notice',
      css: {
        marginTop: '12px',
        padding: '10px 12px',
        border: '1px solid #d63638',
        background: '#fff5f5',
        color: '#b32d2e',
        display: 'none'
      },
      text: PVODCFG.minPriceMsg || 'Regular Price is too low.'
    });

    // place it near regular price field if possible
    const target = $('#_regular_price').closest('.control-group');
    if (target.length) target.append(box);
    else $('#wcv-product-edit').prepend(box);

    return box;
  }

  function setSubmitEnabled(enabled){
    const btnAdd = $('#product_save_button');
    const btnDraft = $('#draft_button');
    btnAdd.prop('disabled', !enabled);
    btnDraft.prop('disabled', !enabled);

    // some themes style disabled buttons; add a class for clarity
    btnAdd.toggleClass('pvod-disabled', !enabled);
    btnDraft.toggleClass('pvod-disabled', !enabled);
  }

  function validateMinPrice(){
    if (!isPVOD()) {
      // if not PVOD, do not block anything
      $('#pvod-min-price-notice').hide();
      setSubmitEnabled(true);
      return true;
    }

    const min = parseFloat(PVODCFG.minRegularPrice || 4.99);
    const priceVal = $('#_regular_price').val();
    const price = parsePrice(priceVal);

    const ok = !isNaN(price) && price >= min;

    const notice = ensureMinPriceNotice();
    if (!ok) notice.show(); else notice.hide();

    setSubmitEnabled(ok);
    return ok;
  }

  function runAll(){
    autoFill();
    lockCategories();
    forceShowPricing();
    hideSaleSchedule();
    validateMinPrice();
  }

  // Initial run
  runAll();

  // Validate on typing price, and on common changes
  $(document).on('input', '#_regular_price', function(){
    validateMinPrice();
  });

  $(document).on('input change', '#title, #content, #product-type, #product_type, select[name="product-type"], select[name="product_type"]', function(){
    runAll();
  });

  // Block submit if invalid (extra safety)
  $(document).on('submit', '#wcv-product-edit', function(e){
    if (!validateMinPrice()) {
      e.preventDefault();
      e.stopPropagation();
      return false;
    }
  });

  // MutationObserver: if WC Vendors rehides, re-apply our fixes
  const target = document.querySelector('#general') || document.body;
  if (target && window.MutationObserver) {
    const obs = new MutationObserver(function(){
      forceShowPricing();
      hideSaleSchedule();
      validateMinPrice();
    });
    obs.observe(target, { attributes: true, childList: true, subtree: true });
  }

});
JS
    );

});