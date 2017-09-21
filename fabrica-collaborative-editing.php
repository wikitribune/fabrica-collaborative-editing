<?php
/*
Plugin Name: Fabrica Collaborative Editing
Plugin URI:
Description:
Version: 0.0.2
Author: Fabrica
Author URI: https://fabri.ca/
Text Domain: fabrica-collaborative-editing
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
*/

namespace Fabrica\CollaborativeEditing;

if (!defined('WPINC')) { die(); }

class Plugin {

	public static $postTypesSupported = array('page', 'post', 'stories', 'projects');

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
		add_filter('wp_insert_post_data', array($this, 'checkEditConflicts'), 1, 2);
		add_action('edit_form_after_title', array($this, 'showResolutionHeader'));
		add_action('edit_form_after_editor', array($this, 'showResolutionFooter'));
	}

	// Generates a transient ID from a post ID and user ID
	public function generateTransientID($postID, $userID) {
		if (!$postID || !$userID) { return false; }
		return 'fce_edit_conflict_' . $postID . '_' . $userID;
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
		add_action('admin_print_footer_scripts', array($this, 'enqueueScript'), 20);
	}

	// Add last revision info as form data on post edit
	public function cacheLastRevisionData($post) {
		if (!$post) { return; } // Exit if some problem with the post
		if (!in_array($post->post_type, self::$postTypesSupported)) { return; } // Exit for unsupported post types
		$latestRevision = $this->getLatestPublishedRevision($post->ID);
		if (!$latestRevision) { return; }
		echo '<input type="hidden" id="fce_last_revision_id" name="_fce_last_revision_id" value="' . $latestRevision->ID . '">';
	}

	// Check for intermediate edits and show a diff for resolution
	public function checkEditConflicts($data, $rawData) {

		// Don't interfere with autosaves - shouldn't happen anyway since this hook is excluded from AJAX requests, but just in case
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) { return $data; }

		// Occasionally seems to get called with an ID of 0, escape early
		if ($rawData['ID'] == 0) { return $data; }

		// Only proceed if we've received the cached data about the previous revision (this will exclude unsupported post types)
		if (!array_key_exists('_fce_last_revision_id', $rawData)) { return $data; }

		// ... and if the current post actually has revisions
		$latestRevision = $this->getLatestPublishedRevision($rawData['ID']);
		if (!$latestRevision) { return $data; }

		// Define name of transient where we store the edit in case of a clash
		$transientID = $this->generateTransientID($rawData['ID'], get_current_user_id());

		// If no new revision, this is either a regular save or a successful manual merge, so delete any cached changes and leave
		if ($latestRevision->ID == $rawData['_fce_last_revision_id']) {
			delete_transient($transientID);
			return $data;
		}

		// If still here, a new revision has been published, so check conflicts
		// Retrieve the saved content of the post being edited, for the diff
		// [TODO] support certain custom fields as well?
		$savedPost = get_post($rawData['ID'], ARRAY_A);

		// Check the diff to see if there's a conflict...
		if (wp_text_diff($savedPost['post_content'], $data['post_content'])) {

			// ... there is, so save the conflicted data in a transient based on the post ID and user ID
			set_transient($transientID, stripslashes($data['post_content']), WEEK_IN_SECONDS);

			// Revert to previously saved version for now - WP will not create a new revision
			$data['post_content'] = $savedPost['post_content'];
		}

		// Return data for saving
		return $data;
	}

	// Show header and steps for resolution
	public function showResolutionHeader($post) {
		if (!in_array(get_current_screen()->post_type, self::$postTypesSupported)) { return; } // Exit for unsupported post types

		$transientID = $this->generateTransientID($post->ID, get_current_user_id());
		$savedContent = get_transient($transientID);

		// Leave if no transient (cached changes) set
		if ($savedContent === false) { return; }

		// Display instructions and render diff
		?>
		<style>
			table.diff { padding: 0.5rem; background-color: #fff; }
			table.diff th { font-family: inherit; font-weight: normal; }
			table.diff td { font-family: Georgia; font-size: 1rem; padding: 0.25rem 0.5rem; }
			table.diff .diff-context { font-size: 13px; color: #999; padding: 0.5rem 0; }
			h3.resolution-header { margin-top: 2rem; font-size: 1.125rem; font-weight: normal; text-align: center; }
			h3.resolution-subhead { margin-top: 2rem; padding-top: 1rem; border-top: 1px solid #ddd; font-size: 1rem; text-align: center; }
			div.resolution-actions { margin-bottom: 3rem; text-align: center; }
		</style>
		<h3 class="resolution-header">Your proposed changes clash with recent edits by other users. To resolve the conflict:</h3>
		<h3 class="resolution-subhead">1. Review the differences between the latest submission and your own:</h3>
		<?php echo $this->renderDiff($post->post_content, $savedContent); ?>
		<h3 class="resolution-subhead">2. Revise your contribution to accommodate the changes made by other users:</h3><?php

		// Show the user's own edit in the body field
		$post->post_content = $savedContent;
	}

	// Show footer and buttons for resolution
	public function showResolutionFooter($post) {
		if (!in_array(get_current_screen()->post_type, self::$postTypesSupported)) { return; } // Exit for unsupported post types

		$transientID = $this->generateTransientID($post->ID, get_current_user_id());
		$savedContent = get_transient($transientID);

		// Leave if no transient (cached changes) set
		if ($savedContent === false) { return; }

		?><h3 class="resolution-subhead">3. Re-submit the edited version:</h3>
		<div class="resolution-actions"><?php submit_button('Resolve Edit Conflict', 'primary large', 'resolve-edit-conflict', false); ?></div><?php
	}

	// Render the diff
	public function renderDiff($left, $right) {
		$args = array(
			'title_left' => 'Latest submission',
			'title_right' => 'Your edit'
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
		// [TODO] reimplement wp_text_diff() locally using per-paragraph diffing
		// [TODO] UI for granular merge conflict resolution (per paragraph)
		$renderer = new \WP_Text_Diff_Renderer_Table($args);
		$diff = $renderer->render($diff);

		$output = '<table class="diff" id="diff">';
		$output .= '<col class="content diffsplit left"><col class="content diffsplit middle"><col class="content diffsplit right">';
		if (array_key_exists('title', $args) || array_key_exists('title_left', $args) || array_key_exists('title_right', $args)) {
			$output .= '<thead>';
		}
		if (array_key_exists('title', $args)) {
			$output .= '<tr class="diff-title" id="diff"><th colspan="3">' . $args['title'] . '</th></tr>';
		}
		if ($args['title_left'] || $args['title_right'] ) {
			$output .= '<tr class="diff-sub-title"><th>' . $args['title_left'] . '</th><td></td><th>' . $args['title_right'] . '</th></tr>';
		}
		if (array_key_exists('title', $args) || array_key_exists('title_left', $args) || array_key_exists('title_right', $args)) {
			$output .= '</thead>';
		}
		$output .= '<tbody>' . $diff . '</tbody>';
		$output .= '</table>';
		return $output;
	}

	// Filter information sent back to browser in Heartbeat
	public function filterHeartbeatResponse($response, $data, $screenID) {

		// Only modify response when we've been passed our own data
		if (!isset($data['fce'])) {
			return $response;
		}

		// Add custom data
		// $response['data'] = $data;

		// Send the latest revision of current post which will be compared to the cached one to see if it's changed while editing
		$latestRevision = $this->getLatestPublishedRevision($data['fce']['post_id']);
		if ($latestRevision) {
			$response['fce'] = array(
				'last_revision_id' => $latestRevision->ID,
				'last_revision_content' => apply_filters('the_content', $latestRevision->post_content)
			);
		}

		// Override and thereby disable edit lock by eliminating the data sent
		unset($response['wp-refresh-post-lock']);

		// Send back to the browser
		return $response;
	}

	// Process information received from server in Heartbeat
	// Plus other interactions
	// [TODO] move to JS file?
	public function enqueueScript() {
		?><script>
			jQuery(document).ready(function($) {

				// Don't show a warning when clicking the Resolve button
				$('#resolve-edit-conflict').click(function() { $(window).off('beforeunload'); });

				// Increase heartbeat to 5 seconds
				wp.heartbeat.interval('fast');

				// Send post ID with first tick
				wp.heartbeat.enqueue('fce', { 'post_id' : jQuery('#post_ID').val() }, true);

				// Listen for Heartbeat repsonses
				$(document).on('heartbeat-tick.fce', function(e, data) {

					// Re-send the post ID with each subsequent tick
					wp.heartbeat.enqueue('fce', { 'post_id' : jQuery('#post_ID').val() }, true);

					// [DEBUG] Log response
					console.log(data);

					// Check if revision has been update
					// [TODO] Force conflict resolution immediately
					if (data.fce.last_revision_id != $('#fce_last_revision_id').val()) {
						console.log('A new revision has been published while you have been editing.');
					}
				});
			});
			</script>
		<?php
	}
}
new Plugin();
