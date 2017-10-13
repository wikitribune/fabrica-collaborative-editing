<?php
/*
Plugin Name: Fabrica Collaborative Editing
Plugin URI:
Description:
Version: 0.0.4
Author: Fabrica
Author URI: https://fabri.ca/
Text Domain: fabrica-collaborative-editing
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
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
