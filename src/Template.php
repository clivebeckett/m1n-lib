<?php

namespace m1n\lib;

/**
 * Very simple and generic template engine.
 * Uses php files with HTML code and PHP snippets and variables.
 */
class Template
{
	/**
	 * @param string $tplFile Filename of template file, full path.
	 * @param array $data Can contain all sorts of data, including objects.
	 * @return string Template with replaced placeholders
	 */
	static public function render($tplFile, $data = array())
	{
		try {
			if (!file_exists($tplFile)) {
				throw new \Exception("Template file not found");
			}
			if (array_key_exists('tplFile', $data)) {
				throw new \Exception("“tplFile” must not be in the data for the template");
			}

			/**
			 * Start output buffering
			 */
			ob_start();

			/**
			 * $data['fooBeeDoo'] will be $fooBeeDoo
			 */
			extract($data);

			/**
			 * load template file
			 */
			require($tplFile);

			/**
			 * write buffered output, delete and clean output buffer
			 * executes both ob_get_contents() and ob_end_clean()
			 */
			$content = ob_get_clean();
		} catch (\Exception $e) {
			return $e->getMessage();
		}

		return $content;
	}

}
