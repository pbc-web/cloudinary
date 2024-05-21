<?php
namespace PBC\Cloudinary;

if( !defined('ABSPATH') || (!ABSPATH) ){
	return;
}

class AdminSetup {

    static $dev_mode;
    static $options;

    public function __construct() {
		add_action( 'admin_menu', [__class__, 'add_settings_page']);
		add_action( 'admin_init', [__class__, 'register_settings'] );
		add_action( 'admin_init', [__class__, 'register_group_settings']);
	}

    public static function is_dev() {
        if(empty(self::$dev_mode)) {
            self::$dev_mode = isset($_SERVER['WP_ENV']) && 'production' !== $_SERVER['WP_ENV'];
        }
        return self::$dev_mode;
    }

	public static function add_settings_page() {
		add_submenu_page(
			'upload.php',
			'Cloudinary Integration',
			'Cloudinary Integration',
			'manage_options',
			'pbc-cloudinary-settings',
			[
				__class__, 
				'create_admin_popup_page'
			]
		);
	}

	public static function register_settings() {
		 register_setting(
			'pbc_cloudinary_option_group',
			'pbc_cloudinary'
		);
	}

	public static function get_settings_from_env() {
		$options = [];
		foreach([
			'CLOUDINARY_ENABLED',
			'CLOUDINARY_CLOUD_NAME',
			'CLOUDINARY_AUTO_UPLOAD_MAPPING_FOLDER',
			'CLOUDINARY_DEFAULT_SETTINGS',
			'PRODUCTION_DOMAIN_SWITCH',
			'PRODUCTION_DOMAIN',
			'ADMIN_SWITCH'
		] as $setting){
			if(isset($_SERVER[$setting]) && $_SERVER[$setting]){
				$options[strtolower($setting)] = $_SERVER[$setting];
			}
		}
		return $options;
	}

    public static function get_settings() {
        if(empty(self::$options)) {
            self::$options = get_option( 'pbc_cloudinary' );
        }
		if(!self::$options){
			self::$options = self::get_settings_from_env();
		}
        return self::$options;
    }

	public static function create_admin_popup_page() {
        $settings = self::get_settings();
		?>

		<div class="wrap">
			<h2>Cloudinary Integration Settings</h2>
			<form method="post" action="options.php">
				<?php
				// This prints out all hidden setting fields
				settings_fields( 'pbc_cloudinary_option_group' );
				do_settings_sections( 'pbc-cloudinary-settings' );
				submit_button(); 
				?>
			</form>
		</div>

		<?php
	}

	public static function register_group_settings() {
		add_settings_section(
			'pbc-cloudinary-settings-section',  							// slug
			'Cloudinary Settings',											// title
			array( __class__, 'main_description' ),	// callback to show the descripion
			'pbc-cloudinary-settings'									// page to show the settings on
		);
		add_settings_field(
			'cloudinary_enabled', 									// slug
			'Cloudinary enabled?', 									// tite
			array( __class__, 'field_create_toggle' ), 			// callback
			'pbc-cloudinary-settings', 									// page to show the setting on
			'pbc-cloudinary-settings-section',							// section for the
			array('option_group' => 'pbc_cloudinary', 'option_name' => 'cloudinary_enabled', 'class' => 'regular-text', 'default' => false)
		);
		add_settings_field(
			'cloudinary_cloud_name', 									// slug
			'Cloud Name', 									// tite
			array( __class__, 'field_create_standard_input' ), 			// callback
			'pbc-cloudinary-settings', 									// page to show the setting on
			'pbc-cloudinary-settings-section',							// section for the
			array('option_group' => 'pbc_cloudinary', 'option_name' => 'cloudinary_cloud_name', 'class' => 'regular-text', 'default' => '', 'option_type' => 'text')
		);
		add_settings_field(
			'cloudinary_auto_upload_mapping_folder', 									// slug
			'Cloudinary auto upload folder', 									// tite
			array( __class__, 'field_create_standard_input' ), 			// callback
			'pbc-cloudinary-settings', 									// page to show the setting on
			'pbc-cloudinary-settings-section',							// section for the
			array('option_group' => 'pbc_cloudinary', 'option_name' => 'cloudinary_auto_upload_mapping_folder', 'class' => 'regular-text', 'default' => 'media', 'option_type' => 'text')
		);
		add_settings_field(
			'cloudinary_default_settings', 									// slug
			'Cloudinary default image settings', 									// tite
			array( __class__, 'field_create_standard_input' ), 			// callback
			'pbc-cloudinary-settings', 									// page to show the setting on
			'pbc-cloudinary-settings-section',							// section for the
			array('option_group' => 'pbc_cloudinary', 'option_name' => 'cloudinary_default_settings', 'class' => 'regular-text', 'default' => 'f_auto/q_auto:best/dpr_auto/', 'option_type' => 'text')
		);
        /*add_settings_field(
			'cloudinary_api_key', 									// slug
			'API Key', 									// tite
			array( __class__, 'field_create_standard_input' ), 			// callback
			'pbc-cloudinary-settings', 									// page to show the setting on
			'pbc-cloudinary-settings-section',							// section for the
			array('option_group' => 'pbc_cloudinary', 'option_name' => 'cloudinary_api_key', 'class' => 'regular-text', 'default' => '', 'option_type' => 'text')
		);
        add_settings_field(
			'cloudinary_api_secret', 									// slug
			'API Secret', 									// tite
			array( __class__, 'field_create_standard_input' ), 			// callback
			'pbc-cloudinary-settings', 									// page to show the setting on
			'pbc-cloudinary-settings-section',							// section for the
			array('option_group' => 'pbc_cloudinary', 'option_name' => 'cloudinary_api_secret', 'class' => 'regular-text', 'default' => '', 'option_type' => 'password')
		);*/
        add_settings_section(
			'pbc-cloudinary-settings-section-dev',  							// slug
			'Developer options',											// title
			array( __class__, 'dev_description' ),	// callback to show the descripion
			'pbc-cloudinary-settings'									// page to show the settings on
		);
        add_settings_field(
			'production_domain_switch', 									// slug
			'Overwrite the local domain with the live environment', 									// tite
			array( __class__, 'field_create_toggle' ), 			// callback
			'pbc-cloudinary-settings', 									// page to show the setting on
			'pbc-cloudinary-settings-section-dev',							// section for the
			array('option_group' => 'pbc_cloudinary', 'option_name' => 'production_domain_switch', 'class' => 'regular-text', 'default' => false)
		);
        add_settings_field(
			'production_domain', 									// slug
			'Root url of the production environment (please include https://)', 									// tite
			array( __class__, 'field_create_standard_input' ), 			// callback
			'pbc-cloudinary-settings', 									// page to show the setting on
			'pbc-cloudinary-settings-section-dev',							// section for the
			array('option_group' => 'pbc_cloudinary', 'option_name' => 'production_domain', 'class' => 'regular-text', 'default' => '', 'option_type' => 'text')
		);
		add_settings_field(
			'admin_switch', 									// slug
			'Apply filters to admin', 									// tite
			array( __class__, 'field_create_toggle' ), 			// callback
			'pbc-cloudinary-settings', 									// page to show the setting on
			'pbc-cloudinary-settings-section-dev',							// section for the
			array('option_group' => 'pbc_cloudinary', 'option_name' => 'admin_switch', 'class' => 'regular-text', 'default' => false)
		);
	}

	public static function main_description() {
		echo 'Settings and control for the Cloudinary integration. You can find a full list of <a href="https://cloudinary.com/documentation/transformation_reference" target="_blank">Cloudinary\'s available image settings here</a>.';
	}

    public static function dev_description() {
		echo 'Developer Settings for testing cloudinary functionality mimicing the live environment.';
	}

	public static function field_create_toggle($args) {

		if(!isset(self::$options[$args['option_name']])) {
			if(empty(self::$options) && isset($args['default']) && $args['default'] === true) {
				$value = true;
			} else {
				$value = false;
			}
		} else {
			$value = filter_var(self::$options[$args['option_name']], FILTER_VALIDATE_BOOLEAN);
		}

		echo '<input type="checkbox" id="'. $args['option_name'].'" name="'.$args['option_group'].'['.$args['option_name'].']" '. ($value ? 'checked="checked"' : '') . ' class="'.$args['class'].'" />';
	}

    public static function field_create_standard_input($args) {
		printf(
			'<input type="'.$args['option_type'].'" id="'.$args['option_name'].'" name="'.$args['option_group'].'['.$args['option_name'].']" value="%s"  class="'.$args['class'].'" />',
			isset( self::$options[$args['option_name']] ) ? esc_attr( self::$options[$args['option_name']]) : (isset($args['default']) ? $args['default'] : '')
			);
	}

	public static function field_create_wysiwyg($args) {
		$value = '';
		if(isset(self::$options[$args['option_name']]) && self::$options[$args['option_name']]) {
			$value = self::$options[$args['option_name']];
		} else if(isset($args['default']) && $args['default']) {
			$value = $args['default'];
		}
		wp_editor($value,$args['option_name'],[
			'textarea_name' => $args['option_group'].'['.$args['option_name'].']',
			'media_buttons' => $args['media'],
			'tinymce' => array(
				'toolbar1' => 'undo redo | bold italic | link image | bullist numlist',
			),
		]);
	}
}