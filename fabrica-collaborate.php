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

	public static $postTypesSupported = array('page', 'post');

	public function __construct() {

		// Exit now for non-admin requests
		if (!is_admin()) { return; }

		// Heartbeat response is called via AJAX, so wouldn't get loaded via `load-post.php` hooks
		// Also, high priority because needs to be applied late to override/cancel edit lock data
		add_filter('heartbeat_received', array($this, 'filterHeartbeatResponse'), 999999, 3);

		// Exit now if AJAX request, to hook admin-only requests after
		if (wp_doing_ajax()) { return; }

		// Main hooks
		add_action('load-edit.php', array($this, 'disablePostListLock'));
		add_action('load-post.php', array($this, 'disablePostEditLock'));
		add_action('edit_form_top', array($this, 'cacheLastRevisionData'));
		add_filter('wp_insert_post_data', array($this, 'resolveEditConflicts'), 1, 2);
		add_action('edit_form_after_title', array($this, 'prepareDiff'));
	}

	// Generates a transient ID from a post ID and user ID
	public function generateTransientID($postID, $userID) {
		if (!$postID || !$userID) { return false; }
		return 'fc_edit_conflict_' . $postID . '_' . $userID;
	}

	// Returns the latest published revision, excluding autosaves
	public function getLatestPublishedRevision($postID) {
		$args = array('posts_per_page', 1, 'suppress_filters' => false);
		add_filter('posts_where', array($this, 'filterOutAutosaves'), 10, 1);
		$revisions = wp_get_post_revisions($postID, $args);
		remove_filter('posts_where', array($this, 'filterOutAutosaves'));
		if (count($revisions) == 0) { return false; }
		return current($revisions);
	}

	// Adds the temporary WHERE clause needed to exclude autosave from the revisions list
	public function filterOutAutosaves($where) {
		global $wpdb;
		$where .= " AND " . $wpdb->prefix . "posts.post_name NOT LIKE '%-autosave-v1'";
		return $where;
	}

	// Completely disable Heartbeat on list page (to avoid 'X is editing' notifications)
	public function disablePostListLock() {
		if (!in_array(get_current_screen()->post_type, self::$postTypesSupported)) { return; } // Exit for unsupported post types
		wp_deregister_script('heartbeat');
		add_filter('wp_check_post_lock_window', '__return_false');
	}

	// Leave Heartbeat active on post edit (so we can push edits for instant resolution) but override single-user lock
	public function disablePostEditLock() {
		if (!in_array(get_current_screen()->post_type, self::$postTypesSupported)) { return; } // Exit for unsupported post types
		add_filter('show_post_locked_dialog', '__return_false');
		add_action('admin_print_footer_scripts', array($this, 'handleHeartbeatResponse'), 20);
	}

	// Add last revision info as form data on post edit
	public function cacheLastRevisionData($post) {
		if (!$post) { return; } // Exit if some problem with the post
		if (!in_array($post->post_type, self::$postTypesSupported)) { return; } // Exit for unsupported post types
		$latestRevision = $this->getLatestPublishedRevision($post->ID);
		if (!$latestRevision) { return; }
		echo '<input type="hidden" id="fc_last_revision_id" name="_fc_last_revision_id" value="' . $latestRevision->ID . '">';
	}

	// Check for intermediate edits and show a diff for resolution
	public function resolveEditConflicts($data, $rawData) {

		// Don't interfere with autosaves
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return $data; }

		// Occasionally seems to get called with an ID of 0, escape early
		if ($rawData['ID'] == 0) { return $data; }

		// Only proceed if we've received the cached data about the previous revision (this will exclude unsupported post types)
		if (!array_key_exists('_fc_last_revision_id', $rawData)) {
			return $data;
		}

		// ... and if the current post actually has revisions
		$latestRevision = $this->getLatestPublishedRevision($rawData['ID']);
		if (!$latestRevision) { return; }

		// Define name of transient where we store the edit in case of a clash
		$transientID = $this->generateTransientID($rawData['ID'], get_current_user_id());

		// Retrieve the saved content of the post being edited, for the diff
		$savedPost = get_post($rawData['ID'], ARRAY_A);

		// If a new revision has been published since we started editing...
		if ($latestRevision->ID != $rawData['_fc_last_revision_id']) {

			// ... check for an edit conflict
			if (wp_text_diff($savedPost['post_content'], $data['post_content'])) {

				// There is one, so save the conflicted data in a transient based on the post ID and user ID
				set_transient($transientID, stripslashes($data['post_content']), WEEK_IN_SECONDS);

				// Revert to previously saved version for now - WP will not create a new revision
				$data['post_content'] = $savedPost['post_content'];
			}
		} else {

			// This is either a normal save or a successful manual merge, so delete any cached changes
			delete_transient($transientID);
		}

		// Return data for saving
		return $data;
	}

	// Show diff if relevant
	public function prepareDiff() {
		global $post;
		$transientID = $this->generateTransientID($post->ID, get_current_user_id());
		$savedContent = get_transient($transientID);

		// Leave if no transient (cached changes) set
		if ($savedContent === false) { return; }

		// Render diff
		// [TODO] UI for granular merge conflict resolution (per paragraph)
		echo $this->renderDiff($post->post_content, $savedContent);

		// Show the user's edit in the body field
		$post->post_content = $savedContent;
	}

	// Render the diff
	public function renderDiff($left, $right) {
		$args = array(
			'title' => 'Your suggested edit clashes with a recent edit by another author: please resolve the conflict and re-publish',
			'title_left' => 'Latest published version',
			'title_right' => 'Your suggested edit'
		);

		// [TODO] tidy this up a lot
		if (!class_exists('WP_Text_Diff_Renderer_Table', false)) {
			require(ABSPATH . WPINC . '/wp-diff.php');
		}
		$left = normalize_whitespace($left);
		$right = normalize_whitespace($right);

		$left = explode("\n", $left);
		$right = explode("\n", $right);
		$diff = new \Text_Diff($left, $right);

		// [TODO] Traverse $diff directly and remove need for WP_Text_Diff_Renderer_Table
		$renderer = new \WP_Text_Diff_Renderer_Table($args);
		$diff = $renderer->render($diff);

		$r = "<table class='diff'>\n";
		$r .= "<col class='content diffsplit left'><col class='content diffsplit middle'><col class='content diffsplit right'>";
		if ($args['title'] || $args['title_left'] || $args['title_right']) {
			$r .= "<thead>";
		}
		if ($args['title']) {
			$r .= "<tr class='diff-title'><th colspan='3'>$args[title]</th></tr>\n";
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
		$r .= "<style>table.diff { margin: 2rem 0; } table.diff th { font-family: inherit; } table.diff td { font-family: Georgia; font-size: 1rem; } table.diff .diff-title th { font-size: 16px; }</style>";
		return $r;
	}

	// Filter information sent back to browser in Heartbeat
	public function filterHeartbeatResponse($response, $data, $screenID) {

		// Only modify response when we've been passed our own data
		if (!isset($data['fabrica-collaborate'])) {
			return $response;
		}

		// Add custom data
		// $response['data'] = $data;

		// Send the latest revision of current post which will be compared to the cached one to see if it's changed while editing
		$latestRevision = $this->getLatestPublishedRevision($data['fabrica-collaborate']['post_id']);
		if ($latestRevision) {
			$response['fabrica-collaborate'] = array(
				'fc_last_revision_id' => $latestRevision->ID
			);
		}

		// Override and thereby disable edit lock by eliminating the data sent
		unset($response['wp-refresh-post-lock']);

		// Send back to the browser
		return $response;
	}

	// Process information received from server in Heartbeat
	public function handleHeartbeatResponse() {
		?><script>
			jQuery(document).ready(function($) {

				// Send post ID with first tick
				wp.heartbeat.enqueue('fabrica-collaborate', { 'post_id' : jQuery('#post_ID').val() }, true);

				// Re-send the post ID with subsequent ticks
				$(document).on('heartbeat-tick.fabrica-collaborate', function(e, data) {
					wp.heartbeat.enqueue('fabrica-collaborate', { 'post_id' : jQuery('#post_ID').val() }, true);
					console.log(data);
					// if (data.fc_last_revision_id != $('#fc_last_revision_id').val()) {
						// alert('A new revision has been published while you have been editing.');
					// }
				});
			});
			</script>
		<?php
	}
}
new Plugin();
