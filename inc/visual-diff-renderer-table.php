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

	// Removes a ins and del tags if found within another tag (eg. '<p <ins>class="para"</ins>>')
	private function removeTagsInsideTags($diff, $keepOld = false) {

		// Close/open unmatched opening/closing ins and del inside another tag
		if ($keepOld) {

			// Eg. `<p <ins>class="para">text</</ins>p>` => `<p <ins>class="para"</ins>><ins>text</ins><<ins>/</ins>p>`
			$patterns = array('/<(\/?[^>]*)<(ins|del)>([^<>]*)>/', '/<(\/?[^>]*)<\/(ins|del)>([^<>]*)>/');
			$replaces = array('<$1<$2>$3</$2>><$2>', '</$2><<$2>$1</$2>$3>');
		} else {

			// Eg. `<p <ins>class="para">text</</ins>p>` => `<p class="para"><ins>text</ins></p>`
			$patterns = array('/<(\/?[^>]*)<ins>([^<>]*)>/', '/<(\/?[^>]*)<\/ins>([^<>]*)>/');
			$replaces = array('<$1$3><$2>', '</$2><$1$3>');
		}
		$diff = preg_replace($patterns, $replaces, $diff);

		// Add marker/tooltip at the beginning of every offending tag with the original diffs, replacing ins with placeholders so they're not removed later
		if ($keepOld) {
			$diff = preg_replace_callback('/<((([^>]*)<(?:ins|del)>([^>]*)<\/(?:ins|del)>)+[^>]*)>/',
				function($markers) {
					$replace = '<span class="fce-intag-change"><span class="fce-intag-change__tooltip">&lt;';
					$replace .= preg_replace('/<(\/?ins)>/', '{!{$1}}', $markers[1]);
					return $replace . '&gt;</span></span><' . $markers[1] . '>';
				}, $diff);
		}

		// Remove ins and del within other tags
		$patterns = array('/<([^>]*)<ins>([^>]*)<\/ins>/', '/<([^>]*)<del>([^>]*)<\/del>/');
		$replaces = array('<$1' . ($keepOld ? '' : '$2'), '<$1' . ($keepOld ? '$2' : ''));
		$newDiff = preg_replace($patterns, $replaces, $diff);
		while ($newDiff != $diff) {
			$diff = $newDiff;
			$newDiff = preg_replace($patterns, $replaces, $diff);
		}

		return $diff;
	}

	public function _changed($orig, $final) {
		$r = '';
		list($orig_matches, $final_matches, $orig_rows, $final_rows) = $this->interleave_changed_lines($orig, $final);
		$orig_diffs  = array();
		$final_diffs = array();

		foreach ($orig_matches as $o => $f) {
			if (is_numeric($o) && is_numeric($f)) {
				$text_diff = new \Text_Diff('auto', array(array($orig[$o]), array($final[$f])));
				$renderer = new $this->inline_diff_renderer;
				$diff = htmlspecialchars_decode($renderer->render($text_diff));
				if (preg_match_all('!(<ins>.*?</ins>|<del>.*?</del>)!', $diff, $diff_matches)) {
					$stripped_matches = strlen(strip_tags(join(' ', $diff_matches[0])));
					$stripped_diff = strlen(strip_tags($diff)) * 2 - $stripped_matches;
					if ($stripped_diff == 0) { continue; } // Avoid division by zero edge case
					$diff_ratio = $stripped_matches / $stripped_diff;
					if ($diff_ratio > $this->_diff_threshold) { continue; } // Too different. Don't save diffs.
				}

				// Remove all del and ins from inside tags
				$oldDiff = $this->removeTagsInsideTags($diff, true);
				$newDiff = $this->removeTagsInsideTags($diff, false);

				// Un-inline the diffs by removing del or ins and replace placeholders
				$oldDiff = preg_replace('|<ins>.*?</ins>|', '', $oldDiff);
				$orig_diffs[$o] = preg_replace('|{!{(/?ins)}}|', '<$1>', $oldDiff);
				$final_diffs[$f] = preg_replace('|<del>.*?</del>|', '', $newDiff);
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
					$r .= '<tr>' . $this->deletedLine($orig_line) . $this->emptyLine() . $this->addedLine($final_line) . "</tr>\n";
				} else {
					$r .= '<tr>' . $this->deletedLine($orig_line) . "</tr><tr>" . $this->addedLine($final_line) . "</tr>\n";
				}
			}
		}
		return $r;
	}

	// Run lists into single blocks for more meaningful Visual diffing
	// Requires ending block tags (<ul>, </ul>, <ol>, </ol>) to be at the beginning of lines
	public function concatenateLists($data) {
		$result = array();
		$depth = 0;
		$newRow = '';
		foreach ($data as $row) {
			$r = trim($row);
			if (substr($r, 0, 3) == '<ul' || substr($r, 0, 3) == '<ol') {
				if ($depth == 0) {
					$newRow = $r;
				} else {
					$newRow .= $r;
				}
				$depth++;
			} else if (substr($r, 0, 4) == '</ul' || substr($r, 0, 4) == '</ol') {
				if ($depth == 1) {
					$newRow .= $r;
					$result[] = $newRow;
					$depth--;
				} else if ($depth > 1) {
					$newRow .= $r;
					$depth--;
				}
			} else if ($depth > 0) {
				$newRow .= $r;
			} else {
				$result[] = $row;
			}
		}
		if ($depth > 0) { // List not closed properly, make sure there is some output
			$result[] = $newRow;
		}
		return $result;
	}
}
