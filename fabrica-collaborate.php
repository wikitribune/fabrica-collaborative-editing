<?php
/*
Plugin Name: Fabrica Collaborate
Plugin URI:
Description:
Version: 0.0.1
Author: Fabrica
Author URI: https://fabri.ca/
Text Domain: fabrica-collaborate
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
		add_action('edit_form_top', array($this, 'cacheNumberRevisions'));
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

	public function cacheNumberRevisions($post) {
		if ($post && $revisions = wp_get_post_revisions($post->ID)) {
			echo '<input type="hidden" name="_number_revisions" value="' . count($revisions) . '">';
		}
	}

	public function resolveEditConflicts($data, $postarr) {

		// if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return $data; }
		if ($postarr['ID'] == 0) { return $data; }

		// Define transient where we store the edit in case of a clash
		$transient = 'conflict_' . $postarr['ID'] . '_' . get_current_user_id();

		// Only proceed to check merge conflicts if there's actually been another edit since we opened the page
		if ($revisions = wp_get_post_revisions($postarr['ID']) && array_key_exists('_number_revisions', $postarr)) {
			if (count($revisions) == $postarr['_number_revisions']) {
				delete_transient($transient);
				return $data;
			}
		}

		// Get the user ID of the last published version
		$latestEditor = get_post_meta($postarr['ID'], '_latest_editor', true);

		// If no latest editor, no one has made an edit yet, so save one now and leave
		if (!$latestEditor) {
			delete_transient($transient);
			update_post_meta($postarr['ID'], '_latest_editor', get_current_user_id());
			return $data;
		}

		// If published editor is current user, they're just editing their own content, so leave
		if ($latestEditor == get_current_user_id()) {
			delete_transient($transient);
			return $data;
		}

		// Retrieve the saved content of the post being edited, for the diff
		$savedPost = get_post($postarr['ID'], ARRAY_A);

		// Check if there's a merge conflict between the saved version and the current version (and the authors are different)
		if (get_transient($transient)) {

			// This means the revision has already been saved once, so let it through the second time
			// TODO: add more sanitization and checks here, as well as more sophisticated controls for merge resolution
			update_post_meta($postarr['ID'], '_latest_editor', get_current_user_id());
			delete_transient($transient);

		} else if (wp_text_diff($savedPost['post_content'], $data['post_content'])) {

			// Save the conflicted data in a transient based on the current post ID and current author ID
			set_transient($transient, stripslashes($data['post_content']), WEEK_IN_SECONDS);

			// Revert to saved version
			$data['post_content'] = $savedPost['post_content'];
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

	public function renderDiff($left, $right) {
		$args = array('title' => 'Your edit clashes with a recent edit by another author: please resolve the conflict and re-publish', 'title_left' => 'Currently published version', 'title_right' => 'Your version');

		if (!class_exists( 'WP_Text_Diff_Renderer_Table', false)) {
			require(ABSPATH . WPINC . '/wp-diff.php');
		}
		$left = normalize_whitespace($left);
		$right = normalize_whitespace($right);

		$left = explode("\n", $left);
		$right = explode("\n", $right);
		$diff = new \Text_Diff($left, $right);
		$renderer = new \WP_Text_Diff_Renderer_Table($args);
		$diff = $renderer->render($diff);

		$r = "<table class='diff'>\n";
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
		$r .= "<style>table.diff { margin: 2rem 0; background-color: #fff; padding: 1rem 2rem; } table.diff td, table.diff th { font-family: inherit; }</style>";
		return $r;
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
}

new Plugin();
