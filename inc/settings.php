<?php

namespace Fabrica\CollaborativeEditing;

if (!defined('WPINC')) { die(); }

require_once('singleton.php');
require_once('base.php');

class Settings extends Singleton {
	private $settings = array();

	public function init() {
		add_action('admin_init', array($this, 'registerSettings'));
		add_action('admin_menu', array($this, 'addSettingsPage'));
	}

	// Return plugin settings
	public function getSettings() {
		$this->settings = $this->settings ?: get_option('fce-settings');
		return $this->settings;
	}

	// Register settings page
	public function addSettingsPage() {
		add_options_page(
			'Fabrica Collaborative Editing Settings',
			'Collaborative Editing',
			'manage_options',
			'fce-settings',
			array($this, 'renderSettingsPage')
		);
	}

	// Render settings page
	public function renderSettingsPage() {
		?><div class="wrap">
			<h1><?php _e("Fabrica Collaborative Editing Settings", Base::DOMAIN); ?></h1>
			<form method="post" action="options.php"><?php
				settings_fields('fce-settings');
				do_settings_sections('fce-settings');
				submit_button();
			?></form>
		</div><?php
	}

	// Register and add settings
	public function registerSettings() {

		// Initialize
		register_setting(
			'fce-settings', // Option group
			'fce-settings', // Option name
			array($this, 'sanitizeSettings') // Sanitize
		);

		// Register section
		add_settings_section(
			'enable_collaboration', // ID
			_("Enable collaborative editing", Base::DOMAIN), // Title
			array($this, 'renderEnableCollaborativeEditingHeader'), // Callback
			'fce-settings' // Page
		);

		// Register setting for each post type
		$args = array('public' => true);
		$postTypes = get_post_types($args, 'objects');
		foreach ($postTypes as $postType) {
			if ($postType->name == 'attachment') { continue; }
			add_settings_field(
				$postType->name . '_collaboration_enabled', // ID
				__($postType->label, Base::DOMAIN), // Title
				array($this, 'renderModeSetting'), // Callback
				'fce-settings', // Page
				'enable_collaboration', // Section
				array('postType' => $postType)
			);
		}

		// Register section
		add_settings_section(
			'tracked_fields', // ID
			_("Fields to track", Base::DOMAIN), // Title
			array($this, 'renderConflictFieldsHeader'), // Callback
			'fce-settings' // Page
		);

		add_settings_field(
			'conflict_fields_acf', // ID
			'ACF fields', // Title
			array($this, 'renderAcfSetting'), // Callback
			'fce-settings', // Page
			'tracked_fields' // Section
		);
	}

	// Render enable header
	public function renderEnableCollaborativeEditingHeader() {
		echo '<p>' . __("Choose which post types can be collaboratively edited.", Base::DOMAIN) . '</p>';
	}

	// Render mode checkboxes
	public function renderModeSetting($data) {
		$settings = $this->getSettings();
		$fieldName = $data['postType']->name . '_collaboration_enabled';
		$savedValue = isset($settings[$fieldName]) ? $settings[$fieldName] : false;
		echo '<input type="checkbox" name="fce-settings[' . $fieldName . ']" ' . checked($savedValue, '1', false) . ' value="1">';
	}

	// Render fields to track
	public function renderConflictFieldsHeader() {
		echo '<p>' . __("Choose which fields are considered in conflict resolution.", Base::DOMAIN) . '</p>';
	}

	// Render ACF field
	public function renderAcfSetting() {
		$settings = $this->getSettings();
		$savedValue = isset($settings['conflict_fields_acf']) ? $settings['conflict_fields_acf'] : '';
		echo '<textarea name="fce-settings[conflict_fields_acf]" rows="6" cols="40">' . implode("\n", $savedValue) . '</textarea>';
		echo '<div><em>Specify ACF field keys or names, one per line. eg. <code>field_59dfd2e9f4e93</code> or <code>sources</code></em>.</div>';
	}

	// Sanitize saved fields
	public function sanitizeSettings($input) {
		$sanitizedInput = array();

		// Mode
		$args = array('public' => true);
		$postTypes = get_post_types($args);
		foreach ($postTypes as $postType) {
			$fieldName = $postType . '_collaboration_enabled';
			if (isset($input[$fieldName]) && $input[$fieldName] == '1') {
				$sanitizedInput[$fieldName] = 1;
			}
		}

		// ACF
		$fields = array();
		if (isset($input['conflict_fields_acf'])) {
			foreach (explode("\n", $input['conflict_fields_acf']) as $field) {
				$fields[] = sanitize_key($field);
			}
		}
		$sanitizedInput['conflict_fields_acf'] = $fields;

		// Save
		return $sanitizedInput;
	}
}

Settings::instance()->init();
