<?php

/**
 * Plugin Name: Cloudinary Integration
 * Plugin URI: https://poweredbycoffee.co.uk
 * Description: Integrate your site with your Cloudinary account
 * Version: 0.0.2
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
                if(is_multisite()){
                    switch_to_blog(1);
                    self::$uploads_dir = wp_upload_dir();
                    restore_current_blog();
                }
                if(isset(self::$options['cloudinary_default_settings'])){
                    self::$basic_cloudinary_conversions = self::$options['cloudinary_default_settings'];
                }
                //add_filter( 'wp_get_attachment_url', array( $this, 'filter_attachment_url' ), 99, 2 ); Too early in the chain and without any sizing context
                add_filter( 'wp_get_attachment_image_src', array( $this, 'filter_attachment_src' ), 99, 4 ); 
                add_filter( 'wp_calculate_image_srcset', array( $this, 'filter_srcset_urls' ), 99, 5 );
                add_filter( 'render_block_core/image', array( $this, 'parse_core_image_block' ), 99, 3 );
                add_action( 'init', array( $this, 'add_image_sizes' ));
            }
        }
	}

    public function add_image_sizes() {
        if( isset( self::$custom_face_crop_image_sizes ) && !empty( self::$custom_face_crop_image_sizes ) ){
            foreach( self::$custom_face_crop_image_sizes as $size => $details ){
                add_image_size( $size, $details['width'], $details['height'], true );
            }
        }
    }

    public function parse_core_image_block( $block_content, $parsed, $block_class ) {
        $size = $parsed['attrs']['sizeSlug'];
        $id = $parsed['attrs']['id'];
        $matches = array();
        preg_match('/src="(.*?)"/',$block_content,$matches);
        if(isset($matches[1])) {
            $details = wp_get_attachment_metadata($id);
            if($details){
                $modifications = $this->create_sizing_filters($size);
                $url = $this->filter_attachment_url($details['file'], $id, $modifications);
                $block_content = str_replace($matches[1], $url, $block_content);
            }
        }

        return $block_content;
    }

    public function filter_attachment_src($image, $attachment_id, $size, $icon) {
        $extra_options = '';
        list( $src, $width, $height, $crop ) = $image;

        if( isset($size) && array_key_exists( $size, self::$custom_face_crop_image_sizes )){
            $size = self::$custom_face_crop_image_sizes[$size];
            $extra_options = "c_lfill,h_".$size['height'].",w_".$size['width'].",g_faces";
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

    public function create_sizing_filters( $size, $attachment_id = false ){
        $global_sizes = $this->get_registered_image_sizes();
        //for standalone urls without any size definition
        if($attachment_id) {
            $raw = wp_get_attachment_metadata($attachment_id);
            $url = $raw['file'];
            $size_widths = array_filter(array_combine(array_keys($raw['sizes']), array_column($raw['sizes'], 'width')));
            $size = array_search($size,$size_widths);
        }
        $modifications = '';
        if(isset($global_sizes[$size])){
            $compass = '';
            $cropping = isset($global_sizes[$size]) && isset($global_sizes[$size]['crop']) ? $global_sizes[$size]['crop'] : false;
            if(is_array($cropping)) {
                $compass = $this->convert_to_compass($cropping);
            }
            $modifications = 'c_'.($cropping !== false ? 'lfill' : 'fit').$compass.',h_'.$global_sizes[$size]['height'].',w_'.$global_sizes[$size]['width'].'/';
        }
        return $modifications;
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

        //SVG bypass
        $ft = wp_check_filetype($url);
        if( is_array($ft) && isset($ft['ext']) && $ft['ext'] === 'svg' ){
            if(isset(self::$options['production_domain_switch'])) {
                $url = str_replace(site_url(), rtrim(self::$options['production_domain'], '\/\\'), $url);
            }
            return $url;
        }

        $finding = array(self::$uploads_dir['baseurl']);
        if(isset(self::$options['production_domain_switch'])) {
            $finding[] = rtrim(self::$options['production_domain'], '\/\\');
        }
        $url = str_replace( $finding, '', $url);

        //try to prevent image sized files being sent to Cloudinary eg filename-WIDTHxHEIGHT.jpg
        $matches = array();
        preg_match('/-(\d*)x(\d*)./',$url,$matches);
        if(isset($matches[0]) && isset($matches[1])){
            if(!$extra_cloudinary_options) $extra_cloudinary_options = $this->create_sizing_filters($matches[1],$attachment_id);
        }

        $final_url = self::$cloudinary_base_url.self::$basic_cloudinary_conversions.$extra_cloudinary_options.self::$cloudinary_sync_folder.$url;

        return $final_url;
    }

    public function filter_srcset_urls($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        $sizes = $image_meta['sizes'];
        $modified_sources = array();
        /*var_dump($sources);
        echo '<br><br>';
        var_dump($size_array);
        echo '<br><br>';
        var_dump($image_src);
        echo '<br><br>';
        var_dump($image_meta);
        echo '<br><br>';
        var_dump($this->get_registered_image_sizes());
        echo '<br><br>';*/
        foreach($sizes as $size_name=>$size) {
            $modifications = $this->create_sizing_filters($size_name);
            $descriptor = isset($sources[$size['width']]) && isset($sources[$size['width']]['descriptor']) && ($sources[$size['width']]['descriptor'] === 'w' || $sources[$size['width']]['descriptor'] === 'x') ? $sources[$size['width']]['descriptor'] : 'w';
            $modified_sources[$size['width']] = array(
                'url' => $this->filter_attachment_url($image_meta['file'], $attachment_id, $modifications),
                'descriptor' => $descriptor,
                'value' => $sources[$size['width']]['value'] ?? $size['width']
            );
        }

        return $modified_sources;
    }

	private function admin_error($the_error) {
		$notice = '<div class="notice notice-error"><p>'.$the_error.'</p></div>';
	
		echo $notice;
	}
}

$cl = Cloudinary::instance();