<?php
/**
 * Copyright (c) 2013 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC;

class Autoloader {
	private $useGlobalClassPath = true;

	private $prefixPaths = array();

	private $classPaths = array();

	/**
	 * Add a custom prefix to the autoloader
	 *
	 * @param string $prefix
	 * @param string $path
	 */
	public function registerPrefix($prefix, $path) {
		$this->prefixPaths[$prefix] = $path;
	}

	/**
	 * Add a custom classpath to the autoloader
	 *
	 * @param string $class
	 * @param string $path
	 */
	public function registerClass($class, $path) {
		$this->classPaths[$class] = $path;
	}

	/**
	 * disable the usage of the global classpath \OC::$CLASSPATH
	 */
	public function disableGlobalClassPath() {
		$this->useGlobalClassPath = false;
	}

	/**
	 * enable the usage of the global classpath \OC::$CLASSPATH
	 */
	public function enableGlobalClassPath() {
		$this->useGlobalClassPath = true;
	}

	/**
	 * get the possible paths for a class
	 *
	 * @param string $class
	 * @return array|bool an array of possible paths or false if the class is not part of ownCloud
	 */
	public function findClass($class) {
		$class = trim($class, '\\');

		$paths = array();
		if (array_key_exists($class, $this->classPaths)) {
			$paths[] = $this->classPaths[$class];
		} else if ($this->useGlobalClassPath and array_key_exists($class, \OC::$CLASSPATH)) {
			$paths[] = \OC::$CLASSPATH[$class];
			/**
			 * @TODO: Remove this when necessary
			 * Remove "apps/" from inclusion path for smooth migration to mutli app dir
			 */
			if (strpos(\OC::$CLASSPATH[$class], 'apps/') === 0) {
				\OC_Log::write('core', 'include path for class "' . $class . '" starts with "apps/"', \OC_Log::DEBUG);
				$paths[] = str_replace('apps/', '', \OC::$CLASSPATH[$class]);
			}
		} elseif (strpos($class, 'OC_') === 0) {
			// first check for legacy classes if underscores are used
			$paths[] = 'legacy/' . strtolower(str_replace('_', '/', substr($class, 3)) . '.php');
			$paths[] = strtolower(str_replace('_', '/', substr($class, 3)) . '.php');
		} elseif (strpos($class, 'OC\\') === 0) {
			$paths[] = strtolower(str_replace('\\', '/', substr($class, 3)) . '.php');
		} elseif (strpos($class, 'OCP\\') === 0) {
			$paths[] = 'public/' . strtolower(str_replace('\\', '/', substr($class, 4)) . '.php');
		} elseif (strpos($class, 'OCA\\') === 0) {
			list(, $app, $rest) = explode('\\', $class, 3);
			$app = strtolower($app);
			foreach (\OC::$APPSROOTS as $appDir) {
				if (stream_resolve_include_path($appDir['path'] . '/' . $app)) {
					$paths[] = $appDir['path'] . '/' . $app . '/' . strtolower(str_replace('\\', '/', $rest) . '.php');
					// If not found in the root of the app directory, insert '/lib' after app id and try again.
					$paths[] = $appDir['path'] . '/' . $app . '/lib/' . strtolower(str_replace('\\', '/', $rest) . '.php');
				}
			}
		} elseif (strpos($class, 'Test_') === 0) {
			$paths[] = 'tests/lib/' . strtolower(str_replace('_', '/', substr($class, 5)) . '.php');
		} elseif (strpos($class, 'Test\\') === 0) {
			$paths[] = 'tests/lib/' . strtolower(str_replace('\\', '/', substr($class, 5)) . '.php');
		} else {
			foreach ($this->prefixPaths as $prefix => $dir) {
				if (0 === strpos($class, $prefix)) {
					$path = str_replace('\\', '/', $class) . '.php';
					$path = str_replace('_', '/', $path);
					$paths[] = $dir . '/' . $path;
				}
			}
		}
		return $paths;
	}

	/**
	 * Load the specified class
	 *
	 * @param string $class
	 * @return bool
	 */
	protected $memoryCache = null;
	protected $constructingMemoryCache = true; // hack to prevent recursion
	public function load($class) {
		// Does this PHP have an in-memory cache? We cache the paths there
		if ($this->constructingMemoryCache && !$this->memoryCache) {
			$this->constructingMemoryCache = false;
			$this->memoryCache = \OC\Memcache\Factory::createLowLatency('Autoloader');
		}
		if ($this->memoryCache) {
			$pathsToRequire = $this->memoryCache->get($class);
			if (is_array($pathsToRequire)) {
				foreach ($pathsToRequire as $path) {
					require_once $path;
				}
				return false;
			}
		}

		// Use the normal class loading path
		$paths = $this->findClass($class);
		if (is_array($paths)) {
			$pathsToRequire = array();
			foreach ($paths as $path) {
				if ($fullPath = stream_resolve_include_path($path)) {
					require_once $fullPath;
					$pathsToRequire[] = $fullPath;
				}
			}

			// Save in our memory cache
			if ($this->memoryCache) {
				$this->memoryCache->set($class, $pathsToRequire, 60); // cache 60 sec
			}
		}
		return false;
	}
}
