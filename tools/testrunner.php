<?php

	include_once(dirname(__FILE__) . '/../src/libraries/utilities.php');
	include_once(dirname(__FILE__) . '/../src/libraries/whichbrowser.php');

	$command = 'compare';
	$files = array();

	array_shift($argv);
	if (count($argv)) {
		if (in_array($argv[0], array('compare', 'check', 'rebase', 'list'))) {
			$command = array_shift($argv);
		}

		if (count($argv)) {
			foreach($argv as $file) {
				if (fnmatch("*.yaml", $file)) {
					$files[] = $file;
				}
			}
		}

		else {
			$files = glob("*/*.yaml");
		}
	}
	else {
		$files = glob("*/*.yaml");
	}

	switch($command) {

		case 'list':
				Runner::search($files);
				break;

		case 'check':
				$result = Runner::compare($files);

				if (!$result) {
					echo "\033[0;31mTest runner failed, please fix or rebase before building or deploying!\033[0m\n\n";
					exit(1);
				}

				break;

		case 'compare':
				Runner::compare($files);
				break;

		case 'rebase':
				Runner::rebase($files);
				break;
	}



	class Runner {

		function compare($files) {
			@unlink('runner.log');

			$result = true;

			foreach($files as $file) {
				$result = Runner::_compareFile($file) && $result;
			}

			return $result;
		}

		function _compareFile($file) {
			$fp = fopen('runner.log', 'a+');

			$success = 0;
			$failed = 0;
			$total = 0;
			$rebase = false;

			$rules = yaml_parse_file ($file);

			foreach($rules as $rule) {
				$detected = new WhichBrowser(array('headers' => http_parse_headers($rule['headers'])));

				if (isset($rule['result'])) {
					if ($detected->toArray() != $rule['result']) {
						fwrite($fp, "\n{$file}\n--------------\n\n");
						fwrite($fp, $rule['headers'] . "\n");
						fwrite($fp, "Base:\n");
						fwrite($fp, yaml_emit($rule['result']) . "\n");
						fwrite($fp, "Calculated:\n");
						fwrite($fp, yaml_emit($detected->toArray()) . "\n");

						$failed++;
					}
					else {
						$success++;
					}
				} else {
					fwrite($fp, "\n{$file}\n--------------\n\n");
					fwrite($fp, $rule['headers'] . "\n");
					fwrite($fp, "New result:\n");
					fwrite($fp, yaml_emit($detected->toArray()) . "\n");

					$rebase = true;
				}

				$total++;
			}

			fclose($fp);

			$counter = "[{$success}/{$total}]";

			echo $success == $total ? "\033[0;32m" : "\033[0;31m";
			echo $counter;
			echo "\033[0m";
			echo str_repeat(' ', 12 - strlen($counter));
			echo $file;
			echo ($rebase ? "\t\t\033[0;31m => rebase required!\033[0m" : "");
			echo "\n";

			return $success == $total && !$rebase;
		}

		function search($files, $query = '') {
			foreach($files as $file) {
				Runner::_searchFile($file, $query);
			}
		}

		function _searchFile($file, $query) {
			$rules = Runner::_sortRules(yaml_parse_file ($file));

			foreach($rules as $rule) {
				$headers = http_parse_headers($rule['headers']);
				echo $headers['User-Agent'] . "\n";
			}
		}

		function rebase($files) {
			foreach($files as $file) {
				Runner::_rebaseFile($file);
			}
		}

		function _rebaseFile($file) {
			$result = array();
			$rules = @yaml_parse_file ($file);

			if (is_array($rules)) {
				echo "Rebasing {$file}\n";

				$rules = Runner::_sortRules($rules);

				foreach($rules as $rule) {
					$detected = new WhichBrowser(array('headers' => http_parse_headers($rule['headers'])));

					$result[] = array(
						'headers' 	=> $rule['headers'],
						'result'	=> $detected->toArray()
					);
				}

				if (count($result)) {
					if (count($result) == count($rules)) {
						if (yaml_emit_file($file . '.tmp', $result)) {
							rename($file, $file . '.old');
							rename($file . '.tmp', $file);
							unlink($file . '.old');
						}
					}
					else {
						echo "Rebasing {$file}\t\t\033[0;31m => output does not match input\033[0m\n";
					}
				} else {
					echo "Rebasing {$file}\t\t\033[0;31m => no results found\033[0m\n";
				}
			} else {
				echo "Rebasing {$file}\t\t\033[0;31m => error reading file\033[0m\n";
			}
		}

		function _sortRules($rules) {
			usort($rules, function($a, $b) {
				$ah = http_parse_headers($a['headers']);
				$bh = http_parse_headers($b['headers']);

				$as = '';
				$bs = '';

				if (isset($ah['User-Agent'])) $as = $ah['User-Agent'];
				if (isset($bh['User-Agent'])) $bs = $bh['User-Agent'];

		        if ($ah == $bh) {
		            return 0;
				}
		        return ($ah > $bh) ? +1 : -1;
			});

			return $rules;
		}
	}
