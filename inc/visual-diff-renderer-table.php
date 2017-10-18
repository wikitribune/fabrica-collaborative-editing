<?php

namespace Fabrica\CollaborativeEditing;

if (!defined('WPINC')) { die(); }

require_once(ABSPATH . WPINC . '/wp-diff.php');
require_once('text-diff-renderer-table.php');

class VisualDiffRendererTable extends TextDiffRendererTable {

	public function _added($lines, $encode = true) {
		$r = '';
		foreach ($lines as $line) {
			if ($this->_show_split_view) {
				$r .= '<tr>' . $this->emptyLine() . $this->emptyLine() . $this->addedLine($line) . "</tr>\n";
			} else {
				$r .= '<tr>' . $this->addedLine($line) . "</tr>\n";
			}
		}
		return $r;
	}

	public function _deleted($lines, $encode = true) {
		$r = '';
		foreach ($lines as $line) {
			if ($this->_show_split_view) {
				$r .= '<tr>' . $this->deletedLine($line) . $this->emptyLine() . $this->emptyLine() . "</tr>\n";
			} else {
				$r .= '<tr>' . $this->deletedLine($line) . "</tr>\n";
			}
		}
		return $r;
	}

	public function _context($lines, $encode = true) {
		$r = '';
		foreach ($lines as $line) {

			if ($this->_show_split_view) {
				$r .= '<tr>' . $this->contextLine($line) . $this->emptyLine() . $this->contextLine($line)  . "</tr>\n";
			} else {
				$r .= '<tr>' . $this->contextLine($line) . "</tr>\n";
			}
		}
		return $r;
	}

	public function _changed($orig, $final) {
		$r = '';
		list($orig_matches, $final_matches, $orig_rows, $final_rows) = $this->interleave_changed_lines($orig, $final);
		$orig_diffs  = array();
		$final_diffs = array();

		foreach (array_keys($orig_rows) as $row) {
			if ($orig_rows[$row] < 0 && $final_rows[$row] < 0)
				continue;
			if (isset($orig_diffs[$orig_rows[$row]]))
				$orig_line = $orig_diffs[$orig_rows[$row]];
			elseif (isset($orig[$orig_rows[$row]]))
				$orig_line = $orig[$orig_rows[$row]];
			else
				$orig_line = '';
			if (isset($final_diffs[$final_rows[$row]]))
				$final_line = $final_diffs[$final_rows[$row]];
			elseif (isset($final[$final_rows[$row]]))
				$final_line = $final[$final_rows[$row]];
			else
				$final_line = '';

			if ($orig_rows[$row] < 0) { // Orig is blank. This is really an added row.
				$r .= $this->_added(array($final_line));
			} elseif ($final_rows[$row] < 0) { // Final is blank. This is really a deleted row.
				$r .= $this->_deleted(array($orig_line));
			} else { // A true changed row.
				if ($this->_show_split_view) {
					$r .= '<tr>' . $this->deletedLine($orig_line) . $this->emptyLine() . $this->addedLine($final_line) . "</tr>\n";
				} else {
					$r .= '<tr>' . $this->deletedLine($orig_line) . "</tr><tr>" . $this->addedLine($final_line) . "</tr>\n";
				}
			}
		}
		return $r;
	}
}
