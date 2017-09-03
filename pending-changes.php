<?php

if (!defined('WPINC')) { die(); }

class PendingChanges {

	public function __construct() {

		if (!is_admin()) {
			// [TODO] move frontend hooks and functions to their own class?
			add_action('the_content', array($this, 'publishedRevisionContent'));
			add_action('the_title', array($this, 'publishedRevisionTitle'), 10, 2);
			add_action('acf/format_value_for_api', array($this, 'publishedRevisionField'), 10, 3); // ACF v4
			add_action('acf/format_value', array($this, 'publishedRevisionField'), 10, 3); // ACF v5+

			return;
		}

		add_action('acf/save_post', array($this, 'savePublishedRevision'), 20);
		// [TODO] show only for edit post
		add_action('post_submitbox_misc_actions', array($this, 'addButton'));
		add_action('wp_prepare_revision_for_js', array($this, 'prepareRevisionForJS'), 10, 3);
		add_action('admin_enqueue_scripts', array($this, 'loadScript'));
	}

	public function publishedRevisionContent($content) {
		$postID = get_the_ID();
		if (get_post_type($postID) != 'post') { return $content; }
		$publishedID = get_field('published_revision_id', $postID);
		if (!$publishedID) { return $content; }

		$contentRevision = get_post($publishedID);
		return $contentRevision->post_content;
	}

	public function publishedRevisionTitle($title, $postID) {
		if (get_post_type($postID) != 'post') { return $title; }
		$publishedID = get_field('published_revision_id', $postID);
		if (!$publishedID) { return $title; }

		$contentRevision = get_post($publishedID);
		return $contentRevision->post_title;
	}

	public function publishedRevisionField($value, $postID, $field) {
		if (get_post_type($postID) != 'post' || $field['name'] == 'published_revision_id') { return $value; }
		$publishedID = get_field('published_revision_id', $postID);
		if (!$publishedID) { return $value; }

		return get_field($field['name'], $publishedID);
	}

	public function savePublishedRevision($postID) {
		if (isset($_POST['pending-changes'])) {

			// Saving a draft: set pointer to currently published revision if necessary
			$publishedID = get_field('published_revision_id', $postID);
			if (!$publishedID) {

				// Get published revision
				$args = array(
					'posts_per_page' => 2
				);
				$revisions = wp_get_post_revisions($postID, $args);

				if (count($revisions) < 2) {

					// No published revision
					return;
				}

				$publishedID = end($revisions)->ID;
				if ($revisionPointer = get_field('published_revision_id', $publishedID)) {

					// Last revision pointing to another revision (means another user saved a draft while editing this)
					$publishedID = $revisionPointer;
				}

				// Point to currently published revision
				update_field('field_59a9a32a205d5', $publishedID, $postID);
			}
		} else {

			// Publishing post: clear the published revision pointer since post itself is published
			update_field('field_59a9a32a205d5', '', $postID);
		}
	}

	public function addButton() {
		// [TODO] show only for editors (and possibly original author)
		// [FIXME] fix ID and styling
		$html  = '<div id="major-publishing-actions" style="overflow:hidden">';
		$html .= '<div id="publishing-action">';
		$html .= '<input type="submit" name="pending-changes" id="pending-changes-submit" value="Save draft" class="button-primary">';
		$html .= '</div>';
		$html .= '</div>';
		echo $html;
	}

	public function prepareRevisionForJS($revisionsData, $revision, $post) {

		// Set published flag in the revision pointed by the post
		$publishedID = get_field('published_revision_id', $post->ID) ?: $post->ID;
		$revisionsData['pending'] = false;
		if ($revision->ID == $publishedID) {
			$revisionsData['current'] = true;
		} else {
			$revisionsData['current'] = false;
			$published = get_post($publishedID);
			if (strtotime($revision->post_date) > strtotime($published->post_date)) {
				$revisionsData['pending'] = true;
			}
		}

		return $revisionsData;
	}

	public function loadScript($hook_suffix) {
		if ($hook_suffix == 'revision.php') {
			wp_enqueue_script('fc-pending-changes', plugin_dir_url( __FILE__ ) . 'js/pending-changes.js', array('jquery', 'revisions'));
		}
	}
}

new PendingChanges();
