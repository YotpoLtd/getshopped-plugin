<?php
/*
	Plugin Name: Yotpo Social Reviews for WP e-Commerce
	Description: Yotpo Social Reviews helps WP e-Commerce store owners generate a ton of reviews for their products. Yotpo is the only solution which makes it easy to share your reviews automatically to your social networks to gain a boost in traffic and an increase in sales.
	Author: Yotpo
	Version: 1.0.0
	Author URI: http://www.yotpo.com?utm_source=yotpo_plugin_wp_ecommerce&utm_medium=plugin_page_link&utm_campaign=wp_ecommerce_plugin_page_link
	Plugin URI: http://www.yotpo.com?utm_source=yotpo_plugin_wp_ecommerce&utm_medium=plugin_page_link&utm_campaign=wp_ecommerce_plugin_page_link
 */

include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
include_once( plugin_dir_path( __FILE__ ) . 'templates/wpsc-yotpo-settings.php' );
include_once( plugin_dir_path( __FILE__ ) . 'lib/yotpo-api/Yotpo.php' );

if (!wpsc_yotpo_compatible()) {
	add_action('admin_notices', 'wpsc_yotpo_not_compatible');
}

register_activation_hook(   __FILE__, 'wpsc_yotpo_activation' );
register_uninstall_hook( __FILE__, 'wpsc_yotpo_uninstall' );

add_action('plugins_loaded', 'wpsc_yotpo_init');
add_action('init', 'wpsc_yotpo_redirect');
add_action('admin_menu', 'wpsc_yotpo_admin_settings');

function wpsc_yotpo_init() {
	$yotpo_settings = get_option('yotpo_settings', wpsc_yotpo_get_default_settings());
	if (!empty($yotpo_settings['app_key']) && wpsc_yotpo_compatible()) {
		if (!is_admin()) {
			add_action('wp_enqueue_scripts', 'wpsc_yotpo_load_js');
			add_action('template_redirect', 'wpsc_yotpo_front_end_init', 1);
		}
		elseif (!empty($yotpo_settings['secret'])) {
			add_action('wpsc_purchase_log_save', 'wpsc_yotpo_map');
		}
	}
}

function wpsc_yotpo_redirect() {
	if (get_option('wpsc_yotpo_just_installed', false)) {
		delete_option('wpsc_yotpo_just_installed');
		wp_redirect( ( ( is_ssl() || force_ssl_admin() || force_ssl_login() ) ? str_replace( 'http:', 'https:', admin_url( 'admin.php?page=wpsc-yotpo-settings-page' ) ) : str_replace( 'https:', 'http:', admin_url( 'admin.php?page=wpsc-yotpo-settings-page' ) ) ) );
		exit;
	}	
}

function wpsc_yotpo_admin_settings() {	
	add_action('admin_enqueue_scripts', 'wpsc_yotpo_admin_styles');
	$page = add_menu_page('Yotpo', 'Yotpo', 'manage_options', 'wpsc-yotpo-settings-page', 'wpsc_display_yotpo_admin_page', 'none', null);
	add_action('load-' . $page, 'wpsc_load_yotpo_admin_page', 1);
}

function wpsc_yotpo_front_end_init() {	
	$settings = get_option('yotpo_settings', wpsc_yotpo_get_default_settings());
	add_action('wpsc_transaction_results_shutdown', 'wpsc_yotpo_conversion_track');

	if (get_post_type() == 'wpsc-product'  && is_single()) {
		if ($settings['bottom_line_enabled_product']) {	
			add_action('wpsc_product_form_fields_begin', 'wpsc_yotpo_show_bottomline');
			wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('assets/css/bottom-line.css', __FILE__));
		}

		$widget_location = $settings['widget_location'];
		if ($widget_location == 'footer') {
			add_action('wpsc_theme_footer', 'wpsc_yotpo_show_widget', 10);
		}
	}
	elseif ($settings['bottom_line_enabled_category']) {
		wp_enqueue_style('yotpoSideBootomLineStylesheet', plugins_url('assets/css/bottom-line.css', __FILE__));
		add_action('wpsc_product_form_fields_begin', 'wpsc_yotpo_show_bottomline');
	}

	if ($settings['yotpo_disable_native_comments']) {
		add_filter('comments_template', 'wpsc_yotpo_comments_template');
	}
}

function wpsc_yotpo_activation() {
	if (current_user_can('activate_plugins')) {
		update_option('wpsc_yotpo_just_installed', true);
		$plugin = isset($_REQUEST['plugin']) ? $_REQUEST['plugin'] : '';
		check_admin_referer( "activate-plugin_{$plugin}" );
		$default_settings = get_option('yotpo_settings', false);
		if (!is_array($default_settings)) {
			add_option('yotpo_settings', wpsc_yotpo_get_default_settings());
		}
		update_option('product_ratings', 0);
	}        
}

function wpsc_yotpo_uninstall() {
	if (current_user_can( 'activate_plugins' ) && __FILE__ == WP_UNINSTALL_PLUGIN) {
		check_admin_referer( 'bulk-plugins' );
		delete_option('yotpo_settings');	
	}	
}

function wpsc_yotpo_show_widget() {
	echo wpsc_yotpo_get_template('reviews');
}

function wpsc_yotpo_load_js() {
	if (is_plugin_active('wp-e-commerce/wp-shopping-cart.php')) {
		wp_enqueue_script('yquery', 'https://www.yotpo.com/js/yQuery.js');
	}
}

function wpsc_yotpo_show_bottomline() {
	echo wpsc_yotpo_get_template('bottomLine');			
}

function wpsc_yotpo_get_template($type) {
	$productId = wpsc_the_product_id();
	$product = get_post($productId);
	if ( $product->comment_status == 'open' ) {
		$yotpo_settings = get_option('yotpo_settings', wpsc_yotpo_get_default_settings());
		
		$productTitle = get_the_title($productId);
		$productDescription = htmlentities(wpsc_the_product_description());
		$productUrl = wpsc_this_page_url();
		$productSku = array_pop(get_product_meta($productId, 'sku'));
		$domain = wpsc_yotpo_get_shop_domain();
		$yotpoLanguageCode = $yotpo_settings['language_code'];

		if($yotpo_settings['yotpo_language_as_site'] == true) {
		$lang = explode('-', get_bloginfo('language'));
			// In some languages there is a 3 letters language code
			//TODO map these iso-639-2 to iso-639-1 (from 3 letters language code to 2 letters language code) 
			if(strlen($lang[0]) == 2) {
			$yotpoLanguageCode = $lang[0];	
			}		

		}	
		$yotpo_div = "<div class='yotpo ".$type."' 
					data-appkey='".$yotpo_settings['app_key']."'
					data-domain='".$domain."'
					data-product-id='".$productId."'
					data-product-models='".$productSku."'
					data-name='".$productTitle."' 
					data-url='".$productUrl."' 
					data-image-url='".wpsc_the_product_image()."' 
					data-description='".$productDescription."' 
					data-bread-crumbs=''
					data-lang='".$yotpoLanguageCode."'></div>";
		return $yotpo_div;
	}
	return '';
}

function wpsc_yotpo_get_shop_domain() {
	return parse_url(get_bloginfo('url'),PHP_URL_HOST);
}

function wpsc_yotpo_map($order) {
	try {
		if (!$order->is_closed_order() && !$order->is_job_dispatched()) {
			//the order status doesn't fit our requirements
			return;
		}

		$purchase_data = wpsc_yotpo_get_single_map_data($order);
		if (!is_null($purchase_data) && is_array($purchase_data)) {
			$yotpo_settings = get_option('yotpo_settings', wpsc_yotpo_get_default_settings());
			$yotpo_api = new Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
			$get_oauth_token_response = $yotpo_api->get_oauth_token();
			if (!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
				$purchase_data['utoken'] = $get_oauth_token_response['access_token'];
				$purchase_data['platform'] = 'wp_ecommerce';
				$response = $yotpo_api->create_purchase($purchase_data);			
			}
		}		
	} catch (Exception $e) {
		error_log($e->getMessage());
	}
}

function wpsc_yotpo_get_single_map_data($order) {
	$data = null;
	if (!is_null($order)) {
		$data = array();
		$data['order_id'] = $order->get('id');
		$data['order_date'] = gmdate("Y-m-d\TH:i:s\Z", $order->get('date'));
		$data['email'] = wpsc_get_buyers_email($data['order_id']);

		$purchase_items_data = new wpsc_purchaselogs_items($data['order_id']);
		$userinfo = $purchase_items_data->userinfo;
		$data['customer_name'] = $userinfo['billingfirstname']['value']." ".$userinfo['billinglastname']['value'];
		$data['currency_iso'] = wpsc_get_currency_code(); 
		$products_arr = array();

		foreach ($order->get_cart_contents() as $product) {
			$product_data = array();
			$product_data['name'] = $product->name;
			$product_data['price'] = $product->price;
			$product_data['url'] = wpsc_product_url($product->prodid);
			$product_data['image'] = wpsc_the_product_image('', '', $product->prodid);
			$product_data['description'] = htmlentities(get_post($product->prodid)->post_content);
			$products_arr[$product->prodid] = $product_data;
			
		}

		$data['products'] = $products_arr;
	}
	return $data;
}

function wpsc_yotpo_get_product_image_url($product_id) {
	$url = wp_get_attachment_url(get_post_thumbnail_id($product_id));
	return $url ? $url : null;
}

function wpsc_yotpo_get_past_orders() {
	global $wpdb;

	$three_months_ago = mktime(0, 0, 0, date("n") - 2, 1, date("Y"));
	$query = "SELECT *
			  FROM " . WPSC_TABLE_PURCHASE_LOGS . "
			  WHERE processed IN (" . implode(',', array(WPSC_Purchase_Log::CLOSED_ORDER, WPSC_Purchase_Log::JOB_DISPATCHED)) . ")
			  AND date >= " . $three_months_ago;
	$offset = 0;
	$row_count = 10000;

	$result = array();
	while ($orders = $wpdb->get_results($query . " LIMIT $offset, $row_count", ARRAY_A)) {
		$orders_batch = array();

		// build order data
		foreach ($orders as $key => $order) {
			$single_order_data = wpsc_yotpo_get_single_map_data(new WPSC_Purchase_Log($order));
			if (!is_null($single_order_data)) {
				$orders_batch[] = $single_order_data;
			}
		}

		// chunk batches by small batch
		foreach (array_chunk($orders_batch, 200) as $key => $batch) {
			$result[] = array(
				'orders' => $batch,
				'platform' => 'wp_ecommerce'
			);
		}

		$offset += $row_count;
	}
	return $result;
}

function wpsc_yotpo_send_past_orders() {
	$yotpo_settings = get_option('yotpo_settings', wpsc_yotpo_get_default_settings());
	if (!empty($yotpo_settings['app_key']) && !empty($yotpo_settings['secret']))
	{
		$past_orders = wpsc_yotpo_get_past_orders();		
		$is_success = true;
		if(!is_null($past_orders) && is_array($past_orders)) {
			$yotpo_api = new Yotpo($yotpo_settings['app_key'], $yotpo_settings['secret']);
			$get_oauth_token_response = $yotpo_api->get_oauth_token();
			if(!empty($get_oauth_token_response) && !empty($get_oauth_token_response['access_token'])) {
				foreach ($past_orders as $post_bulk) 
					if (!is_null($post_bulk))
					{
						$post_bulk['utoken'] = $get_oauth_token_response['access_token'];
						$response = $yotpo_api->create_purchases($post_bulk);						
						if ($response['code'] != 200 && $is_success)
						{
							$is_success = false;
							$message = !empty($response['status']) && !empty($response['status']['message']) ? $response['status']['message'] : 'Error occurred';
							wpsc_yotpo_display_message($message, true);
						}
					}
				if ($is_success)
				{
					wpsc_yotpo_display_message('Past orders sent successfully' , false);
					$yotpo_settings['show_submit_past_orders'] = false;
					update_option('yotpo_settings', $yotpo_settings);
				}	
			}
		}
		else {
			wpsc_yotpo_display_message('Could not retrieve past orders', true);
		}	
	}
	else {
		wpsc_yotpo_display_message('You need to set your app key and secret token to post past orders', false);
	}		
}

function wpsc_yotpo_conversion_track($purchase_log_object) {
	if (!is_null($purchase_log_object) && ($purchase_log_object->is_accepted_payment() ||  $purchase_log_object->is_order_received())) {
		$yotpo_settings = get_option('yotpo_settings', wpsc_yotpo_get_default_settings());

		$conversion_params = http_build_query(
								array(
									'app_key' 		 => $yotpo_settings['app_key'],
									'order_id' 		 => $purchase_log_object->get('id'),
									'order_amount' 	 => $purchase_log_object->get('totalprice'),
									'order_currency' => wpsc_get_currency_code()
								)
							);

		echo "<img 
		src='https://api.yotpo.com/conversion_tracking.gif?$conversion_params'
		width='1'
		height='1'></img>";
	}
}

function wpsc_get_currency_code() {
	global $wpdb;
	return $wpdb->get_var($wpdb->prepare("SELECT `code` FROM `".WPSC_TABLE_CURRENCY_LIST."` WHERE `id` = %d LIMIT 1", get_option('currency_type')));
}

function wpsc_yotpo_get_default_settings() {
	return array( 'app_key' => '',
				  'secret' => '',
				  'widget_location' => 'footer',
				  'language_code' => 'en',
				  'bottom_line_enabled_product' => true,
				  'bottom_line_enabled_category' => true,
				  'yotpo_language_as_site' => true,
				  'yotpo_disable_native_comments' => true,
				  'show_submit_past_orders' => true);
}

function wpsc_yotpo_admin_styles($hook) {
	if ($hook == 'toplevel_page_wpsc-yotpo-settings-page') {		
		wp_enqueue_script('yotpoSettingsJs', plugins_url('assets/js/settings.js', __FILE__), array('jquery-effects-core'));		
		wp_enqueue_style('yotpoSettingsStylesheet', plugins_url('assets/css/yotpo.css', __FILE__));
	}
	wp_enqueue_style('yotpoSideLogoStylesheet', plugins_url('assets/css/side-menu-logo.css', __FILE__));
}

function wpsc_yotpo_compatible() {
	return version_compare(phpversion(), '5.2.0') >= 0 && function_exists('curl_init') && is_plugin_active('wp-e-commerce/wp-shopping-cart.php') && version_compare(get_option('wpsc_version', '0'), '3.8.9', '>=');
}

function wpsc_yotpo_not_compatible_message() {
	return 'WARNING: Yotpo Social Reviews for WP-e-Commerce requires WP e-Commerce (version >= 3.8.9) to be installed and active, PHP Version >= 5.2.0 and CURL.';
}

function wpsc_yotpo_not_compatible() {
	wpsc_yotpo_display_message(wpsc_yotpo_not_compatible_message());
}

function wpsc_yotpo_comments_template() {
	return (get_post_type() == 'wpsc-product') ? (plugin_dir_path( __FILE__ ) . 'templates/comments.php') : false;
}