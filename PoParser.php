<?php

/**
 * This is a simple parser for PO files. It also provides statistics about how many strings
 * have been translated, how many are fuzzy, etc.
 *
 * @author Laurent Cozic
 */

class PoParser {

	private function parseMsgId($line, &$output) {
		$success = @ereg('msgid "(.*)"', $line, $result);
		if ($success === false) return false;
		$output = $result[1];
		return true;
	}


	private function parseMsgStr($line, &$output) {
		$success = @ereg('msgstr "(.*)"', $line, $result);
		if ($success === false) return false;
		$output = $result[1];
		return true;
	}


	private function parseString($line, &$output) {
		$success = @ereg('^"(.*)"', $line, $result);
		if ($success === false) return false;
		$output = $result[1];
		return true;
	}


	private function parseFuzzy($line) {
		$success = @ereg('#, fuzzy', $line, $result);
		if ($success === false) return false;
		return true;
	}


	private function parseObsolete($line) {
		return substr($line, 0, 2) == "#~";
	}

	protected function parseReference($line) {
		$success = @ereg('^#: (.*)$', $line, $result);
		if ($success === false)  {
			return false;
		} else {
			return $result[1];
		}
	}

	protected function parseComment($line) {
		$success = @ereg('^# (.*)$', $line, $result);
		if ($success === false) {
			return false;
		} else {
			return $result[1];
		}
	}

	protected function parseSection($section) {
		$sectionDetails = array(
		    'comments' => array(),
		    'references' => array(),
		    'isFuzzy' => false,
		    'msgid' => null,
		    'msgstr' => null,
		    'unrecognized' => array()
		);
		/*
		 * break the section into seperate lines for better parsing
		 */
		$sectionLines = explode("\n", $section);
		foreach ($sectionLines as $sectionLine) {
			//grab the set of characters before the first whitespace of the line to determine how to parse them
			$sectionParts = explode(' ', $sectionLine);
			$key = array_shift($sectionParts);
			switch ($key) {
				case '#:':
					//parse reference
					$reference = $this->parseReference($sectionLine);
					if ($reference !== false) {
						$sectionDetails['references'][] = $reference;
					}
					break;

				case '#':
					//parse comment
					$comment = $this->parseComment($sectionLine);
					if ($comment !== false) {
						$sectionDetails['comments'][] = $comment;
					}
					break;

				case '#,':
					//parse fuzziness
					$sectionDetails['isFuzzy'] = $this->parseFuzzy($sectionLine);
					break;

				case 'msgid':
					//parse msgid
					$output = '';
					if ($this->parseMsgId($sectionLine, $output)) {
						$sectionDetails['msgid'] = $output;
					}
					break;

				case 'msgstr':
					//parse msgstr
					$output = '';
					if ($this->parseMsgStr($sectionLine, $output)) {
						$sectionDetails['msgstr'] = $output;
					}
					break;
				default:
					//un recognized line
					$sectionDetails['unrecognized'] = $sectionLine;
			}

		}
		return $sectionDetails;
	}

	/*
	 * @deprecated
	 */
	private function getLine($file) {
		return trim(fgets($file));
	}

	public function parsePoFile($path) {
		$file = @file_get_contents($path);
		if ($file === false) {
			throw new Exception('Cannot open ' . $path);
			return;
		}
		$output = array(
		    'header' => array(),
		    'strings' => array()
		);

		/*
		 * break the file into sections by assuming sections are broken by 2 newlines.
		 * this follows the standard way that CakePHP and POEdit create PO files.
		 * @todo check if 2 newlines is a requirement of a valid POT/PO file
		 */
		$sections = explode("\n\n", $file);

		/*
		 * the first section is always the header,
		 * @todo create a function to break up the header further
		 */
		$output['header'] = array_shift($sections);

		/*
		 * the remaing sections should be the msgid/msgstr combinations and details
		 */
		foreach ($sections as $section) {
			$sectionParsed = $this->parseSection($section);
			$output['strings'][$sectionParsed['msgid']] = $sectionParsed;
		}

		return $output;
	}

	/*
	 * @deprecated
	 */
	public function _parsePoFile($path) {
		$file = @fopen($path, 'r');
		if ($file === false) {
			throw new Exception("Cannot open ".$path);
			return;
		}

		$expect = "msgid";
		$parsingString = false;
		$currentIsFuzzy = false;

		$lineIndex = 0;

		$output = array();

		while (!feof($file)) {
			$line = $this->getLine($file);
			$lineIndex++;

			if ($line == "") continue;

			$isFuzzy = $this->parseFuzzy($line);
			if ($isFuzzy === true) {
				$currentIsFuzzy = true;
				continue;
			}

			if ($this->parseObsolete($line)) {
				$currentIsFuzzy = false;
				continue;
			}

			if (substr($line, 0, 1) == "#") continue;



			if ($expect == "msgid") {
				$success = $this->parseMsgId($line, $result);

				if (!$success) {
					throw new Exception("Error at line ".$lineIndex.": expecting msgid");
					return;
				}

				if ($result === false) $result = "";

				$currentObject = array(
					"id" => $result,
					"string" => "",
					"fuzzy" => $currentIsFuzzy
				);

				$currentIsFuzzy = false;

				while (!feof($file)) {
					$line = $this->getLine($file);
					$lineIndex++;

					$success = $this->parseString($line, $result);
					if ($success) {
						$currentObject["id"] = $currentObject["id"].$result;
						continue;
					} else {
						break;
					}
				}

				while (!feof($file)) {
					if ($line == "" || substr($line, 0, 1) == "#") {
						$line = $this->getLine($file);
						$lineIndex++;
					} else {
						break;
					}
				}

				$success = $this->parseMsgStr($line, $result);

				if (!$success) {
					throw new Exception("Error at line	".$lineIndex.": expecting msgstr");
					return;
				}

				$currentObject["string"] = $result;

				while (!feof($file)) {
					$line = $this->getLine($file);
					$lineIndex++;

					$success = $this->parseString($line, $result);
					if ($success) {
						$currentObject["string"] = $currentObject["string"].$result;
						continue;
					} else {
						break;
					}
				}

				$expect = "msgid";

				array_push($output, $currentObject);

				continue;
			}

		}

		fclose($file);

		return $output;
	}


	public function gettextStatus($poDoc) {
		$fuzzyCount = 0;
		$todoCount = 0;
		$totalCount = count($poDoc['strings']);

		foreach ($poDoc['strings'] as $section) {

			if ($section['isFuzzy']) {
				$fuzzyCount++;
			}
			if ($section['msgstr'] == '') {
				$todoCount++;
			}
		}

		return array(
		    'fuzzy' => $fuzzyCount,
		    'todo' => $todoCount,
		    'total' => $totalCount);
	}


	public function getPercentageDone($poDoc) {
		$result = $this->gettextStatus($poDoc);
		$totalDone = $result['total'] - $result['fuzzy'] - $result['todo'];
		return $totalDone / $result['total'];
	}

}
