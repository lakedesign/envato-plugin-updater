<?php
/**
 * [file short description]
 *
 * [file description]
 *
 * @package    Package
 * @subpackage Package/subpackage
 */

/**
 * [class short_description]
 *
 * [class description]
 *
 * @package    Package
 * @subpackage Package/subpackage
 */
class Envato_Plugin_Updater {

	/**
	 * Initialize filters, action, variables and includes.
	 */
	function __construct() {

		$this->show_updates = true; // Show updates

		$this->name = ''; // Plugin name (Another Plugin Name)
		$this->slug = ''; // Plugin slug (apn)
		$this->version = ''; // Current plugin version (1.2.3)

		$this->basename = ''; // The basename of the plugin (apn/apn.php)
		$this->plugin_url = ''; // Link to Envato item (http://codecanyon.net/item/plugin-name/1234567)
		$this->licence_url = ''; // Plugin page where licence information can be updated (admin.php?page=apn)

		$this->envato_author = ''; // Plugin Autor (author)
		$this->envato_token = ''; // Authors privite token from new APIv3 (2ql59y4cpeh5y4b0l2d86cfadvdz2ig2)
		$this->envato_item_id = ''; // Plugin item id	(1234567)
		$this->envato_username = ''; // Buyers username (username)
		$this->envato_api_key = ''; // Buyers API key from old APIv2 (b0l2dpeh5yvdz2ig2d86cfa2ql59y4c4)

		// Hook into actions.
		add_action( 'in_plugin_update_message-' . $this->basename, array( $this, 'in_plugin_update_message' ), 10, 2 );

		// Hook into filter.
		add_filter( 'plugin_row_meta', array( $this, 'plugin_links' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'inject_info' ), 20, 3 );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
	}

	/**
	 * Add a "View Details" link to open a thickbox popup with information about
	 * the plugin from the public directory.
	 *
	 * @param array  $links       List of links.
	 * @param string $plugin_file Relative path to the main plugin file from the plugins directory.
	 * @param array  $plugin_data Data from the plugin headers.
	 *
	 * @return array $links 			The plugin row links
	 */
	function plugin_links( $links, $plugin_file, $plugin_data ) {
		if ( isset( $plugin_data['Name'] ) && ( $this->name == $plugin_data['Name'] ) ) {

			$info = $this->get_remote_info();

			if ( is_array( $info ) ) {

				$links[] = sprintf( '<a href="%s" class="thickbox" aria-label="%s" data-title="%s">%s</a>',
					self_admin_url( 'plugin-install.php?tab=plugin-information&amp;plugin=' . $this->slug . '&amp;TB_iframe=true&amp;width=600&amp;height=550' ),
					esc_attr( sprintf( __( 'More information about %s' ), $plugin_data['Name'] ) ),
					$plugin_data['Name'],
					__( 'View details' )
				);

				unset( $links[2] ); // Remove the 'Visit plugin site' link.
			}
		}

		return $links;
	}

	/**
	 * This function will populate the plugin data visible in the 'View details' popup
	 *
	 * @param	bool|object $result [class description].
	 * @param	string		$action [class description].
	 * @param	object		$args 	[class description].
	 *
	 * @return bool|object $result The the plugin data in the 'View details' popup
	 */
	function inject_info( $result, $action = null, $args = null ) {

		if ( isset( $args->slug ) && ( $this->slug == $args->slug ) ) {
			$info = $this->get_remote_info();

			if ( is_array( $info ) ) {
				$obj = new stdClass();
				foreach ( $info as $k => $v ) {
					$obj->$k = $v;
				}

				return $obj;
			}
		}

		return $result;
	}

	/**
	 * This function will connect to the remote API and find release details.
	 *
	 * @param  object $transient [description].
	 *
	 * @return object           [description]
	 */
	function inject_update( $transient ) {

		// Bail early if no show_updates.
		if ( ! $this->show_updates ) {
			return $transient;
		}

		// Bail early if no update available.
		if ( ! $this->is_update_available() ) {
			return $transient;
		}

		$info = $this->get_remote_info();
		$basename = $this->basename;
		$slug = $this->slug;

		if ( is_array( $info ) ) {

			// Create new object for update.
			$obj = new stdClass();
			$obj->slug = $slug;
			$obj->plugin = $basename;
			$obj->new_version = $info['version'];
			$obj->url = $info['homepage'];
			$obj->package = $info['download_link'];

			// Add to transient.
			$transient->response[ $basename ] = $obj;

			return $transient;
		}

		return $transient;
	}

	/**
	 *  Displays an update message for plugin list screens.
	 *  Shows only the version updates from the current until the newest version
	 *
	 * @param  [type] $plugin_data [description].
	 * @param  [type] $r           [description].
	 *
	 * @return [type]              [description]
	 */
	function in_plugin_update_message( $plugin_data, $r ) {

		// Validate.
		if ( $this->is_license_active() ) {
			return;
		}

		$message = __( 'To enable updates, please enter your license key on the <a href="%s">Settings</a> page. If you don\'t have a licence key, please see <a href="%s">details & pricing</a>', 'ld' );

		// Show message.
		echo '<br />' . sprintf( $message, admin_url( 'admin.php?page=acm-settings&tab=support-updates' ), $this->plugin_url );
	}

	/**
	 * [get_remote_url description]
	 *
	 * @return [type] [description]
	 */
	function get_remote_url( ) {

		$envato_item_id = $this->envato_item_id;
		$envato_username = $this->envato_username;
		$envato_api_key = $this->envato_api_key;

		$url = "http://marketplace.envato.com/api/edge/$envato_username/$envato_api_key/wp-download:$envato_item_id.json";

		$response = wp_remote_get( $url, array(
			'timeout' => 60,
			'headers' => array(
				"Content-Type" => "application/json",
			)
		) );

		if ( is_wp_error( $response )  ) {

			return false; // $response->get_error_message();

		}

		$item =  json_decode($response['body'], true);
		if( isset($item['error']) ){

			return false; // $item['error_description'];

		}

		if( !isset($item['wp-download']['url']) ){

			return false; // "Item purchase verification failed or item isn/'t purchased";

		}

		return $item['wp-download']['url'];
	}


	/**
	 * [get_remote_response description]
	 *
	 * @return [type] [description]
	 */
	function get_remote_response() {

		// Get Envato item details
		if( !empty( $this->envato_token && !empty( $this->envato_item_id ) ) ) {

			$envato_item_id = $this->envato_item_id;
			$envato_token = $this->envato_token;
			$item = array();

			$url = "https://api.envato.com/v3/market/catalog/item?id=" . $envato_item_id;

			$response = wp_remote_get( $url, array(
				'timeout' => 60,
				'headers' => array(
					"Content-Type" => "application/json",
					"Authorization" => "Bearer " . $envato_token,
				)
			) );

			if ( is_wp_error( $response )  ) {

				return false; //$response->get_error_message();

			}

			$item =  json_decode($response['body'], true);
			if( isset($item['error']) ){

				return false; //$item['error_description'];

			}

			$item['sections'] = array();
			$item['download_link'] = false;

			// validate
			if( $this->is_license_active() ) {

				$item['download_link'] = $this->get_remote_url();

			}


			// Divide the product description into tabs by h2 headers
			preg_match_all("@<h2[^>]*?>(.*?)</h2>@siu", $item['description'], $titles);
			$contents = preg_split("@<h2[^>]*?>.*?</h2>@siu", $item['description'], -1, PREG_SPLIT_NO_EMPTY);

			// Transform the h2 titles to slugs
			$sections = array();
			foreach ($titles[1] as $key => $title) {
				if($key == 0){
					$sections[0] = 'description';
				}else{
					$title= preg_replace("/\s+/", " ", $title);
					$title = str_replace(" ", "_", $title);
					$title = preg_replace("/[^A-Za-z0-9_]/","",$title);
					$sections[$key] =strtolower($title);
				}
			}

			// Combine the content with their titles in one array
			if( is_array( $sections ) && is_array( $contents ) ){
				$item['sections'] = array_combine($sections, $contents);
			}


			$info = array(
				"name" =>   $this->name,
				"slug" =>   $this->slug,
				"version" =>  $item['wordpress_plugin_metadata']['version'],
				"author" =>  sprintf( '<a href="%s?ref=%s" title="%s">%s</a>', $item['author_url'], $item['author_username'], $item['wordpress_plugin_metadata']['author'], $item['wordpress_plugin_metadata']['author'] ),
				"author_profile" =>  $item['author_url'] . "?ref=" . $item['author_username'],
				"contributors" => array(
					$item['author_username'] => $item['author_url'] . "?ref=" . $item['author_username'],
				),
				"rating" => round( $item['rating'] * 10 *2 ) ,
				"num_ratings" => $item['rating_count'],
				"active_installs" => $item['number_of_sales'],
				"last_updated" => $item['updated_at'],
				"added" => $item['published_at'],
				"homepage" =>  $item['url'] . "?ref=" . $item['author_username'],
				"sections" => $item['sections'],
				"short_description" => $item['wordpress_plugin_metadata']['description'],
				"download_link" => $item['download_link'],
				"tags" => $item['tags'],

				"banners" => array(
					"low" => $item['previews']['landscape_preview']['landscape_url'],
					"high" => $item['previews']['landscape_preview']['landscape_url'],
				),
				"active_installs" => $item['number_of_sales'],
			);

			$info = json_encode( $info );

			return $info;
		}

		return false;
	}

	/**
	 * [is_update_available description]
	 *
	 * @return boolean [description]
	 */
	function is_update_available() {

		// vars
		$info = $this->get_remote_info();
		$version = $this->version;


		// return false if no info
		if( empty($info['version']) ) {

			return false;

		}


	    // return false if the external version is '<=' the current version
		if( version_compare($info['version'], $version, '<=') ) {

	    	return false;

	    }


		// return
		return true;

	}

	/**
	 * [get_remote_info description]
	 *
	 * @return [type] [description]
	 */
	function get_remote_info() {

		// clear transient if force check is enabled
		if( !empty($_GET['force-check']) ) {

			// only allow transient to be deleted once per page load
			if( empty($_GET[$this->slug . '-ignore-force-check']) ) {

				delete_transient($this->slug . '_get_remote_info' );

			}


			// update $_GET
			$_GET[$this->slug . '-ignore-force-check'] = true;

		}


		// get transient
		$transient = get_transient($this->slug . '_get_remote_info' );

		if( $transient !== false ) {

			return $transient;

		}


		// vars
		$info = $this->get_remote_response();
		$timeout = 12 * HOUR_IN_SECONDS;


	    // decode
	    if( !empty($info) ) {

			$info = json_decode($info, true);

			// fake info version
	        //$info['version'] = '5.0.0';

	    } else {

		    $info = 0; // allow transient to be returned, but empty to validate
		    $timeout = 2 * HOUR_IN_SECONDS;

	    }


		// update transient
		set_transient($this->slug . '_get_remote_info', $info, $timeout );


		// return
		return $info;
	}

	/**
	 * [is_license_active description]
	 *
	 * @return boolean [description]
	 */
	function is_license_active() {

		$url = "http://marketplace.envato.com/api/edge/$this->envato_username/$this->envato_api_key/wp-download:$this->envato_item_id.json";

		$response = wp_remote_get( $url, array(
			'timeout' => 60,
			'headers' => array(
				"Content-Type" => "application/json",
			)
		) );

		if ( is_wp_error( $response )  ) {

			return false; // $response->get_error_message();

		}

		$item =  json_decode($response['body'], true);
		if( isset($item['error']) ){

			return false; // $item['error_description'];

		}

		if( !isset($item['wp-download']['url']) ){

			return false; // "Item purchase verification failed or item isn/'t purchased";

		}

		return true;

	}

}

new Envato_Plugin_Updater();
