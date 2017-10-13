<?php

if (!defined('WPINC')) { die(); }

require_once(ABSPATH . WPINC . '/wp-diff.php');

class FCE_Text_Diff_Renderer_Table extends WP_Text_Diff_Renderer_Table {

	private $column = 0;

	private function getColumnClass() {
		if (++$this->column > 3) { $this->column = 1; }
		if ($this->column == 1) {
			return 'diff-left-side';
		} else if ($this->column == 2) {
			return 'diff-divider';
		} else if ($this->column == 3) {
			return 'diff-right-side';
		}

		return '';
	}

	public function addedLine($line, $column = false) {
		$columnClass = $this->getColumnClass();
		return "<td class='diff-addedline {$columnClass}'>{$line}</td>";
	}

	public function deletedLine($line, $column = false) {
		$columnClass = $this->getColumnClass();
		return "<td class='diff-deletedline {$columnClass}'>{$line}</td>";
	}

	public function contextLine($line, $column = false) {
		$columnClass = $this->getColumnClass();
		return "<td class='diff-context {$columnClass}'>{$line}</td>";
	}

	public function emptyLine($column = false) {
		$columnClass = $this->getColumnClass();
		$class = '';
		if (!empty($columnClass)) {
			$class = "class='{$columnClass}'";
		}
		return "<td {$class}></td>";
	}
}
