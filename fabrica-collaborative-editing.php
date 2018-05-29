<?php
/*
Plugin Name: Fabrica Collaborative Editing
Plugin URI: https://github.com/wikitribune/fabrica-collaborative-editing
Description: Makes WordPress more Wiki-like by allowing more than one person to edit the same Post, Page, or Custom Post Type at the same time. When edits conflict, helps users to view, compare, and merge changes before saving.
Version: 0.1.0
Author: Fabrica
Author URI: https://fabri.ca/
Text Domain: fabrica-collaborative-editing
License: MIT
License URI: https://opensource.org/licenses/MIT
*/

namespace Fabrica\CollaborativeEditing;

if (!defined('WPINC')) { die(); }

require_once('inc/singleton.php');

class Plugin extends Singleton {
	const MAIN_FILE = __FILE__;
}

if (is_admin()) {
	require_once('inc/base.php');
	require_once('inc/settings.php');
}
