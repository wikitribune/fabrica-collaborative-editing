<?php

if (!defined('WPINC')) { die(); }

require_once(ABSPATH . WPINC . '/wp-diff.php');

class FCE_WYSIWYG_Diff_Renderer_Table extends WP_Text_Diff_Renderer_Table {

	private function getColumnClass($column) {
		if ($column == 1) {
			return 'diff-left-side';
		} else if ($column == 2) {
			return 'diff-divider';
		} else if ($column == 3) {
			return 'diff-right-side';
		}

		return '';
	}

	public function addedLine($line, $column = false) {
		$columnClass = $this->getColumnClass($column);
		return "<td class='diff-addedline {$columnClass}'>{$line}</td>";
	}

	public function deletedLine($line, $column = false) {
		$columnClass = $this->getColumnClass($column);
		return "<td class='diff-deletedline {$columnClass}'>{$line}</td>";
	}

	public function contextLine($line, $column = false) {
		$columnClass = $this->getColumnClass($column);
		return "<td class='diff-context {$columnClass}'>{$line}</td>";
	}

	public function emptyLine($column = false) {
		$columnClass = $this->getColumnClass($column);
		$class = '';
		if (!empty($columnClass)) {
			$class = "class='{$columnClass}'";
		}
		return "<td {$class}></td>";
	}

	public function _added($lines, $encode = true) {
		$r = '';
		foreach ($lines as $line) {

			// Eliminate empty block parent elements
			if ($line == '<ul>' || $line == '<ol>' || $line == '</ul>' || $line == '</ol>') { continue; }

			if ($this->_show_split_view) {
				$r .= '<tr>' . $this->emptyLine(1) . $this->emptyLine(2) . $this->addedLine($line, 3) . "</tr>\n";
			} else {
				$r .= '<tr>' . $this->addedLine($line) . "</tr>\n";
			}
		}
		return $r;
	}

	public function _deleted($lines, $encode = true) {
		$r = '';
		foreach ($lines as $line) {

			// Eliminate empty block parent elements
			if ($line == '<ul>' || $line == '<ol>' || $line == '</ul>' || $line == '</ol>') { continue; }

			if ($this->_show_split_view) {
				$r .= '<tr>' . $this->deletedLine($line, 1) . $this->emptyLine(2) . $this->emptyLine(3) . "</tr>\n";
			} else {
				$r .= '<tr>' . $this->deletedLine($line) . "</tr>\n";
			}

		}
		return $r;
	}

	public function _context($lines, $encode = true) {
		$r = '';
		foreach ($lines as $line) {

			// Eliminate empty block parent elements
			if ($line == '<ul>' || $line == '<ol>' || $line == '</ul>' || $line == '</ol>') { continue; }

			if ($this->_show_split_view) {
				$r .= '<tr>' . $this->contextLine($line, 1) . $this->emptyLine(2) . $this->contextLine($line, 3)  . "</tr>\n";
			} else {
				$r .= '<tr>' . $this->contextLine($line) . "</tr>\n";
			}
		}
		return $r;
	}

	private function concatenateLists($data) {
		$result = array();
		$inList = false;
		$newRow = '';
		foreach ($data as $row) {
			$r = trim($row);
			if (substr($r, 0, 3) == '<ul' || substr($r, 0, 3) == '<ol') {
				$inList = substr($r, 1, 2);
				$newRow = $row;
			} else if (($inList == 'ul' && substr($r, 0, 4) == '</ul') || ($inList == 'ol' && substr($r, 0, 4) == '</ol')) {
				$newRow .= $row;
				$result[] = $newRow;
				$inList = '';
			} else if ($inList == 'ul' || $inList == 'ol') {
				$newRow .= $row;
			} else {
				$result[] = $row;
			}
		}
		return $result;
	}

	public function _changed($orig, $final) {

		// Concatenate lists into single blocks
		$orig = $this->concatenateLists($orig);
		$final = $this->concatenateLists($final);

		$r = '';
		list($orig_matches, $final_matches, $orig_rows, $final_rows) = $this->interleave_changed_lines($orig, $final);
		$orig_diffs  = array();
		$final_diffs = array();
		foreach ($orig_matches as $o => $f) {
			if (is_numeric($o) && is_numeric($f)) {
				$text_diff = new Text_Diff('auto', array(array($orig[$o]), array($final[$f])));
				$renderer = new $this->inline_diff_renderer;
				$diff = htmlspecialchars_decode($renderer->render($text_diff));
				if (preg_match_all('!(<ins>.*?</ins>|<del>.*?</del>)!', $diff, $diff_matches)) {
					$stripped_matches = strlen(strip_tags(join(' ', $diff_matches[0])));
					$stripped_diff = strlen(strip_tags($diff)) * 2 - $stripped_matches;
					if ($stripped_diff == 0) { continue; } // Avoid division by zero edge case
					$diff_ratio = $stripped_matches / $stripped_diff;
					if ($diff_ratio > $this->_diff_threshold) { continue; } // Too different. Don't save diffs.
				}

				// Un-inline the diffs by removing del or ins
				$orig_diffs[$o]  = preg_replace('|<ins>.*?</ins>|', '', $diff);
				$final_diffs[$f] = preg_replace('|<del>.*?</del>|', '', $diff);
			}
		}

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
					$r .= '<tr>' . $this->deletedLine($orig_line, 1) . $this->emptyLine(2) . $this->addedLine($final_line, 2) . "</tr>\n";
				} else {
					$r .= '<tr>' . $this->deletedLine($orig_line) . "</tr><tr>" . $this->addedLine($final_line) . "</tr>\n";
				}
			}
		}
		return $r;
	}
}
