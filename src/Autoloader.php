<?php

namespace m1n\lib;

/**
 * Autoloader class, based on
 * https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-4-autoloader-examples.md
 *
 * instantiate and register the loader:
 *     $autoloader = new Autoloader;
 *     $autoloader->register();
 *
 * register the base directories for namespace prefixes:
 *     $autoloader->addNamespace('Name\Space', '/path/to/class/files');
 *
 * instantiate an object
 *     $object = new Name\Space\Class;
 */

class Autoloader
{
	/**
	 * namespace => array of base directory.
	 * @var array
	 */
	public $prefixes = array();

	/**
	 * possible suffixes of file names to be used in $this->loadMappedFile().
	 * @var array
	 */
	private $suffixes = [
		'',
		'.class',
		'.interface'
	];

	/**
	 * register loader
	 * @return void;
	 */
	public function register()
	{
		spl_autoload_register(array($this, 'loadClass'));
	}

	/**
	 * Add base directory for a specific namespace
	 *
	 * @param string $prefix The namespace prefix.
	 * @param string $baseDir Directory for the class files.
	 * @param bool $prepend If true, prepend the base directory
	 *     to the stack of directories (per prefix) instead of appending it;
	 *     it will be searched first rather than last
	 *
	 * @return void
	 */
	public function addNamespace($prefix, $baseDir, $prepend = false)
	{
		// normalise $prefix
		$prefix = trim($prefix, '\\').'\\';

		// normalise $prefix
		$baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

		// initialise the $prefix array within $this->prefixes
		if (isset($this->prefixes[$prefix]) === false) {
			$this->prefixes[$prefix] = array();
		}

		// add direction to $prefix array
		if ($prepend) {
			array_unshift($this->prefixes[$prefix], $baseDir);
		} else {
			array_push($this->prefixes[$prefix], $baseDir);
		}
	}
	
	/**
	 * loads the class file for a given class name.
	 * Is being registered as an autoloader in $this->register().
	 * Will be triggered by calling a class.
	 *
	 * @param string $class The fully qualified class name.
	 * @return mixed The mapped file name on success, or boolean false
	 *     on failure.
	 */
	public function loadClass($class)
	{
		// $prefix will be stripped of possible relative class names
		// in while loop below
		$prefix = $class;

		// go backwards through the namespaced class name
		// to find a mapped file name
		while (false !== $pos = strrpos($prefix, '\\')) {
			// retain trailing namespace separator in the prefix
			$prefix = substr($class, 0, $pos + 1);

			// the relative class name (can contain folder names)
			$relativeClass = substr($class, $pos + 1);

			// trying to load a file
			$mappedFile = $this->loadMappedFile($prefix, $relativeClass);
			if ($mappedFile) {
				return $mappedFile;
			}

			// remove the trailing namespace separator for next iteration
			$prefix = rtrim($prefix, '\\');
		}

		// not found any file
		return false;
	}

	/**
	 * load the mapped file.
	 * @param string $prefix The namespace prefix.
	 * @param string $relativeClass The relative class name.
	 *
	 * @return mixed The mapped file name or boolean false.
	 */
	private function loadMappedFile($prefix, $relativeClass)
	{
		// check if there are any directories for prefix at all
		if (isset($this->prefixes[$prefix]) === false) {
			return false;
		}

		// look through base directories of prefix
		foreach ($this->prefixes[$prefix] AS $baseDir) {
			foreach ($this->suffixes AS $suffix) {
				// create full class file name
				$fileName = $baseDir.str_replace('\\', '/', $relativeClass).$suffix.'.php';
				// require file if it exists
				if ($this->requireFile($fileName)) {
					return $fileName;
				}
			}
		}

		// not found any file
		return false;
	}

	/**
	 * require file if it exists
	 *
	 * @param string $fileName
	 *
	 * @return bool If file exists.
	 */
	private function requireFile($fileName)
	{
		if (file_exists($fileName)) {
			require $fileName;
			return true;
		}
		return false;
	}
}
