<?php

if (!defined('WPINC')) { die(); }

class PendingChanges {

	public function __construct() {

		if (!is_admin()) {
			// [TODO] move frontend hooks and functions to their own class?
			add_action('the_content', array($this, 'publishedRevisionContent'));
			add_action('the_title', array($this, 'publishedRevisionTitle'), 10, 2);
			add_action('acf/format_value_for_api', array($this, 'publishedRevisionField'), 10, 3);

			return;
		}

		add_action('acf/save_post', array($this, 'savePublishedRevision'), 20);
		// [TODO] show only for edit post
		add_action('post_submitbox_misc_actions', array($this, 'addButton'));
	}

	public function publishedRevisionContent($content) {
		$postID = get_the_ID();
		if (get_post_type($postID) != 'post') { return $content; }
		$revisionID = get_field('published_revision_id', $postID);
		if (!$revisionID) { return $content; }

		$contentRevision = get_post($revisionID);
		return $contentRevision->post_content;
	}

	public function publishedRevisionTitle($title, $postID) {
		if (get_post_type($postID) != 'post') { return $title; }
		$revisionID = get_field('published_revision_id', $postID);
		if (!$revisionID) { return $title; }

		$contentRevision = get_post($revisionID);
		return $contentRevision->post_title;
	}

	public function publishedRevisionField($value, $postID, $field) {
		if (get_post_type($postID) != 'post' || $field['name'] == 'published_revision_id') { return $value; }
		$revisionID = get_field('published_revision_id', $postID);
		if (!$revisionID) { return $value; }

		return get_field($field['name'], $revisionID);
	}

	public function savePublishedRevision($postID) {
		if (isset($_POST['pending-changes'])) {

			// Saving a draft: set pointer to currently published revision if necessary
			$revisionID = get_field('published_revision_id', $postID);
			if (!$revisionID) {

				// Get published revision
				$args = array(
					'orderby' => 'ID', // [REVIEW] use `data_modified` instead?
					'order' => 'DESC',
					'posts_per_page' => 2
				);
				$revisions = wp_get_post_revisions($postID, $args);

				if (count($revisions) < 2) {

					// No published revision
					return;
				}

				$revisionID = end($revisions)->ID;
				if ($revisionPointer = get_field('published_revision_id', $revisionID)) {

					// Last revision pointing to another revision (means another user saved a draft while editing this)
					$revisionID = $revisionPointer;
				}

				// Point to currently published revision
				update_field('field_59a9a32a205d5', $revisionID, $postID);
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
}

new PendingChanges();
