<?php

class JestUnitTestEngine extends ArcanistUnitTestEngine {

	const FORCE_ALL_FLAG = 'forceAll';

	private $affectedTests = [];
	private $projectRoot;

	public function getEngineConfigurationName() {
		return 'jest';
	}

	public function supportsRunAllTests() {
		return true;
	}

	public function shouldEchoTestResults() {
		return false;
	}

	/**
	 * @return null|array
	 */
	public function getUnitConfigSection() {
		return $this->getConfigurationManager()->getConfigFromAnySource($this->getEngineConfigurationName());
	}

	/**
	 * @param string $name
	 * @param mixed $default
	 *
	 * @return mixed
	 */
	public function getUnitConfigValue($name, $default = null) {
		$config = $this->getUnitConfigSection();
		return isset($config[$name]) ? $config[$name] : $default;
	}

	public function run() {
		$this->projectRoot = $this->getWorkingCopy()->getProjectRoot();
		$include           = $this->getUnitConfigValue('include');
		$includeFiles      = $include !== null ? $this->getIncludedFiles($include) : [];
		$forceRunAll       = $this->getUnitConfigValue(self::FORCE_ALL_FLAG, false);

		if (!$forceRunAll) {
			foreach ($this->getPaths() as $path) {
				$path = Filesystem::resolvePath($path, $this->projectRoot);

				// TODO: add support for directories
				// Users can call phpunit on the directory themselves
				if (is_dir($path)) {
					continue;
				}

				// Not sure if it would make sense to go further if it is not a JS file
				$extension = pathinfo($path, PATHINFO_EXTENSION);
				if (!in_array($extension, ['js', 'jsx', 'ts', 'tsx'])) {
					continue;
				}

				// do we have an include pattern? does it match the file?
				if (null !== $include && !in_array($path, $includeFiles, true)) {
					continue;
				}

				if (!Filesystem::pathExists($path)) {
					continue;
				}

				$this->affectedTests[$path] = basename($path);
			}

			if (empty($this->affectedTests)) {
				throw new ArcanistNoEffectException(pht('No tests to run.'));
			}
		}

		$future = $this->buildTestFuture();
		list($err, $stdout, $stderr) = $future->resolve();

		// If we are running coverage the output includes a visual (non-JSON) representation
		// If that exists then exclude it before parsing the JSON.
		$json_start_index = strpos($stdout, '{"');
		$json_string      = substr($stdout, $json_start_index);

		try {
			$json_result = phutil_json_decode($json_string);
		} catch (PhutilJSONParserException $ex) {
			$cmd = $this->command;
			throw new CommandException(
				pht(
					"JSON command '%s' did not produce a valid JSON object on stdout: %s",
					$cmd,
					$stdout
				),
				$cmd,
				0,
				$stdout,
				$stderr
			);
		}
		$test_results = $this->parseTestResults($json_result);

		// getEnableCoverage() returns either true, false, or null
		// true and false means it was explicitly turned on or off.  null means use the default
		if ($this->getEnableCoverage() !== false) {
			$coverage = $this->readCoverage($json_result);

			foreach ($test_results as $test_result) {
				$test_result->setCoverage($coverage);
			}
		}

		return $test_results;
	}

	/**
	 * @param String $include
	 *
	 * @return array|false
	 */
	public function getIncludedFiles($include) {
		$dir = new RecursiveDirectoryIterator($this->projectRoot . $include);
		$ite = new RecursiveIteratorIterator($dir);
		$files = new RegexIterator($ite, '%\.{js|ts|tsx|jsx}%');

		$fileList = [];
		foreach ($files as $file) {
			$fileList[] = $file->getRealPath();
		}

		return $fileList;
	}

	public function buildTestFuture() {
		$config = $this->getUnitConfigSection();
		$command = array_key_exists('bin', $config)
			? "{$config['bin']} "
			: $this->getWorkingCopy()->getProjectRoot() . '/node_modules/.bin/jest --json ';

		if (true !== $config[self::FORCE_ALL_FLAG]) {
			$command .= implode(' ', array_unique($this->affectedTests));
		}

		// getEnableCoverage() returns either true, false, or null
		// true and false means it was explicitly turned on or off.  null means use the default
		if ($this->getEnableCoverage() !== false) {
			$command .= ' --coverage';
		}

		return new ExecFuture('%C', $command);
	}

	/**
	 * @param Object $json_result
	 *
	 * @return array
	 */
	public function parseTestResults($json_result) {
		$results = [];

		if ($json_result['numTotalTests'] === 0 && $json_result['numTotalTestSuites'] === 0) {
			throw new ArcanistNoEffectException(pht('No tests to run.'));
		}

		foreach ($json_result['testResults'] as $test_result) {
			$duration_in_seconds = ($test_result['endTime'] - $test_result['startTime']) / 1000;
			$status_result       = $test_result['status'] === 'passed' ?
				ArcanistUnitTestResult::RESULT_PASS :
				ArcanistUnitTestResult::RESULT_FAIL;

			$extraData = [];
			foreach ($test_result['assertionResults'] as $assertion) {
				$extraData[] = $assertion['status'] === 'passed'
					? " [+] {$assertion['fullName']}"
					: " [!] {$assertion['fullName']}";
			}

			$result = new ArcanistUnitTestResult();
			$result->setName($test_result['name']);
			$result->setResult($status_result);
			$result->setDuration($duration_in_seconds);
			$result->setUserData($test_result['message']);
			$result->setExtraData($extraData);
			$results[] = $result;
		}

		return $results;
	}

	/**
	 * @param array $json_result
	 *
	 * @return array
	 */
	private function readCoverage($json_result) {
		if (empty($json_result) || !isset($json_result['coverageMap'])) {
			return [];
		}

		$reports = [];
		foreach ($json_result['coverageMap'] as $file => $coverage) {
			$shouldSkip = strpos($file, '__fixtures__') !== false
				|| strpos($file, '__mocks__') !== false
				|| strpos($file, 'spec') !== false;

			if ($shouldSkip) {
				continue;
			}

			$lineCount = count(file($file));
			$file = str_replace($this->projectRoot . DIRECTORY_SEPARATOR, '', $file);
			$reports[$file] = str_repeat('U', $lineCount); // not covered by default

			foreach ($coverage['statementMap'] as $chunk) {
				for ($i = $chunk['start']['line']; $i < $chunk['end']['line']; $i++) {
					$reports[$file][$i] = 'C';
				}
			}
		}

		return $reports;
	}

	/**
	 * @param string $path
	 *
	 * @return array
	 */
	public function getSearchLocationsForTests($path) {
		$test_dir_names = $this->getUnitConfigValue('test.dirs');
		$test_dir_names = !empty($test_dir_names) ? $test_dir_names : ['tests', 'Tests'];

		// including 5 levels of sub-dirs
		foreach ($test_dir_names as $dir) {
			$test_dir_names[] = $dir . '/**/';
			$test_dir_names[] = $dir . '/**/**/';
			$test_dir_names[] = $dir . '/**/**/**/';
			$test_dir_names[] = $dir . '/**/**/**/**/';
		}

		return $test_dir_names;
	}
}
