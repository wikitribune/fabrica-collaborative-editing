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

		add_action('load-edit.php', array($this, 'disableEditLock'));
		add_action('load-post.php', array($this, 'disablePostLock'));
		add_action('edit_form_top', array($this, 'cacheLastRevisionData'));
		add_filter('wp_insert_post_data', array($this, 'resolveEditConflicts'), 1, 2);
		add_action('edit_form_after_title', array($this, 'prepareDiff'));
		add_filter('gettext', array($this, 'changeLabels'), 10, 2);
		add_filter('heartbeat_received', array($this, 'filterHeartbeatResponse'), 999999, 3); // Needs to go later to override edit lock

		// Exit now if AJAX request, to register pure admin-only requests after
		if (wp_doing_ajax()) { return; }
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

	// Adds temporary where clause to exclude autosaves
	public function filterOutAutosaves($where) {
		global $wpdb;
		$where .= " AND " . $wpdb->prefix . "posts.post_name NOT LIKE '%-autosave-v1'";
		return $where;
	}

	// Completely disable Heartbeat on list page (to avoid 'X is editing' notifications)
	public function disableEditLock() {
		if (!in_array(get_current_screen()->post_type, self::$postTypesSupported)) { return; } // Exit for unsupported post types
		wp_deregister_script('heartbeat');
		add_filter('wp_check_post_lock_window', '__return_false');
	}

	// Leave Heartbeat active on post edit (so we can push edits for instant resolution) but override single-user lock
	public function disablePostLock() {
		if (!in_array(get_current_screen()->post_type, self::$postTypesSupported)) { return; } // Exit for unsupported post types
		add_filter('show_post_locked_dialog', '__return_false');
		add_action('admin_print_footer_scripts', array($this, 'handleHeartbeatResponse'), 20);
	}

	// Add last revision info as form data on post edit
	public function cacheLastRevisionData($post) {
		if (!$post) { return; }
		$latestRevision = $this->getLatestPublishedRevision($post->ID);
		if (!$latestRevision) { return; }
		echo '<input type="hidden" id="fc_last_revision_id" name="_fc_last_revision_id" value="' . $latestRevision->ID . '">';
		echo '<input type="hidden" id="fc_last_revision_author" name="_fc_last_revision_author" value="' . $latestRevision->post_author . '">';
	}

	// Check for intermediate edits and show a diff for resolution
	public function resolveEditConflicts($data, $rawData) {
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return $data; }
		if ($rawData['ID'] == 0) { return $data; }

		// Only proceed if there are revisions
		$latestRevision = $this->getLatestPublishedRevision($rawData['ID']);
		if (!$latestRevision) { return; }

		// And if we have data about the previous revision saved in the page when opened
		if (!array_key_exists('_fc_last_revision_id', $rawData) || !array_key_exists('_fc_last_revision_author', $rawData)) {
			return $data;
		}

		// Define name of transient where we store the edit in case of a clash
		$transient = 'conflict_' . $rawData['ID'] . '_' . get_current_user_id();

		// Only check merge conflicts if there's been another edit by another user since we opened the page
		if ($latestRevision->ID == $rawData['_fc_last_revision_id'] && $rawData['_fc_last_revision_author'] == get_current_user_id()) {
			delete_transient($transient);
			return $data;
		}

		// Retrieve the saved content of the post being edited, for the diff
		$savedPost = get_post($rawData['ID'], ARRAY_A);

		// If we have a transient already saved (and there isn't yet another revision), we're assuming this is an approved merge conflict
		if (get_transient($transient) && $latestRevision->ID == $rawData['_fc_last_revision_id']) {

			// TODO: add more sanitization and checks here, as well as more sophisticated controls for merge resolution
			delete_transient($transient);

		// Otherwise do the diff
		} else if (wp_text_diff($savedPost['post_content'], $data['post_content'])) {

			// Save the conflicted data in a transient based on the current post ID and current author ID
			set_transient($transient, stripslashes($data['post_content']), WEEK_IN_SECONDS);

			// Revert to previously saved version
			$data['post_content'] = $savedPost['post_content'];
		}

		// Return it for saving
		return $data;
	}

	// Show diff if relevant
	public function prepareDiff() {
		global $post;
		$transient = 'conflict_' . $post->ID . '_' . get_current_user_id();
		$content = get_transient($transient);

		// Leave if no transient (cached changes) set
		if ($content === false) { return; }

		// Render diff
		// [TODO] UI for granular merge conflict resolution (per paragraph)
		echo $this->renderDiff($post->post_content, $content);

		// Show the user's edit in the body field
		$post->post_content = $content;
	}

	// Render the diff
	public function renderDiff($left, $right) {
		$args = array(
			'title' => 'Your suggested edit clashes with a recent edit by another author: please resolve the conflict and re-publish',
			'title_left' => 'Latest published version',
			'title_right' => 'Your suggested edit'
		);

		if (!class_exists('WP_Text_Diff_Renderer_Table', false)) {
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
		$r .= "<style>table.diff { margin: 2rem 0; } table.diff th { font-family: inherit; } table.diff td { font-family: Georgia; font-size: 1rem; } table.diff .diff-title th { font-size: 16px; }</style>";
		return $r;
	}

	// Change label of Update button to suit our workflow
	public function changeLabels($translation, $text) {
		if ($text == 'Update') {
			global $post;
			if (!$post) {
				return $translation;
			}
			$transient = 'conflict_' . $post->ID . '_' . get_current_user_id();
			if (get_transient($transient)) {
				return 'Resolve Edit Conflict';
			}
		}
		return $translation;
	}

	// Filter information sent back to browser in Heartbeat
	public function filterHeartbeatResponse($response, $data, $screenID) {

		// Only modify repsonse on screens where we have a custom Heartbeat (single post)
		if (!isset($data['fabrica-collaborate'])) {
			return $response;
		}

		// Override and thereby disable edit lock by eliminating the data sent
		unset($response['wp-refresh-post-lock']);

		// Add custom data
		// $response['data'] = $data;

		// Send the latest revision of current post which will be compared to the cached one to see if it's changed while editing
		$response['fc_last_revision_id'] = $this->getLatestPublishedRevision($data['fabrica-collaborate']['post_id'])->ID;
		return $response;
	}

	// Process information received from server in Heartbeat
	public function handleHeartbeatResponse() {
		?><script>

			// Send data to Heartbeat if needed (we might not need it)
			// Gets removed from the queue after once
			wp.heartbeat.enqueue('fabrica-collaborate', { 'post_id' : jQuery('#post_ID').val() }, true);

			// Listen for response
			jQuery(document).ready(function($) {
				$(document).on('heartbeat-tick', function(e, data) {
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
