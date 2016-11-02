<?php
/*
Plugin Name: ILAB Stem App Framework
Plugin URI: http://interfacelab.com/stem
Description: Framework for building applications using Wordpress and Symfony
Author: Jon Gilkison
Version: 0.1
Author URI: http://interfacelab.com
*/

require_once('vendor/autoload.php');


include 'Classes/Core/System.php';

define('ILAB_STEM_DIR',dirname(__FILE__));
define('ILAB_STEM_VIEW_DIR',ILAB_STEM_DIR.'/views');

$plug_url = plugin_dir_url( __FILE__ );
define('ILAB_STEM_PUB_JS_URL',$plug_url.'public/js');
define('ILAB_STEM_PUB_CSS_URL',$plug_url.'public/css');

