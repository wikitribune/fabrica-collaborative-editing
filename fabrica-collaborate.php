<?php
/*
Plugin Name: Fabrica Collaborate
Plugin URI:
Description:
Version: 0.0.1
Author: Fabrica
Author URI: https://fabri.ca/
Text Domain: fabrica-dashboard
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
*/

namespace Fabrica\Collaborate;

if (!defined('WPINC')) { die(); }

class Plugin {

	public function __construct() {

		// Exit now for non-admin requests
		if (!is_admin()) { return; }

		add_action('load-edit.php', array($this, 'disablePostLock'));
		add_action('load-post.php', array($this, 'disablePostLock'));
		add_filter('wp_insert_post_data', array($this, 'resolveEditConflicts'), 1, 2);
		add_action('edit_form_after_title', array($this, 'showDiff'));
		add_filter('gettext', array($this, 'alterText'), 10, 2);

		// Exit now if AJAX request, to register pure admin-only requests after
		if (wp_doing_ajax()) { return; }
	}

	public function disablePostLock() {
		$currentPostType = get_current_screen()->post_type;

		// Disable locking for page, post and some custom post type
		$collaborativePostTypes = array(
			'page',
			'post',
			'custom_post_type'
		);

		if (in_array($currentPostType, $collaborativePostTypes)) {
			add_filter('show_post_locked_dialog', '__return_false');
			add_filter('wp_check_post_lock_window', '__return_false');
			wp_deregister_script('heartbeat');
		}
	}

	public function resolveEditConflicts($data, $postarr) {

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return $data; }

		// Retrieve the saved version of the post being edited
		$savedPost = get_post($postarr['ID'], ARRAY_A);

		// Get the user ID of the last published version
		$latestEditor = $savedPost['post_author'];

		// Transient name
		$transient = 'conflict_' . $postarr['ID'] . '_' . get_current_user_id();

		// Set the post_author to the current user as a way of keeping track of which author did the last accepted edit
		// the _edit_last post_meta is no good because by this point it's already been changed
		// TODO: probably use our own post_meta instead, to avoid interfering with WP core stuff
		$data['post_author'] = get_current_user_id();

		// Check if there's a merge conflict between the saved version and the current version (and the authors are different)
		if ($latestEditor != get_current_user_id()) {
			if (get_transient($transient)) { // This means the revision has already been saved once, so let it through the second time
				delete_transient($transient);
			} else if (wp_text_diff($savedPost['post_content'], $data['post_content'])) {

				// Save the conflicted data in a transient based on the current post ID and current author ID
				set_transient($transient, stripslashes($data['post_content']), WEEK_IN_SECONDS);

				// Revert to saved version
				$data['post_author'] = $savedPost['post_author'];
				$data['post_content'] = $savedPost['post_content'];
			}
		}

		return $data;
	}

	public function showDiff() {
		global $post;
		$transient = 'conflict_' . $post->ID . '_' . get_current_user_id();
		if ($content = get_transient($transient)) {

			// Show the diff
			echo $this->renderDiff($post->post_content, $content);

			// Show the user's edit in the body field
			// TODO - replace with something more granular for the purposes of the conflict resolution?
			$post->post_content = $content;
		}
	}

	public function alterText($translation, $text) {
		if ($text == 'Update') {
			global $post;
			if (!$post) {
				return $translation;
			}
			$transient = 'conflict_' . $post->ID . '_' . get_current_user_id();
			if (get_transient($transient)) {
				return 'Resolve Merge Conflict';
			}
		}
		return $translation;
	}

	public function renderDiff($left, $right) {
		$args = array('title' => 'Your edit clashes with a recent edit by another author: please resolve the conflict and re-publish', 'title_left' => 'Currently published version', 'title_right' => 'Your version');

		if (!class_exists( 'WP_Text_Diff_Renderer_Table', false)) {
			require(ABSPATH . WPINC . '/wp-diff.php');
		}
		$left  = normalize_whitespace($left);
		$right = normalize_whitespace($right);

		$left  = explode("\n", $left);
		$right = explode("\n", $right);
		$diff = new \Text_Diff($left, $right);
		$renderer  = new \WP_Text_Diff_Renderer_Table($args);
		$diff = $renderer->render($diff);

		$r  = "<table class='diff'>\n";
		$r .= "<col class='content diffsplit left' /><col class='content diffsplit middle' /><col class='content diffsplit right' />";
		if ($args['title'] || $args['title_left'] || $args['title_right']) {
			$r .= "<thead>";
		}
		if ($args['title']) {
			$r .= "<tr class='diff-title'><th colspan='4'>$args[title]</th></tr>\n";
		}
		if ($args['title_left'] || $args['title_right'] ) {
			$r .= "<tr class='diff-sub-title'>\n";
			$r .= "\t<th>$args[title_left]</th>\n";
			$r .= "\t<td></td><th>$args[title_right]</th>\n";
			$r .= "</tr>\n";
		}
		if ($args['title'] || $args['title_left'] || $args['title_right']) {
			$r .= "</thead>\n";
		}
		$r .= "<tbody>\n$diff\n</tbody>\n";
		$r .= "</table>";
		$r .= '<style>table.diff { margin: 2rem 0; background-color: #fff; padding: 1rem 2rem; } table.diff td, table.diff th { font-family: inherit; }</style>';
		return $r;
	}
}

new Plugin();
