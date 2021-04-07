<?php

namespace m1n\lib;

/**
 * This class adds functionality to PHP’s PDO class. It contains some
 * ideas taken from the Medoo database framework http://medoo.in
 * 
 */
class Database extends \PDO
{
	private $dbType = 'mysql';
	private $dbName;
	private $dbServer;
	private $dbUser;
	private $dbPassword;
	private $dbCharset = 'utf8mb4';

	// For SQLite
	private $database_file;
	// For MySQL or MariaDB with unix_socket
	private $socket;
	// Optional
	private $port;
	private $tblPrefix;
	// set to public in order to change it before executing a query
	public $debugMode = false;

	// name that is used for Record UID field all over the project
	private $tblUidName = 'id';
	// can be set to true in __construct catch
	public $stopExec = false;
	// used to prepend table names in queries
	private $prependTblNames = false;

	// listing of parameters for prepared statements
	private $psParams = array();
	// Query for prepared statement: if reused it won’t be written again
	private $psQuery = null;
	// Prepared statement: if reused it won’t be written again
	private $ps = null;

	// can be set by the query() method to be called directly
	public $rowCount = 0;

	/**
	 * Sets up a database connection.
	 * @param array $dbSettings
	 */
	public function __construct($dbSettings = null)
	{
		try {
			// Data Source Name
			$dsn = '';
			// Additional commands for DB connection setup
			$commands = array();
			// driver-specific connection options (PDO::__construct $options param)
			$connOptions = array();

			if (is_array($dbSettings) === true) {
				foreach ($dbSettings as $setting => $value) {
					// set class properties using $dbSettings key of same name
					$this->$setting = $value;
				}
			} else {
				return false;
			}

			$this->dbType = strtolower($this->dbType);

			if (isset($this->port) && is_int($this->port * 1)) {
				$port = $this->port;
			}
			$isPort = isset($port);

			switch ($this->dbType) {
				case 'mariadb':
					$this->dbType = 'mysql';
				case 'mysql':
					$dsn = $this->dbType;
					if ($this->socket) {
						$dsn .= ':unix_socket=' . $this->socket . ';';
					} else {
						$dsn = $this->dbType;
						$dsn .= ':host=' . $this->dbServer. ';';
						$dsn .= ($isPort) ? 'port=' . $port . ';' : '';
					}
					$dsn .= 'dbname=' . $this->dbName;

					// Make MySQL using standard quoted identifier
					$commands[] = 'SET SQL_MODE=ANSI_QUOTES';
					break;
				case 'pgsql':
					$dsn = $this->dbType . ':host=' . $this->dbServer . ';';
					$dsn .= ($isPort ? 'port=' . $port . ';' : '');
					$dsn .= 'dbname=' . $this->dbName;
					break;
				case 'sybase':
					$dsn = 'dblib:host=' . $this->dbServer . ';';
					$dsn .= ($isPort ? 'port=' . $port . ';' : '');
					$dsn .= 'dbname=' . $this->dbName;
					break;
				case 'oracle':
					$dbname = $this->dbServer
						? '//' . $this->dbServer . ($isPort ? ':' . $port : ':1521') . '/' . $this->dbName
						: $this->dbName;
					$dsn = 'oci:dbname=' . $dbname . ($this->dbCharset ? ';charset=' . $this->dbCharset : '');
					break;
				case 'mssql':
					if (strstr(PHP_OS, 'WIN')) {
						$dsn = 'sqlsrv:server=' . $this->dbServer;
						$dsn .= ($isPort ? ',' . $port : '') . ';';
						$dsn .= 'database=' . $this->dbName;
					} else {
						$dsn = 'dblib:host=' . $this->dbServer;
						$dsn .= ($isPort ? ':' . $port : '') . ';';
						$dsn .= 'dbname=' . $this->dbName;
					}
					// Keep MSSQL QUOTED_IDENTIFIER is ON for standard quoting
					$commands[] = 'SET QUOTED_IDENTIFIER ON';
					break;
				case 'sqlite':
					$dsn = $this->dbType . ':' . $this->database_file;
					$this->dbUser = null;
					$this->dbPassword = null;
					break;
			}

			if (in_array($this->dbType, array('mariadb', 'mysql', 'pgsql', 'sybase', 'mssql')) && $this->dbCharset) {
				$commands[] = "SET NAMES '" . $this->dbCharset . "'";
			}

			parent::__construct($dsn, $this->dbUser, $this->dbPassword, $connOptions);
			parent::setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
			parent::setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

			foreach ($commands as $value) {
				$this->exec($value);
			}
		} catch (\PDOException $e) {
			echo '<p class="core-error"><strong>Whoops. There was an error:</strong> ' . $e->getMessage() . '</p>';
			$this->stopExec = true;
			return null;
		}
	}

	/**
	 * @param string $query
	 * @param bool $getRowCount Sets a public variable $this->rowCount.
	 * @return PDOStatement object
	 */
	public function query($query, $getRowCount = false)
	{
		if ($this->debugMode) {
			echo $this->sqlDebugOutput($query);
			$this->debugMode = false;
			return false;
		}
		if ($this->stopExec) {
			return false;
		}
		$queryStatement = parent::query($query);
		if ($queryStatement !== false && $getRowCount) {
			$this->rowCount = $queryStatement->rowCount();
		}
		return $queryStatement;
	}

	/**
	 * prepare a statement; will be reused if followed by the same query
	 * @param string $psQuery
	 * @param array $driverOptions see \PDO::prepare
	 * @return PDOStatement object
	 */
	public function prepare($psQuery, $driverOptions = array())
	{
		if ($this->debugMode) {
			echo $this->sqlDebugOutput($this->psQuery);
			$this->debugMode = false;
			return false;
		}
		if ($this->stopExec) {
			return false;
		}
		/**
		 * Write or overwrite object vars for query and PDOStatement
		 * if the statement ($this->ps) has not yet been set or
		 * the query is not the same as the last one.
		 * Otherwise return the one written before.
		 */
		if (is_null($this->ps) || $this->psQuery != $psQuery) {
			$this->psQuery = $psQuery;
			$this->ps = parent::prepare($this->psQuery);
		}
		return $this->ps;
	}

	/**
	 * @param mixed $tbl String table name or array
	 *     0 => table name
	 *     1 => array: joins, each of which an associative array:
	 *         [
	 *             '[->]tbl_name' => ['field_left' => 'field_right'],
	 *             '[<-]tbl_name' => ['field_left' => 'field_right'],
	 *             '[><]tbl_name' => ['field_left' => 'field_right']
	 *         ]
	 *         (prefixes in square brackets: left, right, inner join)
	 *     -- table names with or without project table prefix
	 *     -- table names will be sanitized from all sorts of characters
	 * @param mixed $cols String or array of columns.
	 * @param mixed $where Integer for ID or array [column => value]
	 *     (details see $this->whereClause())
	 * @param array $filter Grouping (string), ordering (array), limiting (int)
	 *     example: [
	 *         'GROUP BY' => 'field_name_1, field_name_2',
	 *         'ORDER BY' => ['field_name_1 DESC', 'field_name_2 ASC'],
	 *         'LIMIT' => 27
	 *     ]
	 * @param bool $getRowCount For use in $this->query().
	 *
	 * @return mixed Either array with one row or multiple rows multidimensionally
	 *     or bool false
	 */
	public function select($tbl, $cols = null, $where = null, $filter = null, $getRowCount = false)
	{
		$joinQuery = '';
		if (is_array($tbl)) {
			$this->prependTblNames = true;
			$tblStr = $this->sanitizeSqlName($tbl[0]);
			if (is_array($tbl[1])) {
				$joinSql = "";
				foreach ($tbl[1] AS $joinDirTbl => $joinOn) {
					$joinDir = substr($joinDirTbl, 0, 4);
					$joinTbl = $this->sanitizeSqlName(substr($joinDirTbl, 4));
					$joinOnL = $this->sqlColName(key($joinOn), $joinTbl);
					$joinOnR = $this->sqlColName(current($joinOn));
					$joinDirSearch = [
						'[->]',
						'[<-]',
						'[><]',
					];
					$joinDirReplace = [
						'LEFT JOIN',
						'RIGHT JOIN',
						'INNER JOIN',
					];
					$joinDir = str_replace($joinDirSearch, $joinDirReplace, $joinDir);
					$joinQuery .= " " . $joinDir . " `" . $this->tblPrefix . $joinTbl . "`";
					$joinQuery .= " ON " . $joinOnL . " = " . $joinOnR;
				}
			}
		} else {
			$tblStr = $this->sanitizeSqlName($tbl);
		}

		if (is_null($cols) || $cols === '*') {
			$colsStr = '*';
		} else {
			if (is_string($cols)) {
				$colsArray = explode(',', $cols);
			} else {
				$colsArray = $cols;
			}

			$colsStr = '';
			foreach ($colsArray AS $col) {
				$colsStr .= $this->sqlColName($col, $tblStr) . ',';
			}
		}
		$colsStr = trim($colsStr, ',');
		$query = "SELECT " . $colsStr . " FROM `" . $this->tblPrefix . $tblStr . "`";
		$query .= $joinQuery;

		$this->fetchRows = 'multiple';
		$query .= ($where !== null) ? $this->whereClause($where, $tblStr) : "";

		if (is_array($filter)) {
			if (isset($filter['GROUP BY'])) {
				$query .= " GROUP BY ";
				$grpArr = explode(',', $filter['GROUP BY']);
				$i = 1;
				foreach ($grpArr AS $field) {
					$query .= ($i > 1) ? "," : "";
					$query .= $this->sqlColName($field, $tblStr);
					$i++;
				}
			}
			if (isset($filter['ORDER BY']) && is_array($filter['ORDER BY'])) {
				$query .= " ORDER BY ";
				$i = 1;
				foreach ($filter['ORDER BY'] AS $field) {
					$posDirA = strpos($field, 'ASC');
					if ($posDirA !== false) {
						$field = str_replace('ASC', '', $field);
						$dir = 'ASC';
					}
					$posDirD = strpos($field, 'DESC');
					if ($posDirD !== false) {
						$field = str_replace('DESC', '', $field);
						$dir = 'DESC';
					}
					$query .= ($i > 1) ? ", " : "";
					$query .= $this->sqlColName($field, $tblStr);
					$query .= (isset($dir)) ? " " . $dir : "";
					unset($dir);
					$i++;
				}
			}
			if (isset($filter['LIMIT']) && is_int($filter['LIMIT'])) {
				$query .= " LIMIT " . $filter['LIMIT'];
			}
		}

		$query .= ";";
//		return $this->sqlDebugOutput($query);
//		return $this->psParams;

		$prepSt = $this->prepare($query);
		if ($prepSt !== false) {
			foreach ($this->psParams AS $param => $arrVal) {
				if (!isset($arrVal['type'])) {
					$arrVal['type'] = \PDO::PARAM_STR;
				}
				$prepSt->bindValue($param, $arrVal['val'], $arrVal['type']);
			}
			// reset $this->psParams
			$this->psParams = array();
			$prepSt->execute();
			if ($getRowCount) {
				$this->rowCount = $prepSt->rowCount();
			}
			if ($this->fetchRows == 'single') {
				return $prepSt->fetch(\PDO::FETCH_ASSOC);
			} elseif ($this->fetchRows == 'multiple') {
				return $prepSt->fetchAll(\PDO::FETCH_ASSOC);
			}
			return false;
		}
	}

	/**
	 * @param string $tbl Table name
	 * @param array $data --- ['field' => 'value', 'field2' => 'value2']
	 *
	 * @return string \PDO::lastInsertId()
	 */
	public function insert($tbl, $data)
	{
		$tblStr = $this->sanitizeSqlName($tbl);
		$cols = array();
		$params = array();
		$vals = array();
		$cycle = 0;
		foreach ($data as $col => $val) {
			$cols[$cycle] = $this->sqlColName($col);
			$params[$cycle] = $this->col2Param($col);
			$this->psParams[$params[$cycle]]['val'] = $val;
			$this->psParams[$params[$cycle]]['type'] = \PDO::PARAM_STR;
			$cycle++;
		}
		$query = "INSERT INTO `" . $this->tblPrefix . $tblStr . "` ";
		$query .= "(" . implode(', ', $cols) . ") ";
		$query .= "VALUES (:" . implode(', :', $params) . ");";
//		return $this->sqlDebugOutput($query);
		$prepSt = $this->prepare($query);
		if ($prepSt !== false) {
			foreach ($this->psParams AS $param => $arrVal) {
				if (!isset($arrVal['type'])) {
					$arrVal['type'] = \PDO::PARAM_STR;
				}
				$prepSt->bindValue($param, $arrVal['val'], $arrVal['type']);
			}
			// reset $this->psParams
			$this->psParams = array();
			$prepSt->execute();
			return $this->lastInsertId();
		}
		return false;
	}

	/**
	 * @param string $tbl Table name
	 * @param array $data --- ['field' => 'value', 'field2' => 'value2']
	 * @param mixed $where Integer for ID or array [column => value];
	 *     see $this->select() for more information
	 *
	 * @return bool
	 */
	public function update($tbl, $data, $where)
	{
		$tblStr = $this->sanitizeSqlName($tbl);

		$query = "UPDATE `" . $this->tblPrefix . $tblStr . "` SET ";
		$cycle = 0;
		foreach ($data as $col => $val) {
			$colStr = $this->sqlColName($col);
			$param = $this->col2Param($col);
			$query .= ($cycle > 0) ? ", " : "";
			$query .= $colStr . " = :" . $param;
			$this->psParams[$param]['val'] = $val;
			$this->psParams[$param]['type'] = \PDO::PARAM_STR;
			$cycle++;
		}
		$query .= ($where) ? $this->whereClause($where, $tblStr) : "";
//		return $this->sqlDebugOutput($query);
		$prepSt = $this->prepare($query);
		if ($prepSt !== false) {
			foreach ($this->psParams AS $param => $arrVal) {
				if (!isset($arrVal['type'])) {
					$arrVal['type'] = \PDO::PARAM_STR;
				}
				$prepSt->bindValue($param, $arrVal['val'], $arrVal['type']);
			}
			// reset $this->psParams
			$this->psParams = array();
			$prepSt->execute();
			return true;
		}
		return false;
	}

	/**
	 * @param string $tbl Table name
	 * @param mixed $where Integer for ID or array [column => value];
	 *     see $this->select() for more information
	 *
	 * @return bool
	 */
	public function delete($tbl, $where)
	{
		$tblStr = $this->sanitizeSqlName($tbl);
		$query = "DELETE FROM `" . $this->tblPrefix . $tblStr . "` ";
		$query .= ($where) ? $this->whereClause($where, $tblStr) : "";
//		return $this->sqlDebugOutput($query);
		$prepSt = $this->prepare($query);
		if ($prepSt !== false) {
			foreach ($this->psParams AS $param => $arrVal) {
				if (!isset($arrVal['type'])) {
					$arrVal['type'] = \PDO::PARAM_STR;
				}
				$prepSt->bindValue($param, $arrVal['val'], $arrVal['type']);
			}
			// reset $this->psParams
			$this->psParams = array();
			$prepSt->execute();
			$this->tblOptimize($tbl);
			$this->resetAutoinc($tbl);
			return true;
		}
		return false;
	}

	/**
	 * @param mixed $where Record id (int) or array with column/key => val pair
	 *     or multidimensional array with numerical index [['column/key', 'relOp', 'val']]
	 *         for other relational operators than '='
	 *         or if you need to compare one column/key with two values
	 *     columns after the first one can start with [OR]
	 *     in order to link columns with OR instead of AND.
	 *     values can start with exclamation mark (!) to negate the relational operator
	 *     values can start with [FUNCTION] to indicate an SQL function
	 *     EXAMPLE:
	 *         [
	 *             'id' => '!17',
	 *             'idParent' => 9,
	 *             ['date', '>=', '[FUNCTION]CURDATE()'],
	 *             ['date', '<=', '2018-12-12'],
	 *         ]
	 * @param string $tbl For fully qualified table names.
	 *
	 * @return string Where clause.
	 */
	private function whereClause($where, $tbl = null)
	{
		if (is_array($where)) {
			$i = 1;
			$whereStr = " WHERE ";
			foreach ($where AS $col => $val) {
				if (is_array($val)) {
					$col = $val[0];
					$relOp = " " . $this->sanitizeSqlName($val[1]) . " ";
					$valFinal = $val[2];
				} else {
					$valFinal = $val;
					if (strpos($valFinal, '!') !== false) {
						$valFinal = str_replace('!', '', $valFinal);
						$relOp = " != ";
					} else {
						$relOp = " = ";
					}
				}
				if ($i > 1) {
					if (strpos($col, '[OR]') !== false) {
						$col = str_replace('[OR]', '', $col);
						$whereStr .= " OR ";
					} else {
						$whereStr .= " AND ";
					}
				}
				$col = $this->sqlColName($col, $tbl);
				$param = $this->col2Param($col, $tbl);
				if (substr($valFinal, 0, 10) === '[FUNCTION]') {
					$whereStr .= $col . $relOp . $this->sanitizeSqlFunction(substr($valFinal, 10));
				} else {
					$this->psParams[$param]['val'] = $valFinal;
					$this->psParams[$param]['type'] = \PDO::PARAM_STR;
					$whereStr .= $col . $relOp . ":" . $param;
				}
				$i++;
			}
			return $whereStr;
		} elseif (is_int($where * 1)) {
			$this->fetchRows = 'single';
			$col = $this->sqlColName($this->tblUidName, $tbl);
			$param = $this->col2Param($this->tblUidName, $tbl);
			$this->psParams[$param]['val'] = $where * 1;
			$this->psParams[$param]['type'] = \PDO::PARAM_INT;
			return " WHERE " . $col . " = :" . $param;
		}
		return "";
	}

	/**
	 * Returns column name in SQL column quotes, with or without table name.
	 *
	 * @param string $col
	 * @param string $tbl Optional table name, only used if not in string $col.
	 *
	 * @return string $colName
	 */
	private function sqlColName($col, $tbl = null)
	{
		// sanitize column name
		$col = $this->sanitizeSqlName($col);
		$posDot = strpos($col, '.');
		if ($posDot !== false) {
			$colName = '`' . $this->tblPrefix . substr($col, 0, $posDot) . '`.';
			$colName .= '`' . substr($col, $posDot + 1) . '`';
		} else {
			$colName = ($tbl && $this->prependTblNames)
				? '`' . $this->tblPrefix . $tbl . '`.'
				: '';
			$colName .= '`' . $col . '`';
		}
		return $colName;
	}

	/**
	 * Returns column name as parameter for prepared statements,
	 *     with or without table name.
	 *
	 * @param string $col
	 * @param string $tbl Optional table name, only used if not in string $col.
	 *
	 * @return string $paramName In camelCase
	 */
	private function col2Param($col, $tbl = null)
	{
		// sanitize column name
		$col = $this->sanitizeSqlName($col);
		$posDot = strpos($col, '.');
		if ($posDot !== false) {
			$colName = $col;
		} else {
			$colName = ($tbl && $this->prependTblNames)
				? $tbl . '.'
				: '';
			$colName .= $col;
		}
		$param = $this->camelCase($colName);
		/**
		 * check whether this parameter name has already been used
		 * e.g. in $this->update() method
		 * if yes: add a string (multiple times if necessary)
		 */
		if (isset($this->psParams[$param])) {
			$addStr = '_';
			do {
				$param = $param . $addStr;
			} while (isset($this->psParams[$param]));
		}
		return $param;
	}

	/**
	 * Remove spaces, all sorts of quotes, semicolons, colons, commas,
	 *     parentheses, and the table prefix from table and column names
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	private function sanitizeSqlName($name)
	{
		$searchChars = [
			' ',
			':',
			';',
			',',
			'(',
			')',
			'`',
			'"',
			'\''
		];
		$name = str_replace($searchChars, '', $name);
		if (strpos($name, $this->tblPrefix) === 0) {
			return substr_replace($name, '', 0, strlen($this->tblPrefix));
		} else {
			return $name;
		}
	}

	/**
	 * Remove spaces, all sorts of quotes, semicolons, colons, commas,
	 *     parentheses, and the table prefix from table and column names
	 *
	 * @param string $name
	 *
	 * @return string
	 */
	private function sanitizeSqlFunction($name)
	{
		$searchChars = [
			' ',
			':',
			';',
			',',
			'`',
			'"',
			'\''
		];
		return str_replace($searchChars, '', $name);
	}

	/**
	 * turns a string into camelCase,
	 *     replacing spaces, dots, dashes, underscores
	 *
	 * @param string $string
	 *
	 * @return string
	 */
	private function camelCase($string)
	{
		$arr = preg_split('~[\s|\.|\-_]+~', $string);
		$i = 1;
		foreach ($arr AS $key => $str) {
			$arr[$key] = ($i == 1) ? strtolower($str) : ucfirst($str);
			$i++;
		}
		return implode('', $arr);
	}

	/**
	 * Recursive method: returns the root path tree of a database item
	 *
	 * @param string $tbl Database table
	 * @param int $itemId ID of database item
	 * @param mixed $return String or array of what fields to return
	 * @param array $tree The item tree array for recursion
	 * @param int $i Iterator for recursion
	 *
	 * @return array
	 */
	public function itemTree($tbl, $itemId, $return = null, $tree = array(), $i = 0)
	{
		$return = ($return === null) ? $this->tblUidName : $return;
		if (is_array($return)) {
			$cols = $return;
		} else {
			$cols[] = $return;
		}
		$cols[] = 'id_parent';

		$row = $this->select($tbl, $cols, $itemId);

		if ($row) {
			if (is_array($return)) {
				foreach ($return AS $col) {
					if (count($return) > 1) {
						$tree[$i][$col] = $row[$col];
					} else {
						$tree[$i] = $row[$col];
					}
				}
			} else {
				$tree[$i] = $row[$return];
			}
		}
		if ($row['id_parent']) {
			$i++;
			$tree = $this->itemTree($tbl, $row['id_parent'], $return, $tree, $i);
		}
		return $tree;
	}

	/**
	 * Recursive method: returns a property of a parent or
	 * parent’s parent element back to the root element if empty
	 *
	 * @param string $tbl Database table
	 * @param int $itemId ID of database item
	 * @param string $property Database name of property
	 *
	 * @return mixed String property or bool false
	 */
	public function inheritProperty($tbl, $itemId, $property)
	{
		$row = $this->select($tbl, ['id_parent',$property], $itemId);
		if ($row) {
			if ($row[$property] != '') {
				return $row[$property];
			} elseif ($row['id_parent'] !== 0) {
				return $this->inheritProperty($tbl, $row['id_parent'], $property);
			} else {
				return false;
			}
		}
		return false;
	}

	/**
	 * returns a string with line breaks for easier debugging
	 *     as HTML preformatted text
	 * @param string $sqlQuery
	 * @return string
	 */
	private function sqlDebugOutput($query)
	{
		$regexSearch = '~\s([A-Z]+(\s[A-Z]+)?)~';
		$indent = str_repeat(' ', 4);
		$regexReplace = PHP_EOL . $indent . '$1';
		$debugged = preg_replace($regexSearch, $regexReplace, $query);
		$searchArr = [
			'ON',
			PHP_EOL . $indent . 'ASC',
			PHP_EOL . $indent . 'DESC'
		];
		$replaceArr = [
			$indent . 'ON',
			' ASC',
			' DESC'
		];
		$debugged = str_replace($searchArr, $replaceArr, $debugged);
		return '<pre>' . $debugged . '</pre>';
	}

	public function tblListAll()
	{
		$qst = $this->query("SHOW TABLES");
		$arrTbl = $qst->fetchAll(\PDO::FETCH_NUM);
		foreach ($arrTbl AS $key => $arrTblSingle) {
			$arrList[$key] = $arrTblSingle[0];
		}
		return $arrList;
	}

	public function tblColList($tbl, $namesOnly = true)
	{
		$tbl = $this->tblPrefix . $this->sanitizeSqlName($tbl);
		$qst = $this->query("SHOW COLUMNS FROM `".$tbl."`");
		$cols = $qst->fetchAll(\PDO::FETCH_ASSOC);
		foreach ($cols AS $col) {
			if ($namesOnly) {
				$colsReturn[] = $col['Field'];
			} else {
				$colsReturn[$col['Field']] = $col;
			}
		}
		return $colsReturn;
	}

	/**
	 * @param string $nameOld Old table name, will be sanitized and
	 *     checked for existence with and without tblPrefix
	 * @param string $nameNew New table name w/ or w/o prefix, will be sanitized
	 * @return void
	 */
	public function tblRename($nameOld, $nameNew)
	{
		$nameOldTemp = $this->sanitizeSqlName($nameOld);
		$nameNew = $this->tblPrefix . $this->sanitizeSqlName($nameNew);
		$allTbl = $this->tblListAll();
		if (in_array($nameOldTemp, $allTbl)) {
			$nameOld = $nameOldTemp;
		} elseif (in_array($this->tblPrefix . $nameOldTemp, $allTbl)) {
			$nameOld = $this->tblPrefix . $nameOldTemp;
		} else {
			return false;
		}
		$query = "RENAME TABLE `" . $this->dbName . "`.`" . $nameOld . "`";
		$query .= "TO `" . $this->dbName . "`.`" . $nameNew . "`;";
		$this->query($query);
	}

	/**
	 * Only for internal use, must not be used with sensitive data
	 *
	 * @param string $tbl Table name w/ or w/o prefix, will be sanitized
	 * @param string $alterations BE CAREFUL – WILL NOT BE SANITIZED
	 *
	 * @return void
	 */
	public function tblAlter($tbl, $alterations)
	{
		$tblStr = $this->tblPrefix . $this->sanitizeSqlName($tbl);
		$allTbl = $this->tblListAll();
		if (in_array($tblStr, $allTbl)) {
			$this->query("ALTER TABLE `" . $tblStr . "` " . $alterations . ";");
		}
	}

	/**
	 * Only for internal use, must not be used with sensitive data in
	 *     parameter $alterations
	 *
	 * @param string $tbl Table name w/ or w/o prefix, will be sanitized
	 * @param string $colOld Old column name
	 * @param string $colNew New column name, if null, $colOld will be used
	 * @param string $alterations BE CAREFUL – WILL NOT BE SANITIZED.
	 *     Leave empty for simple column rename
	 *
	 * @return void
	 */
	public function tblAlterCol($tbl, $colOld, $colNew = null, $alterations = null)
	{
		$tblStr = $this->tblPrefix . $this->sanitizeSqlName($tbl);
		$colOld = $this->sanitizeSqlName($colOld);
		$colNew = (is_null($colNew)) ? $colOld : $this->sanitizeSqlName($colNew);
		$allTbl = $this->tblListAll();
		if (in_array($tblStr, $allTbl)) {
			$tblCols = $this->tblColList($tblStr, false);
			if (array_key_exists($colOld, $tblCols)) {
				$query = "ALTER TABLE `" . $tblStr . "` ";
				$query .= "CHANGE `" . $colOld . "` `" . $colNew . "` ";
				if (is_null($alterations)) {
					$query .= $tblCols[$colOld]['Type'] . " ";
					if (
						strpos($tblCols[$colOld]['Type'], 'char') !== false ||
						strpos($tblCols[$colOld]['Type'], 'text') !== false
					) {
						$query .= "CHARACTER SET " . $this->dbCharset . " ";
					}
					$query .= ($tblCols[$colOld]['Null'] === 'NO') ? "NOT NULL " : "";
					$query .= ($tblCols[$colOld]['Default'] !== null)
						? "DEFAULT '" . $tblCols[$colOld]['Default'] . "' "
						: "";
					$query .= ($tblCols[$colOld]['Extra'] === 'auto_increment')
						? "AUTO_INCREMENT "
						: "";
				} else {
					$query .= $alterations;
				}
				$query .= ";";
				$this->query($query);
			}
		}
	}

	public function tblOptimize($tbl)
	{
		$tblStr = $this->tblPrefix . $this->sanitizeSqlName($tbl);
		$sql = "OPTIMIZE TABLE `" . $tblStr . "`;";
		$this->query($sql);
	}

	public function resetAutoinc($tbl)
	{
		$cols = $this->tblColList($tbl);
		if (in_array($this->tblUidName, $cols)) {
			$tblStr = $this->tblPrefix . $this->sanitizeSqlName($tbl);
			$qGetMax = "SELECT MAX(`" . $this->tblUidName . "`) ";
			$qGetMax .= "AS 'autoinc' FROM `" . $tblStr . "`;";
			$row = $this->query($qGetMax)->fetch(\PDO::FETCH_ASSOC);
			$qSet = "ALTER TABLE `" . $tblStr . "` ";
			$qSet .= "AUTO_INCREMENT = " . ($row['autoinc']+1) . ";";
			$this->query($qSet);
		}
	}

	/**
	 * __debugInfo controls which data will be output by debug functions
	 *     such as var_dump or print_r
	 * dropping this function will output every single property
	 *     including  database connection information
	 *     and it will do so from other classes using a db object!
	 */
	public function __debugInfo()
	{
		return null;
	}
}
