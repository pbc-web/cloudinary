<?php

/**
 * Plugin Name: Cloudinary Integration
 * Plugin URI: https://poweredbycoffee.co.uk
 * Description: Integrate your site with your Cloudinary account
 * Version: 0.0.4
 * Author: poweredbycoffee, chris-coffee
 * Author URI: https://poweredbycoffee.co.uk
 *
*/
namespace PBC\Cloudinary;

//require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/setup.php';


if( !defined('ABSPATH') || (!ABSPATH) ){
	return;
}

class Cloudinary {

	use \Caffeine\Core\Support\Traits\Singleton;

	static $config;
	static $options;
	static $admin_error = false;
	static $active = false;
    static $cloudinary_base_url = 'https://res.cloudinary.com/*|cloud_name|*/image/upload/';
    static $cloudinary_sync_folder = '';
    static $uploads_dir;
    static $site_url;
    static $all_sizes = false;
    static $basic_cloudinary_conversions = 'f_auto/q_auto:best/dpr_auto/';
    static $custom_face_crop_image_sizes = array(
        'contributor-large' => array(
            'width' => 300,
            'height' => 300
        ),
        'contributor-small' => array(
            'width' => 150,
            'height' => 150
        )
    );

	public function __construct() {
		self::$config = new AdminSetup;
		self::$options = self::$config::get_settings();
		if(isset(self::$options['cloudinary_enabled']) && 'on' === self::$options['cloudinary_enabled']){
            if(!isset(self::$options['cloudinary_cloud_name']) || !self::$options['cloudinary_cloud_name']) {
                $this->admin_error('The cloudinary integration will not become active until you have added your cloud name.');
            }else if(!isset(self::$options['cloudinary_auto_upload_mapping_folder']) || !self::$options['cloudinary_auto_upload_mapping_folder']){
                $this->admin_error('The cloudinary integration will not become active until you have added your auto upload mapping folder. For more information on this see <a href="https://cloudinary.com/documentation/cloudinary_glossary#auto_upload" target="_blank">here<a>');
            }else{
                self::$active = true;
                self::$cloudinary_sync_folder = str_replace('//','/',self::$options['cloudinary_auto_upload_mapping_folder'].'/');
                self::$cloudinary_base_url = str_replace('*|cloud_name|*',self::$options['cloudinary_cloud_name'],self::$cloudinary_base_url);
                self::$uploads_dir = wp_upload_dir();
                self::$site_url = get_home_url();
                if(is_multisite()){
                    switch_to_blog(1);
                    self::$uploads_dir = wp_upload_dir();
                    self::$site_url = get_home_url();
                    restore_current_blog();
                }
                if(isset(self::$options['cloudinary_default_settings'])){
                    self::$basic_cloudinary_conversions = self::$options['cloudinary_default_settings'];
                }
                //add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 99, 2 ); Too early in the chain and without any sizing context
                add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_attachment_src' ), 99, 4 ); 
                add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset_urls' ), 99, 5 );
                add_filter( 'render_block_core/image', array( $this, 'parse_core_image_block' ), 99, 3 );
                add_filter( 'render_block_core/cover', array( $this, 'parse_core_cover_block' ), 99, 3 );
                add_filter( 'wp_get_attachment_image_attributes', array( $this, 'filter_image_attributes' ), 99, 3 );
                add_action( 'init', array( $this, 'add_image_sizes' ));
                add_action( 'wp_head', array( $this, 'add_cloudinary_meta' ), 1);
            }
        }
	}

    public function add_cloudinary_meta() {
        echo '<meta http-equiv="delegate-ch" content="sec-ch-width https://res.cloudinary.com; sec-ch-dpr https://res.cloudinary.com; sec-ch-viewport-width https://res.cloudinary.com;">'; 
    }

    public function filter_image_attributes( $attr, $attachment, $size ) {
        if( 
            ( isset( self::$custom_face_crop_image_sizes ) && !empty( self::$custom_face_crop_image_sizes ) )
            && ( isset($size) && !is_array($size) && array_key_exists( $size, self::$custom_face_crop_image_sizes ) )
        ){
            $size_array = array( absint( self::$custom_face_crop_image_sizes[$size]['width'] ), absint( self::$custom_face_crop_image_sizes[$size]['height'] ) );
            $image_meta = wp_get_attachment_metadata( $attachment->ID );
            $new_sizes = array();
            $mime_type = $image_meta['sizes'][key($image_meta['sizes'])]['mime-type'];
            $file = $image_meta['original_image'] ?? $image_meta['file'];
            foreach(self::$custom_face_crop_image_sizes as $fc_size => $details){
                $new_sizes[$fc_size] = array_merge(
                    $details,
                    array(
                        'mime-type' => $mime_type,
                        'file' => str_replace('.', '-'.$details['width'].'x'.$details['height'].'.', $file),
                        'filesize' => 0
                    )
                );
            }
            $image_meta['sizes'] = $new_sizes;
            $srcset     = wp_calculate_image_srcset( $size_array, $attr['src'], $image_meta, $attachment->ID );
			$sizes      = wp_calculate_image_sizes( $size_array, $attr['src'], $image_meta, $attachment->ID );

			if ( $srcset && $sizes ) {
		    	$attr['srcset'] = $srcset;
				$attr['sizes'] = $sizes;
			}
        }

        return $attr;
    }

    public function add_image_sizes() {
        if( isset( self::$custom_face_crop_image_sizes ) && !empty( self::$custom_face_crop_image_sizes ) ){
            foreach( self::$custom_face_crop_image_sizes as $size => $details ){
                add_image_size( $size, $details['width'], $details['height'], true );
            }
        }
    }

    public function filtered_attachment_metadata($id, $site = '') {
        if($site) \Caffeine\App\MultiSite::instance()->switch_to_blog($site);
        $details = wp_get_attachment_metadata($id);
        if($site) \Caffeine\App\MultiSite::instance()->restore_blog();

        return $details;
    }

    public function parse_core_cover_block( $block_content, $parsed, $block_class ) {
        if(isset($parsed['attrs']['backgroundType']) && $parsed['attrs']['backgroundType'] === 'video'){ //Video background
            if(isset(self::$options['production_domain_switch']) && isset($parsed['attrs']['url'])) { //Pull videos from production if needed
                $prod_uploads = str_replace( self::$site_url, rtrim(self::$options['production_domain'], '\/\\'), self::$uploads_dir['baseurl']);
                $url = str_replace(self::$uploads_dir['baseurl'], $prod_uploads, $parsed['attrs']['url']);
                $block_content = str_replace($parsed['attrs']['url'], $url, $block_content);
            }
            return $block_content;
        }
        if(!isset($parsed['attrs']['url']) || !isset($parsed['attrs']['id'])){ //Either no image or missing details
            return $block_content;
        }

        $new_url = $this->filter_attachment_url( $parsed['attrs']['url'], $parsed['attrs']['id'] );
        $block_content = str_replace($parsed['attrs']['url'], $new_url, $block_content);

        return $block_content;
    }

    public function parse_core_image_block( $block_content, $parsed, $block_class ) {
        $size = $parsed['attrs']['sizeSlug'];
        $id = $parsed['attrs']['id'];
        $matches = array();
        preg_match('/src="(.*?)"/',$block_content,$matches);
        if(isset($matches[1])) {
            preg_match('/sites\/(\d*)\//',$matches[1],$sites);
            $site = isset($sites) && !empty($sites) && isset($sites[1]) ? $sites[1] : '';
            $details = $this->filtered_attachment_metadata($id, $site);
            if($details){
                $url_to_mod = isset($sites) && !empty($sites) ? $sites[0].$details['file'] : $details['file'];
                $modifications = $this->create_sizing_filters($size);
                $url = $this->filter_attachment_url($url_to_mod, $id, $modifications);
                $block_content = str_replace($matches[1], $url, $block_content);
            }
        }

        return $block_content;
    }

    public function filter_attachment_src($image, $attachment_id, $size, $icon) {
        $extra_options = '';
        list( $src, $width, $height, $crop ) = $image;

        if(is_array($size)){
            list( $size_w, $size_h ) = $size;
            $width = $size_w > 0 ? ',w_'.$size_w : '';
            $height = $size_h > 0 ? ',h_'.$size_h : '';
            $extra_options = 'c_limit'.$height.$width.'/';
            $size = $size[0];
            $image[1] = $size_w;
            $image[2] = $size_h;
        }

        if( isset($size) && array_key_exists( $size, self::$custom_face_crop_image_sizes )){
            $size = self::$custom_face_crop_image_sizes[$size];
            $extra_options = "c_lfill,h_".$size['height'].",w_".$size['width'].",g_faces/";
            $image[1] = $size['width'];
            $image[2] = $size['height'];
            $image[3] = true;
        }

        $new_url = $this->filter_attachment_url( $src, $attachment_id, $extra_options );
        $image[0] = $new_url;

        return $image;
    }

    public function get_registered_image_sizes() {
        if( !self::$all_sizes ){
            self::$all_sizes = wp_get_registered_image_subsizes();
        }
        return self::$all_sizes;
    }

    public function convert_to_compass($cropping){
        $converted = '';

        $positions = array('left','center','right','top','bottom');
        $directions = array('west','','east','north','south');

        if(is_array($cropping)) {
            foreach($cropping as $k => $crop) {
                $cropping[$k] = str_replace($positions,$directions,$crop);
            }
            $converted = ($cropping[1] ? '_'.$cropping[1] : '').($cropping[0] ? '_'.$cropping[0] : '');
        }

        return $converted ? 'g'.$converted.',' : '';
    }

    public function check_image_sizing( $url, $attachment_id ){
        $image_sizing = array(
            'url' => $url,
            'extra_cloudinary_options' => ''
        );

        $matches = array();
        preg_match('/-(\d*)x(\d*)./',$url,$matches);
        if(isset($matches[0]) && isset($matches[1]) && isset($matches[2])){
            $get_values = $this->create_sizing_filters($matches[1],$attachment_id,$url);
            $image_sizing['extra_cloudinary_options'] = is_array($get_values) ? $get_values['modifications'] : $get_values;
            if(is_array($get_values) && isset($get_values['url'])){
                $image_sizing['url'] = $get_values['url'];
            }
        }

        return $image_sizing;
    }

    public function create_sizing_filters( $size, $attachment_id = false, $include_url = '' ){
        $global_sizes = $this->get_registered_image_sizes();
        $file_path = '';
        //for standalone urls without any size definition
        if($attachment_id) {
            $raw = wp_get_attachment_metadata($attachment_id);
            $size_widths = array_filter(array_combine(array_keys($raw['sizes']), array_column($raw['sizes'], 'width')));
            $size = array_search($size,$size_widths);
            if($size && $include_url){
                preg_match('/\d*\/\d*\//',$raw['file'],$matches);
                $url_to_replace = isset($matches[0]) && $matches[0] ? str_replace($matches[0], '', $raw['file']) : $raw['file'];
                $file_path = str_replace($raw['sizes'][$size]['file'], $url_to_replace, $include_url);
            }
        }
        $modifications = '';
        if(isset($global_sizes[$size])){
            $compass = '';
            $cropping = isset($global_sizes[$size]) && isset($global_sizes[$size]['crop']) ? $global_sizes[$size]['crop'] : false;
            if(is_array($cropping)) {
                $compass = $this->convert_to_compass($cropping);
            }
            $width = $global_sizes[$size]['width'] > 0 ? ',w_'.$global_sizes[$size]['width'] : '';
            $height = $global_sizes[$size]['height'] > 0 ? ',h_'.$global_sizes[$size]['height'] : '';
            $modifications = 'c_'.($cropping !== false ? 'lfill' : 'limit').$compass.$height.$width.'/';
        }
        if($include_url && $attachment_id && $file_path){
            return array(
                'url' => $file_path,
                'modifications' => $modifications
            );
        }else{
            return $modifications;
        }
    }

    public function filter_attachment_url( $url, $attachment_id, $extra_cloudinary_options=[] ) {
        if(!self::$active){
            return $url;
        }
        if(!is_array(self::$uploads_dir) || !isset(self::$uploads_dir['baseurl'])){
            return $url;
        }
        if(is_admin() && (!isset(self::$options['admin_switch']) || !self::$options['admin_switch'])){
            return $url;
        }

        //svg and mp4 bypass
        $ft = wp_check_filetype($url);
        if( is_array($ft) && isset($ft['ext']) &&
            (
                $ft['ext'] === 'svg' ||
                $ft['ext'] === 'mp4'
            )
        ){
            if(isset(self::$options['production_domain_switch'])) {
                $prod_uploads = str_replace( self::$site_url, rtrim(self::$options['production_domain'], '\/\\'), self::$uploads_dir['baseurl']);
                $url = str_replace(self::$uploads_dir['baseurl'], $prod_uploads, $url);
            }
            return $url;
        }

        $finding = array(self::$uploads_dir['baseurl']);
        if(isset(self::$options['production_domain_switch'])) {
            $finding[] = rtrim(self::$options['production_domain'], '\/\\');
        }
        $url = str_replace( $finding, '', $url);

        //try to prevent image sized files being sent to Cloudinary eg filename-WIDTHxHEIGHT.jpg
        if(!$extra_cloudinary_options){
            $image_sizing = $this->check_image_sizing( $url, $attachment_id );
            $url = $image_sizing['url'] ?? $url;
            $extra_cloudinary_options = $image_sizing['extra_cloudinary_options'] ?? $extra_cloudinary_options;
        }

        $final_url = self::$cloudinary_base_url.self::$basic_cloudinary_conversions.$extra_cloudinary_options.self::$cloudinary_sync_folder.$url;

        return $final_url;
    }

    public function filter_srcset_urls($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        $sizes = $image_meta['sizes'];
        list($width, $height) = $size_array;
        $descriptor = isset($sources[$width]) && isset($sources[$width]['descriptor']) && ($sources[$width]['descriptor'] === 'w' || $sources[$width]['descriptor'] === 'x') ? $sources[$width]['descriptor'] : 'w';
        $image_basename = wp_basename( $image_meta['file'] );
        $dirname = _wp_get_attachment_relative_path( $image_meta['file'] );
        if ( $dirname ) {
            $dirname = trailingslashit( $dirname );
        }
        $upload_dir    = wp_get_upload_dir();
	    $image_baseurl = trailingslashit( $upload_dir['baseurl'] ) . $dirname;
        $image_path = $image_baseurl.$image_basename;

        $modified_sources = array(
            $width => array(
                'url' => $image_src,
                'descriptor' => $descriptor,
                'value' => $sources[$width]['value'] ?? $width
            )
        );

        /*
         * Images that have been edited in WordPress after being uploaded will
         * contain a unique hash. Look for that hash and use it later to filter
         * out images that are leftovers from previous versions.
         */
        $image_edited = preg_match( '/-e[0-9]{13}/', wp_basename( $image_src ), $image_edit_hash );
        
        foreach($sizes as $size_name=>$image) {
            // Filter out images that are from previous edits.
            if ( $image_edited && ! strpos( $image['file'], $image_edit_hash[0] ) ) {
                continue;
            }

            if ( wp_image_matches_ratio( $width, $height, $image['width'], $image['height'] ) ) {
                $modifications = $this->create_sizing_filters($size_name);
                $descriptor = isset($sources[$image['width']]) && isset($sources[$image['width']]['descriptor']) && ($sources[$image['width']]['descriptor'] === 'w' || $sources[$image['width']]['descriptor'] === 'x') ? $sources[$image['width']]['descriptor'] : 'w';
                $modified_sources[$image['width']] = array(
                    'url' => $this->filter_attachment_url($image_path, $attachment_id, $modifications),
                    'descriptor' => $descriptor,
                    'value' => $sources[$image['width']]['value'] ?? $image['width']
                );
            }
        }

        return $modified_sources;
    }

	private function admin_error($the_error) {
		$notice = '<div class="notice notice-error"><p>'.$the_error.'</p></div>';
	
		echo $notice;
	}
}

$cl = Cloudinary::instance();
