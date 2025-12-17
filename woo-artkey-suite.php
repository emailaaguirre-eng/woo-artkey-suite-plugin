<?php
/*
Plugin Name: Woo Art Key Suite (Divi-ready, Moderated, Noindex, reCAPTCHA, Google Fonts)
Description: Per-product "Art Key" pages with customer editor, live preview, theme thumbnails, stock/uploaded backgrounds, guestbook, uploads (moderated), Google Fonts, reCAPTCHA v2, strict noindex/nofollow, and checkout gating to require first edit before checkout.
Version: 1.8.1
Author: Blake Benson
*/

if (!defined('ABSPATH')) exit;

class Woo_ArtKey_Suite {
    /* ===== Constants ===== */
    const CPT                        = 'artkey';
    const PRODUCT_META_ENABLE        = '_artkey_enable';
    const PRODUCT_META_REQUIRES_QR   = '_artkey_requires_qr_code';
    const ORDER_META_ARTKEY_ID       = '_artkey_post_id';
    const META_USER_ID               = '_artkey_user_id';
    const META_EDIT_TOKEN            = '_artkey_edit_token';
    const META_FIELDS                = '_artkey_fields'; // structured array
    // Art Keys created in wp-admin (from the admin menu) should never be deleted by cleanup/purge routines.
    const META_ADMIN_PROTECTED       = '_artkey_admin_protected';
    const SESSION_ARTKEY_ID          = 'artkey_id';
    const SESSION_ARTKEY_COMPLETE    = 'artkey_complete';
    // Print template definitions with QR placeholder coordinates (x, y, width, height as percentages)
    const PRINT_TEMPLATES = [
        'template_1' => ['name' => 'Template 1', 'qr_x' => 0.75, 'qr_y' => 0.85, 'qr_w' => 0.20, 'qr_h' => 0.20],
        'template_2' => ['name' => 'Template 2', 'qr_x' => 0.50, 'qr_y' => 0.90, 'qr_w' => 0.18, 'qr_h' => 0.18],
        'template_3' => ['name' => 'Template 3', 'qr_x' => 0.85, 'qr_y' => 0.75, 'qr_w' => 0.22, 'qr_h' => 0.22],
        'template_4' => ['name' => 'Template 4', 'qr_x' => 0.10, 'qr_y' => 0.88, 'qr_w' => 0.19, 'qr_h' => 0.19],
        'template_5' => ['name' => 'Template 5', 'qr_x' => 0.50, 'qr_y' => 0.10, 'qr_w' => 0.25, 'qr_h' => 0.25],
    ];

    /* Request-scope state */
    private $pending_editor_redirect = '';

    public function __construct() {
        /* CPT + Product UI */
        add_action('init', [$this, 'register_cpt']);
        add_action('woocommerce_product_options_general_product_data', [$this, 'add_product_option']);
        add_action('woocommerce_process_product_meta', [$this, 'save_product_option']);
        
        /* QR Code & Print Integration */
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);
        add_action('woocommerce_order_status_completed', [$this, 'generate_qr_code_for_order'], 20, 1);

        /* Create/attach Art Key per order (thankyou + completed) */
        add_action('woocommerce_thankyou', [$this, 'maybe_attach_artkey_to_order'], 8, 1);
        add_action('woocommerce_order_status_completed', [$this, 'maybe_attach_artkey_to_order'], 8, 1);

        /* Show URL on TY page + inject into emails + order note for dropshipper */
        add_action('woocommerce_thankyou', [$this, 'output_artkey_on_thankyou'], 30, 1);
        add_action('woocommerce_email_after_order_table', [$this, 'email_artkey_block'], 10, 4);

        /* Shortcodes */
        add_shortcode('artkey_block',   [$this, 'shortcode_artkey_block']);
        add_shortcode('artkey_editor',  [$this, 'shortcode_artkey_editor']);
        add_shortcode('artkey_guestbook', [$this, 'shortcode_guestbook']);
        add_shortcode('artkey_upload',  [$this, 'shortcode_upload']);
        /* Back-compat aliases */
        add_shortcode('biolink_block',  [$this, 'shortcode_artkey_block']);
        add_shortcode('biolink_editor', [$this, 'shortcode_artkey_editor']);
        add_shortcode('biolink_guestbook', [$this, 'shortcode_guestbook']);
        add_shortcode('biolink_upload', [$this, 'shortcode_upload']);

        /* Handle editor + upload + guestbook submissions */
        add_action('init', [$this, 'handle_editor_post']);
        add_action('init', [$this, 'handle_guestbook_post']);
        add_action('init', [$this, 'handle_upload_post']);

    /* Basic styles */
    add_action('init', [$this, 'register_cpt']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_styles']);
    add_action('wp_enqueue_scripts', [$this, 'enqueue_google_font']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_recaptcha_script']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_cart_gate_script']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_add_to_cart_intercept']);
        add_action('woocommerce_add_to_cart', [$this, 'on_add_to_cart'], 10, 6);
        add_filter('woocommerce_add_to_cart_redirect', [$this, 'add_to_cart_redirect']);
        add_action('woocommerce_checkout_create_order_line_item', [$this, 'copy_cart_artkey_to_order_item'], 10, 4);
        add_action('woocommerce_order_status_cancelled', [$this, 'delete_artkey_for_order']);
        add_action('woocommerce_order_status_failed', [$this, 'delete_artkey_for_order']);
        add_action('woocommerce_order_status_refunded', [$this, 'delete_artkey_for_order']);
        add_action('woo_artkey_suite_cleanup', [$this, 'cron_cleanup_temporary']);
        add_action('wp', [$this, 'schedule_cleanup_cron']);
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'my_account_order_actions'], 10, 2);
        add_action('woocommerce_order_details_after_order_table', [$this, 'order_details_edit_button']);
        if (is_admin()) {
            add_action('admin_notices', [$this, 'editor_page_admin_notice']);
            add_filter('post_row_actions', [$this, 'admin_row_action_open_editor'], 10, 2);
            add_action('add_meta_boxes', [$this, 'register_artkey_admin_metabox']);
            // Mark newly-created Art Keys via wp-admin so cleanup never deletes them.
            add_action('save_post_' . self::CPT, [$this, 'mark_admin_created_artkey'], 10, 3);
        }

        /* Privacy: noindex/nofollow and sitemap/robots exclusions */
        add_action('wp_head', [$this, 'artkey_meta_robots'], 5);
        add_filter('wp_headers', [$this, 'artkey_robots_header']);
        add_filter('wp_sitemaps_post_types', [$this, 'remove_artkey_from_sitemaps']);
        add_filter('robots_txt', [$this, 'add_artkey_disallow_to_robots'], 10, 2);

        /* Fonts */
        add_action('wp_enqueue_scripts', [$this, 'enqueue_google_font']);

        /* reCAPTCHA settings + script */
        add_action('admin_menu', [$this, 'add_settings_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_recaptcha_script']);

        /* Checkout gating */
        add_filter('woocommerce_get_checkout_url', [$this, 'maybe_gate_to_editor'], 10, 1);
        add_action('template_redirect', [$this, 'gate_checkout_fallback']); // fallback
    // Front-end safety net: intercept checkout button on cart/checkout when gate is needed (no label change)
    add_action('wp_enqueue_scripts', [$this, 'enqueue_cart_gate_script']);

        /* Add-to-cart -> always open editor for enabled products */
        add_action('woocommerce_add_to_cart', [$this, 'on_add_to_cart'], 10, 6);
        add_filter('woocommerce_add_to_cart_redirect', [$this, 'add_to_cart_redirect']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_add_to_cart_intercept']);

    /* Persist per-item Art Key from cart to order items */
    add_action('woocommerce_checkout_create_order_line_item', [$this, 'copy_cart_artkey_to_order_item'], 10, 4);

        /* Order status: delete Art Key if purchase not completed */
        add_action('woocommerce_order_status_cancelled', [$this, 'delete_artkey_for_order']);
        add_action('woocommerce_order_status_failed', [$this, 'delete_artkey_for_order']);
        add_action('woocommerce_order_status_refunded', [$this, 'delete_artkey_for_order']);

        /* Cron: cleanup temporary Art Keys that were never purchased */
        add_action('woo_artkey_suite_cleanup', [$this, 'cron_cleanup_temporary']);
        add_action('wp', [$this, 'schedule_cleanup_cron']);

        /* My Account + Order details: Edit Art Key buttons for completed orders */
        add_filter('woocommerce_my_account_my_orders_actions', [$this, 'my_account_order_actions'], 10, 2);
        add_action('woocommerce_order_details_after_order_table', [$this, 'order_details_edit_button']);
    // Per-item links on emails and order details (HTML or plain)
    add_action('woocommerce_order_item_meta_end', [$this, 'render_item_artkey_links'], 10, 4);

        /* Admin warning if editor page missing */
        if (is_admin()) {
            add_action('admin_notices', [$this, 'editor_page_admin_notice']);
            add_filter('post_row_actions', [$this, 'admin_row_action_open_editor'], 10, 2);
            add_action('add_meta_boxes', [$this, 'register_artkey_admin_metabox']);
        }
    }

    /**
     * Any Art Key created in wp-admin (from the admin menu) should survive all cleanup/purge routines.
     *
     * This hooks save_post_artkey (admin-only) and sets a permanent meta flag at creation time.
     */
    public function mark_admin_created_artkey($post_id, $post, $update) {
        // Safety checks
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
        if (!($post instanceof WP_Post)) return;
        if ($post->post_type !== self::CPT) return;

        // Only set on initial creation (not on edits)
        if ($update) return;

        // Only mark in wp-admin and for logged-in users
        if (!is_admin() || !is_user_logged_in()) return;

        update_post_meta($post_id, self::META_ADMIN_PROTECTED, 1);
    }

    /* ====== CPT ====== */
    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels'        => [
                'name'               => 'Art Keys',
                'singular_name'      => 'Art Key',
                'add_new'            => 'Add Art Key',
                'add_new_item'       => 'Add New Art Key',
                'edit_item'          => 'Edit Art Key',
                'new_item'           => 'New Art Key',
                'view_item'          => 'View Art Key',
                'search_items'       => 'Search Art Keys',
                'not_found'          => 'No Art Keys found',
                'not_found_in_trash' => 'No Art Keys found in Trash',
                'all_items'          => 'All Art Keys',
                'menu_name'          => 'Art Keys',
            ],
            'public'        => true,
            'show_in_menu'  => true,
            'has_archive'   => false,
            'rewrite'       => ['slug' => 'art-key'],
            'supports'      => ['title', 'editor', 'thumbnail', 'comments'],
            'show_in_rest'  => false,
        ]);
    }

    /* ====== Product option ====== */
    public function add_product_option() {
        woocommerce_wp_checkbox([
            'id'          => self::PRODUCT_META_ENABLE,
            'label'       => __('Includes customizable Art Key page', 'woo-artkey-suite'),
            'description' => __('If enabled, an Art Key page will be created for the order and editable by the customer.', 'woo-artkey-suite'),
        ]);
        woocommerce_wp_checkbox([
            'id'          => self::PRODUCT_META_REQUIRES_QR,
            'label'       => __('Requires QR Code', 'woo-artkey-suite'),
            'description' => __('If enabled, customer must select a print template and a QR code will be generated for the Art Key URL.', 'woo-artkey-suite'),
        ]);
    }
    public function save_product_option($post_id) {
        $val = isset($_POST[self::PRODUCT_META_ENABLE]) ? 'yes' : 'no';
        update_post_meta($post_id, self::PRODUCT_META_ENABLE, $val);
        
        $qr_val = isset($_POST[self::PRODUCT_META_REQUIRES_QR]) ? 'yes' : 'no';
        update_post_meta($post_id, self::PRODUCT_META_REQUIRES_QR, $qr_val);
    }

    /* ====== Checkout gate (URL swap) ====== */
    public function maybe_gate_to_editor($checkout_url) {
        if (!function_exists('WC')) return $checkout_url;
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) return $checkout_url;

        $needs = false;
        foreach ($cart->get_cart() as $item) {
            $pid = $item['product_id'];
            if (get_post_meta($pid, self::PRODUCT_META_ENABLE, true) === 'yes') { $needs = true; break; }
        }
        if (!$needs) return $checkout_url;

        $session = WC()->session;
        if ($session && !$session->get(self::SESSION_ARTKEY_COMPLETE)) {
            $aid = (int)($session->get(self::SESSION_ARTKEY_ID) ?: 0);
            if (!$aid || get_post_type($aid)!==self::CPT) {
                $aid = $this->create_session_artkey();
                if ($session) $session->set(self::SESSION_ARTKEY_ID, $aid);
            }
            $token = get_post_meta($aid, self::META_EDIT_TOKEN, true);
            $editor = $this->editor_url($aid, $token);
            if ($editor) return add_query_arg(['checkout_flow'=>1], $editor);
        }
        return $checkout_url;
    }

    /* ====== Fallback redirect at checkout route ====== */
    public function gate_checkout_fallback() {
        if (function_exists('is_checkout') && is_checkout() && !wp_doing_ajax()) {
            if (isset($_GET['key']) || isset($_GET['order-received'])) return;

            if (!function_exists('WC') || !WC()->cart) return;
            $cart = WC()->cart;
            if (!$cart || $cart->is_empty()) return;

            $needs = false;
            foreach ($cart->get_cart() as $item) {
                $pid = $item['product_id'];
                if (get_post_meta($pid, self::PRODUCT_META_ENABLE, true) === 'yes') { $needs = true; break; }
            }
            if (!$needs) return;

            $session = WC()->session;
            if ($session && !$session->get(self::SESSION_ARTKEY_COMPLETE)) {
                $aid = (int)($session->get(self::SESSION_ARTKEY_ID) ?: 0);
                if (!$aid || get_post_type($aid)!==self::CPT) {
                    $aid = $this->create_session_artkey();
                    $session->set(self::SESSION_ARTKEY_ID, $aid);
                }
                $token  = get_post_meta($aid, self::META_EDIT_TOKEN, true);
                $editor = $this->editor_url($aid, $token);
                if ($editor) {
                    wp_safe_redirect(add_query_arg(['checkout_flow'=>1], $editor));
                    exit;
                }
            }
        }
    }

    /* ====== Front-end safety net (no label change) ====== */
    public function enqueue_cart_gate_script() {
        if (!function_exists('is_cart') || !function_exists('is_checkout')) return;
        if (!is_cart() && !is_checkout()) return;
        if (!function_exists('WC') || !WC()->cart) return;
        if (!$this->needs_editor_gate()) return; // Only when gating is required

        // Ensure session Art Key exists so we have a valid editor URL
        $session = WC()->session;
        $aid = 0;
        if ($session) {
            $aid = (int)($session->get(self::SESSION_ARTKEY_ID) ?: 0);
            if (!$aid || get_post_type($aid)!==self::CPT) {
                $aid = $this->create_session_artkey();
                $session->set(self::SESSION_ARTKEY_ID, $aid);
            }
        }
        if (!$aid) return;
        $token  = get_post_meta($aid, self::META_EDIT_TOKEN, true);
        $editor = $this->editor_url($aid, $token);
        if (!$editor) return;

        $target = add_query_arg(['checkout_flow'=>1], $editor);
        $js = "(function(){\n".
            "  var url = '".esc_js($target)."';\n".
            "  function bind(){\n".
            "    var tries=0,max=24;\n".
            "    var iv=setInterval(function(){\n".
            "      tries++;\n".
            "      var btns=[];\n".
            "      var wrap=document.querySelector('.wc-proceed-to-checkout');\n".
            "      if(wrap){ var a=wrap.querySelector('a,button'); if(a) btns.push(a); }\n".
            "      document.querySelectorAll('a[href*=\"/checkout\"]').forEach(function(a){ btns.push(a); });\n".
            "      btns.forEach(function(b){\n".
            "        if(b.dataset && b.dataset.akGateBound) return;\n".
            "        if(!b.addEventListener) return;\n".
            "        b.dataset.akGateBound='1';\n".
            "        b.addEventListener('click', function(e){\n".
            "          try{\n".
            "            e.preventDefault(); e.stopPropagation();\n".
            "          }catch(_){}\n".
            "          window.location.href=url;\n".
            "        }, true);\n".
            "      });\n".
            "      if(tries>=max) clearInterval(iv);\n".
            "    }, 150);\n".
            "  }\n".
            "  if(document.readyState==='complete'||document.readyState==='interactive'){ bind(); } else { document.addEventListener('DOMContentLoaded', bind); }\n".
            "})();";
        wp_register_script('woo-artkey-suite-gate', '', [], null, true);
        wp_enqueue_script('woo-artkey-suite-gate');
        wp_add_inline_script('woo-artkey-suite-gate', $js);
    }

    /* ====== Add-to-cart -> New Art Key + redirect to editor ====== */
    private function is_product_enabled($product_id) {
        return (get_post_meta($product_id, self::PRODUCT_META_ENABLE, true) === 'yes');
    }
    public function on_add_to_cart($cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data) {
        $pid = (int)$product_id;
        if (!$pid || !$this->is_product_enabled($pid)) return;

        // Create a fresh Art Key every time and mark as not complete
        $aid = $this->create_session_artkey();
        if (!$aid) return;
        $token = get_post_meta($aid, self::META_EDIT_TOKEN, true);

        if (function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_ARTKEY_ID, $aid);
            WC()->session->set(self::SESSION_ARTKEY_COMPLETE, 0);
        }
        $editor = $this->editor_url($aid, $token);
        if ($editor) {
            // Stash redirect for non-AJAX add-to-cart flow
            $this->pending_editor_redirect = add_query_arg(['from_add'=>1], $editor);
        }
        // Attach Art Key to this cart line so it follows into the order item
        if (function_exists('WC') && WC()->cart && isset(WC()->cart->cart_contents[$cart_item_key])) {
            WC()->cart->cart_contents[$cart_item_key]['_artkey_post_id'] = (int)$aid;
        }
    }
    public function add_to_cart_redirect($url) {
        if (!empty($this->pending_editor_redirect)) return $this->pending_editor_redirect;
        return $url;
    }
    public function enqueue_add_to_cart_intercept() {
        // Collect enabled product IDs (basic, may be optimized later)
        $enabled_ids = [];
        $prods = get_posts([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 200, // cap to keep light; adjust if needed
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'   => self::PRODUCT_META_ENABLE,
                'value' => 'yes',
            ]],
        ]);
        if (!empty($prods)) $enabled_ids = array_map('intval', $prods);

        if (empty($enabled_ids)) return;

        $js = "(function(){\n".
            "  var enabled = new Set([".implode(',', $enabled_ids)."]);\n".
            "  function onClick(e){\n".
            "    var a = e.target.closest('a.add_to_cart_button');\n".
            "    if(!a) return;\n".
            "    var id = parseInt(a.getAttribute('data-product_id')||'0',10);\n".
            "    if(!id || !enabled.has(id)) return;\n".
            "    // Force non-AJAX add-to-cart so server redirect filter can run\n".
            "    try{ e.preventDefault(); }catch(_){}\n".
            "    if(a && a.href){ window.location.href = a.href; }\n".
            "  }\n".
            "  document.addEventListener('click', onClick, true);\n".
            "  // Intercept single-product forms (including variations) to avoid AJAX and let server redirect\n".
            "  document.addEventListener('click', function(e){\n".
            "    var btn = e.target.closest('form.cart button[name=add-to-cart], form.cart button.single_add_to_cart_button');\n".
            "    if(!btn) return;\n".
            "    var form = btn.closest('form');\n".
            "    if(!form) return;\n".
            "    var pidInput = form.querySelector('input[name=add-to-cart]');\n".
            "    var id = pidInput ? parseInt(pidInput.value||'0',10) : 0;\n".
            "    if(!id || !enabled.has(id)) return;\n".
            "    try{ e.preventDefault(); }catch(_){}\n".
            "    // Submit form natively to bypass JS AJAX handlers\n".
            "    if(form && form.submit){ form.submit(); }\n".
            "  }, true);\n".
            "})();";
        wp_register_script('woo-artkey-addtocart-redirect', '', [], null, true);
        wp_enqueue_script('woo-artkey-addtocart-redirect');
        wp_add_inline_script('woo-artkey-addtocart-redirect', $js);
    }

    private function create_session_artkey() {
        $user_id = is_user_logged_in() ? get_current_user_id() : 0;
        $post_id = wp_insert_post([
            'post_type'   => self::CPT,
            'post_status' => 'publish',
            'post_title'  => 'Art Key (in progress)',
            'post_name'   => uniqid('artkey-session-'),
            'post_content'=> '',
        ]);
        if (is_wp_error($post_id) || !$post_id) return 0;

        update_post_meta($post_id, self::META_USER_ID, $user_id);
        $token = wp_generate_password(20, false, false);
        update_post_meta($post_id, self::META_EDIT_TOKEN, $token);

        // Mark as temporary until an order is completed
        update_post_meta($post_id, '_artkey_temp', 1);
        update_post_meta($post_id, '_artkey_created', time());

        $defaults = [
            'title' => 'Your Art Key',
            'theme' => ['template' => 'classic', 'bg_color' => '#F6F7FB', 'bg_image_id' => 0, 'bg_image_url'=>'', 'font' => 'system'],
            'features' => [
                'show_guestbook'    => true,
                'allow_img_uploads' => true,
                'allow_vid_uploads' => false,
                'enable_gallery'    => true,
                'enable_video'      => true,
            ],
            'watch_video' => ['id' => 0, 'url' => ''],
            'links'   => [],
            'spotify' => ['url' => '', 'autoplay' => false],
            // Print template for QR code products
            'print_template' => '', // e.g., 'template_1' through 'template_5'
            'user_design_image_id' => 0, // User's custom design uploaded from React editor
        ];
        update_post_meta($post_id, self::META_FIELDS, $defaults);
        return $post_id;
    }

    /* ====== Attach to order ====== */
    public function maybe_attach_artkey_to_order($order_id) {
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order) return;

    // Back-compat: if legacy single-order Art Key already exists, keep as-is
    $existing = get_post_meta($order_id, self::ORDER_META_ARTKEY_ID, true);
    // We'll still proceed to ensure each eligible line item has its own Art Key

        $first_aid = 0;
        $user_id = $order->get_user_id() ? (int)$order->get_user_id() : 0;

        // If there is a session Art Key, attach it to the first eligible item lacking an Art Key
        $session_aid = 0;
        if (function_exists('WC') && WC()->session) {
            $session_aid = (int)(WC()->session->get(self::SESSION_ARTKEY_ID) ?: 0);
        }

        foreach ($order->get_items() as $item) {
            $pid = $item->get_product_id();
            if (!$pid || get_post_meta($pid, self::PRODUCT_META_ENABLE, true) !== 'yes') continue;

            $item_aid = (int)$item->get_meta(self::ORDER_META_ARTKEY_ID, true);
            if (!$item_aid || get_post_type($item_aid)!==self::CPT) {
                // Prefer using session Art Key for the first eligible item if present and unused
                if ($session_aid && get_post_type($session_aid)===self::CPT) {
                    $item_aid = $session_aid;
                    $session_aid = 0; // consume it once
                    if ($user_id) update_post_meta($item_aid, self::META_USER_ID, $user_id);
                } else {
                    // Create a fresh Art Key for this line item
                    $item_aid = wp_insert_post([
                        'post_type'   => self::CPT,
                        'post_status' => 'publish',
                        'post_title'  => sprintf('Art Key for Order #%s — %s', $order->get_order_number(), $item->get_name()),
                        'post_name'   => uniqid('artkey-order-item-'),
                        'post_content'=> '',
                    ]);
                    if (!is_wp_error($item_aid) && $item_aid) {
                        if ($user_id) update_post_meta($item_aid, self::META_USER_ID, $user_id);
                        $token = wp_generate_password(20, false, false);
                        update_post_meta($item_aid, self::META_EDIT_TOKEN, $token);
                        $defaults = [
                            'title' => 'Your Art Key',
                            'theme' => ['template' => 'classic', 'bg_color' => '#F6F7FB', 'bg_image_id' => 0, 'bg_image_url'=>'', 'font' => 'system'],
                            'features' => [
                                'show_guestbook'    => true,
                                'allow_img_uploads' => true,
                                'allow_vid_uploads' => false,
                                'enable_gallery'    => true,
                                'enable_video'      => true,
                            ],
                            'watch_video' => ['id' => 0, 'url' => ''],
                            'links'   => [],
                                'spotify' => ['url' => '', 'autoplay' => false],
                        ];
                        update_post_meta($item_aid, self::META_FIELDS, $defaults);
                    } else {
                        $item_aid = 0;
                    }
                }
            }

            if ($item_aid) {
                // Assign to line item and clear temporary flags
                $item->update_meta_data(self::ORDER_META_ARTKEY_ID, (int)$item_aid);
                delete_post_meta($item_aid, '_artkey_temp');
                delete_post_meta($item_aid, '_artkey_created');
                if (!$first_aid) $first_aid = (int)$item_aid;
            }
        }

        // For backward compatibility, store the first Art Key on order meta (if not already set)
        if ($first_aid && !$existing) {
            update_post_meta($order_id, self::ORDER_META_ARTKEY_ID, $first_aid);
            $order->add_order_note('Art Key URL: ' . get_permalink($first_aid));
        }
    }

    /* ====== Customer access: Edit buttons for completed orders ====== */
    public function my_account_order_actions($actions, $order) {
        if (!($order instanceof WC_Order)) return $actions;
        $aid = (int)get_post_meta($order->get_id(), self::ORDER_META_ARTKEY_ID, true);
        if (!$aid || get_post_type($aid)!==self::CPT) return $actions;
        if (!$order->has_status('completed')) return $actions;

        // Only show to owner
        $uid = get_current_user_id();
        if ($uid && (int)$order->get_user_id() === (int)$uid) {
            $token = get_post_meta($aid, self::META_EDIT_TOKEN, true);
            $url = $this->editor_url($aid, $token);
            if ($url) {
                $actions['edit_artkey'] = [
                    'url'  => esc_url($url),
                    'name' => __('Edit Art Key', 'woo-artkey-suite'),
                ];
            }
        }
        return $actions;
    }

    public function order_details_edit_button($order) {
        if (!($order instanceof WC_Order)) return;
        $aid = (int)get_post_meta($order->get_id(), self::ORDER_META_ARTKEY_ID, true);
        if (!$aid || get_post_type($aid)!==self::CPT) return;
        if (!$order->has_status('completed')) return;

        $uid = get_current_user_id();
        if ($uid && (int)$order->get_user_id() === (int)$uid) {
            $token = get_post_meta($aid, self::META_EDIT_TOKEN, true);
            $url = $this->editor_url($aid, $token);
            if ($url) {
                echo '<p style="margin-top:10px;">'
                    .'<a class="button" href="'.esc_url($url).'" style="display:inline-block;padding:10px 14px;border-radius:6px;background:#4f46e5;color:#fff;text-decoration:none;">'
                    .esc_html__('Edit Your Art Key', 'woo-artkey-suite')
                    .'</a>'
                .'</p>';
            }
        }
    }

    /* ====== Delete Art Key for non-completed orders ====== */
    public function delete_artkey_for_order($order_id) {
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order) return;
        // Remove per-item Art Keys
        foreach ($order->get_items() as $item) {
            $aid = (int)$item->get_meta(self::ORDER_META_ARTKEY_ID, true);
            if ($aid && get_post_type($aid)===self::CPT) {
                if (!get_post_meta($aid, self::META_ADMIN_PROTECTED, true)) {
                    $this->hard_delete_artkey($aid);
                }
                $item->delete_meta_data(self::ORDER_META_ARTKEY_ID);
            }
        }
        // Remove legacy single-order Art Key if present
        $order_level = (int)get_post_meta($order_id, self::ORDER_META_ARTKEY_ID, true);
        if ($order_level && get_post_type($order_level)===self::CPT) {
            if (!get_post_meta($order_level, self::META_ADMIN_PROTECTED, true)) {
                $this->hard_delete_artkey($order_level);
            }
        }
        delete_post_meta($order_id, self::ORDER_META_ARTKEY_ID);
    }

    public function copy_cart_artkey_to_order_item($item, $cart_item_key, $values, $order) {
        if (!empty($values['_artkey_post_id'])) {
            $item->add_meta_data(self::ORDER_META_ARTKEY_ID, (int)$values['_artkey_post_id'], true);
        }
    }

    private function hard_delete_artkey($aid) {
        // Delete child attachments first
        $atts = get_children([
            'post_parent' => $aid,
            'post_type'   => 'attachment',
            'numberposts' => -1,
            'post_status' => 'inherit',
            'fields'      => 'ids',
        ]);
        if (!empty($atts)) {
            foreach ($atts as $att_id) {
                wp_delete_attachment($att_id, true);
            }
        }
        wp_delete_post($aid, true);
    }

    /* ====== Cron: cleanup temporary Art Keys ====== */
    public function schedule_cleanup_cron() {
        if (!wp_next_scheduled('woo_artkey_suite_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'woo_artkey_suite_cleanup');
        }
    }
    public function cron_cleanup_temporary() {
        $opt = $this->opt();
        $days = isset($opt['retain_days']) ? max(0, (int)$opt['retain_days']) : 3;
        $cutoff = time() - ($days * DAY_IN_SECONDS);
        $q = new WP_Query([
            'post_type'      => self::CPT,
            'post_status'    => 'publish',
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'meta_query'     => [
                'relation' => 'AND',
                [ 'key' => '_artkey_temp', 'value' => '1', 'compare' => '=' ],
                [ 'key' => '_artkey_created', 'value' => $cutoff, 'type' => 'NUMERIC', 'compare' => '<=' ],
                // Never clean/purge Art Keys created from wp-admin.
                [ 'key' => self::META_ADMIN_PROTECTED, 'compare' => 'NOT EXISTS' ],
            ],
        ]);
        if ($q->have_posts()) {
            foreach ($q->posts as $aid) {
                $this->hard_delete_artkey((int)$aid);
            }
        }
        wp_reset_postdata();
    }

    /* ====== TY page + Email ====== */
    public function output_artkey_on_thankyou($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $items_with_keys = [];
        foreach ($order->get_items() as $item) {
            $pid = $item->get_product_id();
            if (!$pid || get_post_meta($pid, self::PRODUCT_META_ENABLE, true) !== 'yes') continue;
            $aid = (int)$item->get_meta(self::ORDER_META_ARTKEY_ID, true);
            if ($aid && get_post_type($aid)===self::CPT) {
                $items_with_keys[] = [$item, $aid];
            }
        }
        if (empty($items_with_keys)) return;

        echo '<div style="margin:28px 0; padding:22px; background:linear-gradient(135deg, rgba(102,126,234,0.12), rgba(118,75,162,0.12)); border:1px solid rgba(118,75,162,0.25); border-radius:18px; backdrop-filter: blur(6px); box-shadow: 0 10px 28px rgba(0,0,0,0.08);">';
        echo '<h3 style="margin:0 0 12px; font-size:22px; font-weight:800; text-align:center; background:linear-gradient(135deg,#667eea,#764ba2); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent;">Your Art Keys</h3>';
        echo '<ul style="list-style:none; padding:0; margin:0; display:grid; gap:10px;">';
        foreach ($items_with_keys as [$item, $aid]) {
            $token = get_post_meta($aid, self::META_EDIT_TOKEN, true);
            $view  = get_permalink($aid);
            $edit  = $this->editor_url($aid, $token);
            $name  = $item->get_name();
            echo '<li style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:12px 14px;">';
            echo '<div style="font-weight:700; margin-bottom:6px;">'.esc_html($name).'</div>';
            echo '<div style="display:flex; flex-wrap:wrap; gap:10px; align-items:center;">';
            echo '<span>View: <a href="'.esc_url($view).'" style="color:#4f46e5; text-decoration:none; word-break:break-all;">'.esc_html($view).'</a></span>';
            if ($edit) echo '<span><a href="'.esc_url($edit).'" style="display:inline-block; padding:6px 10px; border-radius:999px; background:#4f46e5; color:#fff; text-decoration:none; font-weight:700;">Customize</a></span>';
            echo '</div>';
            echo '</li>';
        }
        echo '</ul>';
        echo '</div>';
    }

    public function email_artkey_block($order, $sent_to_admin, $plain_text, $email) {
        // No-op: per-item links are injected via woocommerce_order_item_meta_end
        // Keep this function for backward compatibility but intentionally do not output single-order link.
        return;
    }

    public function render_item_artkey_links($item_id, $item, $order, $plain_text) {
        if (!($order instanceof WC_Order)) return;
        if (!($item instanceof WC_Order_Item_Product)) return;
        $pid = $item->get_product_id();
        if (!$pid || get_post_meta($pid, self::PRODUCT_META_ENABLE, true) !== 'yes') return;
        $aid = (int)$item->get_meta(self::ORDER_META_ARTKEY_ID, true);
        if (!$aid || get_post_type($aid)!==self::CPT) return;
        $token = get_post_meta($aid, self::META_EDIT_TOKEN, true);
        $view  = get_permalink($aid);
        $edit  = $this->editor_url($aid, $token);
        $label = 'Art Key';

        if ($plain_text) {
            echo "\n{$label}: {$view}";
            if ($edit) echo "\nEdit: {$edit}";
            return;
        }
        echo '<div style="margin-top:6px; font-size:13px; line-height:1.4;">'
            .'<div><strong>'.esc_html($label).':</strong> '
                .'<a href="'.esc_url($view).'" style="color:#4f46e5; text-decoration:none; word-break:break-all;">'.esc_html($view).'</a>'
            .'</div>'
            .($edit ? ('<div><a href="'.esc_url($edit).'" style="display:inline-block; margin-top:4px; padding:6px 10px; border-radius:999px; background:#4f46e5; color:#fff; text-decoration:none;">Customize</a></div>') : '')
        .'</div>';
    }

    /* ====== Shortcodes ====== */
    public function shortcode_artkey_block($atts = []) {
        $id = get_the_ID();
        if (get_post_type($id) !== self::CPT) return '';

        $fields   = get_post_meta($id, self::META_FIELDS, true);
        $fields   = is_array($fields) ? $fields : [];
        $title    = esc_html($fields['title'] ?? get_the_title($id));
        $theme    = $fields['theme'] ?? [];
        $features = $fields['features'] ?? [];
        $links    = $fields['links'] ?? [];
    $spotify  = $fields['spotify']['url'] ?? '';
    $spotify_autoplay = !empty($fields['spotify']['autoplay']);
    $watch_video = is_array($fields['watch_video'] ?? null) ? $fields['watch_video'] : [];
    $watch_video_url = esc_url($watch_video['url'] ?? '');

        $messages_1 = is_array($fields['messages_1'] ?? null) ? $fields['messages_1'] : [];
        $messages_2 = is_array($fields['messages_2'] ?? null) ? $fields['messages_2'] : [];
        $messages_1_url = esc_url($messages_1['url'] ?? '');
        $messages_2_url = esc_url($messages_2['url'] ?? '');

        $approved_imgs = $this->get_approved_media($id, 'image');
        $approved_vids = $this->get_approved_media($id, 'video');

        ob_start();
        ?>
        <!-- Desktop: Phone Frame View -->
        <div class="desktop-phone-wrapper">
            <div class="desktop-phone-frame">
                <div class="desktop-phone-notch"></div>
                <div class="desktop-phone-screen">
                    <div class="artkey-wrap <?php echo esc_attr('tpl-'.($theme['template'] ?? 'classic')); ?>" style="<?php echo esc_attr($this->inline_theme_style($theme)); ?>">
            <?php 
                $text_color = (!empty($theme['text_color']) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string)$theme['text_color'])) ? $theme['text_color'] : '';
                $title_color = (!empty($theme['title_color']) && preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', (string)$theme['title_color'])) ? $theme['title_color'] : '';
                $color_scope = $theme['color_scope'] ?? 'content';
                $title_style = $theme['title_style'] ?? 'gradient';
                $inner_style = '';
                if ($text_color && ($color_scope==='content' || $color_scope==='content_buttons')) { $inner_style = 'color: '.$text_color.';'; }
                $title_inline = '';
                if ($title_style==='solid') {
                    $col = $title_color ?: ($color_scope==='title' || $color_scope==='content' || $color_scope==='content_buttons' ? $text_color : '');
                    if ($col) {
                        $title_inline = 'color: '.$col.'; background:none; -webkit-text-fill-color: initial;';
                    }
                } else {
                    // If background image is present and not solid, add slight shadow for readability
                    $has_bg = !empty($theme['bg_image_id']) || !empty($theme['bg_image_url']);
                    if ($has_bg) {
                        $title_inline = 'text-shadow: 0 1px 2px rgba(0,0,0,0.25);';
                    }
                }
            ?>
            <div class="artkey-inner"<?php echo $inner_style ? ' style="'.esc_attr($inner_style).'"' : ''; ?>>
                <h1 class="artkey-title"<?php echo $title_inline ? ' style="'.esc_attr($title_inline).'"' : ''; ?>><?php echo $title; ?></h1>

                <?php 
                // Buttons row: user links + feature CTAs
                $cta_btns = [];
                $btn_style = '';
                if ($text_color && ($color_scope==='buttons' || $color_scope==='content_buttons')) {
                    // Compute simple luminance for contrast
                    $hex = ltrim((string)$text_color, '#');
                    if (strlen($hex)===3) { $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]; }
                    $r = hexdec(substr($hex,0,2));
                    $g = hexdec(substr($hex,2,2));
                    $b = hexdec(substr($hex,4,2));
                    $luma = 0.2126*$r + 0.7152*$g + 0.0722*$b;
                    $is_light = ($luma > 180);
                    $bg = $is_light ? 'rgba(0,0,0,0.10)' : 'rgba(255,255,255,0.12)';
                    $br = $is_light ? 'rgba(0,0,0,0.18)' : 'rgba(255,255,255,0.18)';
                    $ts = $is_light ? '0 1px 2px rgba(0,0,0,0.35)' : '0 1px 2px rgba(255,255,255,0.25)';
                    $btn_style = ' style="color: '.esc_attr($text_color).'; background-color: '.$bg.'; border: 1px solid '.$br.'; text-shadow: '.$ts.'; border-radius:999px; backdrop-filter: blur(2px);"';
                }
                foreach ($links as $btn) {
                    $label = trim($btn['label'] ?? '');
                    $url   = trim($btn['url'] ?? '');
                    if ($label && $url) {
                        $cta_btns[] = '<a class="artkey-btn" href="'.esc_url($url).'" target="_blank" rel="noopener"'.$btn_style.'>'.esc_html($label).'</a>';
                    }
                }
                if (!empty($features['enable_gallery']) && !empty($approved_imgs)) {
                    $cta_btns[] = '<a class="artkey-btn" data-cta="gallery" href="#"'.$btn_style.'>'.esc_html($this->feature_button_label_for_artkey($id, 'gallery', $fields)).'</a>';
                }
                if (!empty($features['enable_video']) && !empty($approved_vids)) {
                    $cta_btns[] = '<a class="artkey-btn" data-cta="video" href="#"'.$btn_style.'>'.esc_html($this->feature_button_label_for_artkey($id, 'video', $fields)).'</a>';
                }
                if (!empty($features['enable_watch_video']) && !empty($watch_video_url)) {
                    $cta_btns[] = '<a class="artkey-btn" data-cta="watch_video" href="#"'.$btn_style.'>'.esc_html($this->feature_button_label_for_artkey($id, 'watch_video', $fields)).'</a>';
                }
                if (!empty($features['show_guestbook'])) {
                    $gb_view_on = isset($features['gb_btn_view']) ? (bool)$features['gb_btn_view'] : true;
                    $gb_sign_on = isset($features['gb_btn_sign']) ? (bool)$features['gb_btn_sign'] : true;
                    if ($gb_view_on) { $cta_btns[] = '<a class="artkey-btn" data-cta="gb_view" href="#"'.$btn_style.'>'.esc_html($this->feature_button_label_for_artkey($id, 'gb_view', $fields)).'</a>'; }
                    if ($gb_sign_on) { $cta_btns[] = '<a class="artkey-btn" data-cta="gb_sign" href="#"'.$btn_style.'>'.esc_html($this->feature_button_label_for_artkey($id, 'gb_sign', $fields)).'</a>'; }
                }
                if (!empty($features['enable_messages_1']) && !empty($messages_1_url)) {
                    $cta_btns[] = '<a class="artkey-btn" data-cta="messages_1" href="#"'.$btn_style.'>'.esc_html($this->feature_button_label_for_artkey($id, 'messages_1', $fields)).'</a>';
                }
                if (!empty($features['enable_messages_2']) && !empty($messages_2_url)) {
                    $cta_btns[] = '<a class="artkey-btn" data-cta="messages_2" href="#"'.$btn_style.'>'.esc_html($this->feature_button_label_for_artkey($id, 'messages_2', $fields)).'</a>';
                }
                if (!empty($features['allow_img_uploads'])) {
                    $cta_btns[] = '<a class="artkey-btn" data-cta="imgup" href="#"'.$btn_style.'>'.esc_html($this->feature_button_label_for_artkey($id, 'imgup', $fields)).'</a>';
                }
                if (!empty($features['allow_vid_uploads'])) {
                    $cta_btns[] = '<a class="artkey-btn" data-cta="vidup" href="#"'.$btn_style.'>'.esc_html($this->feature_button_label_for_artkey($id, 'vidup', $fields)).'</a>';
                }
                if (!empty($cta_btns)) {
                    echo '<div class="artkey-buttons">'.implode('', $cta_btns).'</div>';
                }
                ?>

                <?php if (!empty($spotify)): ?>
                    <div class="artkey-spotify">
                        <?php echo $this->spotify_embed($spotify, $spotify_autoplay); ?>
                    </div>
                <?php endif; ?>

                <?php if (!empty($features['enable_gallery']) && !empty($approved_imgs)): ?>
                    <div id="ak_gallery_modal" class="ak-modal" style="display:none;">
                        <div class="ak-modal-inner">
                            <button type="button" id="ak_gallery_close" class="ak-modal-close">&times;</button>
                            <div class="ak-modal-body" style="max-height:80vh;overflow:auto;">
                                <div class="artkey-gallery">
                                    <h3>Image Gallery</h3>
                                    <div class="grid-gallery">
                                        <?php foreach ($approved_imgs as $img_id): $full = wp_get_attachment_image_url($img_id, 'full'); ?>
                                            <a href="#" class="ak-gallery-thumb" data-full="<?php echo esc_url($full); ?>" style="display:block">
                                                <?php echo wp_get_attachment_image($img_id, 'large', false, ['loading'=>'lazy', 'style'=>'width:100%;height:auto;border-radius:16px']); ?>
                                            </a>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="ak_lightbox" class="ak-modal" style="display:none;">
                        <div class="ak-modal-inner">
                            <button type="button" id="ak_lightbox_close" class="ak-modal-close">&times;</button>
                            <div class="ak-modal-body" style="text-align:center;max-height:80vh;overflow:auto;">
                                <img id="ak_lightbox_img" src="" alt="" style="max-width:100%;max-height:80vh;border-radius:12px;" />
                                <div class="ak-lightbox-nav" style="display:flex;justify-content:space-between;gap:10px;margin-top:10px;">
                                    <button type="button" id="ak_prev_img" class="btn-light">Prev</button>
                                    <button type="button" id="ak_next_img" class="btn-light">Next</button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($features['enable_video']) && !empty($approved_vids)): ?>
                    <div id="ak_videos_modal" class="ak-modal" style="display:none;">
                        <div class="ak-modal-inner">
                            <button type="button" id="ak_videos_close" class="ak-modal-close">&times;</button>
                            <div class="ak-modal-body" style="max-height:80vh;overflow:auto;">
                                <div class="artkey-videos">
                                    <h3>Video Library</h3>
                                    <div class="grid-videos">
                                        <?php foreach ($approved_vids as $vid): 
                                            $src = wp_get_attachment_url($vid);
                                            $mime = get_post_mime_type($vid) ?: 'video/mp4'; ?>
                                            <video controls preload="metadata" playsinline style="max-width:100%;border-radius:12px;">
                                                <source src="<?php echo esc_url($src); ?>" type="<?php echo esc_attr($mime); ?>">
                                                <a href="<?php echo esc_url($src); ?>" target="_blank" rel="noopener">Download video</a>
                                            </video>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php // Modals moved outside phone wrapper for fullscreen display ?>

                <?php if (!empty($features['allow_img_uploads']) || !empty($features['allow_vid_uploads'])): ?>
                    <div class="artkey-upload" id="upload" style="display:none;">
                        <?php echo do_shortcode('[artkey_upload id="'.$id.'"]'); ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <!-- JavaScript is now in the shared script block below -->
                    </div>
                </div>
                <div class="desktop-phone-home"></div>
            </div>
        </div>

        <!-- Shared Modals (used by both desktop and mobile versions) -->
        <?php if (!empty($features['enable_gallery']) && !empty($approved_imgs)): ?>
            <div id="ak_gallery_modal" class="ak-modal" style="display:none;">
                <div class="ak-modal-inner">
                    <button type="button" id="ak_gallery_close" class="ak-modal-close">&times;</button>
                    <div class="ak-modal-body" style="max-height:80vh;overflow:auto;">
                        <div class="artkey-gallery">
                            <h3>Image Gallery</h3>
                            <div class="grid-gallery">
                                <?php foreach ($approved_imgs as $img_id): $full = wp_get_attachment_image_url($img_id, 'full'); ?>
                                    <a href="#" class="ak-gallery-thumb" data-full="<?php echo esc_url($full); ?>" style="display:block">
                                        <?php echo wp_get_attachment_image($img_id, 'large', false, ['loading'=>'lazy', 'style'=>'width:100%;height:auto;border-radius:16px']); ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div id="ak_lightbox" class="ak-modal" style="display:none;">
                <div class="ak-modal-inner">
                    <button type="button" id="ak_lightbox_close" class="ak-modal-close">&times;</button>
                    <div class="ak-modal-body" style="text-align:center;max-height:80vh;overflow:auto;">
                        <img id="ak_lightbox_img" src="" alt="" style="max-width:100%;max-height:80vh;border-radius:12px;" />
                        <div class="ak-lightbox-nav" style="display:flex;justify-content:space-between;gap:10px;margin-top:10px;">
                            <button type="button" id="ak_prev_img" class="btn-light">Prev</button>
                            <button type="button" id="ak_next_img" class="btn-light">Next</button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($features['enable_video']) && !empty($approved_vids)): ?>
            <div id="ak_videos_modal" class="ak-modal" style="display:none;">
                <div class="ak-modal-inner">
                    <button type="button" id="ak_videos_close" class="ak-modal-close">&times;</button>
                    <div class="ak-modal-body" style="max-height:80vh;overflow:auto;">
                        <div class="artkey-videos">
                            <h3>Video Library</h3>
                            <div class="grid-videos">
                                <?php foreach ($approved_vids as $vid): 
                                    $src = wp_get_attachment_url($vid);
                                    $mime = get_post_mime_type($vid) ?: 'video/mp4'; ?>
                                    <video controls preload="metadata" playsinline style="max-width:100%;border-radius:12px;">
                                        <source src="<?php echo esc_url($src); ?>" type="<?php echo esc_attr($mime); ?>">
                                        <a href="<?php echo esc_url($src); ?>" target="_blank" rel="noopener">Download video</a>
                                    </video>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($features['enable_watch_video']) && !empty($watch_video_url)): ?>
            <div id="ak_watch_video_modal" class="ak-modal" style="display:none;">
                <div class="ak-modal-inner ak-watch-video-inner">
                    <button type="button" id="ak_watch_video_close" class="ak-modal-close" aria-label="Close">&times;</button>
                    <div class="ak-modal-body ak-watch-video-body">
                        <video id="ak_watch_video_player" controls playsinline preload="metadata" style="width:100%;height:100%;object-fit:contain;border-radius:12px;background:#000;">
                            <source src="<?php echo esc_url($watch_video_url); ?>" type="<?php echo esc_attr(get_post_mime_type((int)($watch_video['id'] ?? 0)) ?: 'video/mp4'); ?>">
                            <a href="<?php echo esc_url($watch_video_url); ?>" target="_blank" rel="noopener">Download video</a>
                        </video>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($features['show_guestbook'])): ?>
            <div id="ak_gb_sign_panel" class="gb-sign-panel" style="display:none;">
                <?php echo do_shortcode('[artkey_guestbook id="'.$id.'" mode="form"]'); ?>
            </div>
            <div id="ak_gb_modal" class="ak-modal" style="display:none;">
                <div class="ak-modal-inner">
                    <button type="button" id="ak_gb_close" class="ak-modal-close">&times;</button>
                    <div class="ak-modal-body" style="max-height:70vh;overflow:auto;">
                        <?php echo do_shortcode('[artkey_guestbook id="'.$id.'" mode="list"]'); ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($features['enable_messages_1']) && !empty($messages_1_url)): ?>
            <div id="ak_messages_1_modal" class="ak-modal ak-msg-modal" style="display:none;position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;z-index:999999!important;background:rgba(0,0,0,.95)!important;margin:0!important;padding:0!important;">
                <div class="ak-modal-inner ak-msg-modal-inner" style="position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;max-width:100vw!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;border-radius:0!important;margin:0!important;padding:0!important;overflow:hidden!important;">
                    <button type="button" id="ak_messages_1_close" class="ak-modal-close ak-msg-close" aria-label="Close" style="position:fixed!important;top:20px!important;right:20px!important;z-index:1000001!important;width:50px!important;height:50px!important;min-width:50px!important;min-height:50px!important;border-radius:50%!important;background:rgba(0,0,0,.9)!important;color:#fff!important;border:2px solid rgba(255,255,255,.3)!important;font-size:32px!important;line-height:1!important;cursor:pointer!important;display:flex!important;align-items:center!important;justify-content:center!important;box-shadow:0 4px 16px rgba(0,0,0,.6)!important;touch-action:manipulation!important;-webkit-tap-highlight-color:transparent!important;font-weight:bold!important;">&times;</button>
                    <div class="ak-modal-body ak-msg-body" style="position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;overflow:auto!important;padding:0!important;margin:0!important;-webkit-overflow-scrolling:touch!important;">
                        <?php if (preg_match('/\.pdf(\?.*)?$/i', (string)$messages_1_url)): ?>
                            <iframe src="<?php echo esc_url($messages_1_url); ?>" style="position:absolute!important;top:0!important;left:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;border:0!important;background:#fff!important;display:block!important;margin:0!important;padding:0!important;"></iframe>
                        <?php else: ?>
                            <div style="position:absolute!important;top:0!important;left:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;display:flex!important;align-items:center!important;justify-content:center!important;background:#000!important;padding:20px!important;box-sizing:border-box!important;margin:0!important;">
                                <img src="<?php echo esc_url($messages_1_url); ?>" alt="" style="max-width:100%!important;max-height:calc(100vh - 40px)!important;max-height:calc(100dvh - 40px)!important;width:auto!important;height:auto!important;object-fit:contain!important;border-radius:8px!important;display:block!important;margin:auto!important;" />
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if (!empty($features['enable_messages_2']) && !empty($messages_2_url)): ?>
            <div id="ak_messages_2_modal" class="ak-modal ak-msg-modal" style="display:none;position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;z-index:999999!important;background:rgba(0,0,0,.95)!important;margin:0!important;padding:0!important;">
                <div class="ak-modal-inner ak-msg-modal-inner" style="position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;max-width:100vw!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;border-radius:0!important;margin:0!important;padding:0!important;overflow:hidden!important;">
                    <button type="button" id="ak_messages_2_close" class="ak-modal-close ak-msg-close" aria-label="Close" style="position:fixed!important;top:20px!important;right:20px!important;z-index:1000001!important;width:50px!important;height:50px!important;min-width:50px!important;min-height:50px!important;border-radius:50%!important;background:rgba(0,0,0,.9)!important;color:#fff!important;border:2px solid rgba(255,255,255,.3)!important;font-size:32px!important;line-height:1!important;cursor:pointer!important;display:flex!important;align-items:center!important;justify-content:center!important;box-shadow:0 4px 16px rgba(0,0,0,.6)!important;touch-action:manipulation!important;-webkit-tap-highlight-color:transparent!important;font-weight:bold!important;">&times;</button>
                    <div class="ak-modal-body ak-msg-body" style="position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;overflow:auto!important;padding:0!important;margin:0!important;-webkit-overflow-scrolling:touch!important;">
                        <?php if (preg_match('/\.pdf(\?.*)?$/i', (string)$messages_2_url)): ?>
                            <iframe src="<?php echo esc_url($messages_2_url); ?>" style="position:absolute!important;top:0!important;left:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;border:0!important;background:#fff!important;display:block!important;margin:0!important;padding:0!important;"></iframe>
                        <?php else: ?>
                            <div style="position:absolute!important;top:0!important;left:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;display:flex!important;align-items:center!important;justify-content:center!important;background:#000!important;padding:20px!important;box-sizing:border-box!important;margin:0!important;">
                                <img src="<?php echo esc_url($messages_2_url); ?>" alt="" style="max-width:100%!important;max-height:calc(100vh - 40px)!important;max-height:calc(100dvh - 40px)!important;width:auto!important;height:auto!important;object-fit:contain!important;border-radius:8px!important;display:block!important;margin:auto!important;" />
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- View Toggle Button (allows switching between mobile/desktop views) -->
        <button id="artkey_view_toggle" class="artkey-view-toggle" aria-label="Toggle view">
            <span class="toggle-icon">🖥️</span>
            <span>Desktop View</span>
        </button>

        <!-- Shared JavaScript (used by both desktop and mobile versions) -->
        <script>
        (function(){
            // FORCE body class for ArtKey pages (in case WordPress theme doesn't add it)
            if (document.querySelector('.desktop-phone-wrapper')) {
                document.body.classList.add('single-artkey');
                console.log('ArtKey: Added single-artkey class to body');
            }

            // Debug: Log that script is running
            console.log('ArtKey: JavaScript initialized');
            console.log('ArtKey: Screen width:', window.innerWidth);
            console.log('ArtKey: Is mobile:', window.innerWidth <= 768);

            // viewToggle initialization moved below to avoid duplicate declaration

            const signPanel = document.getElementById('ak_gb_sign_panel');
            const modal = document.getElementById('ak_gb_modal');
            const modalClose = document.getElementById('ak_gb_close');
            const galModal = document.getElementById('ak_gallery_modal');
            const galClose = document.getElementById('ak_gallery_close');
            const vidModal = document.getElementById('ak_videos_modal');
            const vidClose = document.getElementById('ak_videos_close');
            const watchModal = document.getElementById('ak_watch_video_modal');
            const watchClose = document.getElementById('ak_watch_video_close');
            const watchCloseX = document.getElementById('ak_watch_video_close_x');
            const watchPlayer = document.getElementById('ak_watch_video_player');
            const uploadWrap = document.getElementById('upload');
            const msg1Modal = document.getElementById('ak_messages_1_modal');
            const msg1Close = document.getElementById('ak_messages_1_close');
            const msg2Modal = document.getElementById('ak_messages_2_modal');
            const msg2Close = document.getElementById('ak_messages_2_close');
            // Lightbox elements
            const lb = document.getElementById('ak_lightbox');
            const lbImg = document.getElementById('ak_lightbox_img');
            const lbClose = document.getElementById('ak_lightbox_close');
            const lbPrev = document.getElementById('ak_prev_img');
            const lbNext = document.getElementById('ak_next_img');
            let lbIndex = -1;
            let lbItems = [];

            // Helper function to lock/unlock body scroll (Cross-browser compatible)
            var savedScrollPosition = 0;
            function lockBodyScroll() {
                // Cross-browser scroll position detection
                savedScrollPosition = window.pageYOffset || window.scrollY || document.documentElement.scrollTop || document.body.scrollTop || 0;
                document.body.style.overflow = 'hidden';
                document.body.style.position = 'fixed';
                document.body.style.width = '100%';
                document.body.style.top = '-' + savedScrollPosition + 'px';
                // Add class for CSS targeting
                if (document.body.classList) {
                    document.body.classList.add('modal-open');
                } else {
                    document.body.className += ' modal-open';
                }
                // Prevent iOS bounce scrolling
                document.body.style.webkitOverflowScrolling = 'touch';
            }
            function unlockBodyScroll() {
                document.body.style.overflow = '';
                document.body.style.position = '';
                document.body.style.width = '';
                document.body.style.top = '';
                // Remove class
                if (document.body.classList) {
                    document.body.classList.remove('modal-open');
                } else {
                    document.body.className = document.body.className.replace(/\bmodal-open\b/g, '');
                }
                // Cross-browser scroll restoration
                if (window.scrollTo) {
                    window.scrollTo(0, savedScrollPosition);
                } else if (window.scroll) {
                    window.scroll(0, savedScrollPosition);
                }
            }

            function openModal(){ 
                if (modal) {
                    modal.setAttribute('style', 'position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;background:rgba(0,0,0,.75)!important;display:flex!important;align-items:center!important;justify-content:center!important;z-index:999999!important;padding:20px!important;visibility:visible!important;opacity:1!important;');
                    lockBodyScroll();
                }
            }
            function closeModal(){ 
                if (modal) {
                    modal.style.display = 'none';
                    unlockBodyScroll();
                }
            }
            if (modal) {
                modal.addEventListener('click', (e)=>{ if (e.target === modal) closeModal(); });
            }
            if (modalClose) modalClose.addEventListener('click', closeModal);

            function openGallery(){ 
                if (galModal) {
                    galModal.setAttribute('style', 'position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;background:rgba(0,0,0,.75)!important;display:flex!important;align-items:center!important;justify-content:center!important;z-index:999999!important;padding:20px!important;visibility:visible!important;opacity:1!important;');
                    lockBodyScroll();
                }
            }
            function closeGallery(){ 
                if (galModal) {
                    galModal.style.display = 'none';
                    unlockBodyScroll();
                }
            }
            if (galModal) {
                galModal.addEventListener('click', (e)=>{ if (e.target === galModal) closeGallery(); });
            }
            if (galClose) galClose.addEventListener('click', closeGallery);

            // Build lightbox items from gallery thumbs
            (function initLightbox(){
                const nodes = document.querySelectorAll('#ak_gallery_modal .grid-gallery .ak-gallery-thumb');
                lbItems = Array.from(nodes);
                document.addEventListener('click', function(e){
                    const a = e.target.closest('#ak_gallery_modal .ak-gallery-thumb');
                    if (!a) return;
                    e.preventDefault();
                    const idx = lbItems.indexOf(a);
                    if (idx >= 0) openLightbox(idx);
                });
                if (lbClose) lbClose.addEventListener('click', closeLightbox);
                if (lb) lb.addEventListener('click', (e)=>{ if (e.target === lb) closeLightbox(); });
                if (lbPrev) lbPrev.addEventListener('click', function(e){ e.preventDefault(); stepLightbox(-1); });
                if (lbNext) lbNext.addEventListener('click', function(e){ e.preventDefault(); stepLightbox(1); });
                document.addEventListener('keydown', function(e){
                    if (!lb || lb.style.display === 'none') return;
                    if (e.key === 'Escape' || e.key === 'Esc') closeLightbox();
                    if (e.key === 'ArrowLeft') stepLightbox(-1);
                    if (e.key === 'ArrowRight') stepLightbox(1);
                });
            })();

            function openLightbox(i){
                lbIndex = i;
                const a = lbItems[i];
                const src = a ? a.getAttribute('data-full') : '';
                if (lbImg && src) lbImg.src = src;
                if (lb) {
                    lb.setAttribute('style', 'position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;background:rgba(0,0,0,.9)!important;display:flex!important;align-items:center!important;justify-content:center!important;z-index:999999!important;padding:20px!important;visibility:visible!important;opacity:1!important;');
                    lockBodyScroll();
                }
            }
            function closeLightbox(){ 
                if (lb) {
                    lb.style.display = 'none';
                    unlockBodyScroll();
                }
            }
            function stepLightbox(delta){
                if (!lbItems.length) return;
                lbIndex = (lbIndex + delta + lbItems.length) % lbItems.length;
                openLightbox(lbIndex);
            }

            function openVideos(){ 
                if (vidModal) {
                    vidModal.setAttribute('style', 'position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;background:rgba(0,0,0,.75)!important;display:flex!important;align-items:center!important;justify-content:center!important;z-index:999999!important;padding:20px!important;visibility:visible!important;opacity:1!important;');
                    lockBodyScroll();
                }
            }
            function closeVideos(){ 
                if (vidModal) {
                    vidModal.style.display = 'none';
                    unlockBodyScroll();
                }
            }
            if (vidModal) {
                vidModal.addEventListener('click', (e)=>{ if (e.target === vidModal) closeVideos(); });
            }
            if (vidClose) vidClose.addEventListener('click', closeVideos);

            function openWatchVideo(){
                if (watchModal) {
                    watchModal.setAttribute('style', 'position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;background:rgba(0,0,0,.9)!important;display:flex!important;align-items:center!important;justify-content:center!important;z-index:999999!important;padding:0!important;visibility:visible!important;opacity:1!important;');
                    lockBodyScroll();
                }
                if (watchPlayer && watchPlayer.play) {
                    try {
                        watchPlayer.currentTime = 0;
                        const wasMuted = !!watchPlayer.muted;
                        watchPlayer.muted = true;
                        const p = watchPlayer.play();
                        if (p && typeof p.then === 'function') {
                            p.then(function(){
                                if (!wasMuted) {
                                    setTimeout(function(){ try { watchPlayer.muted = false; } catch(e) {} }, 150);
                                }
                            }).catch(function(){ /* autoplay blocked */ });
                        }
                    } catch(e) {}
                }
            }
            function closeWatchVideo(){
                if (watchPlayer) {
                    try { if (watchPlayer.pause) watchPlayer.pause(); } catch(e) {}
                    try { watchPlayer.currentTime = 0; } catch(e) {}
                }
                if (watchModal) {
                    watchModal.style.display = 'none';
                    unlockBodyScroll();
                }
            }
            if (watchModal) {
                watchModal.addEventListener('click', (e)=>{ if (e.target === watchModal) closeWatchVideo(); });
            }
            if (watchClose) watchClose.addEventListener('click', closeWatchVideo);
            if (watchCloseX) watchCloseX.addEventListener('click', closeWatchVideo);

            // Close modals with Esc key
            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape' || e.key === 'Esc') {
                    closeModal();
                    closeGallery();
                    closeVideos();
                    closeWatchVideo();
                    closeLightbox();
                    closeMsg1();
                    closeMsg2();
                    unlockBodyScroll();
                }
            });

            // ===== SWIPE TO CLOSE (Mobile Touch Support) =====
            // Swipe down from top or swipe right from left edge to close modals
            (function initSwipeToClose(){
                var touchStartY = 0;
                var touchStartX = 0;
                var touchEndY = 0;
                var touchEndX = 0;
                var swipeThreshold = 100; // pixels needed to trigger close
                var edgeThreshold = 50; // pixels from edge to start edge swipe

                function closeAllModals(){
                    closeModal();
                    closeGallery();
                    closeVideos();
                    closeWatchVideo();
                    closeLightbox();
                    closeMsg1();
                    closeMsg2();
                    unlockBodyScroll();
                }

                function isAnyModalOpen(){
                    var modals = [modal, galModal, vidModal, watchModal, lb, msg1Modal, msg2Modal];
                    for (var i = 0; i < modals.length; i++){
                        if (modals[i] && (modals[i].style.display === 'flex' || getComputedStyle(modals[i]).display === 'flex')){
                            return true;
                        }
                    }
                    return false;
                }

                document.addEventListener('touchstart', function(e){
                    if (!isAnyModalOpen()) return;
                    var touch = e.touches[0];
                    touchStartY = touch.clientY;
                    touchStartX = touch.clientX;
                }, {passive: true});

                document.addEventListener('touchend', function(e){
                    if (!isAnyModalOpen()) return;
                    var touch = e.changedTouches[0];
                    touchEndY = touch.clientY;
                    touchEndX = touch.clientX;

                    var deltaY = touchEndY - touchStartY;
                    var deltaX = touchEndX - touchStartX;

                    // Swipe down from top area (first 100px of screen)
                    if (touchStartY < 100 && deltaY > swipeThreshold && Math.abs(deltaX) < swipeThreshold){
                        closeAllModals();
                        return;
                    }

                    // Swipe right from left edge (first 50px of screen)
                    if (touchStartX < edgeThreshold && deltaX > swipeThreshold && Math.abs(deltaY) < swipeThreshold){
                        closeAllModals();
                        return;
                    }
                }, {passive: true});
            })();

            function openMsg1(){ 
                console.log('openMsg1 called, msg1Modal:', msg1Modal);
                if (!msg1Modal) {
                    console.error('ERROR: msg1Modal is null!');
                    return;
                }
                // Get viewport dimensions for mobile
                const vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
                const vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
                const isMobile = vw <= 768;
                
                // Force all styles inline to override any CSS - FULL SCREEN on mobile
                const modalStyle = 'position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;max-width:100vw!important;max-height:100vh!important;max-height:100dvh!important;background:rgba(0,0,0,.95)!important;display:flex!important;align-items:stretch!important;justify-content:stretch!important;z-index:999999!important;padding:0!important;margin:0!important;overflow:hidden!important;visibility:visible!important;opacity:1!important;';
                msg1Modal.setAttribute('style', modalStyle);
                msg1Modal.style.cssText = modalStyle;
                
                // Ensure inner container fills screen
                const inner = msg1Modal.querySelector('.ak-modal-inner');
                if (inner) {
                    inner.style.cssText = 'position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;max-width:100vw!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;border-radius:0!important;margin:0!important;padding:0!important;overflow:hidden!important;display:flex!important;flex-direction:column!important;';
                }
                
                // Ensure body fills screen
                const body = msg1Modal.querySelector('.ak-modal-body');
                if (body) {
                    body.style.cssText = 'position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;overflow:auto!important;padding:0!important;margin:0!important;-webkit-overflow-scrolling:touch!important;';
                }
                
                // Fix images to prevent stretching
                const img = msg1Modal.querySelector('img');
                if (img) {
                    img.style.cssText = 'max-width:100%!important;max-height:calc(100vh - 40px)!important;max-height:calc(100dvh - 40px)!important;width:auto!important;height:auto!important;object-fit:contain!important;display:block!important;margin:auto!important;';
                }
                
                // Fix iframes to fill screen
                const iframe = msg1Modal.querySelector('iframe');
                if (iframe) {
                    iframe.style.cssText = 'position:absolute!important;top:0!important;left:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;border:0!important;background:#fff!important;display:block!important;margin:0!important;padding:0!important;';
                }
                
                lockBodyScroll();
                
                // Force show on all browsers
                requestAnimationFrame(function(){
                    if (msg1Modal) {
                        msg1Modal.style.display = 'flex';
                        msg1Modal.style.visibility = 'visible';
                    }
                });
                setTimeout(function(){
                    if (msg1Modal) {
                        msg1Modal.style.display = 'flex';
                        msg1Modal.style.visibility = 'visible';
                        msg1Modal.style.opacity = '1';
                        msg1Modal.style.zIndex = '999999';
                    }
                }, 10);
            }
            function closeMsg1(){ 
                if (msg1Modal) {
                    msg1Modal.style.display = 'none';
                    unlockBodyScroll();
                }
            }
            if (msg1Modal) {
                msg1Modal.addEventListener('click', (e)=>{ if (e.target === msg1Modal) closeMsg1(); });
                msg1Modal.addEventListener('touchend', (e)=>{ if (e.target === msg1Modal) closeMsg1(); });
            }
            if (msg1Close) {
                msg1Close.addEventListener('click', closeMsg1);
                msg1Close.addEventListener('touchend', closeMsg1);
            }

            function openMsg2(){ 
                console.log('openMsg2 called, msg2Modal:', msg2Modal);
                if (!msg2Modal) {
                    console.error('ERROR: msg2Modal is null!');
                    return;
                }
                // Get viewport dimensions for mobile
                const vw = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
                const vh = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
                const isMobile = vw <= 768;
                
                // Force all styles inline to override any CSS - FULL SCREEN on mobile
                const modalStyle = 'position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;max-width:100vw!important;max-height:100vh!important;max-height:100dvh!important;background:rgba(0,0,0,.95)!important;display:flex!important;align-items:stretch!important;justify-content:stretch!important;z-index:999999!important;padding:0!important;margin:0!important;overflow:hidden!important;visibility:visible!important;opacity:1!important;';
                msg2Modal.setAttribute('style', modalStyle);
                msg2Modal.style.cssText = modalStyle;
                
                // Ensure inner container fills screen
                const inner = msg2Modal.querySelector('.ak-modal-inner');
                if (inner) {
                    inner.style.cssText = 'position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;max-width:100vw!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;border-radius:0!important;margin:0!important;padding:0!important;overflow:hidden!important;display:flex!important;flex-direction:column!important;';
                }
                
                // Ensure body fills screen
                const body = msg2Modal.querySelector('.ak-modal-body');
                if (body) {
                    body.style.cssText = 'position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;overflow:auto!important;padding:0!important;margin:0!important;-webkit-overflow-scrolling:touch!important;';
                }
                
                // Fix images to prevent stretching
                const img = msg2Modal.querySelector('img');
                if (img) {
                    img.style.cssText = 'max-width:100%!important;max-height:calc(100vh - 40px)!important;max-height:calc(100dvh - 40px)!important;width:auto!important;height:auto!important;object-fit:contain!important;display:block!important;margin:auto!important;';
                }
                
                // Fix iframes to fill screen
                const iframe = msg2Modal.querySelector('iframe');
                if (iframe) {
                    iframe.style.cssText = 'position:absolute!important;top:0!important;left:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;border:0!important;background:#fff!important;display:block!important;margin:0!important;padding:0!important;';
                }
                
                lockBodyScroll();
                
                // Force show on all browsers
                requestAnimationFrame(function(){
                    if (msg2Modal) {
                        msg2Modal.style.display = 'flex';
                        msg2Modal.style.visibility = 'visible';
                    }
                });
                setTimeout(function(){
                    if (msg2Modal) {
                        msg2Modal.style.display = 'flex';
                        msg2Modal.style.visibility = 'visible';
                        msg2Modal.style.opacity = '1';
                        msg2Modal.style.zIndex = '999999';
                    }
                }, 10);
            }
            function closeMsg2(){ 
                if (msg2Modal) {
                    msg2Modal.style.display = 'none';
                    unlockBodyScroll();
                }
            }
            if (msg2Modal) {
                msg2Modal.addEventListener('click', (e)=>{ if (e.target === msg2Modal) closeMsg2(); });
                msg2Modal.addEventListener('touchend', (e)=>{ if (e.target === msg2Modal) closeMsg2(); });
            }
            if (msg2Close) {
                msg2Close.addEventListener('click', closeMsg2);
                msg2Close.addEventListener('touchend', closeMsg2);
            }

            function showSign(){ 
                const panels = document.querySelectorAll('#ak_gb_sign_panel');
                panels.forEach(panel => {
                    if (panel) { 
                        panel.style.display = 'block'; 
                        panel.scrollIntoView({behavior:'smooth', block:'center'}); 
                    }
                });
            }
            function toggleUpload(mode){
                const uploads = document.querySelectorAll('#upload');
                uploads.forEach(uploadWrap => {
                    if (!uploadWrap) return;
                    uploadWrap.style.display = 'block';
                    const imgForm = document.getElementById('ak_upload_form_images');
                    const vidForm = document.getElementById('ak_upload_form_videos');
                    if (imgForm && vidForm) {
                        if (mode === 'images') { imgForm.style.display = 'block'; vidForm.style.display = 'none'; }
                        else if (mode === 'videos') { imgForm.style.display = 'none'; vidForm.style.display = 'block'; }
                    }
                    uploadWrap.scrollIntoView({behavior:'smooth', block:'center'});
                });
            }

            // Handle button clicks - use both click and touch events for iOS & Android compatibility
            function handleButtonClick(e) {
                console.log('ArtKey: Click/touch event detected on:', e.target.tagName, e.target.className);
                const a = e.target.closest('a.artkey-btn[data-cta]');
                if (!a) {
                    console.log('ArtKey: No artkey-btn found in click path');
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                const cta = a.getAttribute('data-cta');
                console.log('ArtKey: Button clicked with CTA:', cta); // Debug
                
                // Visual feedback for debugging
                a.style.opacity = '0.5';
                setTimeout(function(){ a.style.opacity = '1'; }, 200);
                if (cta === 'gb_view') {
                    console.log('Opening guestbook modal');
                    openModal();
                } else if (cta === 'gb_sign') {
                    showSign();
                } else if (cta === 'gallery') {
                    console.log('Opening gallery');
                    openGallery();
                } else if (cta === 'video') {
                    openVideos();
                } else if (cta === 'watch_video') {
                    openWatchVideo();
                } else if (cta === 'messages_1') {
                    console.log('Opening messages_1 modal');
                    if (msg1Modal) {
                        console.log('msg1Modal found:', msg1Modal);
                        openMsg1();
                    } else {
                        console.error('msg1Modal NOT FOUND!');
                    }
                } else if (cta === 'messages_2') {
                    console.log('Opening messages_2 modal');
                    if (msg2Modal) {
                        console.log('msg2Modal found:', msg2Modal);
                        openMsg2();
                    } else {
                        console.error('msg2Modal NOT FOUND!');
                    }
                } else if (cta === 'imgup') {
                    toggleUpload('images');
                } else if (cta === 'vidup') {
                    toggleUpload('videos');
                }
            }
            // Support both click and touch events for iOS & Android
            document.addEventListener('click', handleButtonClick, true);
            document.addEventListener('touchend', handleButtonClick, true);

            // Internal upload switch buttons
            document.addEventListener('click', function(e){
                const btn = e.target.closest('[data-upload-switch]');
                if (!btn) return;
                e.preventDefault();
                const mode = btn.getAttribute('data-upload-switch');
                toggleUpload(mode);
            });

            // View toggle (mobile/desktop switch) - allows users to switch views on any device
            const viewToggle = document.getElementById('artkey_view_toggle');
            const body = document.body;
            
            // Initialize toggle button text based on screen size
            if (viewToggle && window.innerWidth <= 768) {
                viewToggle.innerHTML = '<span class="toggle-icon">🖥️</span> <span>Desktop View</span>';
            } else if (viewToggle) {
                viewToggle.innerHTML = '<span class="toggle-icon">📱</span> <span>Mobile View</span>';
            }
            
            // On mobile, start with mobile view, but allow switching
            if (window.innerWidth <= 768) {
                body.classList.add('force-mobile-view');
            }

            if (viewToggle) {
                viewToggle.addEventListener('click', function(e){
                    e.preventDefault();
                    const isCurrentlyMobile = body.classList.contains('force-mobile-view');
                    const isCurrentlyDesktop = body.classList.contains('force-desktop-view');
                    
                    if (isCurrentlyMobile || (!isCurrentlyDesktop && window.innerWidth <= 768)) {
                        // Switch to desktop view
                        body.classList.remove('force-mobile-view');
                        body.classList.add('force-desktop-view');
                        viewToggle.innerHTML = '<span class="toggle-icon">📱</span> <span>Mobile View</span>';
                    } else {
                        // Switch to mobile view
                        body.classList.remove('force-desktop-view');
                        body.classList.add('force-mobile-view');
                        viewToggle.innerHTML = '<span class="toggle-icon">🖥️</span> <span>Desktop View</span>';
                    }
                });
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    public function shortcode_guestbook($atts = []) {
        $a = shortcode_atts(['id'=>0, 'mode' => 'full'], $atts);
        $id = (int)($a['id'] ?: get_the_ID());
        if (get_post_type($id) !== self::CPT) return '';

        $entries = get_comments([
            'post_id' => $id,
            'status'  => 'approve',
            'number'  => 50,
            'orderby' => 'comment_date_gmt',
            'order'   => 'DESC',
        ]);

        $mode = in_array($a['mode'], ['form','list','full'], true) ? $a['mode'] : 'full';
        ob_start();
        if ($mode === 'form' || $mode === 'full') {
            ?>
            <div class="guestbook-wrap gb-sign-card">
                <?php if ($mode === 'full'): ?><h3>Guestbook</h3><?php endif; ?>
                <form method="post" class="guestbook-form">
                    <?php wp_nonce_field('guestbook_'.$id, 'guestbook_nonce'); ?>
                    <input type="hidden" name="artkey_id" value="<?php echo esc_attr($id); ?>">
                    <input type="text" name="gb_name" placeholder="Your name" required>
                    <textarea name="gb_msg" placeholder="Say something nice…" maxlength="300" required></textarea>
                    <?php $opt = $this->opt(); if (!empty($opt['site_key'])): ?>
                        <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($opt['site_key']); ?>"></div>
                    <?php endif; ?>
                    <button type="submit" name="gb_submit" value="1" class="btn-primary">Sign Guestbook</button>
                </form>
            </div>
            <?php
        }
        if ($mode === 'list' || $mode === 'full') {
            ?>
            <div class="guestbook-list-wrap">
                <?php if ($mode === 'full'): ?><h3>Guestbook</h3><?php endif; ?>
                <?php if ($entries): ?>
                <ul class="guestbook-list">
                    <?php foreach ($entries as $c): ?>
                        <li>
                            <strong><?php echo esc_html($c->comment_author); ?>:</strong>
                            <span><?php echo esc_html($c->comment_content); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                    <p>No entries yet. Be the first to sign!</p>
                <?php endif; ?>
            </div>
            <?php
        }
        return ob_get_clean();
    }

    public function shortcode_upload($atts = []) {
        $a = shortcode_atts(['id'=>0], $atts);
        $id = (int)($a['id'] ?: get_the_ID());
        if (get_post_type($id) !== self::CPT) return '';

        $fields = get_post_meta($id, self::META_FIELDS, true);
        $features = $fields['features'] ?? [];
        $allow_img = !empty($features['allow_img_uploads']);
        $allow_vid = !empty($features['allow_vid_uploads']);

        if (!$allow_img && !$allow_vid) return '';

        ob_start(); ?>
        <div class="visitor-upload-wrap">
            <h3>Contribute Media</h3>
            <div class="upload-switch" style="display:flex;gap:8px;justify-content:center;margin-bottom:10px;">
                <?php if ($allow_img): ?><a href="#" class="artkey-btn" data-upload-switch="images">Images</a><?php endif; ?>
                <?php if ($allow_vid): ?><a href="#" class="artkey-btn" data-upload-switch="videos">Videos</a><?php endif; ?>
            </div>
            <?php if ($allow_img): ?>
            <form id="ak_upload_form_images" method="post" enctype="multipart/form-data" class="visitor-upload-form" style="display:none;">
                <?php wp_nonce_field('upload_'.$id, 'upload_nonce'); ?>
                <input type="hidden" name="artkey_id" value="<?php echo esc_attr($id); ?>">
                <label>Upload image(s)</label>
                <input type="file" name="images[]" accept="image/*" multiple>
                <?php $opt = $this->opt(); if (!empty($opt['site_key'])): ?>
                    <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($opt['site_key']); ?>"></div>
                <?php endif; ?>
                <button type="submit" name="upload_submit" value="1" class="btn-primary">Submit Images</button>
                <small>Uploads are reviewed by the page owner before they appear.</small>
            </form>
            <?php endif; ?>
            <?php if ($allow_vid): ?>
            <form id="ak_upload_form_videos" method="post" enctype="multipart/form-data" class="visitor-upload-form" style="display:none;">
                <?php wp_nonce_field('upload_'.$id, 'upload_nonce'); ?>
                <input type="hidden" name="artkey_id" value="<?php echo esc_attr($id); ?>">
                <label>Upload video(s)</label>
                <input type="file" name="videos[]" accept="video/*" multiple>
                <?php $opt = $this->opt(); if (!empty($opt['site_key'])): ?>
                    <div class="g-recaptcha" data-sitekey="<?php echo esc_attr($opt['site_key']); ?>"></div>
                <?php endif; ?>
                <button type="submit" name="upload_submit" value="1" class="btn-primary">Submit Videos</button>
                <small>Uploads are reviewed by the page owner before they appear.</small>
            </form>
            <?php endif; ?>
        </div>
        <?php return ob_get_clean();
    }

    public function shortcode_artkey_editor() {
        $aid   = isset($_GET['artkey_id']) ? (int)$_GET['artkey_id'] : 0;
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        if (!$aid && isset($_GET['biolink_id'])) $aid = (int)$_GET['biolink_id'];
        if (!$aid || get_post_type($aid) !== self::CPT) return '<p>Missing or invalid Art Key.</p>';
        if (!$this->can_edit($aid, $token)) return '<p>Access denied.</p>';

        if (function_exists('wp_enqueue_media')) wp_enqueue_media();

        $fields = get_post_meta($aid, self::META_FIELDS, true);
        $fields = is_array($fields) ? $fields : [];
        
        // Check if this Art Key is associated with a QR-required product
        $requires_qr = $this->artkey_requires_qr_code($aid);

        $title    = esc_attr($fields['title'] ?? '');
        $theme    = $fields['theme'] ?? ['template'=>'classic','bg_color'=>'#F6F7FB','bg_image_id'=>0,'bg_image_url'=>'','font'=>'system'];
        $features = $fields['features'] ?? [];
        $links    = $fields['links'] ?? [];
        $spotify  = esc_url($fields['spotify']['url'] ?? '');

    $watch_video = is_array($fields['watch_video'] ?? null) ? $fields['watch_video'] : [];
    $watch_video_id  = (int)($watch_video['id'] ?? 0);
    $watch_video_url = esc_url($watch_video['url'] ?? '');

    $pending_imgs = $this->get_media_by_status($aid, 'image', false);
    $pending_vids = $this->get_media_by_status($aid, 'video', false);
    $approved_imgs = $this->get_media_by_status($aid, 'image', true);
    $approved_vids = $this->get_media_by_status($aid, 'video', true);

        $checkout_flow = isset($_GET['checkout_flow']) ? 1 : 0;

        $stock = [
            ['label'=>'Cloudy Sky','url'=>'https://theartfulexperience.com/wp-content/uploads/2025/10/1b9ab436-3aad-479b-a692-dfc4a3571608.jpeg?w=1600&q=80&auto=format&fit=crop'],
            ['label'=>'City Nightline','url'=>'https://images.unsplash.com/photo-1494783367193-149034c05e8f?w=1600&q=80&auto=format&fit=crop'],
            ['label'=>'Ocean Waves','url'=>'https://images.unsplash.com/photo-1507525428034-b723cf961d3e?w=1600&q=80&auto=format&fit=crop'],
            ['label'=>'Forest Mist','url'=>'https://images.unsplash.com/photo-1441974231531-c6227db76b6e?w=1600&q=80&auto=format&fit=crop'],
            ['label'=>'Desert Dunes','url'=>'https://images.unsplash.com/photo-1501785888041-af3ef285b470?w=1600&q=80&auto=format&fit=crop'],
            ['label'=>'Marble Texture','url'=>'https://images.unsplash.com/photo-1525362081669-2b476bb628c3?w=1600&q=80&auto=format&fit=crop'],
        ];

        // Success notice after editor-origin uploads
        $just_uploaded = isset($_GET['uploaded']) ? (int)$_GET['uploaded'] : 0;
        $u_i = isset($_GET['u_i']) ? max(0, (int)$_GET['u_i']) : 0;
        $u_v = isset($_GET['u_v']) ? max(0, (int)$_GET['u_v']) : 0;

        ob_start(); ?>
        <div class="artkey-editor">
            <h2>Customize Your Art Key</h2>
            <?php if (!empty($_GET['updated'])): ?>
                <div class="ak-notice ak-success">Changes saved.</div>
            <?php endif; ?>
            <?php if ($just_uploaded): ?>
                <div class="ak-notice ak-success">
                    <?php
                        $parts = [];
                        if ($u_i) $parts[] = sprintf(_n('%d image', '%d images', $u_i, 'woo-artkey-suite'), $u_i);
                        if ($u_v) $parts[] = sprintf(_n('%d video', '%d videos', $u_v, 'woo-artkey-suite'), $u_v);
                        $msg = $parts ? ('Uploaded ' . implode(' and ', $parts) . ' successfully.') : 'Upload successful.';
                        echo esc_html($msg);
                    ?>
                </div>
            <?php endif; ?>
            <form method="post" class="artkey-form" id="artkey-form" enctype="multipart/form-data">
                <?php wp_nonce_field('editor_'.$aid, 'editor_nonce'); ?>
                <input type="hidden" name="artkey_id" value="<?php echo esc_attr($aid); ?>">
                <input type="hidden" name="checkout_flow" value="<?php echo esc_attr($checkout_flow); ?>">
                <?php // Shared upload auth for editor-triggered uploads ?>
                <?php wp_nonce_field('upload_'.$aid, 'upload_nonce'); ?>
                <input type="hidden" name="from_editor" value="1">
                <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">
                
                <div class="editor-grid">
                    <div class="editor-col-left">
                        <div class="card">
                            <h3>Live Preview</h3>
                            <div class="phone-frame">
                                <div class="phone-notch"></div>
                                <div class="phone-screen">
                                    <div id="ak_preview_box" class="artkey-wrap phone-preview">
                                        <div class="artkey-inner">
                                            <h1 id="ak_prev_title" class="artkey-title"><?php echo $title ? $title : 'Your Art Key'; ?></h1>
                                            <div class="artkey-buttons" id="ak_prev_btns"></div>
                                            <div class="artkey-spotify" id="ak_prev_spotify" style="display:none;"></div>
                                        </div>
                                    </div>
                                </div>
                                <div class="phone-home"></div>
                            </div>
                            <small>Gallery & Video buttons only appear on the live page once there is approved content.</small>
                        </div>
                    </div>
                    <div class="editor-col-right">
                        <div class="card">
                            <h3>Header</h3>
                            <label>Page Title
                                <input type="text" name="f[title]" id="f_title" value="<?php echo $title; ?>" required>
                            </label>
                        </div>

                        <div class="card">
                            <h3>Theme</h3>
                            <div class="theme-chooser">
                                <div class="theme-grid">
                                    <?php
                                    $themes = [
                                        ['value'=>'classic','name'=>'Classic','style'=>'background:#F6F7FB; color:#111;'],
                                        ['value'=>'dark','name'=>'Dark','style'=>'background:#0f1218; color:#f6f7fb;'],
                                        ['value'=>'bold','name'=>'Bold','style'=>'background:#111; color:#fff; border:2px solid #fff;'],
                                        ['value'=>'sunset','name'=>'Sunset','style'=>'background:linear-gradient(135deg,#ff9a9e,#fad0c4); color:#111;'],
                                        ['value'=>'ocean','name'=>'Ocean','style'=>'background:linear-gradient(135deg,#74ebd5,#ACB6E5); color:#111;'],
                                        ['value'=>'aurora','name'=>'Aurora','style'=>'background:linear-gradient(135deg,#667eea,#764ba2,#f093fb); color:#fff;'],
                                        ['value'=>'rose_gold','name'=>'Rose Gold','style'=>'background:linear-gradient(135deg,#f7971e,#ffd200,#f093fb); color:#2d3436;'],
                                        ['value'=>'forest','name'=>'Forest','style'=>'background:linear-gradient(135deg,#134e5e,#71b280,#a8e6cf); color:#fff;'],
                                        ['value'=>'cosmic','name'=>'Cosmic','style'=>'background:linear-gradient(135deg,#0c0c0c,#1a1a2e,#16213e,#e94560); color:#fff;'],
                                        ['value'=>'vintage','name'=>'Vintage','style'=>'background:linear-gradient(135deg,#8b4513,#daa520,#f4e4bc); color:#2d3436;'],
                                        ['value'=>'paper','name'=>'Paper','style'=>'background:#fbf8f1; color:#222;'],
                                    ];
                                    foreach ($themes as $t):
                                        $active = (($theme['template'] ?? 'classic') === $t['value']) ? 'active' : '';
                                    ?>
                                        <button type="button" class="theme-tile <?php echo esc_attr($active); ?>" data-theme="<?php echo esc_attr($t['value']); ?>" style="<?php echo esc_attr($t['style']); ?>">
                                            <span><?php echo esc_html($t['name']); ?></span>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                                <input type="hidden" name="f[theme][template]" id="f_theme_template" value="<?php echo esc_attr($theme['template'] ?? 'classic'); ?>">
                            </div>

                            <div class="theme-fields">
                                <label>Background Color
                                    <input type="color" name="f[theme][bg_color]" id="f_theme_bg_color" value="<?php echo esc_attr($theme['bg_color'] ?? '#F6F7FB'); ?>">
                                </label>
                                <div class="bg-picker">
                                    <h4>Background Image</h4>
                                    <input type="hidden" name="f[theme][bg_image_id]" id="f_theme_bg_image_id" value="<?php echo esc_attr((int)($theme['bg_image_id'] ?? 0)); ?>">
                                    <input type="hidden" name="f[theme][bg_image_url]" id="f_theme_bg_image_url" value="<?php echo esc_attr($theme['bg_image_url'] ?? ''); ?>">
                                    <div class="stock-grid">
                                        <?php foreach ($stock as $i=>$s): $sel = (($theme['bg_image_url']??'')===$s['url']) ? 'selected' : ''; ?>
                                            <button type="button" class="stock-tile <?php echo esc_attr($sel); ?>" data-url="<?php echo esc_attr($s['url']); ?>" title="<?php echo esc_attr($s['label']); ?>" style="background-image:url('<?php echo esc_url($s['url']); ?>')"></button>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="bg-actions">
                                        <input type="file" name="bg_image" accept="image/*">
                                        <button type="submit" name="upload_submit" value="1" class="btn-light">Upload as Background</button>
                                        <button type="button" class="btn-light" id="btn_clear_bg">Clear Image</button>
                                    </div>
                                    <div class="bg-tip"><small>Tip: Stock images are royalty-free (Unsplash). Or upload your own using the same uploader as the gallery.</small></div>
                                </div>
                                <div class="field-row">
                                    <label style="flex:1">Font
                                        <select name="f[theme][font]" id="f_theme_font">
                                            <?php
                                            $fonts = ['system','serif','mono','g:Inter','g:Poppins','g:Lato','g:Montserrat','g:Roboto','g:Playfair Display','g:Open Sans'];
                                            foreach ($fonts as $f) {
                                                printf('<option value="%s" %s>%s</option>',
                                                    esc_attr($f),
                                                    selected(($theme['font'] ?? 'system') === $f, true, false),
                                                    $f === 'system' ? 'System' : ($f === 'serif' ? 'Serif' : ($f === 'mono' ? 'Monospace' : substr($f,2)))
                                                );
                                            }
                                            ?>
                                        </select>
                                    </label>
                                </div>
                                <div class="field-row">
                                    <label class="inline-color" style="flex:1;min-width:240px;">Text Color
                                        <input type="color" name="f[theme][text_color]" id="f_theme_text_color" value="<?php echo esc_attr($theme['text_color'] ?? '#111111'); ?>">
                                    </label>
                                </div>
                                <div class="field-row" style="margin-top:8px;align-items:center;gap:10px;">
                                    <label style="flex:1">Apply Text Color To
                                        <?php $scope = $theme['color_scope'] ?? 'content'; ?>
                                        <select name="f[theme][color_scope]" id="f_theme_color_scope">
                                            <option value="content" <?php selected($scope==='content'); ?>>Content (default)</option>
                                            <option value="buttons" <?php selected($scope==='buttons'); ?>>Buttons only</option>
                                            <option value="title" <?php selected($scope==='title'); ?>>Title only</option>
                                            <option value="content_buttons" <?php selected($scope==='content_buttons'); ?>>Content + Buttons</option>
                                        </select>
                                    </label>
                                    <button type="button" class="btn-light" id="btn_reset_text_color" title="Reset Text Color to template default">Reset to Template Color</button>
                                </div>
                                <div class="field-row" style="align-items:center;gap:10px;">
                                    <label class="inline-color" style="flex:1;min-width:240px;">Title Color
                                        <input type="color" name="f[theme][title_color]" id="f_theme_title_color" value="<?php echo esc_attr($theme['title_color'] ?? ($theme['text_color'] ?? '#111111')); ?>">
                                    </label>
                                    <label style="display:flex;align-items:center;gap:8px;white-space:nowrap;">
                                        <?php $title_style = $theme['title_style'] ?? 'gradient'; ?>
                                        <input type="checkbox" name="f[theme][title_style]" id="f_theme_title_style" value="solid" <?php checked($title_style==='solid'); ?>>
                                        <span>Use solid title color</span>
                                    </label>
                                </div>
                                <small style="display:block;color:#6b7280;margin-top:4px;">Scope controls where the Text Color applies. Title Color is independent and only applies when "Use solid title color" is checked. Reset sets both Text and Title colors to the theme default.</small>
                            </div>
                        </div>

                        <div class="editor-two-col">
                    <div class="card">
                        <h3>Media Gallery</h3>
                        <p>View existing media, upload new items, or delete approved items. Uploads made here are added instantly to your page. Visitor submissions (if enabled) will appear below in the Moderation Queue for your review.</p>
                        <div class="row">
                            <div>
                                <h4>Images</h4>
                                <?php if (!empty($approved_imgs)): ?>
                                <div class="grid-gallery">
                                    <?php foreach ($approved_imgs as $img): ?>
                                        <div class="pending-item">
                                            <?php echo wp_get_attachment_image($img, 'medium', false, ['style'=>'max-width:100%;border-radius:10px']); ?>
                                            <label><input type="checkbox" name="delete_img[]" value="<?php echo esc_attr($img); ?>"> Delete</label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                    <p>No approved images yet.</p>
                                <?php endif; ?>
                                <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <input type="file" name="images[]" accept="image/*" multiple>
                                    <button type="submit" name="upload_submit" value="1" class="btn-light">+ Upload Images</button>
                                </div>
                            </div>
                            <div>
                                <h4>Videos</h4>
                                <?php if (!empty($approved_vids)): ?>
                                <div class="grid-videos">
                                    <?php foreach ($approved_vids as $vid): $url = wp_get_attachment_url($vid); $mime = get_post_mime_type($vid) ?: 'video/mp4'; ?>
                                        <div class="pending-item">
                                            <video controls preload="metadata" playsinline style="max-width:100%;border-radius:10px;">
                                                <source src="<?php echo esc_url($url); ?>" type="<?php echo esc_attr($mime); ?>">
                                                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Download video</a>
                                            </video>
                                            <label><input type="checkbox" name="delete_vid[]" value="<?php echo esc_attr($vid); ?>"> Delete</label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                    <p>No approved videos yet.</p>
                                <?php endif; ?>
                                <div style="margin-top:10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                                    <input type="file" name="videos[]" accept="video/*" multiple>
                                    <button type="submit" name="upload_submit" value="1" class="btn-light">+ Upload Videos</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <h3>Buttons</h3>
                        <div id="links-wrap" class="sortable-list">
                        <?php if (empty($links)) $links = [['label'=>'','url'=>'']]; ?>
                        <?php foreach ($links as $i => $lnk): ?>
                            <div class="row draggable" draggable="true" data-index="<?php echo esc_attr($i); ?>">
                                <span class="drag-handle" title="Drag to reorder">↕</span>
                                <input type="text" name="f[links][<?php echo $i; ?>][label]" placeholder="Button label" value="<?php echo esc_attr($lnk['label'] ?? ''); ?>">
                                <input type="url" name="f[links][<?php echo $i; ?>][url]" placeholder="https://example.com" value="<?php echo esc_url($lnk['url'] ?? ''); ?>">
                                <button type="button" class="remove-link" aria-label="Remove button">✕</button>
                            </div>
                        <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-light" id="btn_add_link">+ Add Button</button>
                    </div>

                    <div class="card">
                        <h3>Spotify</h3>
                        <?php $spotify_auto = !empty($fields['spotify']['autoplay']); ?>
                        <input type="url" name="f[spotify][url]" id="f_spotify_url" placeholder="https://open.spotify.com/playlist/..." value="<?php echo $spotify; ?>">
                        <label style="display:flex;align-items:center;gap:8px;margin-top:8px;">
                            <input type="checkbox" name="f[spotify][autoplay]" id="f_spotify_autoplay" value="1" <?php checked($spotify_auto); ?>>
                            <span>Autoplay playlist</span>
                        </label>
                        <small style="display:block;color:#6b7280;margin-top:6px;">Note: most browsers only autoplay if allowed by the user (often requires an interaction and/or may be muted by the browser).</small>
                    </div>

                    <div class="card">
                        <h3>Features</h3>
                    <?php
                        $feature_btn_labels_meta = isset($fields['feature_btn_labels']) && is_array($fields['feature_btn_labels']) ? $fields['feature_btn_labels'] : [];
                        $feature_defs = [
                            ['key'=>'gallery','label'=>$this->feature_button_label_for_artkey($aid, 'gallery', $fields),'field'=>'enable_gallery'],
                            ['key'=>'video','label'=>$this->feature_button_label_for_artkey($aid, 'video', $fields),'field'=>'enable_video'],
                            ['key'=>'watch_video','label'=>$this->feature_button_label_for_artkey($aid, 'watch_video', $fields),'field'=>'enable_watch_video'],
                            ['key'=>'guestbook','label'=>'Guestbook','field'=>'show_guestbook'],
                            ['key'=>'messages_1','label'=>$this->feature_button_label_for_artkey($aid, 'messages_1', $fields),'field'=>'enable_messages_1'],
                            ['key'=>'messages_2','label'=>$this->feature_button_label_for_artkey($aid, 'messages_2', $fields),'field'=>'enable_messages_2'],
                            ['key'=>'imgup','label'=>$this->feature_button_label_for_artkey($aid, 'imgup', $fields),'field'=>'allow_img_uploads'],
                            ['key'=>'vidup','label'=>$this->feature_button_label_for_artkey($aid, 'vidup', $fields),'field'=>'allow_vid_uploads'],
                        ];
                        $saved_order = isset($features['order']) && is_array($features['order']) ? $features['order'] : array_map(function($d){return $d['key'];}, $feature_defs);
                        // Normalize order to known keys
                        $order_lookup = array_flip($saved_order);
                        usort($feature_defs, function($a,$b) use ($order_lookup){
                            $ia = $order_lookup[$a['key']] ?? 999;
                            $ib = $order_lookup[$b['key']] ?? 999;
                            return $ia <=> $ib;
                        });
                    ?>
                    <input type="hidden" name="f[features_order]" id="f_features_order" value="<?php echo esc_attr(implode(',', array_map(function($d){return $d['key'];}, $feature_defs))); ?>">
                    <div id="features-sort" class="sortable-pills">
                        <?php foreach ($feature_defs as $def):
                            $active = !empty($features[$def['field']]);
                        ?>
                            <div class="pill draggable" draggable="true" data-key="<?php echo esc_attr($def['key']); ?>" data-field="<?php echo esc_attr($def['field']); ?>" data-active="<?php echo $active ? '1' : '0'; ?>">
                                <span class="drag-handle" title="Drag to reorder">↕</span>
                                <button type="button" class="pill-toggle <?php echo $active ? 'on' : 'off'; ?>" aria-pressed="<?php echo $active ? 'true' : 'false'; ?>"><?php echo esc_html($def['label']); ?></button>
                                <input type="hidden" name="f[features][<?php echo esc_attr($def['field']); ?>]" value="<?php echo $active ? '1' : ''; ?>">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <small>Drag to set feature button order. Click to toggle features on/off.</small>
                        <div class="sub-options" id="guestbook-subopts" style="margin-top:10px; display: <?php echo !empty($features['show_guestbook']) ? 'block' : 'none'; ?>;">
                            <label style="display:flex;align-items:center;gap:8px;margin-bottom:6px;">
                                <input type="checkbox" name="f[features][gb_btn_view]" id="f_gb_btn_view" value="1" <?php checked(!isset($features['gb_btn_view']) || !empty($features['gb_btn_view'])); ?>>
                                <span>Show “<?php echo esc_html($this->feature_button_label_for_artkey($aid, 'gb_view', $fields)); ?>” button (opens popup)</span>
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;">
                                <input type="checkbox" name="f[features][gb_btn_sign]" id="f_gb_btn_sign" value="1" <?php checked(!isset($features['gb_btn_sign']) || !empty($features['gb_btn_sign'])); ?>>
                                <span>Show “<?php echo esc_html($this->feature_button_label_for_artkey($aid, 'gb_sign', $fields)); ?>” button (reveals 3D form)</span>
                            </label>
                        </div>

                        <div class="sub-options" style="margin-top:12px;">
                            <label style="display:block;margin-bottom:6px;font-weight:700;">Feature button labels (optional, per Art Key)</label>
                            <div style="display:grid;grid-template-columns:minmax(160px,1fr) minmax(240px,2fr);gap:8px 12px;align-items:center;">
                                <?php
                                    $label_keys = ['gallery','video','watch_video','gb_view','gb_sign','messages_1','messages_2','imgup','vidup'];
                                    foreach ($label_keys as $lk):
                                        $current = isset($feature_btn_labels_meta[$lk]) ? (string)$feature_btn_labels_meta[$lk] : '';
                                        $ph = $this->feature_button_label($lk);
                                ?>
                                    <div style="font-size:13px;color:#374151;"><?php echo esc_html($lk); ?></div>
                                    <input type="text" name="f[feature_btn_labels][<?php echo esc_attr($lk); ?>]" value="<?php echo esc_attr($current); ?>" placeholder="<?php echo esc_attr($ph); ?>">
                                <?php endforeach; ?>
                            </div>
                            <small style="display:block;color:#6b7280;margin-top:6px;">Leave blank to use the global/default label.</small>
                        </div>

                        <?php
                            $m1 = is_array($fields['messages_1'] ?? null) ? $fields['messages_1'] : [];
                            $m2 = is_array($fields['messages_2'] ?? null) ? $fields['messages_2'] : [];
                            $m1_id = (int)($m1['id'] ?? 0);
                            $m2_id = (int)($m2['id'] ?? 0);
                            $m1_url = esc_url($m1['url'] ?? '');
                            $m2_url = esc_url($m2['url'] ?? '');
                        ?>
                        <div class="sub-options" id="messages-1-subopts" style="margin-top:12px; display: <?php echo !empty($features['enable_messages_1']) ? 'block' : 'none'; ?>;">
                            <label style="display:block;margin-bottom:6px;font-weight:700;">Messages 1 file (PDF or image)</label>
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <input type="url" name="f[messages_1][url]" id="f_messages_1_url" placeholder="https://... (PDF or image url)" value="<?php echo $m1_url; ?>" style="min-width:260px;flex:1;">
                                <button type="button" class="btn-light" id="btn_pick_messages_1">Select/Upload</button>
                                <button type="button" class="btn-light" id="btn_clear_messages_1">Clear</button>
                            </div>
                            <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <input type="file" name="messages_1_file" id="f_messages_1_file" accept="application/pdf,image/*">
                                <button type="submit" name="messages_1_upload" value="1" class="btn-light">Upload from device</button>
                            </div>
                            <input type="hidden" name="f[messages_1][id]" id="f_messages_1_id" value="<?php echo esc_attr($m1_id); ?>">
                            <small style="display:block;color:#6b7280;margin-top:6px;">Stored separately from the gallery uploads and opens fullscreen from the Art Key page.</small>
                        </div>

                        <div class="sub-options" id="messages-2-subopts" style="margin-top:12px; display: <?php echo !empty($features['enable_messages_2']) ? 'block' : 'none'; ?>;">
                            <label style="display:block;margin-bottom:6px;font-weight:700;">Messages 2 file (PDF or image)</label>
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <input type="url" name="f[messages_2][url]" id="f_messages_2_url" placeholder="https://... (PDF or image url)" value="<?php echo $m2_url; ?>" style="min-width:260px;flex:1;">
                                <button type="button" class="btn-light" id="btn_pick_messages_2">Select/Upload</button>
                                <button type="button" class="btn-light" id="btn_clear_messages_2">Clear</button>
                            </div>
                            <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <input type="file" name="messages_2_file" id="f_messages_2_file" accept="application/pdf,image/*">
                                <button type="submit" name="messages_2_upload" value="1" class="btn-light">Upload from device</button>
                            </div>
                            <input type="hidden" name="f[messages_2][id]" id="f_messages_2_id" value="<?php echo esc_attr($m2_id); ?>">
                            <small style="display:block;color:#6b7280;margin-top:6px;">Stored separately from the gallery uploads and opens fullscreen from the Art Key page.</small>
                        </div>

                        <div class="sub-options" id="watch-video-subopts" style="margin-top:10px; display: <?php echo !empty($features['enable_watch_video']) ? 'block' : 'none'; ?>;">
                            <label style="display:block;margin-bottom:6px;font-weight:700;">Watch video (single video popup)</label>
                            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <input type="url" name="f[watch_video][url]" id="f_watch_video_url" placeholder="https://... (MP4 url)" value="<?php echo $watch_video_url; ?>" style="min-width:260px;flex:1;">
                                <button type="button" class="btn-light" id="btn_pick_watch_video">Select/Upload Video</button>
                                <button type="button" class="btn-light" id="btn_clear_watch_video">Clear</button>
                            </div>
                            <div style="margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                                <input type="file" name="watch_video_file" id="f_watch_video_file" accept="video/*">
                                <button type="submit" name="watch_video_upload" value="1" class="btn-light">Upload from device</button>
                            </div>
                            <input type="hidden" name="f[watch_video][id]" id="f_watch_video_id" value="<?php echo esc_attr($watch_video_id); ?>">
                            <small style="display:block;color:#6b7280;margin-top:6px;">This powers the “Watch video” button on the Art Key page (opens an 80% screen popup).</small>
                        </div>
                    </div>

                    <div class="card">
                        <h3>Moderation Queue</h3>
                        <?php if (empty($pending_imgs) && empty($pending_vids)): ?>
                            <p>No pending submissions.</p>
                        <?php else: ?>
                            <?php if (!empty($pending_imgs)): ?>
                                <h4>Images</h4>
                                <div class="grid-gallery">
                                <?php foreach ($pending_imgs as $img):
                                    $src = wp_get_attachment_image($img, 'medium', false, ['style'=>'max-width:100%;border-radius:10px']); ?>
                                    <div class="pending-item">
                                        <?php echo $src; ?>
                                        <label><input type="checkbox" name="approve_img[]" value="<?php echo esc_attr($img); ?>"> Approve</label>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($pending_vids)): ?>
                                <h4>Videos</h4>
                                <div class="grid-videos">
                                <?php foreach ($pending_vids as $vid):
                                    $url = wp_get_attachment_url($vid);
                                    $mime = get_post_mime_type($vid) ?: 'video/mp4'; ?>
                                    <div class="pending-item">
                                        <video controls preload="metadata" playsinline style="max-width:100%;border-radius:10px;">
                                            <source src="<?php echo esc_url($url); ?>" type="<?php echo esc_attr($mime); ?>">
                                            <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Download video</a>
                                        </video>
                                        <label><input type="checkbox" name="approve_vid[]" value="<?php echo esc_attr($vid); ?>"> Approve</label>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="actions" style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap;">
                    <button class="btn-light" type="button" id="btn_preview">Preview</button>
                    <button class="btn-primary" type="submit" name="artkey_save" value="1">Save</button>
                    <button class="btn-primary" type="submit" name="artkey_save_shop" value="1">Save & Continue to Products</button>
                    <?php if (!empty($checkout_flow)): ?>
                        <button class="btn-primary" type="submit" name="artkey_save_checkout" value="1">Save & Continue to Checkout</button>
                    <?php endif; ?>
                </div>
                    </div><!-- /.editor-col-right -->
                </div><!-- /.editor-grid -->
            </form>
        </div>

        <div id="ak_preview_modal" class="ak-modal" style="display:none;">
            <div class="ak-modal-inner">
                <button type="button" id="ak_preview_close" class="ak-modal-close">&times;</button>
                <div id="ak_modal_preview"></div>
            </div>
        </div>

        <script>
        // Helpers for luminance-based contrast in preview
        function akHexToRgb(hex){
            if (!hex) return {r:0,g:0,b:0};
            let h = String(hex).replace('#','').trim();
            if (h.length===3) h = h[0]+h[0]+h[1]+h[1]+h[2]+h[2];
            const r = parseInt(h.substring(0,2),16) || 0;
            const g = parseInt(h.substring(2,4),16) || 0;
            const b = parseInt(h.substring(4,6),16) || 0;
            return {r,g,b};
        }
        function akLuma(hex){
            const c = akHexToRgb(hex||'#000');
            return 0.2126*c.r + 0.7152*c.g + 0.0722*c.b;
        }
        function addLinkRow(){
            const wrap = document.getElementById('links-wrap');
            const i = wrap.querySelectorAll('.row').length;
            const row = document.createElement('div');
            row.className = 'row draggable';
            row.setAttribute('draggable','true');
            row.innerHTML = `<span class="drag-handle" title="Drag to reorder">↕</span>
                             <input type="text" name="f[links][${i}][label]" placeholder="Button label">
                             <input type="url" name="f[links][${i}][url]" placeholder="https://example.com">
                             <button type="button" class="remove-link" aria-label="Remove button">✕</button>`;
            wrap.appendChild(row);
            attachLinkListeners(row);
            updatePreview();
        }

        function attachLinkListeners(scope){
            const container = scope || document;
            container.querySelectorAll('#links-wrap input').forEach(inp => {
                inp.addEventListener('input', updatePreview);
            });
        }

        // Theme choose tiles
        document.querySelectorAll('.theme-tile').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.theme-tile').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                document.getElementById('f_theme_template').value = btn.dataset.theme;
                updatePreview();
            });
        });

        // Stock background picker
        document.querySelectorAll('.stock-tile').forEach(tile => {
            tile.addEventListener('click', () => {
                document.querySelectorAll('.stock-tile').forEach(t => t.classList.remove('selected'));
                tile.classList.add('selected');
                document.getElementById('f_theme_bg_image_url').value = tile.dataset.url;
                document.getElementById('f_theme_bg_image_id').value = 0;
                updatePreview();
            });
        });

        // Background upload now uses the same file-upload flow as the gallery via the editor form

        // Clear background
        const clearBtn = document.getElementById('btn_clear_bg');
        if (clearBtn) {
            clearBtn.addEventListener('click', function(){
                document.getElementById('f_theme_bg_image_id').value = 0;
                document.getElementById('f_theme_bg_image_url').value = '';
                document.querySelectorAll('.stock-tile').forEach(t => t.classList.remove('selected'));
                updatePreview();
            });
        }

        // Live changes
    document.getElementById('f_title').addEventListener('input', updatePreview);
    document.getElementById('f_theme_bg_color').addEventListener('input', updatePreview);
    var akTextColor = document.getElementById('f_theme_text_color');
    if (akTextColor) akTextColor.addEventListener('input', updatePreview);
    var akTitleColor = document.getElementById('f_theme_title_color');
    if (akTitleColor) akTitleColor.addEventListener('input', updatePreview);
        // Feature pills toggle already calls updatePreview in its click handler

        document.getElementById('f_theme_font').addEventListener('change', function(){
            const val = this.value;
            if (val.startsWith('g:')) {
                const fam = encodeURIComponent(val.substring(2));
                const id = 'ak-google-font';
                let link = document.getElementById(id);
                if (!link) { link = document.createElement('link'); link.id = id; link.rel = 'stylesheet'; document.head.appendChild(link); }
                link.href = `https://fonts.googleapis.com/css2?family=${fam}:wght@400;600&display=swap`;
            }
            updatePreview();
        });
    document.getElementById('f_spotify_url').addEventListener('input', updatePreview);
    const spAuto = document.getElementById('f_spotify_autoplay');
    if (spAuto) spAuto.addEventListener('change', updatePreview);
    const scopeSel = document.getElementById('f_theme_color_scope');
    if (scopeSel) scopeSel.addEventListener('change', updatePreview);
    const titleStyle = document.getElementById('f_theme_title_style');
    if (titleStyle) titleStyle.addEventListener('change', updatePreview);
        // Guestbook sub-option checkboxes should refresh preview
        ['f_gb_btn_view','f_gb_btn_sign'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', updatePreview);
        });

        document.getElementById('btn_add_link').addEventListener('click', addLinkRow);

        // Initialize preview on page load
        setTimeout(function(){
            updatePreview();
        }, 100);

        // Preview modal
        const modal = document.getElementById('ak_preview_modal');
        document.getElementById('btn_preview').addEventListener('click', function(){
            const modalHost = document.getElementById('ak_modal_preview');
            modalHost.innerHTML = '';
            const clone = document.getElementById('ak_preview_box').cloneNode(true);
            clone.style.maxWidth = '980px';
            clone.style.margin = '0 auto';
            modalHost.appendChild(clone);
            modal.style.display = 'block';
        });
        document.getElementById('ak_preview_close').addEventListener('click', function(){ modal.style.display='none'; });
        modal.addEventListener('click', function(e){ if (e.target === modal) modal.style.display='none'; });

        // Default colors per theme for auto-apply and reset
        const AK_DEFAULTS = {
            classic: { text: '#111111', title: '#111111' },
            dark:    { text: '#f6f7fb', title: '#f6f7fb' },
            bold:    { text: '#ffffff', title: '#ffffff' },
            sunset:  { text: '#111111', title: '#111111' },
            ocean:   { text: '#111111', title: '#111111' },
            aurora:  { text: '#ffffff', title: '#ffffff' },
            rose_gold: { text: '#2d3436', title: '#2d3436' },
            forest:  { text: '#ffffff', title: '#ffffff' },
            cosmic:  { text: '#ffffff', title: '#ffffff' },
            vintage: { text: '#2d3436', title: '#2d3436' },
            paper:   { text: '#222222', title: '#222222' },
        };

        // Feature CTA label overrides from plugin settings
        const AK_FEATURE_LABELS = {
            gallery: <?php echo json_encode($this->feature_button_label_for_artkey($aid, 'gallery', $fields)); ?>,
            video: <?php echo json_encode($this->feature_button_label_for_artkey($aid, 'video', $fields)); ?>,
            watch_video: <?php echo json_encode($this->feature_button_label_for_artkey($aid, 'watch_video', $fields)); ?>,
            gb_view: <?php echo json_encode($this->feature_button_label_for_artkey($aid, 'gb_view', $fields)); ?>,
            gb_sign: <?php echo json_encode($this->feature_button_label_for_artkey($aid, 'gb_sign', $fields)); ?>,
            messages_1: <?php echo json_encode($this->feature_button_label_for_artkey($aid, 'messages_1', $fields)); ?>,
            messages_2: <?php echo json_encode($this->feature_button_label_for_artkey($aid, 'messages_2', $fields)); ?>,
            imgup: <?php echo json_encode($this->feature_button_label_for_artkey($aid, 'imgup', $fields)); ?>,
            vidup: <?php echo json_encode($this->feature_button_label_for_artkey($aid, 'vidup', $fields)); ?>,
        };

        function updatePreview(forcedUrl){
            const wrap = document.getElementById('ak_preview_box');
            const btnsWrap = document.getElementById('ak_prev_btns');
            btnsWrap.innerHTML = '';

            const title = document.getElementById('f_title').value || 'Your Art Key';
            const bgColor = document.getElementById('f_theme_bg_color').value;
            const tpl = document.getElementById('f_theme_template').value;
            const font = document.getElementById('f_theme_font').value;
            const stockUrl = document.getElementById('f_theme_bg_image_url').value;
            const txtEl = document.getElementById('f_theme_text_color');
            const textColor = txtEl ? txtEl.value : '';
            const ttlEl = document.getElementById('f_theme_title_color');
            const titleColor = ttlEl ? ttlEl.value : '';
            const scopeSel = document.getElementById('f_theme_color_scope');
            const colorScope = scopeSel ? scopeSel.value : 'content';
            const titleStyleEl = document.getElementById('f_theme_title_style');
            const isTitleSolid = titleStyleEl ? titleStyleEl.checked : false;

            // Theme toggle class
            wrap.classList.remove('tpl-classic','tpl-dark','tpl-bold','tpl-sunset','tpl-ocean','tpl-aurora','tpl-rose_gold','tpl-forest','tpl-cosmic','tpl-vintage','tpl-paper');
            wrap.classList.add('tpl-' + tpl);

            // Base styles - ensure preview is visible
            if (!wrap) return; // Safety check
            wrap.style.display = 'block';
            wrap.style.visibility = 'visible';
            wrap.style.opacity = '1';
            wrap.style.background = bgColor || '';
            let bgUrl = forcedUrl || '';
            if (!bgUrl && stockUrl) bgUrl = stockUrl;
            wrap.style.backgroundImage = bgUrl ? `url('${bgUrl}')` : '';
            wrap.style.backgroundSize = bgUrl ? 'cover' : '';
            wrap.style.backgroundPosition = bgUrl ? 'center' : '';
            wrap.style.width = '100%';
            wrap.style.height = '100%';
            wrap.style.minHeight = '100%';

            // Font
            if (font === 'serif') {
                wrap.style.fontFamily = 'Georgia,Times,"Times New Roman",serif';
            } else if (font === 'mono') {
                wrap.style.fontFamily = 'ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace';
            } else if (font.startsWith('g:')) {
                wrap.style.fontFamily = `"${font.substring(2)}", system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial`;
            } else {
                wrap.style.fontFamily = 'system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,"Helvetica Neue",Arial,"Apple Color Emoji","Segoe UI Emoji"';
            }

            // Apply text color to inner content (scope-aware)
            const inner = wrap.querySelector('.artkey-inner');
            if (inner) inner.style.color = (textColor && (colorScope==='content' || colorScope==='content_buttons')) ? textColor : '';

            // Title
            const titleNode = document.getElementById('ak_prev_title');
            titleNode.textContent = title;
            // Apply title solid color if selected; prefer explicit title color else fall back to text color when scope includes title
            if (isTitleSolid) {
                const col = titleColor || (colorScope==='title' || colorScope==='content' || colorScope==='content_buttons' ? textColor : '');
                titleNode.style.color = col || '';
                titleNode.style.background = 'none';
                titleNode.style.webkitTextFillColor = '';
                titleNode.style.textShadow = '';
            } else {
                // Gradient per CSS; add readability shadow if bg image present
                titleNode.style.color = '';
                titleNode.style.background = '';
                titleNode.style.webkitTextFillColor = '';
                const hasBg = !!(stockUrl);
                titleNode.style.textShadow = hasBg ? '0 1px 2px rgba(0,0,0,0.25)' : '';
            }

            // Custom link buttons (only when label+url present); make draggable in preview
            document.querySelectorAll('#links-wrap .row').forEach((row, idx) => {
                const label = (row.querySelector('input[type="text"]')?.value || '').trim();
                const url = (row.querySelector('input[type="url"]')?.value || '').trim();
                if (label && url) {
                    const a = document.createElement('a');
                    a.className = 'artkey-btn ak-prev-link';
                    a.textContent = label;
                    a.href = url;
                    a.target = '_blank';
                    a.rel = 'noopener';
                    a.setAttribute('draggable', 'true');
                    a.dataset.rowIndex = String(idx);
                    btnsWrap.appendChild(a);
                }
            });

            // Feature CTAs from pills in saved order (always preview when toggled on)
            function addCta(key, txt){
                const a = document.createElement('a');
                a.className = 'artkey-btn ak-prev-cta';
                a.textContent = txt;
                a.href = 'javascript:void(0)';
                a.setAttribute('draggable','true');
                a.dataset.ctaKey = key;
                btnsWrap.appendChild(a);
            }
            const pills = Array.from(document.querySelectorAll('#features-sort .pill'));
            pills.forEach(p => {
                const active = (p.querySelector('input[type="hidden"]')?.value || '') !== '';
                if (!active) return;
                const key = p.dataset.key;
                if (key === 'gallery') addCta('gallery', AK_FEATURE_LABELS.gallery || 'View Gallery');
                else if (key === 'video') addCta('video', AK_FEATURE_LABELS.video || 'Watch Videos');
                else if (key === 'watch_video') addCta('watch_video', AK_FEATURE_LABELS.watch_video || 'Watch video');
                else if (key === 'guestbook') {
                    const showView = document.getElementById('f_gb_btn_view') ? document.getElementById('f_gb_btn_view').checked : true;
                    const showSign = document.getElementById('f_gb_btn_sign') ? document.getElementById('f_gb_btn_sign').checked : true;
                    if (showView) addCta('gb_view', AK_FEATURE_LABELS.gb_view || 'View Guestbook');
                    if (showSign) addCta('gb_sign', AK_FEATURE_LABELS.gb_sign || 'Sign Guestbook');
                }
                else if (key === 'messages_1') addCta('messages_1', AK_FEATURE_LABELS.messages_1 || 'Messages');
                else if (key === 'messages_2') addCta('messages_2', AK_FEATURE_LABELS.messages_2 || 'Messages');
                else if (key === 'imgup') addCta('imgup', AK_FEATURE_LABELS.imgup || 'Upload Images');
                else if (key === 'vidup') addCta('vidup', AK_FEATURE_LABELS.vidup || 'Upload Videos');
            });

            // Mirror live button contrast styling when scope includes buttons
            if (textColor && (colorScope==='buttons' || colorScope==='content_buttons')) {
                const l = akLuma(textColor);
                const isLight = l > 180;
                const bg = isLight ? 'rgba(0,0,0,0.10)' : 'rgba(255,255,255,0.12)';
                const br = isLight ? 'rgba(0,0,0,0.18)' : 'rgba(255,255,255,0.18)';
                const ts = isLight ? '0 1px 2px rgba(0,0,0,0.35)' : '0 1px 2px rgba(255,255,255,0.25)';
                btnsWrap.querySelectorAll('.artkey-btn').forEach(a => {
                    a.style.color = textColor;
                    a.style.backgroundColor = bg;
                    a.style.border = '1px solid ' + br;
                    a.style.textShadow = ts;
                    a.style.backdropFilter = 'blur(2px)';
                    a.style.borderRadius = '999px';
                });
            } else {
                btnsWrap.querySelectorAll('.artkey-btn').forEach(a => {
                    a.style.color = '';
                    a.style.backgroundColor = '';
                    a.style.border = '';
                    a.style.textShadow = '';
                    a.style.backdropFilter = '';
                });
            }

            // Show/hide guestbook sub-options based on toggle
            const gbPill = document.querySelector('#features-sort .pill[data-key="guestbook"]');
            const gbActive = gbPill && (gbPill.querySelector('input[type="hidden"]')?.value || '') !== '';
            const subopts = document.getElementById('guestbook-subopts');
            if (subopts) subopts.style.display = gbActive ? 'block' : 'none';

            // Show/hide watch-video sub-options based on toggle
            const wvPill = document.querySelector('#features-sort .pill[data-key="watch_video"]');
            const wvActive = wvPill && (wvPill.querySelector('input[type="hidden"]')?.value || '') !== '';
            const wvSub = document.getElementById('watch-video-subopts');
            if (wvSub) wvSub.style.display = wvActive ? 'block' : 'none';

            // Spotify
            const sURL = document.getElementById('f_spotify_url').value.trim();
            const sAuto = !!document.getElementById('f_spotify_autoplay')?.checked;
            const sHost = document.getElementById('ak_prev_spotify');
            if (sURL && sURL.includes('open.spotify.com')) {
                let embed = sURL.replace('open.spotify.com/', 'open.spotify.com/embed/');
                if (sAuto) embed += (embed.includes('?') ? '&' : '?') + 'autoplay=1';
                sHost.style.display = 'block';
                sHost.innerHTML = `<iframe style="border-radius:12px" src="${embed}" width="100%" height="152" frameborder="0" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture"></iframe>`;
            } else {
                sHost.style.display = 'none';
                sHost.innerHTML = '';
            }
        }

        // Auto-apply text/title colors when selecting a theme tile
        document.querySelectorAll('.theme-tile').forEach(btn => {
            btn.addEventListener('click', () => {
                const tpl = btn.dataset.theme;
                const def = AK_DEFAULTS[tpl] || AK_DEFAULTS.classic;
                const txt = document.getElementById('f_theme_text_color');
                const ttl = document.getElementById('f_theme_title_color');
                if (txt && !txt.matches(':focus')) txt.value = def.text;
                if (ttl && !ttl.matches(':focus')) ttl.value = def.title;
                updatePreview();
            });
        });

        // Reset button: set both Text & Title colors to template defaults
        (function(){
            const btn = document.getElementById('btn_reset_text_color');
            if (!btn) return;
            btn.addEventListener('click', function(){
                const tpl = document.getElementById('f_theme_template').value || 'classic';
                const def = AK_DEFAULTS[tpl] || AK_DEFAULTS.classic;
                const txt = document.getElementById('f_theme_text_color');
                const ttl = document.getElementById('f_theme_title_color');
                if (txt) txt.value = def.text;
                if (ttl) ttl.value = def.title;
                updatePreview();
            });
        })();

        // Init
        attachLinkListeners();
        updatePreview();

        // ===== Drag & Drop: Links (Buttons) =====
        (function(){
            const list = document.getElementById('links-wrap');
            if (!list) return;
            let draggingEl = null;

            function renumber(){
                Array.from(list.querySelectorAll('.row')).forEach((row, idx) => {
                    const txt = row.querySelector('input[type="text"]');
                    const url = row.querySelector('input[type="url"]');
                    if (txt) txt.name = `f[links][${idx}][label]`;
                    if (url) url.name = `f[links][${idx}][url]`;
                });
            }

            function onDragStart(e){
                const target = e.target.closest('.draggable');
                if (!target) return;
                draggingEl = target;
                target.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain','');
            }
            function onDragOver(e){
                e.preventDefault();
                const after = getDragAfterElement(list, e.clientY);
                const dragging = list.querySelector('.dragging');
                if (!dragging) return;
                if (after == null) {
                    list.appendChild(dragging);
                } else {
                    list.insertBefore(dragging, after);
                }
            }
            function onDrop(){
                const dragging = list.querySelector('.dragging');
                if (dragging) dragging.classList.remove('dragging');
                // Renumber input names to preserve order on submit
                renumber();
                updatePreview();
            }
            function onDragEnd(e){
                e.target.classList.remove('dragging');
            }
            function getDragAfterElement(container, y){
                const els = [...container.querySelectorAll('.row:not(.dragging)')];
                return els.reduce((closest, child) => {
                    const box = child.getBoundingClientRect();
                    const offset = y - box.top - box.height/2;
                    if (offset < 0 && offset > closest.offset) {
                        return { offset, element: child };
                    } else {
                        return closest;
                    }
                }, { offset: Number.NEGATIVE_INFINITY }).element;
            }

            list.addEventListener('dragstart', onDragStart);
            list.addEventListener('dragover', onDragOver);
            list.addEventListener('drop', onDrop);
            list.addEventListener('dragend', onDragEnd);

            // Remove row handler
            list.addEventListener('click', (e) => {
                const btn = e.target.closest('.remove-link');
                if (!btn) return;
                const row = btn.closest('.row');
                if (!row) return;
                row.remove();
                renumber();
                updatePreview();
            });
        })();

        // ===== Drag & Drop: within Live Preview (link buttons only) =====
        (function(){
            const btnsWrap = document.getElementById('ak_prev_btns');
            if (!btnsWrap) return;
            let isDraggingPreview = false;

            function getAfterElement(y){
                const els = [...btnsWrap.querySelectorAll('.ak-prev-link:not(.dragging)')];
                return els.reduce((closest, child) => {
                    const box = child.getBoundingClientRect();
                    const offset = y - box.top - box.height/2;
                    if (offset < 0 && offset > closest.offset) {
                        return { offset, element: child };
                    } else {
                        return closest;
                    }
                }, { offset: Number.NEGATIVE_INFINITY }).element;
            }

            function syncToForm(){
                const order = Array.from(btnsWrap.querySelectorAll('.ak-prev-link')).map(a => parseInt(a.dataset.rowIndex || '-1', 10)).filter(n => n>=0);
                if (!order.length) return;
                const list = document.getElementById('links-wrap');
                const rows = Array.from(list.querySelectorAll('.row'));
                const inSet = new Set(order);
                const ordered = order.map(i => rows[i]).filter(Boolean);
                const remaining = rows.filter((_, i) => !inSet.has(i));
                const combined = [...ordered, ...remaining];
                combined.forEach(r => list.appendChild(r));
                // Renumber inputs
                Array.from(list.querySelectorAll('.row')).forEach((row, idx) => {
                    const txt = row.querySelector('input[type="text"]');
                    const url = row.querySelector('input[type="url"]');
                    if (txt) txt.name = `f[links][${idx}][label]`;
                    if (url) url.name = `f[links][${idx}][url]`;
                });
                updatePreview();
            }

            btnsWrap.addEventListener('dragstart', (e) => {
                const link = e.target.closest('.ak-prev-link');
                if (!link) return;
                link.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain','');
                isDraggingPreview = true;
            });
            btnsWrap.addEventListener('dragover', (e) => {
                e.preventDefault();
                const dragging = btnsWrap.querySelector('.ak-prev-link.dragging');
                if (!dragging) return;
                const after = getAfterElement(e.clientY);
                if (after == null) btnsWrap.appendChild(dragging); else btnsWrap.insertBefore(dragging, after);
            });
            btnsWrap.addEventListener('drop', () => {
                const dragging = btnsWrap.querySelector('.ak-prev-link.dragging');
                if (dragging) dragging.classList.remove('dragging');
                syncToForm();
                isDraggingPreview = false;
            });
            btnsWrap.addEventListener('dragend', () => {
                const dragging = btnsWrap.querySelector('.ak-prev-link.dragging');
                if (dragging) dragging.classList.remove('dragging');
                syncToForm();
                isDraggingPreview = false;
            });
            btnsWrap.addEventListener('click', (e) => {
                if (!isDraggingPreview) return;
                const link = e.target.closest('.ak-prev-link');
                if (link) e.preventDefault();
                isDraggingPreview = false;
            }, true);
        })();

        // ===== Drag & Drop: within Live Preview (feature CTAs) =====
        (function(){
            const btnsWrap = document.getElementById('ak_prev_btns');
            const area = document.getElementById('features-sort');
            const orderInput = document.getElementById('f_features_order');
            if (!btnsWrap || !area || !orderInput) return;

            let dragging = null;

            function getAfterElement(y){
                const els = [...btnsWrap.querySelectorAll('.ak-prev-cta:not(.dragging)')];
                return els.reduce((closest, child) => {
                    const box = child.getBoundingClientRect();
                    const offset = y - box.top - box.height/2;
                    if (offset < 0 && offset > closest.offset) {
                        return { offset, element: child };
                    } else {
                        return closest;
                    }
                }, { offset: Number.NEGATIVE_INFINITY }).element;
            }

            function syncCtaOrderToPills(){
                const ctaKeys = Array.from(btnsWrap.querySelectorAll('.ak-prev-cta')).map(a => a.dataset.ctaKey);
                if (!ctaKeys.length) return;
                const allKeys = Array.from(area.querySelectorAll('.pill')).map(p => p.dataset.key);
                const seen = new Set();
                const combined = [];
                // Map special CTA keys back to their feature pill key
                const normalizedCtas = ctaKeys.map(k => (k === 'gb_view' || k === 'gb_sign') ? 'guestbook' : k);
                normalizedCtas.forEach(k => { if (!seen.has(k)) { combined.push(k); seen.add(k); } });
                allKeys.forEach(k => { if (!seen.has(k)) { combined.push(k); seen.add(k); } });
                // Reorder DOM of pills to match combined
                combined.forEach(k => {
                    const pill = area.querySelector(`.pill[data-key="${k}"]`);
                    if (pill) area.appendChild(pill);
                });
                orderInput.value = combined.join(',');
                updatePreview();
            }

            btnsWrap.addEventListener('dragstart', (e) => {
                const cta = e.target.closest('.ak-prev-cta');
                if (!cta) return;
                dragging = cta;
                cta.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain','');
            });
            btnsWrap.addEventListener('dragover', (e) => {
                const draggingEl = btnsWrap.querySelector('.ak-prev-cta.dragging');
                if (!draggingEl) return; // only when dragging a CTA
                e.preventDefault();
                const after = getAfterElement(e.clientY);
                if (after == null) btnsWrap.appendChild(draggingEl); else btnsWrap.insertBefore(draggingEl, after);
            });
            function endDrag(){
                const draggingEl = btnsWrap.querySelector('.ak-prev-cta.dragging');
                if (draggingEl) draggingEl.classList.remove('dragging');
                dragging = null;
                syncCtaOrderToPills();
            }
            btnsWrap.addEventListener('drop', endDrag);
            btnsWrap.addEventListener('dragend', endDrag);
            btnsWrap.addEventListener('click', (e) => {
                const cta = e.target.closest('.ak-prev-cta');
                if (cta) e.preventDefault();
            });
        })();

        // ===== Drag & Drop + Toggle: Features =====
        (function(){
            const area = document.getElementById('features-sort');
            const orderInput = document.getElementById('f_features_order');
            if (!area || !orderInput) return;

            let dragging = null;

            function syncOrder(){
                const keys = Array.from(area.querySelectorAll('.pill')).map(p => p.dataset.key);
                orderInput.value = keys.join(',');
                updatePreview();
            }

            area.addEventListener('dragstart', (e) => {
                const pill = e.target.closest('.pill');
                if (!pill) return;
                dragging = pill;
                pill.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain','');
            });
            area.addEventListener('dragover', (e) => {
                e.preventDefault();
                const over = e.target.closest('.pill');
                const draggingEl = area.querySelector('.pill.dragging');
                if (!over || !draggingEl || over === draggingEl) return;
                over.classList.add('drag-over');
            });
            area.addEventListener('dragleave', (e) => {
                const over = e.target.closest('.pill');
                if (over) over.classList.remove('drag-over');
            });
            area.addEventListener('drop', (e) => {
                e.preventDefault();
                const over = e.target.closest('.pill');
                const draggingEl = area.querySelector('.pill.dragging');
                if (over && draggingEl && over !== draggingEl) {
                    over.classList.remove('drag-over');
                    area.insertBefore(draggingEl, over);
                    draggingEl.classList.remove('dragging');
                    dragging = null;
                    syncOrder();
                }
            });
            area.addEventListener('dragend', () => {
                const draggingEl = area.querySelector('.pill.dragging');
                if (draggingEl) draggingEl.classList.remove('dragging');
            });

            // Toggle on/off
            area.addEventListener('click', (e) => {
                const btn = e.target.closest('.pill-toggle');
                if (!btn) return;
                const pill = btn.closest('.pill');
                const hidden = pill.querySelector('input[type="hidden"]');
                const isOn = btn.classList.toggle('on');
                btn.classList.toggle('off', !isOn);
                btn.setAttribute('aria-pressed', isOn ? 'true' : 'false');
                if (hidden) hidden.value = isOn ? '1' : '';

                // Keep sub-option dialogs in sync (similar to watch_video behavior)
                try {
                    const key = pill.dataset && pill.dataset.key ? pill.dataset.key : pill.getAttribute('data-key');
                    if (key === 'guestbook') {
                        const gb = document.getElementById('guestbook-subopts');
                        if (gb) gb.style.display = isOn ? 'block' : 'none';
                    } else if (key === 'watch_video') {
                        const wv = document.getElementById('watch-video-subopts');
                        if (wv) wv.style.display = isOn ? 'block' : 'none';
                    } else if (key === 'messages_1') {
                        const m1 = document.getElementById('messages-1-subopts');
                        if (m1) m1.style.display = isOn ? 'block' : 'none';
                    } else if (key === 'messages_2') {
                        const m2 = document.getElementById('messages-2-subopts');
                        if (m2) m2.style.display = isOn ? 'block' : 'none';
                    }
                } catch(e) {}
                updatePreview();
            });
        })();

        </script>
        <?php if ($just_uploaded): ?>
        <script>
        (function(){
            try{
                const url = new URL(window.location.href);
                url.searchParams.delete('uploaded');
                url.searchParams.delete('u_i');
                url.searchParams.delete('u_v');
                window.history.replaceState({}, document.title, url.toString());
            }catch(e){}
        })();

        // Watch video media picker
        (function(){
            const btnPick = document.getElementById('btn_pick_watch_video');
            const btnClear = document.getElementById('btn_clear_watch_video');
            const urlInput = document.getElementById('f_watch_video_url');
            const idInput = document.getElementById('f_watch_video_id');
            if (!btnPick || typeof wp === 'undefined' || !wp.media) return;
            let frame;
            btnPick.addEventListener('click', function(e){
                e.preventDefault();
                if (frame) {
                    try{ frame.open(); frame.content && frame.content.mode && frame.content.mode('upload'); }catch(_){}
                    return;
                }
                frame = wp.media({
                    title: 'Select or Upload Watch Video',
                    button: { text: 'Use this video' },
                    library: { type: 'video' },
                    multiple: false
                });
                frame.on('select', function(){
                    const sel = frame.state().get('selection');
                    const att = sel && sel.first ? sel.first().toJSON() : null;
                    if (!att) return;
                    if (urlInput) urlInput.value = att.url || '';
                    if (idInput) idInput.value = att.id || '0';
                    updatePreview();
                });
                frame.on('open', function(){
                    // Default to Upload tab (device upload) instead of Media Library
                    try{ frame.content && frame.content.mode && frame.content.mode('upload'); }catch(_){}
                });
                frame.open();
            });
            if (btnClear) {
                btnClear.addEventListener('click', function(e){
                    e.preventDefault();
                    if (urlInput) urlInput.value = '';
                    if (idInput) idInput.value = '0';
                    updatePreview();
                });
            }
            if (urlInput) urlInput.addEventListener('input', updatePreview);
        })();

        // Messages 1/2 media pickers (PDF or image)
        (function(){
            const bindPicker = function(which){
                const btnPick = document.getElementById('btn_pick_' + which);
                const btnClear = document.getElementById('btn_clear_' + which);
                const urlInput = document.getElementById('f_' + which + '_url');
                const idInput = document.getElementById('f_' + which + '_id');
                if (!btnPick || typeof wp === 'undefined' || !wp.media) return;
                let frame;

                btnPick.addEventListener('click', function(e){
                    e.preventDefault();
                    if (frame) {
                        try{ frame.open(); frame.content && frame.content.mode && frame.content.mode('upload'); }catch(_){ }
                        return;
                    }
                    frame = wp.media({
                        title: 'Select or Upload File',
                        button: { text: 'Use this file' },
                        library: { type: [ 'application/pdf', 'image' ] },
                        multiple: false
                    });
                    frame.on('select', function(){
                        const sel = frame.state().get('selection');
                        const att = sel && sel.first ? sel.first().toJSON() : null;
                        if (!att) return;
                        if (urlInput) urlInput.value = att.url || '';
                        if (idInput) idInput.value = att.id || '0';
                        updatePreview();
                    });
                    frame.on('open', function(){
                        try{ frame.content && frame.content.mode && frame.content.mode('upload'); }catch(_){ }
                    });
                    frame.open();
                });

                if (btnClear) {
                    btnClear.addEventListener('click', function(e){
                        e.preventDefault();
                        if (urlInput) urlInput.value = '';
                        if (idInput) idInput.value = '0';
                        updatePreview();
                    });
                }
                if (urlInput) urlInput.addEventListener('input', updatePreview);
            };

            bindPicker('messages_1');
            bindPicker('messages_2');
        })();
        </script>
        <?php endif; ?>
        <?php
        return ob_get_clean();
    }

    /* ====== Handlers ====== */
    public function handle_editor_post() {
        $triggered = isset($_POST['artkey_save']) || isset($_POST['artkey_save_shop']) || isset($_POST['artkey_save_checkout']);
        if (!$triggered) return;
        $aid = (int)($_POST['artkey_id'] ?? 0);
        if (!$aid || get_post_type($aid)!==self::CPT) return;
        if (!isset($_POST['editor_nonce']) || !wp_verify_nonce($_POST['editor_nonce'], 'editor_'.$aid)) return;

        $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : (isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '');
        if (!$this->can_edit($aid, $token)) return;

        $in = $_POST['f'] ?? [];
        $fields = get_post_meta($aid, self::META_FIELDS, true);
        $fields = is_array($fields) ? $fields : [];

        $fields['title'] = sanitize_text_field($in['title'] ?? ($fields['title'] ?? 'Your Art Key'));

        $fields['links'] = [];
        if (!empty($in['links']) && is_array($in['links'])) {
            foreach ($in['links'] as $row) {
                $label = sanitize_text_field($row['label'] ?? '');
                $url   = esc_url_raw($row['url'] ?? '');
                if ($label && $url) $fields['links'][] = ['label'=>$label,'url'=>$url];
            }
        }

    $fields['spotify']['url'] = esc_url_raw($in['spotify']['url'] ?? '');
    $fields['spotify']['autoplay'] = !empty($in['spotify']['autoplay']) ? 1 : 0;

        $fields['features'] = [
            'show_guestbook'    => !empty($in['features']['show_guestbook']),
            'allow_img_uploads' => !empty($in['features']['allow_img_uploads']),
            'allow_vid_uploads' => !empty($in['features']['allow_vid_uploads']),
            'enable_gallery'    => !empty($in['features']['enable_gallery']),
            'enable_video'      => !empty($in['features']['enable_video']),
            'enable_watch_video'=> !empty($in['features']['enable_watch_video']),
            'enable_messages_1' => !empty($in['features']['enable_messages_1']),
            'enable_messages_2' => !empty($in['features']['enable_messages_2']),
            // New guestbook CTA sub-options
            'gb_btn_view'       => !empty($in['features']['gb_btn_view']),
            'gb_btn_sign'       => !empty($in['features']['gb_btn_sign']),
        ];
        // Save feature order (comma-separated keys)
        $allowed_order_keys = ['gallery','video','watch_video','guestbook','messages_1','messages_2','imgup','vidup'];
        $order_str = sanitize_text_field($in['features_order'] ?? '');
        $order_arr = array_values(array_filter(array_map('trim', explode(',', $order_str)), function($k) use ($allowed_order_keys){ return in_array($k, $allowed_order_keys, true); }));
        if (empty($order_arr)) { $order_arr = $allowed_order_keys; }
        $fields['features']['order'] = $order_arr;

        // Per-Art Key feature button label overrides
    $allowed_label_keys = ['gallery','video','watch_video','gb_view','gb_sign','messages_1','messages_2','imgup','vidup'];
        $labels_in = isset($in['feature_btn_labels']) && is_array($in['feature_btn_labels']) ? $in['feature_btn_labels'] : [];
        $labels_out = [];
        foreach ($allowed_label_keys as $k) {
            $v = sanitize_text_field($labels_in[$k] ?? '');
            $v = trim((string)$v);
            if ($v !== '') {
                $labels_out[$k] = $v;
            }
        }
        if (!empty($labels_out)) {
            $fields['feature_btn_labels'] = $labels_out;
        } else {
            unset($fields['feature_btn_labels']);
        }

        // Messages 1/2 files (PDF or image)
        foreach ([
            'messages_1' => ($in['messages_1'] ?? []),
            'messages_2' => ($in['messages_2'] ?? []),
        ] as $msg_key => $msg_in) {
            $msg_in = is_array($msg_in) ? $msg_in : [];
            $msg_id = (int)($msg_in['id'] ?? 0);
            $msg_url = esc_url_raw($msg_in['url'] ?? '');

            // If attachment ID is provided, prefer pulling URL from attachment
            if ($msg_id) {
                $att = get_post($msg_id);
                if ($att && $att->post_type === 'attachment') {
                    $msg_url = wp_get_attachment_url($msg_id) ?: $msg_url;
                }
            }

            if ($msg_id || $msg_url) {
                $fields[$msg_key] = ['id' => $msg_id, 'url' => $msg_url];
            } else {
                unset($fields[$msg_key]);
            }
        }

        // Watch video field (single attachment)
        $wv_in = isset($in['watch_video']) && is_array($in['watch_video']) ? $in['watch_video'] : [];
        $wv_id = (int)($wv_in['id'] ?? 0);
        $wv_url = esc_url_raw($wv_in['url'] ?? '');
        // If ID is provided, prefer pulling URL from attachment
        if ($wv_id) {
            $att = get_post($wv_id);
            if ($att && $att->post_type === 'attachment') {
                $wv_url = wp_get_attachment_url($wv_id) ?: $wv_url;
            }
        }
        $fields['watch_video'] = ['id' => $wv_id, 'url' => $wv_url];

        $theme_in = $in['theme'] ?? [];
        // Persist theme including advanced options
        $fields['theme'] = [
            'template'    => in_array(($theme_in['template'] ?? 'classic'), ['classic','dark','bold','sunset','ocean','aurora','rose_gold','forest','cosmic','vintage','paper'], true) ? $theme_in['template'] : 'classic',
            'bg_color'    => sanitize_hex_color($theme_in['bg_color'] ?? '#F6F7FB') ?: '#F6F7FB',
            'bg_image_id' => (int)($theme_in['bg_image_id'] ?? 0),
            'bg_image_url'=> esc_url_raw($theme_in['bg_image_url'] ?? ''),
            'text_color'  => sanitize_hex_color($theme_in['text_color'] ?? '') ?: '',
            'title_color' => sanitize_hex_color($theme_in['title_color'] ?? '') ?: '',
            // New scope for applying text color: content | buttons | title | content_buttons
            'color_scope' => (function($s){
                $allowed = ['content','buttons','title','content_buttons'];
                return in_array($s ?? 'content', $allowed, true) ? $s : 'content';
            })($theme_in['color_scope'] ?? 'content'),
            // New title style: 'solid' uses text_color, default 'gradient' keeps theme gradient
            'title_style' => (($theme_in['title_style'] ?? '') === 'solid') ? 'solid' : 'gradient',
            'font'        => (function($f){
                $allowed = ['system','serif','mono','g:Inter','g:Poppins','g:Lato','g:Montserrat','g:Roboto','g:Playfair Display','g:Open Sans'];
                return in_array($f ?? 'system', $allowed, true) ? $f : 'system';
            })($theme_in['font'] ?? 'system'),
        ];

        // Save print template selection (for QR code products)
        if (isset($in['print_template'])) {
            $template_key = sanitize_text_field($in['print_template']);
            if (array_key_exists($template_key, self::PRINT_TEMPLATES)) {
                $fields['print_template'] = $template_key;
            }
        }
        
        // Save user design image ID (uploaded from React editor)
        if (isset($in['user_design_image_id'])) {
            $design_id = (int)$in['user_design_image_id'];
            if ($design_id > 0 && get_post_type($design_id) === 'attachment') {
                $fields['user_design_image_id'] = $design_id;
            }
        }

        update_post_meta($aid, self::META_FIELDS, $fields);
        
        // Generate QR code and composited print image if template is selected
        if (!empty($fields['print_template']) && !empty($fields['user_design_image_id'])) {
            $this->generate_composited_print_image($aid);
        }

        $approve_img = array_map('intval', $_POST['approve_img'] ?? []);
        foreach ($approve_img as $img) update_post_meta($img, '_approved', 1);

        $approve_vid = array_map('intval', $_POST['approve_vid'] ?? []);
        foreach ($approve_vid as $vid) update_post_meta($vid, '_approved', 1);

        // Deletions from Media Gallery (approved-only UI)
        $delete_img = array_map('intval', $_POST['delete_img'] ?? []);
        $delete_vid = array_map('intval', $_POST['delete_vid'] ?? []);
        $to_delete = array_unique(array_merge($delete_img, $delete_vid));
        foreach ($to_delete as $del_id) {
            if (!$del_id) continue;
            $p = get_post($del_id);
            if (!$p || $p->post_type !== 'attachment') continue;
            if ((int)$p->post_parent !== (int)$aid) continue; // must belong to this Art Key
            // Optional: ensure it's approved or kind-restricted; UI already scopes to approved
            wp_delete_attachment($del_id, true);
        }

        wp_update_post(['ID'=>$aid,'post_title'=>$fields['title']]);

        $checkout_flow = !empty($_POST['checkout_flow']);
        // Submit routing
        $save_only          = !empty($_POST['artkey_save']);
        $save_and_shop      = !empty($_POST['artkey_save_shop']);
        $save_and_checkout  = !empty($_POST['artkey_save_checkout']);

        if ($save_and_checkout && function_exists('WC') && WC()->session) {
            WC()->session->set(self::SESSION_ARTKEY_COMPLETE, 1);
            WC()->session->set(self::SESSION_ARTKEY_ID, $aid);
            $checkout_url = wc_get_checkout_url();
            wp_safe_redirect($checkout_url);
            exit;
        }

        if ($save_and_shop) {
            // Go back to products listing
            $shop_url = function_exists('wc_get_page_permalink') ? wc_get_page_permalink('shop') : home_url('/shop/');
            wp_safe_redirect(add_query_arg(['updated'=>1], $shop_url));
            exit;
        }

        // Default: stay on editor with confirmation
        $referer = $_SERVER['HTTP_REFERER'] ?? $this->editor_url($aid, $_POST['token'] ?? '');
        $dest = add_query_arg(['updated'=>1], $referer ?: get_permalink($aid));
        wp_safe_redirect($dest);
        exit;
    }

    public function handle_guestbook_post() {
        if (!isset($_POST['gb_submit'])) return;
        $aid = (int)($_POST['artkey_id'] ?? 0);
        if (!$aid || get_post_type($aid)!==self::CPT) return;
        if (!isset($_POST['guestbook_nonce']) || !wp_verify_nonce($_POST['guestbook_nonce'], 'guestbook_'.$aid)) return;
        if (!$this->recaptcha_valid()) return;

        $name = sanitize_text_field($_POST['gb_name'] ?? '');
        $msg  = sanitize_textarea_field($_POST['gb_msg'] ?? '');
        if (!$name || !$msg) return;

        if (!empty($_COOKIE['gb_'.$aid])) return;
        setcookie('gb_'.$aid, '1', time()+60, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true);

        wp_insert_comment([
            'comment_post_ID' => $aid,
            'comment_author'  => $name,
            'comment_content' => $msg,
            'comment_approved'=> 1,
        ]);
        wp_safe_redirect($_SERVER['HTTP_REFERER'] ?? get_permalink($aid));
        exit;
    }

    public function handle_upload_post() {
        if (!isset($_POST['upload_submit']) && !isset($_POST['watch_video_upload']) && !isset($_POST['messages_1_upload']) && !isset($_POST['messages_2_upload'])) return;
        $aid = (int)($_POST['artkey_id'] ?? 0);
        if (!$aid || get_post_type($aid)!==self::CPT) return;
        if (!isset($_POST['upload_nonce']) || !wp_verify_nonce($_POST['upload_nonce'], 'upload_'.$aid)) return;
        $from_editor = !empty($_POST['from_editor']);
        if ($from_editor) {
            $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
            if (!$this->can_edit($aid, $token)) return; // editor-authenticated upload bypasses reCAPTCHA
        } else {
            if (!$this->recaptcha_valid()) return; // visitor uploads require reCAPTCHA
        }

        require_once ABSPATH.'wp-admin/includes/file.php';

    $uploaded_images = 0;
        $uploaded_videos = 0;

        if (isset($_FILES['images']) && !empty($_FILES['images']['name']) && !empty(array_filter((array)$_FILES['images']['name']))) {
            foreach ($_FILES['images']['name'] as $i => $name) {
                if (empty($name)) continue;
                $file = [
                    'name'     => $_FILES['images']['name'][$i],
                    'type'     => $_FILES['images']['type'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error'    => $_FILES['images']['error'][$i],
                    'size'     => $_FILES['images']['size'][$i],
                ];
                $new_id = $this->handle_single_upload($file, $aid, 'image', $from_editor);
                if ($new_id) $uploaded_images++;
            }
        }

        // Watch video (single file) upload from editor
        if (!empty($_POST['watch_video_upload']) && !empty($_FILES['watch_video_file']) && !empty($_FILES['watch_video_file']['name'])) {
            $file = [
                'name'     => $_FILES['watch_video_file']['name'],
                'type'     => $_FILES['watch_video_file']['type'],
                'tmp_name' => $_FILES['watch_video_file']['tmp_name'],
                'error'    => $_FILES['watch_video_file']['error'],
                'size'     => $_FILES['watch_video_file']['size'],
            ];
            $wv_id = $this->handle_single_upload($file, $aid, 'video', true, 'watch_video'); // stored separately from gallery
            if ($wv_id) {
                $uploaded_videos++;
                $fields = get_post_meta($aid, self::META_FIELDS, true);
                $fields = is_array($fields) ? $fields : [];
                $fields['watch_video'] = [
                    'id'  => (int)$wv_id,
                    'url' => wp_get_attachment_url((int)$wv_id) ?: '',
                ];
                update_post_meta($aid, self::META_FIELDS, $fields);
            }
        }

        // Messages 1 (single file) upload from editor (PDF or image)
        if (!empty($_POST['messages_1_upload']) && !empty($_FILES['messages_1_file']) && !empty($_FILES['messages_1_file']['name'])) {
            $file = [
                'name'     => $_FILES['messages_1_file']['name'],
                'type'     => $_FILES['messages_1_file']['type'],
                'tmp_name' => $_FILES['messages_1_file']['tmp_name'],
                'error'    => $_FILES['messages_1_file']['error'],
                'size'     => $_FILES['messages_1_file']['size'],
            ];
            // Store separately from gallery
            $m1_id = $this->handle_single_upload($file, $aid, 'message', true, 'messages_1');
            if ($m1_id) {
                $fields = get_post_meta($aid, self::META_FIELDS, true);
                $fields = is_array($fields) ? $fields : [];
                $fields['messages_1'] = [
                    'id'  => (int)$m1_id,
                    'url' => wp_get_attachment_url((int)$m1_id) ?: '',
                ];
                update_post_meta($aid, self::META_FIELDS, $fields);
            }
        }

        // Messages 2 (single file) upload from editor (PDF or image)
        if (!empty($_POST['messages_2_upload']) && !empty($_FILES['messages_2_file']) && !empty($_FILES['messages_2_file']['name'])) {
            $file = [
                'name'     => $_FILES['messages_2_file']['name'],
                'type'     => $_FILES['messages_2_file']['type'],
                'tmp_name' => $_FILES['messages_2_file']['tmp_name'],
                'error'    => $_FILES['messages_2_file']['error'],
                'size'     => $_FILES['messages_2_file']['size'],
            ];
            // Store separately from gallery
            $m2_id = $this->handle_single_upload($file, $aid, 'message', true, 'messages_2');
            if ($m2_id) {
                $fields = get_post_meta($aid, self::META_FIELDS, true);
                $fields = is_array($fields) ? $fields : [];
                $fields['messages_2'] = [
                    'id'  => (int)$m2_id,
                    'url' => wp_get_attachment_url((int)$m2_id) ?: '',
                ];
                update_post_meta($aid, self::META_FIELDS, $fields);
            }
        }

        if (isset($_FILES['videos']) && !empty($_FILES['videos']['name']) && !empty(array_filter((array)$_FILES['videos']['name']))) {
            foreach ($_FILES['videos']['name'] as $i => $name) {
                if (empty($name)) continue;
                $file = [
                    'name'     => $_FILES['videos']['name'][$i],
                    'type'     => $_FILES['videos']['type'][$i],
                    'tmp_name' => $_FILES['videos']['tmp_name'][$i],
                    'error'    => $_FILES['videos']['error'][$i],
                    'size'     => $_FILES['videos']['size'][$i],
                ];
                $new_id = $this->handle_single_upload($file, $aid, 'video', $from_editor);
                if ($new_id) $uploaded_videos++;
            }
        }
        // Background image single-file upload (from editor Theme section)
        if (!empty($_FILES['bg_image']) && !empty($_FILES['bg_image']['name'])) {
            $file = [
                'name'     => $_FILES['bg_image']['name'],
                'type'     => $_FILES['bg_image']['type'],
                'tmp_name' => $_FILES['bg_image']['tmp_name'],
                'error'    => $_FILES['bg_image']['error'],
                'size'     => $_FILES['bg_image']['size'],
            ];
            $bg_id = $this->handle_single_upload($file, $aid, 'image', $from_editor);
            if ($bg_id) {
                $uploaded_images++;
                // Set as theme background (id wins over url)
                $fields = get_post_meta($aid, self::META_FIELDS, true);
                $fields = is_array($fields) ? $fields : [];
                if (!isset($fields['theme']) || !is_array($fields['theme'])) $fields['theme'] = [];
                $fields['theme']['bg_image_id'] = (int)$bg_id;
                $fields['theme']['bg_image_url'] = '';
                update_post_meta($aid, self::META_FIELDS, $fields);
            }
        }
        // Redirect back to editor when uploading from editor to avoid context loss
        if ($from_editor) {
            $token = isset($_POST['token']) ? sanitize_text_field($_POST['token']) : '';
            $editor = $this->editor_url($aid, $token);
            $dest = $editor ? $editor : ($_SERVER['HTTP_REFERER'] ?? get_permalink($aid));
            // Append small success flag and counts when something uploaded
            if (($uploaded_images + $uploaded_videos) > 0) {
                $dest = add_query_arg([
                    'uploaded' => 1,
                    'u_i'      => $uploaded_images,
                    'u_v'      => $uploaded_videos,
                ], $dest);
            }
            wp_safe_redirect($dest);
        } else {
            wp_safe_redirect($_SERVER['HTTP_REFERER'] ?? get_permalink($aid));
        }
        exit;
    }

    private function handle_single_upload($file, $aid, $type, $autoApprove = false, $role = '') {
        $max_size    = 30 * 1024 * 1024; // 30MB

        if (empty($file['name']) || $file['error']) return;
        if ($file['size'] > $max_size) return;

        $overrides = ['test_form'=>false,'mimes'=>null];
        $upload = wp_handle_upload($file, $overrides);
        if (!empty($upload['error'])) return 0;

        $attachment = [
            'post_mime_type' => $upload['type'],
            'post_title'     => sanitize_file_name($file['name']),
            'post_status'    => 'inherit',
            'post_parent'    => $aid,
        ];
        $new_id = wp_insert_attachment($attachment, $upload['file'], $aid);
        if (is_wp_error($new_id) || !$new_id) return 0;

        // Generate metadata only for images to avoid server issues when video toolchain isn't available
        // (messages_1/messages_2 uploads may come through as $type === 'message' but still be an image)
        if ($type === 'image' || $type === 'message') {
            require_once ABSPATH.'wp-admin/includes/image.php';
            $meta = wp_generate_attachment_metadata($new_id, $upload['file']);
            if (!is_wp_error($meta) && !empty($meta)) {
                wp_update_attachment_metadata($new_id, $meta);
            }
        }

        // Editor-origin uploads are auto-approved; visitor uploads require moderation
        update_post_meta($new_id, '_approved', $autoApprove ? 1 : 0);
        // Use attachment meta to drive gallery/video library listings.
        // Note: 'message' attachments are separate from gallery.
        update_post_meta($new_id, '_media_kind', $type); // image|video|message
        if (!empty($role)) {
            update_post_meta($new_id, '_media_role', sanitize_key($role));
        }
        return (int)$new_id;
    }

    /* ====== Helpers ====== */
    private function can_edit($aid, $token) {
        // Admins can always open the editor
        if (current_user_can('manage_options')) return true;
        $owner = (int)get_post_meta($aid, self::META_USER_ID, true);
        if ($owner && is_user_logged_in() && get_current_user_id() === $owner) return true;
        $saved = get_post_meta($aid, self::META_EDIT_TOKEN, true);
        return ($token && hash_equals($saved, $token));
    }

    private function get_media_by_status($aid, $kind, $approved) {
        $atts = get_children([
            'post_parent' => $aid,
            'post_type'   => 'attachment',
            'numberposts' => -1,
            'post_status' => 'inherit',
        ]);
        $out = [];
        foreach ($atts as $a) {
            // Exclude special-purpose attachments from gallery/video library.
            // (Watch video and messages are handled separately via $fields['watch_video'] / $fields['messages_1'] / $fields['messages_2']).
            $role = get_post_meta($a->ID, '_media_role', true);
            if ($role === 'watch_video' || $role === 'messages_1' || $role === 'messages_2') continue;

            $ok = (int)get_post_meta($a->ID, '_approved', true) === ($approved ? 1 : 0);
            $k  = get_post_meta($a->ID, '_media_kind', true);
            if (!$k) {
                $mime = get_post_mime_type($a->ID);
                if (strpos((string)$mime, 'image/') === 0) $k = 'image';
                elseif (strpos((string)$mime, 'video/') === 0) $k = 'video';
            }
            if ($ok && $k === $kind) $out[] = $a->ID;
        }
        return $out;
    }
    private function get_approved_media($aid, $kind) {
        return $this->get_media_by_status($aid, $kind, true);
    }

    private function editor_url($aid, $token) {
        $editor_page = $this->find_page_with_shortcode(array('artkey_editor','biolink_editor'));
        if (!$editor_page) return '';
        return add_query_arg(['artkey_id'=>$aid,'token'=>$token], get_permalink($editor_page));
    }

    /* Removed cart button label overrides to restore WooCommerce defaults. */

    /** Determine if current cart needs to go to the editor before checkout */
    private function needs_editor_gate() {
        if (!function_exists('WC') || !WC()->cart) return false;
        $cart = WC()->cart;
        if (!$cart || $cart->is_empty()) return false;

        $needs = false;
        foreach ($cart->get_cart() as $item) {
            $pid = $item['product_id'] ?? 0;
            if ($pid && get_post_meta($pid, self::PRODUCT_META_ENABLE, true) === 'yes') { $needs = true; break; }
        }
        if (!$needs) return false;

        $session = WC()->session;
        if ($session && !$session->get(self::SESSION_ARTKEY_COMPLETE)) return true;
        return false;
    }
    private function find_page_with_shortcode($shortcodes) {
        if (!is_array($shortcodes)) $shortcodes = [$shortcodes];
        $pages = get_posts(['post_type'=>'page','posts_per_page'=>-1]);
        foreach ($pages as $p) {
            $content = $p->post_content ?? '';
            foreach ($shortcodes as $sc) {
                if (has_shortcode($content, $sc)) return $p->ID;
            }
        }
        return 0;
    }

    /* Removed DOM-based cart label override script injection. */

    private function inline_theme_style($theme) {
        $styles = [];
        $tpl = $theme['template'] ?? 'classic';
        if (!empty($theme['bg_color'])) $styles[] = 'background: '.$theme['bg_color'];
        if ($tpl === 'sunset') $styles[] = 'background: linear-gradient(135deg,#ff9a9e,#fad0c4)';
    if ($tpl === 'ocean')  $styles[] = 'background: linear-gradient(135deg,#74ebd5,#ACB6E5)';
    if ($tpl === 'aurora') $styles[] = 'background: linear-gradient(135deg,#667eea,#764ba2,#f093fb)';
    if ($tpl === 'rose_gold') $styles[] = 'background: linear-gradient(135deg,#f7971e,#ffd200,#f093fb)';
    if ($tpl === 'forest') $styles[] = 'background: linear-gradient(135deg,#134e5e,#71b280,#a8e6cf)';
    if ($tpl === 'cosmic') $styles[] = 'background: linear-gradient(135deg,#0c0c0c,#1a1a2e,#16213e,#e94560)';
    if ($tpl === 'vintage') $styles[] = 'background: linear-gradient(135deg,#8b4513,#daa520,#f4e4bc)';
        if ($tpl === 'paper')  $styles[] = 'background: #fbf8f1';

        if (!empty($theme['bg_image_id'])) {
            $url = wp_get_attachment_image_url((int)$theme['bg_image_id'], 'full');
            if ($url) $styles[] = "background-image:url('{$url}'); background-size:cover; background-position:center";
        } elseif (!empty($theme['bg_image_url'])) {
            $url = esc_url($theme['bg_image_url']);
            $styles[] = "background-image:url('{$url}'); background-size:cover; background-position:center";
        }

        $font = $theme['font'] ?? 'system';
        if (strpos($font, 'g:') === 0) {
            $family = substr($font, 2);
            $styles[] = 'font-family: "'.$family.'", system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Helvetica Neue", Arial';
        } elseif ($font==='serif') {
            $styles[] = 'font-family: Georgia,Times,"Times New Roman",serif';
        } elseif ($font==='mono') {
            $styles[] = 'font-family: ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono","Courier New",monospace';
        } else {
            $styles[] = 'font-family: system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,"Helvetica Neue",Arial,"Apple Color Emoji","Segoe UI Emoji"';
        }
        return implode(';', $styles);
    }

    private function spotify_embed($url, $autoplay = false) {
        if (strpos($url, 'open.spotify.com')===false) return '';
        $embed = str_replace('open.spotify.com/', 'open.spotify.com/embed/', $url);
        if ($autoplay) {
            $sep = (strpos($embed, '?') !== false) ? '&' : '?';
            $embed .= $sep . 'autoplay=1';
        }
        return '<iframe style="border-radius:12px" src="'.esc_url($embed).'" width="100%" height="152" frameborder="0" allow="autoplay; clipboard-write; encrypted-media; picture-in-picture"></iframe>';
    }

    public function enqueue_styles() {
        $css = "
        /* Cross-browser compatibility reset */
        .artkey-wrap *,.artkey-wrap *::before,.artkey-wrap *::after{box-sizing:border-box;-webkit-box-sizing:border-box;-moz-box-sizing:border-box}
        
        /* Artistic animations - with vendor prefixes for older browsers */
        @-webkit-keyframes ak-gradient {0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
        @-moz-keyframes ak-gradient {0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
        @-o-keyframes ak-gradient {0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
        @keyframes ak-gradient {0%{background-position:0% 50%}50%{background-position:100% 50%}100%{background-position:0% 50%}}
        
        @-webkit-keyframes ak-shimmer {0%{-webkit-transform:translateX(-100%);transform:translateX(-100%)}100%{-webkit-transform:translateX(100%);transform:translateX(100%)}}
        @-moz-keyframes ak-shimmer {0%{-moz-transform:translateX(-100%)}100%{-moz-transform:translateX(100%)}}
        @keyframes ak-shimmer {0%{transform:translateX(-100%)}100%{transform:translateX(100%)}}

        /* Layout - Full screen on all devices with comprehensive fallbacks */
        .artkey-wrap{
            min-height:100vh;/* Fallback for older browsers */
            min-height:-webkit-fill-available;/* iOS Safari */
            min-height:100dvh;/* Modern browsers with dynamic viewport */
            display:-webkit-box;display:-webkit-flex;display:-moz-box;display:-ms-flexbox;display:flex;
            -webkit-box-align:center;-webkit-align-items:center;-moz-box-align:center;-ms-flex-align:center;align-items:center;
            -webkit-box-pack:center;-webkit-justify-content:center;-moz-box-pack:center;-ms-flex-pack:center;justify-content:center;
            padding:40px;position:relative;overflow:hidden;width:100%;
            -webkit-box-sizing:border-box;-moz-box-sizing:border-box;box-sizing:border-box
        }
        /* Android Chrome & iOS Safari specific fixes with fallbacks */
        @media screen and (max-width:768px) {
            .artkey-wrap{
                min-height:100vh;/* Fallback */
                min-height:-webkit-fill-available;/* iOS Safari */
                min-height:100dvh;/* Modern Android Chrome */
                width:100vw;
                -webkit-box-sizing:border-box;-moz-box-sizing:border-box;box-sizing:border-box;
                padding:10px 8px;/* Reduced padding on small screens */
            }
        }
        
        /* Fallback for browsers that don't support dvh */
        @supports not (height: 100dvh) {
            .artkey-wrap{min-height:100vh}
            @media screen and (max-width:768px) {
                .artkey-wrap{min-height:100vh;height:auto}
            }
        }
    .artkey-wrap::before{content:\"\";position:absolute;inset:-40%;background:
            radial-gradient(40% 40% at 20% 70%, rgba(255,154,158,.15) 0%, transparent 60%),
            radial-gradient(40% 40% at 80% 30%, rgba(172,182,229,.15) 0%, transparent 60%);
            z-index:-1;filter:blur(10px)}

        /* Card - with backdrop-filter fallback for older browsers */
        .artkey-inner{
            background:rgba(255,255,255,.95);/* Fallback for browsers without backdrop-filter */
            -webkit-backdrop-filter:saturate(160%) blur(12px);/* Safari */
            backdrop-filter:saturate(160%) blur(12px);/* Modern browsers */
            -webkit-border-radius:22px;-moz-border-radius:22px;border-radius:22px;
            -webkit-box-shadow:0 18px 50px rgba(0,0,0,.12),0 8px 24px rgba(0,0,0,.08);
            -moz-box-shadow:0 18px 50px rgba(0,0,0,.12),0 8px 24px rgba(0,0,0,.08);
            box-shadow:0 18px 50px rgba(0,0,0,.12),0 8px 24px rgba(0,0,0,.08);
            padding:36px;max-width:820px;width:100%;position:relative;border:1px solid rgba(255,255,255,.4);
            -webkit-transition:-webkit-transform .35s ease, box-shadow .35s ease;
            -moz-transition:-moz-transform .35s ease, box-shadow .35s ease;
            -o-transition:-o-transform .35s ease, box-shadow .35s ease;
            transition:transform .35s ease, box-shadow .35s ease
        }
        
        /* Fallback for browsers without backdrop-filter support */
        @supports not (backdrop-filter: blur(12px)) {
            .artkey-inner{background:rgba(255,255,255,.98)}
        }
    .artkey-inner::after{content:\"\";position:absolute;top:0;left:-100%;height:100%;width:100%;
            background:linear-gradient(90deg,transparent,rgba(255,255,255,.5),transparent)}
        .artkey-inner:hover{
            -webkit-transform:translateY(-4px);-moz-transform:translateY(-4px);-ms-transform:translateY(-4px);transform:translateY(-4px);
            -webkit-box-shadow:0 26px 70px rgba(0,0,0,.16),0 12px 32px rgba(0,0,0,.12);
            -moz-box-shadow:0 26px 70px rgba(0,0,0,.16),0 12px 32px rgba(0,0,0,.12);
            box-shadow:0 26px 70px rgba(0,0,0,.16),0 12px 32px rgba(0,0,0,.12)
        }
        .artkey-inner:hover::after{
            -webkit-animation:ak-shimmer .8s linear 1;
            -moz-animation:ak-shimmer .8s linear 1;
            -o-animation:ak-shimmer .8s linear 1;
            animation:ak-shimmer .8s linear 1
        }

        /* Themes */
        .tpl-dark .artkey-inner{background:rgba(15,18,24,.92);color:#f4f6fb;border:1px solid rgba(255,255,255,.12)}
        .tpl-bold .artkey-inner{background:linear-gradient(145deg,#0a0a0a,#1a1a1a);color:#fff;border:2px solid #fff;box-shadow:0 0 28px rgba(255,255,255,.25),0 18px 50px rgba(0,0,0,.4)}
    .tpl-sunset .artkey-inner{background:linear-gradient(-45deg,#ff9a9e,#fad0c4,#ffeaa7);background-size:300% 300%;animation:ak-gradient 10s ease infinite;color:#2d3436}
    .tpl-ocean .artkey-inner{background:linear-gradient(-45deg,#74ebd5,#ACB6E5,#a8edea);background-size:300% 300%;animation:ak-gradient 12s ease infinite;color:#2d3436}
    .tpl-aurora .artkey-inner{background:linear-gradient(-45deg,#667eea,#764ba2,#f093fb);background-size:300% 300%;animation:ak-gradient 10s ease infinite;color:#ffffff}
    .tpl-rose_gold .artkey-inner{background:linear-gradient(-45deg,#f7971e,#ffd200,#f093fb);background-size:300% 300%;animation:ak-gradient 10s ease infinite;color:#2d3436}
    .tpl-forest .artkey-inner{background:linear-gradient(-45deg,#134e5e,#71b280,#a8e6cf);background-size:300% 300%;animation:ak-gradient 12s ease infinite;color:#f0fff4}
    .tpl-cosmic .artkey-inner{background:linear-gradient(-45deg,#0c0c0c,#1a1a2e,#16213e,#e94560);background-size:300% 300%;animation:ak-gradient 14s ease infinite;color:#eaeaea}
    .tpl-vintage .artkey-inner{background:linear-gradient(-45deg,#8b4513,#daa520,#f4e4bc);background-size:300% 300%;animation:ak-gradient 11s ease infinite;color:#2d2a26}

        /* Title - with gradient text fallback */
        .artkey-title{
            margin:0 0 18px;
            font-size:28px;/* Fallback for browsers without clamp() */
            font-size:clamp(28px,5vw,44px);/* Responsive font size */
            line-height:1.15;font-weight:800;text-align:center;
            background:#667eea;/* Fallback color */
            background:-webkit-linear-gradient(135deg,#667eea 0%, #764ba2 100%);
            background:-moz-linear-gradient(135deg,#667eea 0%, #764ba2 100%);
            background:linear-gradient(135deg,#667eea 0%, #764ba2 100%);
            -webkit-background-clip:text;background-clip:text;
            -webkit-text-fill-color:transparent;color:#667eea;/* Fallback for browsers without -webkit-text-fill-color */
        }
        /* Fallback for browsers without gradient text support */
        @supports not (-webkit-background-clip: text) {
            .artkey-title{color:#667eea;background:none}
        }
        .tpl-dark .artkey-title{
            background:#ffeaa7;/* Fallback */
            background:-webkit-linear-gradient(135deg,#ffeaa7 0%, #fab1a0 100%);
            background:-moz-linear-gradient(135deg,#ffeaa7 0%, #fab1a0 100%);
            background:linear-gradient(135deg,#ffeaa7 0%, #fab1a0 100%);
            -webkit-background-clip:text;background-clip:text;
            color:#ffeaa7;/* Fallback */
        }

        /* Buttons - with Grid fallback for older browsers */
        .artkey-buttons{
            display:-ms-grid;display:grid;/* IE10+ fallback */
            gap:14px;margin:22px 0;
            -ms-grid-columns:1fr;grid-template-columns:1fr
        }
        /* Fallback for browsers without Grid support */
        @supports not (display: grid) {
            .artkey-buttons{display:block}
            .artkey-btn{display:block;width:100%;margin-bottom:14px}
        }
        .artkey-btn{
            display:-webkit-box;display:-webkit-flex;display:-moz-box;display:-ms-flexbox;display:flex;
            -webkit-box-align:center;-webkit-align-items:center;-moz-box-align:center;-ms-flex-align:center;align-items:center;
            -webkit-box-pack:center;-webkit-justify-content:center;-moz-box-pack:center;-ms-flex-pack:center;justify-content:center;
            padding:16px 22px;border:2px solid transparent;
            -webkit-border-radius:999px;-moz-border-radius:999px;border-radius:999px;
            text-decoration:none;font-weight:700;letter-spacing:.3px;
            background:#667eea;/* Fallback for older browsers */
            background:-webkit-linear-gradient(135deg,#667eea,#764ba2);
            background:-moz-linear-gradient(135deg,#667eea,#764ba2);
            background:-o-linear-gradient(135deg,#667eea,#764ba2);
            background:linear-gradient(135deg,#667eea,#764ba2);
            color:#fff;
            -webkit-box-shadow:0 10px 26px rgba(102,126,234,.35);
            -moz-box-shadow:0 10px 26px rgba(102,126,234,.35);
            box-shadow:0 10px 26px rgba(102,126,234,.35);
            -webkit-transition:-webkit-transform .25s ease, box-shadow .25s ease, background .25s ease;
            -moz-transition:-moz-transform .25s ease, box-shadow .25s ease, background .25s ease;
            -o-transition:-o-transform .25s ease, box-shadow .25s ease, background .25s ease;
            transition:transform .25s ease, box-shadow .25s ease, background .25s ease;
            touch-action:manipulation;-webkit-tap-highlight-color:transparent;-moz-tap-highlight-color:transparent;
            cursor:pointer;-webkit-user-select:none;-moz-user-select:none;-ms-user-select:none;user-select:none;
            min-height:44px;/* Minimum touch target size for accessibility */
            -webkit-appearance:none;-moz-appearance:none;appearance:none;/* Remove default button styles */
        }
        .artkey-btn:hover{
            -webkit-transform:translateY(-2px) scale(1.03);-moz-transform:translateY(-2px) scale(1.03);-ms-transform:translateY(-2px) scale(1.03);transform:translateY(-2px) scale(1.03);
            -webkit-box-shadow:0 16px 36px rgba(102,126,234,.45);
            -moz-box-shadow:0 16px 36px rgba(102,126,234,.45);
            box-shadow:0 16px 36px rgba(102,126,234,.45)
        }
        .artkey-btn:active{
            -webkit-transform:translateY(0) scale(0.98);-moz-transform:translateY(0) scale(0.98);-ms-transform:translateY(0) scale(0.98);transform:translateY(0) scale(0.98);
            background:#5a6fd8;/* Fallback */
            background:-webkit-linear-gradient(135deg,#5a6fd8,#6a4190);
            background:-moz-linear-gradient(135deg,#5a6fd8,#6a4190);
            background:linear-gradient(135deg,#5a6fd8,#6a4190)
        }
        .tpl-dark .artkey-btn{background:linear-gradient(135deg,#ffeaa7,#fab1a0);color:#2d3436;box-shadow:0 10px 26px rgba(250,177,160,.35)}
        .tpl-bold .artkey-btn{background:transparent;border-color:#fff;color:#fff;box-shadow:0 0 18px rgba(255,255,255,.25)}
        .tpl-bold .artkey-btn:hover{background:#fff;color:#000}
    /* Theme-specific buttons */
    .tpl-forest .artkey-btn{background:linear-gradient(135deg,#1b5e20,#4caf50);box-shadow:0 10px 26px rgba(76,175,80,.35)}
    .tpl-forest .artkey-btn:hover{box-shadow:0 16px 36px rgba(76,175,80,.5)}
    .tpl-cosmic .artkey-btn{background:linear-gradient(135deg,#1a1a2e,#16213e,#e94560);color:#eaeaea;box-shadow:0 10px 26px rgba(233,69,96,.35)}
    .tpl-cosmic .artkey-btn:hover{box-shadow:0 16px 36px rgba(233,69,96,.5)}
    .tpl-vintage .artkey-btn{background:linear-gradient(135deg,#b8860b,#f4e4bc);color:#2d2a26;box-shadow:0 10px 26px rgba(184,134,11,.35)}
    .tpl-vintage .artkey-btn:hover{box-shadow:0 16px 36px rgba(184,134,11,.5)}

        /* Media grids - with Grid fallback */
        .artkey-gallery .grid-gallery,.artkey-videos .grid-videos{
            display:-ms-grid;display:grid;
            -ms-grid-columns:repeat(auto-fit,minmax(220px,1fr));grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
            gap:18px
        }
        /* Fallback for browsers without Grid support */
        @supports not (display: grid) {
            .artkey-gallery .grid-gallery,.artkey-videos .grid-videos{
                display:block;overflow:hidden
            }
            .artkey-gallery .grid-gallery > *,.artkey-videos .grid-videos > *{
                float:left;width:calc(33.333% - 12px);margin:0 6px 18px 6px
            }
        }
        .artkey-gallery img,.artkey-videos video{
            -webkit-border-radius:16px;-moz-border-radius:16px;border-radius:16px;
            -webkit-box-shadow:0 10px 26px rgba(0,0,0,.12);
            -moz-box-shadow:0 10px 26px rgba(0,0,0,.12);
            box-shadow:0 10px 26px rgba(0,0,0,.12);
            -webkit-transition:-webkit-transform .25s ease, box-shadow .25s ease;
            -moz-transition:-moz-transform .25s ease, box-shadow .25s ease;
            -o-transition:-o-transform .25s ease, box-shadow .25s ease;
            transition:transform .25s ease, box-shadow .25s ease;
            max-width:100%;height:auto;/* Responsive images */
        }
        .artkey-gallery img:hover,.artkey-videos video:hover{
            -webkit-transform:translateY(-6px);-moz-transform:translateY(-6px);-ms-transform:translateY(-6px);transform:translateY(-6px);
            -webkit-box-shadow:0 20px 44px rgba(0,0,0,.2);
            -moz-box-shadow:0 20px 44px rgba(0,0,0,.2);
            box-shadow:0 20px 44px rgba(0,0,0,.2)
        }

        /* Editor */
    /* Overall editor width: 80% of 1920px (~1536px) */
    .artkey-editor{max-width:1536px;width:100%;margin:24px auto;padding:12px}
        .artkey-editor .card{background:rgba(255,255,255,.95);backdrop-filter:blur(8px);border:1px solid rgba(0,0,0,.06);border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:0 8px 22px rgba(0,0,0,.06)}
        .artkey-editor input[type=text],.artkey-editor input[type=url],.artkey-editor input[type=number],.artkey-editor input[type=color],.artkey-editor select,.artkey-editor textarea{width:100%;padding:12px;border:1px solid #ddd;border-radius:10px;transition:box-shadow .2s ease,border-color .2s ease}
        .artkey-editor input:focus,.artkey-editor select:focus,.artkey-editor textarea:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.15)}
        
    /* Editor: 2-column layout (Preview left, options right) */
    .editor-grid{display:grid;grid-template-columns:420px 1fr;gap:20px;align-items:start}
    .editor-col-left{position:sticky;top:16px;height:fit-content}
        
    /* Smartphone preview frame */
    .phone-frame{position:relative;width:100%;max-width:380px;margin:0 auto;padding:18px;border-radius:40px;background:#111;box-shadow:0 18px 50px rgba(0,0,0,.25), inset 0 -2px 6px rgba(255,255,255,.08);border:3px solid #222}
    .phone-notch{position:absolute;top:10px;left:50%;transform:translateX(-50%);width:120px;height:16px;background:#111;border-radius:0 0 12px 12px;box-shadow:inset 0 -2px 6px rgba(255,255,255,.06)}
    .phone-screen{background:#000;border-radius:28px;overflow:hidden;aspect-ratio:9/19;}
    .phone-home{position:absolute;bottom:12px;left:50%;transform:translateX(-50%);width:56px;height:6px;background:#1f2937;border-radius:999px}
    .phone-preview{min-height:unset;display:block;padding:0;width:100%;height:100%}
    .phone-preview .artkey-inner{border-radius:0;box-shadow:none;background:rgba(255,255,255,.92);backdrop-filter:saturate(160%) blur(12px);-webkit-backdrop-filter:saturate(160%) blur(12px);padding:16px;width:100%;height:100%;overflow-y:auto;box-sizing:border-box}
        .artkey-editor .row{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:8px}
        .artkey-editor .actions{display:flex;justify-content:flex-end}
        .btn-primary{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:12px 18px;border-radius:12px;border:none;cursor:pointer;box-shadow:0 8px 22px rgba(102,126,234,.35)}
        .btn-light{background:#f3f4f6;border:1px solid #e5e7eb;padding:8px 12px;border-radius:10px;cursor:pointer}

    /* Notices */
    .ak-notice{margin:10px 0 14px;padding:10px 12px;border-radius:10px;border:1px solid #e5e7eb;background:#f9fafb;color:#111}
    .ak-notice.ak-success{border-color:#bbf7d0;background:#ecfdf5;color:#065f46}

    /* Editor sections container for Media Gallery/Buttons/Spotify/Features/Moderation (single column) */
    .editor-two-col{display:grid;grid-template-columns:1fr;gap:16px;align-items:start}
    .editor-two-col > .card{margin-bottom:0}

    /* Editor: two-column theme layout */
    .theme-layout{display:grid;grid-template-columns:1.2fr 0.8fr;gap:16px;align-items:start}
    .theme-left .live-preview{margin-top:0}
    .theme-right{display:flex;flex-direction:column;gap:12px}

    /* Drag handles and drag styles */
    .drag-handle{font-size:16px;line-height:1;cursor:grab;user-select:none;color:#6b7280}
    .draggable{cursor:grab}
    .draggable.dragging{opacity:.7}
    .sortable-list .row{display:grid;grid-template-columns:auto 1fr 1fr auto;gap:8px;align-items:center}
    .sortable-list .row.drag-over{outline:2px dashed #c7d2fe;outline-offset:2px;border-radius:10px}
    .sortable-list .row .remove-link{background:#fee2e2;border:1px solid #fecaca;color:#991b1b;border-radius:8px;padding:6px 10px;cursor:pointer}
    .sortable-list .row .remove-link:hover{background:#fecaca}

    /* Live preview drag styles (links + CTAs) */
    .artkey-buttons .ak-prev-link,.artkey-buttons .ak-prev-cta{cursor:grab}
    .artkey-buttons .ak-prev-link.dragging,.artkey-buttons .ak-prev-cta.dragging{opacity:.85;transform:scale(.98);cursor:grabbing}

    /* Feature pills */
    .sortable-pills{display:flex;flex-wrap:wrap;gap:8px}
    .pill{display:flex;align-items:center;gap:8px;padding:6px 8px;border:1px solid #e5e7eb;border-radius:999px;background:#f9fafb}
    .pill .pill-toggle{border:none;border-radius:999px;padding:6px 10px;font-weight:700;cursor:pointer}
    .pill .pill-toggle.on{background:#4f46e5;color:#fff}
    .pill .pill-toggle.off{background:#e5e7eb;color:#111}
    .pill.drag-over{outline:2px dashed #c7d2fe}

        /* Theme chooser */
        .theme-chooser .theme-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-bottom:10px}
        .theme-tile{height:88px;border-radius:12px;border:2px solid transparent;display:flex;align-items:flex-end;justify-content:center;padding:8px;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease}
        .theme-tile:hover{transform:translateY(-3px);box-shadow:0 10px 24px rgba(0,0,0,.12)}
        .theme-tile.active{border-color:#111;box-shadow:0 0 0 2px #111 inset}

        /* Stock tiles */
        .stock-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin:8px 0}
        .stock-tile{height:80px;border-radius:12px;background-size:cover;background-position:center;border:2px solid transparent;cursor:pointer;transition:transform .2s ease, box-shadow .2s ease}
        .stock-tile:hover{transform:translateY(-3px);box-shadow:0 12px 26px rgba(0,0,0,.18)}
    .stock-tile.selected{border-color:#111}
    .bg-actions{display:flex;gap:8px;align-items:center;margin-top:6px;flex-wrap:wrap}
    .bg-tip small{display:block;margin-top:4px;color:#6b7280}
    .theme-fields .field-row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
    .theme-fields .field-row > label{flex:1}
    .theme-fields label.inline-color{display:flex;align-items:center;gap:8px}
    .theme-fields input[type=color]{width:42px;height:42px;padding:0;border:1px solid #e5e7eb;border-radius:10px;background:#fff;cursor:pointer}
    .theme-fields input[type=color]::-webkit-color-swatch-wrapper{padding:0;border-radius:10px}
    .theme-fields input[type=color]::-webkit-color-swatch{border:none;border-radius:10px}
    .theme-fields input[type=color]::-moz-color-swatch{border:none;border-radius:10px}
        .live-preview{margin-top:12px}

        /* Modal - Cross-browser compatibility with comprehensive fallbacks */
        .ak-modal{
            position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;
            width:100vw!important;
            height:100vh!important;/* Fallback */
            height:-webkit-fill-available!important;/* iOS Safari */
            height:100dvh!important;/* Modern browsers */
            background:rgba(0,0,0,.85)!important;
            display:none!important;
            -webkit-box-align:center;-webkit-align-items:center;-moz-box-align:center;-ms-flex-align:center;align-items:center!important;
            -webkit-box-pack:center;-webkit-justify-content:center;-moz-box-pack:center;-ms-flex-pack:center;justify-content:center!important;
            z-index:999999!important;padding:0!important;margin:0!important;
            overflow-y:auto!important;
            -webkit-overflow-scrolling:touch;/* iOS smooth scrolling */
            touch-action:pan-y;/* Prevent zoom on double-tap */
            visibility:visible!important;opacity:1!important;
            -webkit-backface-visibility:hidden;backface-visibility:hidden;/* Prevent flickering */
            -webkit-perspective:1000;perspective:1000;/* Hardware acceleration */
        }
        .ak-modal[style*='display: flex'],.ak-modal[style*='display:flex']{display:flex!important}
        .ak-modal-inner{background:#fff;border-radius:16px;max-width:1100px;width:calc(100% - 40px);padding:18px;position:relative;max-height:90vh;max-height:90dvh;overflow:auto;box-shadow:0 20px 60px rgba(0,0,0,.5);-webkit-overflow-scrolling:touch;margin:20px;z-index:1000000}
        .ak-modal-close{position:absolute;right:10px;top:8px;border:none;background:rgba(255,255,255,.95);font-size:32px;line-height:1;cursor:pointer;color:#111;z-index:1000001;width:50px;height:50px;display:flex;align-items:center;justify-content:center;touch-action:manipulation;-webkit-tap-highlight-color:transparent;-moz-tap-highlight-color:transparent;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,.3);font-weight:bold;min-width:50px;min-height:50px}
        .ak-msg-close{position:fixed!important;top:20px!important;right:20px!important;z-index:1000001!important;width:50px!important;height:50px!important;min-width:50px!important;min-height:50px!important;border-radius:50%!important;background:rgba(0,0,0,.9)!important;color:#fff!important;border:2px solid rgba(255,255,255,.4)!important;font-size:32px!important;line-height:1!important;cursor:pointer!important;display:flex!important;align-items:center!important;justify-content:center!important;box-shadow:0 4px 16px rgba(0,0,0,.6)!important;touch-action:manipulation;-webkit-tap-highlight-color:transparent;-moz-tap-highlight-color:transparent;font-weight:bold}
        .ak-msg-close:hover,.ak-msg-close:active{background:rgba(0,0,0,1)!important;transform:scale(1.1);border-color:rgba(255,255,255,.6)!important}
        .ak-msg-modal-inner{max-width:none!important;width:100vw!important;height:100vh!important;height:100dvh!important;padding:0!important;border-radius:0!important;position:fixed!important;top:0!important;left:0!important;right:0!important;bottom:0!important;overflow:hidden!important;z-index:1000000!important;display:flex!important;flex-direction:column!important}
        .ak-msg-body{position:absolute!important;top:0!important;left:0!important;right:0!important;bottom:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;max-height:100vh!important;max-height:100dvh!important;overflow:auto!important;padding:0!important;margin:0!important;-webkit-overflow-scrolling:touch!important}
        .ak-msg-body img{max-width:100%!important;max-height:calc(100vh - 40px)!important;max-height:calc(100dvh - 40px)!important;width:auto!important;height:auto!important;object-fit:contain!important;display:block!important;margin:auto!important}
        .ak-msg-body iframe{position:absolute!important;top:0!important;left:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;border:0!important;background:#fff!important;display:block!important;margin:0!important;padding:0!important}
        .ak-msg-body > div{position:absolute!important;top:0!important;left:0!important;width:100vw!important;height:100vh!important;height:100dvh!important;display:flex!important;align-items:center!important;justify-content:center!important;background:#000!important;padding:20px!important;box-sizing:border-box!important;margin:0!important}
        /* Ensure modals break out of phone frame on desktop but stay within on mobile */
        @media (min-width:769px){
            .desktop-phone-wrapper .ak-modal{position:fixed!important;z-index:999999!important}
        }
        /* iOS Safari viewport fix - comprehensive */
        @supports (-webkit-touch-callout: none) {
            .ak-modal{
                position:fixed!important;
                height:100vh!important;/* Fallback */
                height:-webkit-fill-available!important;/* iOS Safari */
                min-height:100vh!important;
                min-height:-webkit-fill-available!important
            }
            .ak-msg-modal-inner{
                height:100vh!important;
                height:-webkit-fill-available!important;
                min-height:100vh!important;
                min-height:-webkit-fill-available!important
            }
            .ak-msg-body{
                height:100vh!important;
                height:-webkit-fill-available!important;
                max-height:100vh!important;
                max-height:-webkit-fill-available!important
            }
            .artkey-wrap{
                min-height:100vh;/* Fallback */
                min-height:-webkit-fill-available;/* iOS Safari */
            }
        }
        /* Android Chrome & modern mobile browsers viewport fix */
        @media screen and (max-width:768px) {
            .ak-modal{
                height:100vh!important;/* Fallback */
                height:100dvh!important;/* Modern Android Chrome */
                min-height:100vh!important;
                min-height:100dvh!important
            }
            .ak-msg-modal-inner{
                height:100vh!important;
                height:100dvh!important;
                min-height:100vh!important;
                min-height:100dvh!important
            }
            .ak-msg-body{
                height:100vh!important;
                height:100dvh!important;
                max-height:100vh!important;
                max-height:100dvh!important
            }
            .artkey-wrap{
                min-height:100vh;/* Fallback */
                min-height:100dvh;/* Modern browsers */
            }
        }
        /* Fallback for browsers that don't support dvh or -webkit-fill-available */
        @supports not (height: 100dvh) and not (height: -webkit-fill-available) {
            .ak-modal,.ak-msg-modal-inner,.ak-msg-body{height:100vh!important;min-height:100vh!important}
            .artkey-wrap{min-height:100vh}
        }

    /* Watch video: 80% viewport popup with full-size player */
    .ak-watch-video-inner{width:80vw;max-width:none;height:80vh;max-height:none;padding:14px;overflow:hidden;display:flex;flex-direction:column;position:relative}
    .ak-watch-video-body{flex:1;min-height:0;overflow:hidden}
    /* Video modal close button - positioned for visibility on all devices */
    #ak_watch_video_modal .ak-modal-close{
        position:fixed!important;
        top:60px!important;
        right:20px!important;
        width:50px!important;
        height:50px!important;
        min-width:50px!important;
        min-height:50px!important;
        border-radius:50%!important;
        background:rgba(255,255,255,.95)!important;
        color:#333!important;
        border:none!important;
        font-size:28px!important;
        font-weight:bold!important;
        cursor:pointer!important;
        display:flex!important;
        align-items:center!important;
        justify-content:center!important;
        z-index:1000001!important;
        box-shadow:0 4px 20px rgba(0,0,0,.4)!important;
        touch-action:manipulation!important;
        -webkit-tap-highlight-color:transparent!important;
    }
    /* Mobile: Account for iPhone safe areas */
    @media screen and (max-width:768px){
        #ak_watch_video_modal{
            position:fixed!important;
            top:0!important;
            left:0!important;
            right:0!important;
            bottom:0!important;
            width:100vw!important;
            height:100vh!important;
            height:100dvh!important;
            padding:0!important;
            margin:0!important;
            z-index:999999!important;
        }
        #ak_watch_video_modal .ak-modal-inner{
            width:100vw!important;
            height:100vh!important;
            height:100dvh!important;
            max-width:100vw!important;
            max-height:100vh!important;
            max-height:100dvh!important;
            border-radius:0!important;
            padding:0!important;
            margin:0!important;
        }
        #ak_watch_video_modal .ak-modal-close{
            position:fixed!important;
            top:max(60px, env(safe-area-inset-top, 60px))!important;
            right:max(15px, env(safe-area-inset-right, 15px))!important;
            width:44px!important;
            height:44px!important;
            min-width:44px!important;
            min-height:44px!important;
            font-size:24px!important;
            z-index:1000002!important;
        }
        #ak_watch_video_modal .ak-modal-body{
            width:100vw!important;
            height:100vh!important;
            height:100dvh!important;
            padding:0!important;
        }
        #ak_watch_video_modal video{
            width:100vw!important;
            height:100vh!important;
            height:100dvh!important;
            object-fit:contain!important;
            border-radius:0!important;
        }
    }
    /* iOS Safari safe area support */
    @supports (padding-top: env(safe-area-inset-top)){
        #ak_watch_video_modal .ak-modal-close{
            top:max(60px, calc(env(safe-area-inset-top) + 15px))!important;
            right:max(15px, env(safe-area-inset-right))!important;
        }
    }

        /* Section headers */
        .artkey-gallery h3,.artkey-videos h3,.artkey-guestbook h3,.artkey-upload h3{font-size:22px;font-weight:800;text-align:center;margin:28px 0 12px;position:relative}
    .artkey-gallery h3::after,.artkey-videos h3::after,.artkey-guestbook h3::after,.artkey-upload h3::after{content:\"\";position:absolute;left:50%;transform:translateX(-50%);bottom:-8px;width:64px;height:3px;border-radius:2px;background:linear-gradient(135deg,#667eea,#764ba2)}

        /* Guestbook */
        .guestbook-form input,.guestbook-form textarea{width:100%;padding:12px;border:1px solid #ddd;border-radius:10px;margin-bottom:10px}
        .guestbook-list{list-style:none;padding:0;margin:12px 0}
        .guestbook-list li{background:#fff;border:1px solid #eee;border-radius:12px;padding:12px 14px;margin-bottom:10px;box-shadow:0 6px 18px rgba(0,0,0,.06)}

    /* Guestbook sign card (3D effect) */
    .gb-sign-card{position:relative;background:rgba(255,255,255,.9);backdrop-filter:blur(8px);border-radius:18px;border:1px solid rgba(0,0,0,.06);box-shadow:0 18px 48px rgba(0,0,0,.2), 0 1px 0 rgba(255,255,255,.6) inset;transform:perspective(1000px) rotateX(.6deg) rotateY(-.6deg);transition:transform .25s ease, box-shadow .25s ease}
    .gb-sign-card::before{content:\"\";position:absolute;inset:0;border-radius:18px;background:linear-gradient(145deg, rgba(255,255,255,.35), rgba(255,255,255,0) 40%);pointer-events:none}
    .gb-sign-card:hover{transform:perspective(1000px) rotateX(1.2deg) rotateY(1.2deg) translateY(-2px);box-shadow:0 28px 68px rgba(0,0,0,.26), 0 1px 0 rgba(255,255,255,.6) inset}
    .gb-sign-card .btn-primary{box-shadow:0 10px 26px rgba(102,126,234,.35)}

        /* ===== FORCE FULLSCREEN ON ARTKEY PAGES ===== */
        /* Hide WordPress theme elements on ArtKey single pages */
        body.single-artkey,
        body.postid-artkey,
        body.single-post-type-artkey {
            margin:0!important;
            padding:0!important;
            overflow-x:hidden!important;
        }
        /* Hide common theme elements on ArtKey pages */
        body.single-artkey #header,
        body.single-artkey .site-header,
        body.single-artkey header,
        body.single-artkey #footer,
        body.single-artkey .site-footer,
        body.single-artkey footer,
        body.single-artkey .nav-menu,
        body.single-artkey .main-navigation,
        body.single-artkey .site-navigation,
        body.single-artkey #masthead,
        body.single-artkey #colophon,
        body.single-artkey .breadcrumb,
        body.single-artkey .breadcrumbs,
        body.single-artkey .page-title,
        body.single-artkey .entry-title,
        body.single-artkey .site-branding,
        body.single-artkey #wpadminbar,
        body.single-artkey .admin-bar,
        body.single-artkey .sidebar,
        body.single-artkey #sidebar,
        body.single-artkey aside,
        body.single-artkey .widget-area,
        body.single-artkey .comments-area,
        body.single-artkey .post-navigation,
        body.single-artkey .entry-meta,
        body.single-artkey .entry-footer,
        /* Divi theme specific */
        body.single-artkey #main-header,
        body.single-artkey #top-header,
        body.single-artkey #main-footer,
        body.single-artkey #footer-bottom,
        body.single-artkey .et_pb_section:not(.artkey-section),
        body.single-artkey #et-main-area > .container,
        /* WooCommerce elements */
        body.single-artkey .woocommerce-breadcrumb,
        body.single-artkey .storefront-breadcrumb,
        body.single-artkey .woocommerce-products-header {
            display:none!important;
            visibility:hidden!important;
            height:0!important;
            overflow:hidden!important;
        }
        /* Force content to be fullscreen */
        body.single-artkey #page,
        body.single-artkey #content,
        body.single-artkey .site-content,
        body.single-artkey #primary,
        body.single-artkey #main,
        body.single-artkey .entry-content,
        body.single-artkey article,
        body.single-artkey .post,
        body.single-artkey .hentry,
        body.single-artkey #et-main-area,
        body.single-artkey .et_pb_section,
        body.single-artkey .container {
            width:100%!important;
            max-width:100%!important;
            padding:0!important;
            margin:0!important;
            background:transparent!important;
        }

        /* ===== DESKTOP-FIRST RESPONSIVE DESIGN ===== */
        /* 
         * DESKTOP (default): Phone frame with notch visible
         * MOBILE (max-width:768px): No phone frame, content fills screen
         */

        /* === DESKTOP VIEW (Default) - Phone Frame Visible === */
        .desktop-phone-wrapper{
            display:flex!important;
            justify-content:center!important;
            align-items:center!important;
            min-height:100vh!important;
            padding:40px 20px!important;
            margin:0!important;
            background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%)!important;
            background-image:radial-gradient(circle at 20% 50%,rgba(102,126,234,.1) 0%,transparent 50%),radial-gradient(circle at 80% 50%,rgba(118,75,162,.1) 0%,transparent 50%)!important;
        }
        .desktop-phone-frame{
            display:block!important;
            position:relative!important;
            width:375px!important;
            max-width:90vw!important;
            padding:10px!important;
            border-radius:42px!important;
            background:linear-gradient(145deg,#1a1a1a,#0a0a0a)!important;
            box-shadow:0 25px 70px rgba(0,0,0,.6),inset 0 -3px 8px rgba(255,255,255,.05),inset 0 3px 8px rgba(0,0,0,.3)!important;
            border:3px solid #2a2a2a!important;
            animation:phoneFloat 3s ease-in-out infinite;
        }
        @keyframes phoneFloat{0%,100%{transform:translateY(0)}50%{transform:translateY(-8px)}}
        .desktop-phone-notch{
            display:block!important;
            position:absolute!important;
            top:0!important;
            left:50%!important;
            transform:translateX(-50%)!important;
            width:110px!important;
            height:30px!important;
            background:#000!important;
            border-radius:0 0 18px 18px!important;
            z-index:10!important;
        }
        .desktop-phone-notch::before{content:'';position:absolute;top:8px;left:50%;transform:translateX(-50%);width:50px;height:4px;background:rgba(255,255,255,.08);border-radius:2px}
        .desktop-phone-notch::after{content:'';position:absolute;top:6px;right:20px;width:8px;height:8px;background:rgba(255,255,255,.12);border-radius:50%}
        .desktop-phone-home{
            display:block!important;
            position:absolute!important;
            bottom:8px!important;
            left:50%!important;
            transform:translateX(-50%)!important;
            width:134px!important;
            height:4px!important;
            background:rgba(255,255,255,.25)!important;
            border-radius:999px!important;
            z-index:10!important;
        }
        .desktop-phone-screen{
            display:block!important;
            background:#000!important;
            border-radius:38px!important;
            overflow:hidden!important;
            aspect-ratio:9/16!important;
            max-height:667px!important;
            position:relative!important;
            width:100%!important;
        }
        .desktop-phone-wrapper .artkey-wrap{
            display:flex!important;
            padding:0!important;
            min-height:100%!important;
            border-radius:0!important;
            height:100%!important;
            width:100%!important;
        }
        .desktop-phone-wrapper .artkey-inner{
            border-radius:0!important;
            margin:0!important;
            max-width:100%!important;
            padding:20px 16px!important;
            height:100%!important;
            overflow-y:auto!important;
            display:flex!important;
            flex-direction:column!important;
        }
        .desktop-phone-wrapper .artkey-title{font-size:clamp(18px,4.5vw,24px)!important;margin-bottom:14px!important}
        .desktop-phone-wrapper .artkey-buttons{gap:8px!important;margin:14px 0!important}
        .desktop-phone-wrapper .artkey-btn{padding:10px 16px!important;font-size:13px!important;min-height:44px!important}
        .desktop-phone-wrapper .artkey-spotify{margin:12px 0!important}
        .desktop-phone-wrapper .artkey-spotify iframe{height:120px!important}

        /* === MOBILE VIEW (max-width:768px) - No Phone Frame === */
        @media screen and (max-width:768px){
            /* AGGRESSIVE MOBILE OVERRIDES - Hide ALL theme elements */
            body.single-artkey,
            body.single-artkey *:not(.desktop-phone-wrapper):not(.desktop-phone-wrapper *):not(.ak-modal):not(.ak-modal *) {
                /* This is intentionally aggressive to override stubborn themes */
            }
            body.single-artkey > *:not(.desktop-phone-wrapper):not(script):not(style):not(link) {
                display:none!important;
            }
            body.single-artkey {
                background:#000!important;
                margin:0!important;
                padding:0!important;
                overflow-x:hidden!important;
            }
            body.single-artkey .desktop-phone-wrapper {
                position:fixed!important;
                top:0!important;
                left:0!important;
                right:0!important;
                bottom:0!important;
                z-index:999998!important;
            }
            .desktop-phone-wrapper{
                display:block!important;
                width:100%!important;
                min-height:100vh!important;
                min-height:100dvh!important;
                padding:0!important;
                margin:0!important;
                background:transparent!important;
                background-image:none!important;
            }
            .desktop-phone-frame{
                display:contents!important;
                width:auto!important;
                max-width:none!important;
                padding:0!important;
                border-radius:0!important;
                background:transparent!important;
                box-shadow:none!important;
                border:none!important;
                animation:none!important;
            }
            .desktop-phone-notch{display:none!important}
            .desktop-phone-home{display:none!important}
            .desktop-phone-screen{
                display:block!important;
                width:100%!important;
                max-width:100%!important;
                max-height:none!important;
                aspect-ratio:unset!important;
                border-radius:0!important;
                background:transparent!important;
                overflow:visible!important;
            }
            .desktop-phone-wrapper .artkey-wrap{
                display:flex!important;
                min-height:100vh!important;
                min-height:100dvh!important;
                width:100%!important;
                padding:0!important;
                margin:0!important;
                border-radius:0!important;
                height:auto!important;
            }
            .desktop-phone-wrapper .artkey-inner{
                border-radius:0!important;
                margin:0!important;
                max-width:100%!important;
                width:100%!important;
                padding:20px 16px!important;
                min-height:100vh!important;
                min-height:100dvh!important;
                height:auto!important;
                overflow-y:auto!important;
                display:flex!important;
                flex-direction:column!important;
                box-sizing:border-box!important;
            }
            .desktop-phone-wrapper .artkey-title{font-size:clamp(20px,5vw,28px)!important;margin-bottom:16px!important}
            .desktop-phone-wrapper .artkey-buttons{gap:12px!important;margin:16px 0!important}
            .desktop-phone-wrapper .artkey-btn{padding:14px 20px!important;font-size:16px!important;min-height:52px!important;touch-action:manipulation!important}
            .desktop-phone-wrapper .artkey-spotify{margin:16px 0!important}
            .desktop-phone-wrapper .artkey-spotify iframe{height:152px!important}
        }
        /* Desktop size variations */
        @media (min-width:769px) and (max-width:1024px){
            .desktop-phone-frame{width:320px;padding:8px;border-radius:38px}
            .desktop-phone-screen{max-height:568px}
            .desktop-phone-notch{width:95px;height:26px}
        }
        @media (min-width:1025px) and (max-width:1440px){
            .desktop-phone-frame{width:375px;padding:10px}
            .desktop-phone-screen{max-height:667px}
        }
        @media (min-width:1441px){
            .desktop-phone-frame{width:450px;padding:12px;border-radius:48px}
            .desktop-phone-screen{max-height:800px}
            .desktop-phone-notch{width:132px;height:32px}
            .desktop-phone-wrapper .artkey-inner{padding:28px 22px}
            .desktop-phone-wrapper .artkey-title{font-size:clamp(22px,4vw,28px)}
            .desktop-phone-wrapper .artkey-btn{padding:12px 18px;font-size:14px}
        }
        @media (min-width:1920px){
            .desktop-phone-frame{width:525px;padding:14px;border-radius:52px}
            .desktop-phone-screen{max-height:933px}
            .desktop-phone-notch{width:154px;height:36px}
            .desktop-phone-wrapper .artkey-inner{padding:32px 26px}
            .desktop-phone-wrapper .artkey-title{font-size:clamp(24px,3.5vw,32px)}
            .desktop-phone-wrapper .artkey-btn{padding:14px 20px;font-size:15px}
        }

        /* View Toggle Button (hidden by default on both mobile and desktop) */
        .artkey-view-toggle{display:none}

        /* === MOBILE-SPECIFIC ENHANCEMENTS === */
        @media (max-width:768px){
            /* Ensure full-screen experience on mobile */
            html,body{
                overflow-x:hidden;
            }
            .artkey-inner{
                padding:20px 16px;
                width:100%;
                max-width:100%;
                box-sizing:border-box;
                padding-bottom:env(safe-area-inset-bottom,20px);
            }
            .artkey-buttons{
                grid-template-columns:1fr;
                gap:12px;
            }
            .artkey-btn{
                min-height:52px;
                padding:16px 20px;
                font-size:clamp(15px,4vw,17px);
                touch-action:manipulation;
                -webkit-tap-highlight-color:transparent;
            }
            .artkey-editor .row{grid-template-columns:1fr}
            .theme-layout{grid-template-columns:1fr}
            .sortable-list .row{grid-template-columns:1fr}
            .editor-two-col{grid-template-columns:1fr}
            .editor-grid{grid-template-columns:1fr}
            .editor-col-left{position:relative}
            .phone-frame{max-width:100%}
            /* Ensure modals work on iOS & Android - fullscreen */
            .ak-modal{
                position:fixed!important;
                top:0!important;
                left:0!important;
                right:0!important;
                bottom:0!important;
                width:100vw!important;
                height:100vh!important;
                height:100dvh!important;
                display:flex!important;
                z-index:999999!important;
                background:rgba(0,0,0,.95)!important;
            }
            .ak-msg-modal-inner{width:100vw!important;height:100vh!important;height:100dvh!important}
            .ak-modal-inner{width:100%!important;max-width:100%!important;margin:0!important;border-radius:0!important;height:100%!important}
            .ak-modal-body{padding:20px!important;padding-top:60px!important}
            /* Enhanced mobile close button - very visible Back style */
            .ak-modal-close{
                position:fixed!important;
                top:15px!important;
                left:15px!important;
                right:auto!important;
                width:auto!important;
                min-width:80px!important;
                height:44px!important;
                min-height:44px!important;
                font-size:16px!important;
                font-weight:600!important;
                z-index:1000001!important;
                background:rgba(255,255,255,.95)!important;
                border:none!important;
                border-radius:22px!important;
                color:#333!important;
                display:flex!important;
                align-items:center!important;
                justify-content:center!important;
                gap:6px!important;
                padding:0 16px!important;
                touch-action:manipulation!important;
                -webkit-tap-highlight-color:transparent!important;
                box-shadow:0 4px 20px rgba(0,0,0,.3)!important;
                cursor:pointer!important;
            }
            .ak-modal-close::before{
                content:\"\\2190\"!important;
                font-size:18px!important;
                font-weight:bold!important;
            }
            .ak-modal-close::after{
                content:\"Back\"!important;
            }
            /* Hide the X symbol on mobile since we are using arrow Back */
            .ak-modal-close>*,.ak-modal-close{font-size:0!important}
            .ak-modal-close::before,.ak-modal-close::after{font-size:16px!important}
            /* Swipe indicator at top of modal */
            .ak-modal-inner::before{
                content:\"\";
                display:block;
                width:40px;
                height:4px;
                background:rgba(255,255,255,.4);
                border-radius:2px;
                margin:12px auto 0;
                position:absolute;
                top:0;
                left:50%;
                transform:translateX(-50%);
            }
            /* Prevent body scroll when modal is open (iOS & Android) */
            body.modal-open{overflow:hidden!important;position:fixed!important;width:100%!important;height:100%!important;top:0!important;left:0!important}
            /* Safe area support for notched phones (iPhone X+, etc.) */
            @supports (padding: env(safe-area-inset-top)){
                .artkey-inner{
                    padding-top:max(20px,env(safe-area-inset-top));
                    padding-bottom:max(20px,env(safe-area-inset-bottom));
                    padding-left:max(16px,env(safe-area-inset-left));
                    padding-right:max(16px,env(safe-area-inset-right));
                }
                .ak-modal-close{
                    top:max(15px,env(safe-area-inset-top))!important;
                    right:max(15px,env(safe-area-inset-right))!important;
                }
            }
            /* Android Chrome address bar fix */
            @supports (height: 100dvh) {
                .artkey-wrap{min-height:100dvh}
                .ak-modal{height:100dvh!important}
                .ak-msg-modal-inner{height:100dvh!important}
            }
        }
        
        /* Reduced motion for accessibility */
        @media (prefers-reduced-motion: reduce){
            *{animation:none !important;transition:none !important}
        }
        ";
        wp_register_style('woo-artkey-suite', false);
        wp_enqueue_style('woo-artkey-suite');
        wp_add_inline_style('woo-artkey-suite', $css);
    }

    /* ===== Privacy / robots control ===== */
    public function artkey_meta_robots() {
        if (is_singular(self::CPT)) {
            echo '<meta name="robots" content="noindex,nofollow" />' . "\n";
        }
    }
    public function artkey_robots_header($headers) {
        if (is_singular(self::CPT)) {
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }
        return $headers;
    }
    public function remove_artkey_from_sitemaps($post_types) {
        unset($post_types[self::CPT]);
        return $post_types;
    }
    public function add_artkey_disallow_to_robots($output, $public) {
        $output .= "\nDisallow: /art-key/\n";
        return $output;
    }

    /* ===== Fonts ===== */
    public function enqueue_google_font() {
        $is_artkey_view = is_singular(self::CPT);
        $is_editor_page  = (function(){
            if (isset($_GET['artkey_id']) && isset($_GET['token'])) return true;
            if (is_page()) {
                $post = get_post();
                if ($post && (has_shortcode($post->post_content, 'artkey_editor') || has_shortcode($post->post_content, 'biolink_editor'))) return true;
            }
            return false;
        })();
        if (!$is_artkey_view && !$is_editor_page) return;

        $aid = $is_artkey_view ? get_the_ID() : (int)($_GET['artkey_id'] ?? 0);
        if (!$aid || get_post_type($aid)!==self::CPT) return;

        $fields = get_post_meta($aid, self::META_FIELDS, true);
        $font   = $fields['theme']['font'] ?? 'system';
        if (strpos($font, 'g:') !== 0) return;

        $family = urlencode(substr($font, 2));
        $href = "https://fonts.googleapis.com/css2?family={$family}:wght@400;600&display=swap";
        wp_enqueue_style('woo-artkey-googlefont', $href, [], null);
    }

    /* ===== reCAPTCHA settings ===== */
    /**
     * Default labels for the front-end feature CTA buttons.
     * These can be overridden via the plugin settings page.
     */
    private function feature_button_label_defaults() {
        return [
            'gallery'     => 'View Gallery',
            'video'       => 'Watch Videos',
            'watch_video' => 'Watch video',
            'gb_view'     => 'View Guestbook',
            'gb_sign'     => 'Sign Guestbook',
            'messages_1'  => 'Messages',
            'messages_2'  => 'Messages',
            'imgup'       => 'Upload Images',
            'vidup'       => 'Upload Videos',
        ];
    }

    /** Safely fetch a feature button label (override -> default). */
    private function feature_button_label($key) {
        $key = (string)$key;
        $defaults = $this->feature_button_label_defaults();
        $opt = $this->opt();
        $labels = isset($opt['feature_btn_labels']) && is_array($opt['feature_btn_labels']) ? $opt['feature_btn_labels'] : [];

        $val = isset($labels[$key]) ? trim((string)$labels[$key]) : '';
        if ($val !== '') return $val;
        return (string)($defaults[$key] ?? $key);
    }

    /**
     * Per-Art Key label lookup (post meta override -> plugin settings override -> default).
     *
     * @param int $aid Art Key post ID
     * @param string $key One of: gallery, video, watch_video, gb_view, gb_sign, imgup, vidup
     * @param array|null $fields Optional meta cache for this Art Key
     */
    private function feature_button_label_for_artkey($aid, $key, $fields = null) {
        $aid = (int)$aid;
        $key = (string)$key;

        if ($fields === null) {
            $fields = get_post_meta($aid, self::META_FIELDS, true);
        }
        $fields = is_array($fields) ? $fields : [];

        $meta_labels = isset($fields['feature_btn_labels']) && is_array($fields['feature_btn_labels']) ? $fields['feature_btn_labels'] : [];
        $val = isset($meta_labels[$key]) ? trim((string)$meta_labels[$key]) : '';
        if ($val !== '') return $val;

        return $this->feature_button_label($key);
    }

    private function opt() {
        $opt = get_option('woo_artkey_suite_options', []);
        $feature_btn_labels = [];
        if (!empty($opt['feature_btn_labels']) && is_array($opt['feature_btn_labels'])) {
            $feature_btn_labels = $opt['feature_btn_labels'];
        }
        return [
            'site_key'   => $opt['site_key']   ?? '',
            'secret_key' => $opt['secret_key'] ?? '',
            'retain_days'=> isset($opt['retain_days']) ? (int)$opt['retain_days'] : 3,
            'feature_btn_labels' => $feature_btn_labels,
        ];
    }
    public function add_settings_page() {
        add_options_page(
            'Woo Art Key Suite',
            'Woo Art Key Suite',
            'manage_options',
            'woo-artkey-suite',
            [$this, 'render_settings_page']
        );
    }
    public function register_settings() {
        register_setting('woo_artkey_suite_group', 'woo_artkey_suite_options', [
            'type' => 'array',
            'sanitize_callback' => function($in){
                $defaults = $this->feature_button_label_defaults();

                $labels_in = $in['feature_btn_labels'] ?? [];
                $labels_out = [];
                if (is_array($labels_in)) {
                    foreach ($defaults as $k => $_default_label) {
                        $labels_out[$k] = sanitize_text_field($labels_in[$k] ?? '');
                    }
                }
                return [
                    'site_key'   => sanitize_text_field($in['site_key'] ?? ''),
                    'secret_key' => sanitize_text_field($in['secret_key'] ?? ''),
                    'retain_days'=> max(0, (int)($in['retain_days'] ?? 3)),
                    'feature_btn_labels' => $labels_out,
                ];
            },
        ]);
        add_settings_section('recaptcha', 'reCAPTCHA', function(){
            echo '<p>Add your Google reCAPTCHA v2 (checkbox) keys to protect guestbook and uploads.</p>';
        }, 'woo-artkey-suite');

        add_settings_field('site_key', 'Site Key', function(){
            $opt = $this->opt(); echo '<input type="text" name="woo_artkey_suite_options[site_key]" value="'.esc_attr($opt['site_key']).'" class="regular-text">';
        }, 'woo-artkey-suite', 'recaptcha');
        add_settings_field('secret_key', 'Secret Key', function(){
            $opt = $this->opt(); echo '<input type="text" name="woo_artkey_suite_options[secret_key]" value="'.esc_attr($opt['secret_key']).'" class="regular-text">';
        }, 'woo-artkey-suite', 'recaptcha');

        add_settings_section('cleanup', 'Cleanup', function(){
            echo '<p>Automatically delete unfinished Art Keys after a retention period.</p>';
        }, 'woo-artkey-suite');
        add_settings_field('retain_days', 'Retention (days)', function(){
            $opt = $this->opt();
            echo '<input type="number" min="0" step="1" name="woo_artkey_suite_options[retain_days]" value="'.esc_attr((int)$opt['retain_days']).'" class="small-text">';
            echo ' <span class="description">Temporary Art Keys older than this will be permanently deleted.</span>';
        }, 'woo-artkey-suite', 'cleanup');

        add_settings_section('feature_labels', 'Feature Button Labels', function(){
            echo '<p>Optional: override the text shown on the front-end feature buttons (Gallery, Guestbook, Uploads, etc). Leave blank to use the defaults.</p>';
        }, 'woo-artkey-suite');

        add_settings_field('feature_btn_labels', 'Labels', function(){
            $opt = $this->opt();
            $labels = isset($opt['feature_btn_labels']) && is_array($opt['feature_btn_labels']) ? $opt['feature_btn_labels'] : [];
            $defaults = $this->feature_button_label_defaults();

            echo '<table class="widefat striped" style="max-width:820px">';
            echo '<thead><tr><th style="width:220px">Button</th><th>Label</th><th style="width:240px">Default</th></tr></thead>';
            echo '<tbody>';
            foreach ($defaults as $key => $default_label) {
                $val = isset($labels[$key]) ? (string)$labels[$key] : '';
                echo '<tr>';
                echo '<td><code>'.esc_html($key).'</code></td>';
                echo '<td><input type="text" class="regular-text" name="woo_artkey_suite_options[feature_btn_labels]['.esc_attr($key).']" value="'.esc_attr($val).'" /></td>';
                echo '<td>'.esc_html($default_label).'</td>';
                echo '</tr>';
            }
            echo '</tbody></table>';
        }, 'woo-artkey-suite', 'feature_labels');
    }
    public function render_settings_page() {
        $qr_library_loaded = $this->load_qr_code_library();
        ?>
        <div class="wrap">
            <h1>Woo Art Key Suite</h1>
            
            <?php if (!$qr_library_loaded): ?>
            <div class="notice notice-warning" style="margin: 20px 0;">
                <h3 style="margin-top: 0;">QR Code Library Setup Required</h3>
                <p>The <code>endroid/qr-code</code> library is required for QR code generation. Choose one of the following setup methods:</p>
                
                <h4>Option 1: Bundle in Plugin (Recommended)</h4>
                <ol>
                    <li>Navigate to your plugin directory: <code><?php echo esc_html(plugin_dir_path(__FILE__)); ?></code></li>
                    <li>Run Composer to install the library in the plugin:
                        <pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; border-radius: 4px; overflow-x: auto;">cd "<?php echo esc_html(plugin_dir_path(__FILE__)); ?>"
composer require endroid/qr-code</pre>
                    </li>
                    <li>This will create a <code>vendor</code> folder in your plugin directory with the library</li>
                    <li>Refresh this page to verify installation</li>
                </ol>
                
                <h4>Option 2: Global Composer Installation</h4>
                <ol>
                    <li>If you have Composer set up globally in your WordPress root, run:
                        <pre style="background: #f5f5f5; padding: 10px; border: 1px solid #ddd; border-radius: 4px; overflow-x: auto;">cd "<?php echo esc_html(ABSPATH); ?>"
composer require endroid/qr-code</pre>
                    </li>
                    <li>The plugin will automatically detect it in the WordPress root vendor directory</li>
                </ol>
                
                <h4>Manual Installation (Alternative)</h4>
                <p>If you cannot use Composer, you can manually download and extract the library:</p>
                <ol>
                    <li>Download the <code>endroid/qr-code</code> package from <a href="https://github.com/endroid/qr-code" target="_blank">GitHub</a></li>
                    <li>Extract it to: <code><?php echo esc_html(plugin_dir_path(__FILE__) . 'vendor/endroid/qr-code/'); ?></code></li>
                    <li>Ensure the Composer autoloader is present in <code><?php echo esc_html(plugin_dir_path(__FILE__) . 'vendor/autoload.php'); ?></code></li>
                </ol>
                
                <p><strong>Current Status:</strong> <span style="color: #d63638;">QR Code library not found</span></p>
            </div>
            <?php else: ?>
            <div class="notice notice-success" style="margin: 20px 0;">
                <p><strong>QR Code Library:</strong> <span style="color: #00a32a;">✓ Loaded successfully</span></p>
            </div>
            <?php endif; ?>
            
            <form method="post" action="options.php">
                <?php
                settings_fields('woo_artkey_suite_group');
                do_settings_sections('woo-artkey-suite');
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }
    public function enqueue_recaptcha_script() {
        $opt = $this->opt();
        if (empty($opt['site_key'])) return;
        if (is_singular(self::CPT)) {
            wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js', [], null, true);
        }
    }
    private function recaptcha_valid() {
        $opt = $this->opt();
        if (empty($opt['secret_key'])) return true; // allow if not configured
        $resp = $_POST['g-recaptcha-response'] ?? '';
        if (empty($resp)) return false;

        $response = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', [
            'timeout' => 15,
            'body' => [
                'secret'   => $opt['secret_key'],
                'response' => $resp,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ],
        ]);
        if (is_wp_error($response)) return false;
        $data = json_decode(wp_remote_retrieve_body($response), true);
        return !empty($data['success']);
    }

    /* ===== QR Code Generation & Image Composition ===== */
    
    /**
     * Generate QR code for Art Key URL using endroid/qr-code library
     * 
     * @param string $url The URL to encode
     * @param int $size QR code size in pixels (default 300 for high-res)
     * @return string|false Base64-encoded PNG image data, or false on failure
     */
    /**
     * Load endroid/qr-code library if available
     * Checks plugin vendor directory first, then global Composer paths
     */
    private function load_qr_code_library() {
        // Check if already loaded
        if (class_exists('\Endroid\QrCode\QrCode')) {
            return true;
        }
        
        // Try plugin's bundled vendor directory first (recommended)
        $plugin_vendor = plugin_dir_path(__FILE__) . 'vendor/autoload.php';
        if (file_exists($plugin_vendor)) {
            require_once $plugin_vendor;
            if (class_exists('\Endroid\QrCode\QrCode')) {
                return true;
            }
        }
        
        // Try WordPress root vendor directory
        $wp_vendor = ABSPATH . 'vendor/autoload.php';
        if (file_exists($wp_vendor)) {
            require_once $wp_vendor;
            if (class_exists('\Endroid\QrCode\QrCode')) {
                return true;
            }
        }
        
        // Try wp-content vendor directory
        $content_vendor = WP_CONTENT_DIR . '/vendor/autoload.php';
        if (file_exists($content_vendor)) {
            require_once $content_vendor;
            if (class_exists('\Endroid\QrCode\QrCode')) {
                return true;
            }
        }
        
        return false;
    }
    
    private function generate_qr_code($url, $size = 300) {
        // Load the QR code library
        if (!$this->load_qr_code_library()) {
            // Log error but don't break the site
            error_log('Woo Art Key Suite: endroid/qr-code library not found. Please bundle it in the plugin or install via Composer.');
            return false;
        }
        
        try {
            // Use the fully qualified class name
            $qrCode = new \Endroid\QrCode\QrCode($url);
            $writer = new \Endroid\QrCode\Writer\PngWriter();
            
            // High-resolution settings for print quality
            $qrCode->setSize($size);
            $qrCode->setMargin(10);
            $qrCode->setErrorCorrectionLevel(\Endroid\QrCode\ErrorCorrectionLevel::High);
            
            $result = $writer->write($qrCode);
            return $result->getString();
        } catch (\Exception $e) {
            error_log('Woo Art Key Suite: QR code generation failed: ' . $e->getMessage());
            return false;
        } catch (\Throwable $e) {
            error_log('Woo Art Key Suite: QR code generation failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if a product requires QR code generation
     */
    public static function product_requires_qr_code($product_id) {
        return get_post_meta($product_id, self::PRODUCT_META_REQUIRES_QR, true) === 'yes';
    }
    
    /**
     * Check if an Art Key is associated with a QR-required product
     */
    private function artkey_requires_qr_code($aid) {
        // Check orders for this Art Key
        $orders = wc_get_orders([
            'meta_key' => self::ORDER_META_ARTKEY_ID,
            'meta_value' => $aid,
            'limit' => 1,
        ]);
        
        if (empty($orders)) {
            // Check cart items (for session-based Art Keys)
            if (function_exists('WC') && WC()->cart) {
                foreach (WC()->cart->get_cart() as $item) {
                    $pid = $item['product_id'] ?? 0;
                    if ($pid && self::product_requires_qr_code($pid)) {
                        return true;
                    }
                }
            }
            return false;
        }
        
        $order = $orders[0];
        foreach ($order->get_items() as $item) {
            $aid_from_item = (int)$item->get_meta(self::ORDER_META_ARTKEY_ID, true);
            if ($aid_from_item === $aid) {
                $pid = $item->get_product_id();
                if ($pid && self::product_requires_qr_code($pid)) {
                    return true;
                }
            }
        }
        
        return false;
    }
    
    /**
     * Generate composited print image: User Design + Art Key Template + QR Code
     * 
     * @param int $aid Art Key post ID
     * @return int|false Attachment ID of composited image, or false on failure
     */
    /**
     * Generate composited print image: User Design + Art Key Template + QR Code
     * 
     * STRICT WORKFLOW SEQUENCE:
     * 1. URL Creation: Generate unique Art Key permalink (must be finalized)
     * 2. QR Generation: Pass URL to endroid/qr-code library
     * 3. Image Composition: Overlay QR code onto user design at template coordinates
     * 
     * @param int $aid Art Key post ID
     * @return int|false Attachment ID of composited image, or false on failure
     */
    private function generate_composited_print_image($aid) {
        // Validation: Ensure Art Key exists and is published
        $artkey_post = get_post($aid);
        if (!$artkey_post || $artkey_post->post_type !== self::CPT) {
            error_log("Woo Art Key Suite: Invalid Art Key ID #{$aid}");
            return false;
        }
        
        // Ensure post is published to get final permalink
        if ($artkey_post->post_status !== 'publish') {
            wp_update_post(['ID' => $aid, 'post_status' => 'publish']);
        }
        
        $fields = get_post_meta($aid, self::META_FIELDS, true);
        if (!is_array($fields)) {
            error_log("Woo Art Key Suite: No fields found for Art Key #{$aid}");
            return false;
        }
        
        $template_key = $fields['print_template'] ?? '';
        $design_id = (int)($fields['user_design_image_id'] ?? 0);
        
        if (empty($template_key) || !array_key_exists($template_key, self::PRINT_TEMPLATES)) {
            error_log("Woo Art Key Suite: Invalid template key '{$template_key}' for Art Key #{$aid}");
            return false;
        }
        
        if (!$design_id || get_post_type($design_id) !== 'attachment') {
            error_log("Woo Art Key Suite: Invalid design image ID #{$design_id} for Art Key #{$aid}");
            return false;
        }
        
        // Get template definition
        $template = self::PRINT_TEMPLATES[$template_key];
        
        // ===== STEP 1: URL CREATION =====
        // Generate unique, non-indexed URL for the Art Key landing page
        // Ensure permalink is finalized by flushing rewrite rules if needed
        $artkey_url = get_permalink($aid);
        if (!$artkey_url) {
            // Force permalink generation
            clean_post_cache($aid);
            $artkey_url = get_permalink($aid);
            if (!$artkey_url) {
                error_log("Woo Art Key Suite: Failed to generate permalink for Art Key #{$aid}");
                return false;
            }
        }
        
        error_log("Woo Art Key Suite: Art Key #{$aid} - URL generated: {$artkey_url}");
        
        // ===== STEP 2: QR CODE GENERATION =====
        // Pass the unique URL to endroid/qr-code library to generate QR code image server-side
        $qr_image_data = $this->generate_qr_code($artkey_url, 600); // High-res for printing
        if (!$qr_image_data) {
            error_log("Woo Art Key Suite: QR code generation failed for Art Key #{$aid}");
            return false;
        }
        
        error_log("Woo Art Key Suite: Art Key #{$aid} - QR code generated successfully");
        
        // Generate QR code image
        $qr_image_data = $this->generate_qr_code($artkey_url, 600); // High-res for printing
        if (!$qr_image_data) return false;
        
        // Create GD image resources
        $qr_image = imagecreatefromstring($qr_image_data);
        if (!$qr_image) return false;
        
        // Load user design image
        $design_path = get_attached_file($design_id);
        if (!$design_path || !file_exists($design_path)) {
            imagedestroy($qr_image);
            return false;
        }
        
        // Detect image type and load accordingly
        $image_info = getimagesize($design_path);
        if (!$image_info) {
            imagedestroy($qr_image);
            return false;
        }
        
        $design_image = false;
        switch ($image_info[2]) {
            case IMAGETYPE_JPEG:
                $design_image = imagecreatefromjpeg($design_path);
                break;
            case IMAGETYPE_PNG:
                $design_image = imagecreatefrompng($design_path);
                break;
            case IMAGETYPE_GIF:
                $design_image = imagecreatefromgif($design_path);
                break;
            default:
                imagedestroy($qr_image);
                return false;
        }
        
        if (!$design_image) {
            imagedestroy($qr_image);
            return false;
        }
        
        // Get dimensions
        $design_width = imagesx($design_image);
        $design_height = imagesy($design_image);
        $qr_width = imagesx($qr_image);
        $qr_height = imagesy($qr_image);
        
        // Calculate QR code position and size based on template percentages
        $qr_final_width = (int)($design_width * $template['qr_w']);
        $qr_final_height = (int)($design_height * $template['qr_h']);
        $qr_x = (int)($design_width * $template['qr_x']);
        $qr_y = (int)($design_height * $template['qr_y']);
        
        // Resize QR code to fit template dimensions
        $qr_resized = imagecreatetruecolor($qr_final_width, $qr_final_height);
        imagealphablending($qr_resized, false);
        imagesavealpha($qr_resized, true);
        imagecopyresampled($qr_resized, $qr_image, 0, 0, 0, 0, $qr_final_width, $qr_final_height, $qr_width, $qr_height);
        
        // Create final composited image (start with user design as base)
        $composite = imagecreatetruecolor($design_width, $design_height);
        imagealphablending($composite, false);
        imagesavealpha($composite, true);
        
        // Copy user design to composite
        imagecopy($composite, $design_image, 0, 0, 0, 0, $design_width, $design_height);
        
        // Enable alpha blending for QR code overlay
        imagealphablending($composite, true);
        
        // ===== STEP 3: IMAGE COMPOSITION =====
        // Programmatically overlay the generated QR code onto the specific coordinates
        // of the user's chosen Art Key template
        imagecopy($composite, $qr_resized, $qr_x, $qr_y, 0, 0, $qr_final_width, $qr_final_height);
        
        error_log("Woo Art Key Suite: Art Key #{$aid} - QR code overlaid at coordinates ({$qr_x}, {$qr_y}) size ({$qr_final_width}x{$qr_final_height})");
        
        // Save composited image to temporary file
        $upload_dir = wp_upload_dir();
        $filename = 'artkey-composite-' . $aid . '-' . time() . '.png';
        $filepath = $upload_dir['path'] . '/' . $filename;
        
        // Output as PNG for high quality
        imagealphablending($composite, false);
        imagesavealpha($composite, true);
        $saved = imagepng($composite, $filepath, 9); // Maximum quality
        
        // Clean up GD resources
        imagedestroy($qr_image);
        imagedestroy($qr_resized);
        imagedestroy($design_image);
        imagedestroy($composite);
        
        if (!$saved || !file_exists($filepath)) {
            return false;
        }
        
        // Create WordPress attachment
        $attachment = [
            'post_mime_type' => 'image/png',
            'post_title'     => 'Print-Ready Art Key Composite #' . $aid,
            'post_status'    => 'inherit',
            'post_parent'    => $aid,
        ];
        
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $attach_id = wp_insert_attachment($attachment, $filepath, $aid);
        if (is_wp_error($attach_id) || !$attach_id) {
            @unlink($filepath);
            return false;
        }
        
        // Generate attachment metadata
        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        wp_update_attachment_metadata($attach_id, $attach_data);
        
        // Store composited image ID in Art Key meta
        update_post_meta($aid, '_artkey_composite_image_id', $attach_id);
        
        error_log("Woo Art Key Suite: Art Key #{$aid} - Composited image created: attachment #{$attach_id}");
        error_log("Woo Art Key Suite: Art Key #{$aid} - Final file URL: " . wp_get_attachment_url($attach_id));
        
        // IMPORTANT: This composited image (User Design + Template + QR Code) is what
        // must be sent to Gelato API, NOT the raw user design file
        return $attach_id;
    }
    
    /**
     * Generate QR code when order is completed (if product requires it)
     */
    public function generate_qr_code_for_order($order_id) {
        if (!$order_id) return;
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        foreach ($order->get_items() as $item) {
            $pid = $item->get_product_id();
            if (!$pid) continue;
            
            // Check if product requires QR code
            if (get_post_meta($pid, self::PRODUCT_META_REQUIRES_QR, true) !== 'yes') continue;
            
            // Get Art Key for this order item
            $aid = (int)$item->get_meta(self::ORDER_META_ARTKEY_ID, true);
            if (!$aid || get_post_type($aid) !== self::CPT) continue;
            
            $fields = get_post_meta($aid, self::META_FIELDS, true);
            if (!is_array($fields)) continue;
            
            // Generate composite if template and design are available
            if (!empty($fields['print_template']) && !empty($fields['user_design_image_id'])) {
                $this->generate_composited_print_image($aid);
            }
        }
    }
    
    /**
     * Register REST API endpoints for React frontend integration
     */
    public function register_rest_endpoints() {
        // Endpoint to get print-ready composited image URL
        register_rest_route('woo-artkey-suite/v1', '/print-image/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_print_image'],
            'permission_callback' => [$this, 'rest_check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && get_post_type((int)$param) === self::CPT;
                    },
                ],
            ],
        ]);
        
        // Endpoint to upload user design and select template
        register_rest_route('woo-artkey-suite/v1', '/artkey/(?P<id>\d+)/design', [
            'methods' => 'POST',
            'callback' => [$this, 'rest_save_design'],
            'permission_callback' => [$this, 'rest_check_permission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'validate_callback' => function($param) {
                        return is_numeric($param) && get_post_type((int)$param) === self::CPT;
                    },
                ],
            ],
        ]);
        
        // Endpoint to get available templates
        register_rest_route('woo-artkey-suite/v1', '/templates', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_get_templates'],
            'permission_callback' => '__return_true', // Public endpoint
        ]);
    }
    
    public function rest_check_permission($request) {
        $aid = (int)$request->get_param('id');
        if (!$aid) return false;
        
        // Allow if user can edit this Art Key
        $token = $request->get_header('X-ArtKey-Token');
        if ($token && $this->can_edit($aid, $token)) {
            return true;
        }
        
        // Allow if user owns the Art Key
        $user_id = get_current_user_id();
        if ($user_id) {
            $owner = (int)get_post_meta($aid, self::META_USER_ID, true);
            if ($owner === $user_id) return true;
        }
        
        // Allow admins
        return current_user_can('manage_options');
    }
    
    /**
     * REST API: Get print-ready composited image URL for Gelato API
     * 
     * Returns the final composited image (User Design + Template + QR Code),
     * NOT the raw user design file.
     * 
     * @param WP_REST_Request $request
     * @return array|WP_Error
     */
    public function rest_get_print_image($request) {
        $aid = (int)$request->get_param('id');
        $composite_id = get_post_meta($aid, '_artkey_composite_image_id', true);
        
        if (!$composite_id) {
            // Generate if not exists (follows strict workflow: URL → QR → Composition)
            $composite_id = $this->generate_composited_print_image($aid);
        }
        
        if (!$composite_id) {
            return new WP_Error(
                'no_image', 
                'Print-ready composited image not available. Ensure template and design are set.',
                ['status' => 404]
            );
        }
        
        $url = wp_get_attachment_image_url($composite_id, 'full');
        if (!$url) {
            return new WP_Error('image_url_error', 'Failed to retrieve image URL', ['status' => 500]);
        }
        
        // Return the composited image URL - this is what should be sent to Gelato API
        return [
            'url' => $url,
            'attachment_id' => $composite_id,
            'artkey_id' => $aid,
            'note' => 'This is the composited image (User Design + Template + QR Code) ready for printing',
        ];
    }
    
    public function rest_save_design($request) {
        $aid = (int)$request->get_param('id');
        $template_key = $request->get_param('print_template');
        $image_id = (int)$request->get_param('user_design_image_id');
        
        $fields = get_post_meta($aid, self::META_FIELDS, true);
        if (!is_array($fields)) $fields = [];
        
        if ($template_key && array_key_exists($template_key, self::PRINT_TEMPLATES)) {
            $fields['print_template'] = $template_key;
        }
        
        if ($image_id > 0 && get_post_type($image_id) === 'attachment') {
            $fields['user_design_image_id'] = $image_id;
        }
        
        update_post_meta($aid, self::META_FIELDS, $fields);
        
        // Generate composite image if both are provided
        if (!empty($fields['print_template']) && !empty($fields['user_design_image_id'])) {
            $composite_id = $this->generate_composited_print_image($aid);
            return [
                'success' => true,
                'composite_image_id' => $composite_id,
                'composite_image_url' => $composite_id ? wp_get_attachment_image_url($composite_id, 'full') : null,
            ];
        }
        
        return ['success' => true];
    }
    
    public function rest_get_templates($request) {
        return array_map(function($key, $template) {
            return [
                'key' => $key,
                'name' => $template['name'],
                'qr_position' => [
                    'x' => $template['qr_x'],
                    'y' => $template['qr_y'],
                    'width' => $template['qr_w'],
                    'height' => $template['qr_h'],
                ],
            ];
        }, array_keys(self::PRINT_TEMPLATES), self::PRINT_TEMPLATES);
    }

    /* ===== Admin notice if editor page is missing ===== */
    public function editor_page_admin_notice() {
        if (!current_user_can('manage_options')) return;
        
        $notices = [];
        
        // Check for editor page
        $pid = $this->find_page_with_shortcode(['artkey_editor','biolink_editor']);
        if (!$pid) {
            $notices[] = 'No page with the <code>[artkey_editor]</code> shortcode was found. Create a page (e.g. "Art Key Editor") and add <code>[artkey_editor]</code> so the pre-checkout editor can open.';
        }
        
        // Check for QR code library
        if (!$this->load_qr_code_library()) {
            $notices[] = 'The <code>endroid/qr-code</code> library is not found. QR code generation requires this library. <a href="' . admin_url('options-general.php?page=woo-artkey-suite') . '">View setup instructions</a> in plugin settings.';
        }
        
        if (!empty($notices)) {
            foreach ($notices as $notice) {
                echo '<div class="notice notice-warning"><p><strong>Woo Art Key Suite:</strong> ' . $notice . '</p></div>';
            }
        }
    }

    /* ===== Admin: quick open in editor ===== */
    public function admin_row_action_open_editor($actions, $post) {
        if (!is_admin()) return $actions;
        if (empty($post) || $post->post_type !== self::CPT) return $actions;
        if (!current_user_can('edit_post', $post->ID)) return $actions;

        $token = get_post_meta($post->ID, self::META_EDIT_TOKEN, true);
        $url   = $this->editor_url($post->ID, $token);
        if ($url) {
            $actions['open_artkey_editor'] = '<a href="'.esc_url($url).'" target="_blank" rel="noopener">'.esc_html__('Open Editor', 'woo-artkey-suite').'</a>';
        }
        return $actions;
    }

    public function register_artkey_admin_metabox() {
        add_meta_box(
            'artkey_admin_tools',
            __('Art Key Tools', 'woo-artkey-suite'),
            [$this, 'render_artkey_admin_metabox'],
            self::CPT,
            'side',
            'high'
        );
    }
    public function render_artkey_admin_metabox($post) {
        if (!current_user_can('edit_post', $post->ID)) return;
        $token = get_post_meta($post->ID, self::META_EDIT_TOKEN, true);
        $view  = get_permalink($post->ID);
        $edit  = $this->editor_url($post->ID, $token);
        echo '<p><a href="'.esc_url($edit).'" target="_blank" rel="noopener" class="button button-primary" style="width:100%;text-align:center;">'.esc_html__('Open in Editor', 'woo-artkey-suite').'</a></p>';
        echo '<p><a href="'.esc_url($view).'" target="_blank" rel="noopener" class="button" style="width:100%;text-align:center;">'.esc_html__('View Live Page', 'woo-artkey-suite').'</a></p>';
        if (current_user_can('manage_options')) {
            echo '<p style="word-break:break-all"><strong>'.esc_html__('Edit Token:', 'woo-artkey-suite').'</strong><br><code>'.esc_html($token ?: '—').'</code></p>';
        }
        if (!$edit) {
            echo '<p class="description">'.esc_html__('No page with the [artkey_editor] shortcode found. Create one to use the editor.', 'woo-artkey-suite').'</p>';
        }
    }
}

new Woo_ArtKey_Suite();

// Clear scheduled cleanup on deactivation
if (function_exists('register_deactivation_hook')) {
    register_deactivation_hook(__FILE__, function(){
        if (function_exists('wp_clear_scheduled_hook')) {
            wp_clear_scheduled_hook('woo_artkey_suite_cleanup');
        }
    });
}
