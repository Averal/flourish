<?php
/**
 * Takes a subset of SQL from MySQL, PostgreSQL, SQLite and MSSQL and translates into the various dialects allowing for cross-database code
 * 
 * @copyright  Copyright (c) 2007-2009 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @package    Flourish
 * @link       http://flourishlib.com/fSQLTranslation
 * 
 * @internal
 * 
 * @version    1.0.0b2
 * @changes    1.0.0b2  Fixed a notice with SQLite foreign key constraints having no `ON` clauses [wb, 2009-02-21]
 * @changes    1.0.0b   The initial implementation [wb, 2007-09-25]
 */
class fSQLTranslation
{
	// The following constants allow for nice looking callbacks to static methods
	const sqliteCotangent    = 'fSQLTranslation::sqliteCotangent';
	const sqliteLogBaseFirst = 'fSQLTranslation::sqliteLogBaseFirst';
	const sqliteSign         = 'fSQLTranslation::sqliteSign';
	
	
	/**
	 * Composes text using fText if loaded
	 * 
	 * @param  string  $message    The message to compose
	 * @param  mixed   $component  A string or number to insert into the message
	 * @param  mixed   ...
	 * @return string  The composed and possible translated message
	 */
	static protected function compose($message)
	{
		$args = array_slice(func_get_args(), 1);
		
		if (class_exists('fText', FALSE)) {
			return call_user_func_array(
				array('fText', 'compose'),
				array($message, $args)
			);
		} else {
			return vsprintf($message, $args);
		}
	}
	
	
	/**
	 * Takes a Flourish SQL `SELECT` query and parses it into clauses.
	 * 
	 * The select statement must be of the format:
	 * 
	 * {{{
	 * SELECT [ table_name. | alias. ]*
	 * FROM table [ AS alias ] [ [ INNER | OUTER ] [ LEFT | RIGHT ] JOIN other_table ON condition | , ] ...
	 * [ WHERE condition [ , condition ]... ]
	 * [ GROUP BY conditions ]
	 * [ HAVING conditions ]
	 * [ ORDER BY [ column | expression ] [ ASC | DESC ] [ , [ column | expression ] [ ASC | DESC ] ] ... ]
	 * [ LIMIT integer [ OFFSET integer ] ]
	 * }}}
	 * 
	 * The returned array will contain the following keys, which may have a `NULL` or non-empty string value:
	 * 
	 *  - `'SELECT'`
	 *  - `'FROM'`
	 *  - `'WHERE'`
	 *  - `'GROUP BY'`
	 *  - `'HAVING'`
	 *  - `'ORDER BY'`
	 *  - `'LIMIT'`
	 * 
	 * @param  string $sql  The SQL to parse
	 * @return array  The various clauses of the `SELECT` statement - see method description for details
	 */
	static private function parseSelectSQL($sql)
	{
		// Split the strings out of the sql so parsing doesn't get messed up by quoted values
		preg_match_all("#(?:'(?:''|\\\\'|\\\\[^']|[^'\\\\]+)*')|(?:[^']+)#", $sql, $matches);
		
		$possible_clauses = array('SELECT', 'FROM', 'WHERE', 'GROUP BY', 'HAVING', 'ORDER BY', 'LIMIT');
		$found_clauses    = array();
		foreach ($possible_clauses as $possible_clause) {
			$found_clauses[$possible_clause] = NULL;
		}
		
		$current_clause = 0;
		
		foreach ($matches[0] as $match) {
			// This is a quoted string value, don't do anything to it
			if ($match[0] == "'") {
				$found_clauses[$possible_clauses[$current_clause]] .= $match;
			
			// Non-quoted strings should be checked for clause markers
			} else {
				
				// Look to see if a new clause starts in this string
				$i = 1;
				while ($current_clause+$i < sizeof($possible_clauses)) {
					// If the next clause is found in this string
					if (stripos($match, $possible_clauses[$current_clause+$i]) !== FALSE) {
						list($before, $after) = preg_split('#\s*' . $possible_clauses[$current_clause+$i] . '\s*#i', $match);
						$found_clauses[$possible_clauses[$current_clause]] .= preg_replace('#\s*' . $possible_clauses[$current_clause] . '\s*#i', '', $before);
						$match = $after;
						$current_clause = $current_clause + $i;
						$i = 0;
					}
					$i++;
				}
				
				// Otherwise just add on to the current clause
				if (!empty($match)) {
					$found_clauses[$possible_clauses[$current_clause]] .= preg_replace('#\s*' . $possible_clauses[$current_clause] . '\s*#i', '', $match);
				}
			}
		}
		
		return $found_clauses;
	}
	
		
	/**
	 * Takes the `FROM` clause from ::parseSelectSQL() and returns all of the tables and each one's alias
	 * 
	 * @param  string $clause  The SQL `FROM` clause to parse
	 * @return array  The tables in the `FROM` clause, in the format `{table_alias} => {table_name}`
	 */
	static private function parseTableAliases($sql)
	{
		$aliases = array();
		
		preg_match_all("#(?:'(?:''|\\\\'|\\\\[^']|[^'\\\\]+)*')|(?:[^']+)#", $sql, $matches);
		
		$sql = '';
		// Replace strings with two single quotes
		foreach ($matches[0] as $match) {
			if ($match[0] == "'") {
				$match = "''";
			}
			$sql .= $match;
		}
		
		// Turn comma joins into cross joins
		if (preg_match('#^(?:\w+(?:\s+(?:as\s+)?(?:\w+))?)(?:\s*,\s*(?:\w+(?:\s+(?:as\s+)?(?:\w+))?))*$#isD', $sql)) {
			$sql = str_replace(',', ' CROSS JOIN ', $sql);
		}
		
		// Error out if we can't figure out the join structure
		if (!preg_match('#^(?:\w+(?:\s+(?:as\s+)?(?:\w+))?)(?:\s+(?:(?:CROSS|INNER|OUTER|LEFT|RIGHT)?\s+)*JOIN\s+(?:\w+(?:\s+(?:as\s+)?(?:\w+))?)(?:\s+ON\s+.*)?)*$#isD', $sql)) {
			throw new fProgrammerException(
				'Unable to parse FROM clause, does not appears to be in comma style or join style'
			);
		}
		
		$tables = preg_split('#\s+((?:(?:CROSS|INNER|OUTER|LEFT|RIGHT)?\s+)*?JOIN)\s+#i', $sql);
		
		foreach ($tables as $table) {
			// This grabs the table name and alias (if there is one)
			preg_match('#\s*([\w.]+)(?:\s+(?:as\s+)?((?!ON)[\w.]+))?\s*(?:ON\s+(.*))?#im', $table, $parts);
			
			$table_name  = $parts[1];
			$table_alias = (isset($parts[2])) ? $parts[2] : $parts[1];
			
			$aliases[$table_alias] = $table_name;
		}
		
		return $aliases;
	}
	
	
	/**
	 * Callback for custom SQLite function; calculates the cotangent of a number
	 * 
	 * @internal
	 * 
	 * @param  numeric $x  The number to calculate the cotangent of
	 * @return numeric  The contangent of `$x`
	 */
	static public function sqliteCotangent($x)
	{
		return 1/tan($x);
	}
	
	
	/**
	 * Callback for custom SQLite function; calculates the log to a specific base of a number
	 * 
	 * @internal
	 * 
	 * @param  integer $base  The base for the log calculation
	 * @param  numeric $num   The number to calculate the logarithm of
	 * @return numeric  The logarithm of `$num` to `$base`
	 */
	static public function sqliteLogBaseFirst($base, $num)
	{
		return log($num, $base);
	}
	
	
	/**
	 * Callback for custom SQLite function; returns the sign of the number
	 * 
	 * @internal
	 * 
	 * @param  numeric $x  The number to change the sign of
	 * @return numeric  `-1` if a negative sign, `0` if zero, `1` if positive sign
	 */
	static public function sqliteSign($x)
	{
		if ($x == 0) {
			return 0;
		}
		if ($x > 0) {
			return 1;
		}
		return -1;
	}
	
	
	/**
	 * The database connection resource or PDO object
	 * 
	 * @var mixed
	 */
	private $connection;
	
	/**
	 * The fDatabase instance
	 * 
	 * @var fDatabase
	 */
	private $database;
	
	/**
	 * If debugging is enabled
	 * 
	 * @var boolean
	 */
	private $debug;
	
	
	/**
	 * Sets up the class and creates functions for SQLite databases
	 * 
	 * @internal
	 * 
	 * @param  fDatabase $database    The database being translated for
	 * @param  mixed     $connection  The connection resource or PDO object
	 * @return fSQLTranslation
	 */
	public function __construct($database, $connection)
	{
		if (!is_resource($connection) && !is_object($connection)) {
			throw new fProgrammerException(
				'The connection specified, %s, is not a valid database connection',
				$connection
			);
		}
		
		$this->connection = $connection;
		$this->database   = $database;
		
		if ($database->getType() == 'sqlite') {
			$this->createSQLiteFunctions();
		}
	}
	
	
	/**
	 * Creates a trigger for SQLite that handles an on delete clause
	 * 
	 * @param  string $referencing_table   The table that contains the foreign key
	 * @param  string $referencing_column  The column the foriegn key constraint is on
	 * @param  string $referenced_table    The table the foreign key references
	 * @param  string $referenced_column   The column the foreign key references
	 * @param  string $delete_clause       What is to be done on a delete
	 * @return string  The trigger
	 */
	private function createSQLiteForeignKeyTriggerOnDelete($referencing_table, $referencing_column, $referenced_table, $referenced_column, $delete_clause)
	{
		switch (strtolower($delete_clause)) {
			case 'no action':
			case 'restrict':
				$sql = "\nCREATE TRIGGER fkd_res_" . $referencing_table . "_" . $referencing_column . "
							 BEFORE DELETE ON " . $referenced_table . "
							 FOR EACH ROW BEGIN
								 SELECT RAISE(ROLLBACK, 'delete on table \"" . $referenced_table . "\" can not be executed because it would violate the foreign key constraint on column \"" . $referencing_column . "\" of table \"" . $referencing_table . "\"')
								 WHERE (SELECT " . $referencing_column . " FROM " . $referencing_table . " WHERE " . $referencing_column . " = OLD." . $referenced_table . ") IS NOT NULL;
							 END;";
				break;
			
			case 'set null':
				$sql = "\nCREATE TRIGGER fkd_nul_" . $referencing_table . "_" . $referencing_column . "
							 BEFORE DELETE ON " . $referenced_table . "
							 FOR EACH ROW BEGIN
								 UPDATE " . $referencing_table . " SET " . $referencing_column . " = NULL WHERE " . $referencing_column . " = OLD." . $referenced_column . ";
							 END;";
				break;
				
			case 'cascade':
				$sql = "\nCREATE TRIGGER fkd_cas_" . $referencing_table . "_" . $referencing_column . "
							 BEFORE DELETE ON " . $referenced_table . "
							 FOR EACH ROW BEGIN
								 DELETE FROM " . $referencing_table . " WHERE " . $referencing_column . " = OLD." . $referenced_column . ";
							 END;";
				break;
		}
		return $sql;
	}
	
	
	/**
	 * Creates a trigger for SQLite that handles an on update clause
	 * 
	 * @param  string $referencing_table   The table that contains the foreign key
	 * @param  string $referencing_column  The column the foriegn key constraint is on
	 * @param  string $referenced_table    The table the foreign key references
	 * @param  string $referenced_column   The column the foreign key references
	 * @param  string $update_clause       What is to be done on an update
	 * @return string  The trigger
	 */
	private function createSQLiteForeignKeyTriggerOnUpdate($referencing_table, $referencing_column, $referenced_table, $referenced_column, $update_clause)
	{
		switch (strtolower($update_clause)) {
			case 'no action':
			case 'restrict':
				$sql = "\nCREATE TRIGGER fku_res_" . $referencing_table . "_" . $referencing_column . "
							 BEFORE UPDATE ON " . $referenced_table . "
							 FOR EACH ROW BEGIN
								 SELECT RAISE(ROLLBACK, 'update on table \"" . $referenced_table . "\" can not be executed because it would violate the foreign key constraint on column \"" . $referencing_column . "\" of table \"" . $referencing_table . "\"')
								 WHERE (SELECT " . $referencing_column . " FROM " . $referencing_table . " WHERE " . $referencing_column . " = OLD." . $referenced_column . ") IS NOT NULL;
							 END;";
				break;
			
			case 'set null':
				$sql = "\nCREATE TRIGGER fku_nul_" . $referencing_table . "_" . $referencing_column . "
							 BEFORE UPDATE ON " . $referenced_table . "
							 FOR EACH ROW BEGIN
								 UPDATE " . $referencing_table . " SET " . $referencing_column . " = NULL WHERE OLD." . $referenced_column . " <> NEW." . $referenced_column . " AND " . $referencing_column . " = OLD." . $referenced_column . ";
							 END;";
				break;
				
			case 'cascade':
				$sql = "\nCREATE TRIGGER fku_cas_" . $referencing_table . "_" . $referencing_column . "
							 BEFORE UPDATE ON " . $referenced_table . "
							 FOR EACH ROW BEGIN
								 UPDATE " . $referencing_table . " SET " . $referencing_column . " = NEW." . $referenced_column . " WHERE OLD." . $referenced_column . " <> NEW." . $referenced_column . " AND " . $referencing_column . " = OLD." . $referenced_column . ";
							 END;";
				break;
		}
		return $sql;
	}
	
	
	/**
	 * Creates a trigger for SQLite that prevents inserting or updating to values the violate a `FOREIGN KEY` constraint
	 * 
	 * @param  string  $referencing_table     The table that contains the foreign key
	 * @param  string  $referencing_column    The column the foriegn key constraint is on
	 * @param  string  $referenced_table      The table the foreign key references
	 * @param  string  $referenced_column     The column the foreign key references
	 * @param  boolean $referencing_not_null  If the referencing columns is set to not null
	 * @return string  The trigger
	 */
	private function createSQLiteForeignKeyTriggerValidInsertUpdate($referencing_table, $referencing_column, $referenced_table, $referenced_column, $referencing_not_null)
	{
		// Verify key on inserts
		$sql  = "\nCREATE TRIGGER fki_ver_" . $referencing_table . "_" . $referencing_column . "
					  BEFORE INSERT ON " . $referencing_table . "
					  FOR EACH ROW BEGIN
						  SELECT RAISE(ROLLBACK, 'insert on table \"" . $referencing_table . "\" violates foreign key constraint on column \"" . $referencing_column . "\"')
							  WHERE ";
		if (!$referencing_not_null) {
			$sql .= "NEW." . $referencing_column . " IS NOT NULL AND ";
		}
		$sql .= " (SELECT " . $referenced_column . " FROM " . $referenced_table . " WHERE " . $referenced_column . " = NEW." . $referencing_column . ") IS NULL;
					  END;";
					
		// Verify key on updates
		$sql .= "\nCREATE TRIGGER fku_ver_" . $referencing_table . "_" . $referencing_column . "
					  BEFORE UPDATE ON " . $referencing_table . "
					  FOR EACH ROW BEGIN
						  SELECT RAISE(ROLLBACK, 'update on table \"" . $referencing_table . "\" violates foreign key constraint on column \"" . $referencing_column . "\"')
							  WHERE ";
		if (!$referencing_not_null) {
			$sql .= "NEW." . $referencing_column . " IS NOT NULL AND ";
		}
		$sql .= " (SELECT " . $referenced_column . " FROM " . $referenced_table . " WHERE " . $referenced_column . " = NEW." . $referencing_column . ") IS NULL;
					  END;";
		
		return $sql;
	}
	
	
	/**
	 * Adds a number of math functions to SQLite that MSSQL, MySQL and PostgreSQL have by default
	 * 
	 * @return void
	 */
	private function createSQLiteFunctions()
	{
		$function = array();
		$functions[] = array('acos',     'acos',                                         1);
		$functions[] = array('asin',     'asin',                                         1);
		$functions[] = array('atan',     'atan',                                         1);
		$functions[] = array('atan2',    'atan2',                                        2);
		$functions[] = array('ceil',     'ceil',                                         1);
		$functions[] = array('ceiling',  'ceil',                                         1);
		$functions[] = array('cos',      'cos',                                          1);
		$functions[] = array('cot',      array('fSQLTranslation', 'sqliteCotangent'),    1);
		$functions[] = array('degrees',  'rad2deg',                                      1);
		$functions[] = array('exp',      'exp',                                          1);
		$functions[] = array('floor',    'floor',                                        1);
		$functions[] = array('ln',       'log',                                          1);
		$functions[] = array('log',      array('fSQLTranslation', 'sqliteLogBaseFirst'), 2);
		$functions[] = array('pi',       'pi',                                           1);
		$functions[] = array('power',    'pow',                                          1);
		$functions[] = array('radians',  'deg2rad',                                      1);
		$functions[] = array('sign',     array('fSQLTranslation', 'sqliteSign'),         1);
		$functions[] = array('sqrt',     'sqrt',                                         1);
		$functions[] = array('sin',      'sin',                                          1);
		$functions[] = array('tan',      'tan',                                          1);
		
		foreach ($functions as $function) {
			if ($this->database->getExtension() == 'pdo') {
				$this->connection->sqliteCreateFunction($function[0], $function[1], $function[2]);
			} else {
				sqlite_create_function($this->connection, $function[0], $function[1], $function[2]);
			}
		}
	}
	
	
	/**
	 * Sets if debug messages should be shown
	 *
	 * @internal
	 *  
	 * @param  boolean $flag  If debugging messages should be shown
	 * @return void
	 */
	public function enableDebugging($flag)
	{
		$this->debug = (boolean) $flag;
	}
	
	
	/**
	 * Fixes pulling unicode data out of national data type MSSQL columns
	 * 
	 * @param  string $sql  The SQL to fix
	 * @return string  The fixed SQL
	 */
	private function fixMSSQLNationalColumns($sql)
	{
		if (!preg_match_all('#^\s*(select.*)$|\(\s*(select(?:\s*(?:[^()\']+|\'(?:\'\'|\\\\\'|\\\\[^\']|[^\'\\\\]+)*\'|\((?2)\)|\(\))+\s*))\s*\)\s*(?= union)|\s+union(?:\s+all)?\s+\(\s*(select(?:\s*(?:[^()\']+|\'(?:\'\'|\\\\\'|\\\\[^\']|[^\'\\\\]+)*\'|\((?3)\)|\(\))+\s*))\s*\)#iD', $sql, $matches)) {
			return $sql;
		}
		
		static $national_columns = NULL;
		static $national_types   = NULL;
		
		if ($national_columns === NULL) {
			$result = $this->database->query(
				"SELECT
						c.table_name  AS 'table',						
						c.column_name AS 'column',
						c.data_type   AS 'type'
					FROM
						INFORMATION_SCHEMA.COLUMNS AS c
					WHERE
						(c.data_type = 'nvarchar' OR
						 c.data_type = 'ntext' OR
						 c.data_type = 'nchar') AND
						c.table_catalog = 'flourish'
					ORDER BY
						lower(c.table_name) ASC,
						lower(c.column_name) ASC"
			);
			
			$national_columns = array();
			
			foreach ($result as $row) {
				if (!isset($national_columns[$row['table']])) {
					$national_columns[$row['table']] = array();	
					$national_types[$row['table']]   = array();
				}	
				$national_columns[$row['table']][] = $row['column'];
				$national_types[$row['table']][$row['column']] = $row['type'];
			}
		}
		
		$selects = array_merge(
			array_filter($matches[1]),
			array_filter($matches[2]),
			array_filter($matches[3])
		);
		
		$additions = array();
		
		foreach ($selects as $select) {
			$clauses       = self::parseSelectSQL($select);
			$table_aliases = self::parseTableAliases($clauses['FROM']);
			
			preg_match_all('#([^,()\']+|\'(?>\'\'|\\\\\'|\\\\[^\']|[^\'\\\\]+)*\'|\((?:(?1)|,)*\)|\(\))+#i', $clauses['SELECT'], $selections);
			$selections    = array_map('trim', $selections[0]);
			$to_fix        = array();
			
			foreach ($selections as $selection) {
				// We just skip CASE statements since we can't really do those reliably
				if (preg_match('#^case#i', $selection)) {
					continue;	
				}
				
				if (preg_match('#(\w+)\.\*#i', $selection, $match)) {
					$table = $table_aliases[$match[1]];
					if (empty($national_columns[$table])) {
						continue;	
					}
					if (!isset($to_fix[$table])) {
						$to_fix[$table] = array();	
					}
					$to_fix[$table] = array_merge($to_fix[$table], $national_columns[$table]);
						
				} elseif (preg_match('#\*#', $selection, $match)) {
					foreach ($table_aliases as $alias => $table) {
						if (empty($national_columns[$table])) {
							continue;	
						}
						if (!isset($to_fix[$table])) {
							$to_fix[$table] = array();	
						}
						$to_fix[$table] = array_merge($to_fix[$table], $national_columns[$table]); 		
					}
					
				} elseif (preg_match('#^(?:(\w+)\.(\w+)|((?:min|max|trim|rtrim|ltrim|substring|replace)\((\w+)\.(\w+).*?\)))(?:\s+as\s+(\w+))?$#iD', $selection, $match)) {
					$table = $match[1] . ((isset($match[4])) ? $match[4] : '');
					$table = $table_aliases[$table];
					
					$column = $match[2] . ((isset($match[5])) ? $match[5] : '');;
					
					if (empty($national_columns[$table]) || !in_array($column, $national_columns[$table])) {
						continue;	
					}
					
					if (!isset($to_fix[$table])) {
						$to_fix[$table] = array();	
					}
					
					// Handle column aliasing
					if (!empty($match[6])) {
						$column = array('column' => $column, 'alias' => $match[6]);	
					}
					
					if (!empty($match[3])) {
						if (!is_array($column)) {
							$column = array('column' => $column);
						}	
						$column['expression'] = $match[3];
					}
					
					$to_fix[$table] = array_merge($to_fix[$table], array($column));
				
				// Match unqualified column names
				} elseif (preg_match('#^(?:(\w+)|((?:min|max|trim|rtrim|ltrim|substring|replace)\((\w+).*?\)))(?:\s+as\s+(\w+))?$#iD', $selection, $match)) {
					$column = $match[1] . ((isset($match[3])) ? $match[3] : '');
					foreach ($table_aliases as $alias => $table) {
						if (empty($national_columns[$table])) {
							continue;	
						}
						if (!in_array($column, $national_columns[$table])) {
							continue;
						}
						if (!isset($to_fix[$table])) {
							$to_fix[$table] = array();	
						}
						
						// Handle column aliasing
						if (!empty($match[4])) {
							$column = array('column' => $column, 'alias' => $match[4]);	
						}
						
						if (!empty($match[2])) {
							if (!is_array($column)) {
								$column = array('column' => $column);
							}	
							$column['expression'] = $match[2];
						}
						
						$to_fix[$table] = array_merge($to_fix[$table], array($column)); 		
					}
				}
			}
			
			$reverse_table_aliases = array_flip($table_aliases);
			foreach ($to_fix as $table => $columns) {
				$columns = array_unique($columns);
				$alias   = $reverse_table_aliases[$table];
				foreach ($columns as $column) {
					if (is_array($column)) {
						if (isset($column['alias'])) {
							$as = ' AS __flourish_mssqln_' . $column['alias'];
						} else {
							$as = ' AS __flourish_mssqln_' . $column['column']; 	
						}
						if (isset($column['expression'])) {
							$expression = $column['expression'];	
						} else {
							$expression = $alias . '.' . $column['column'];
						}
						$column = $column['column'];
					} else {
						$as     = ' AS __flourish_mssqln_' . $column;
						$expression = $alias . '.' . $column;
					}
					if ($national_types[$table][$column] == 'ntext') {
						$cast = 'CAST(' . $expression . ' AS IMAGE)';	
					} else {
						$cast = 'CAST(' . $expression . ' AS VARBINARY(8000))';
					}
					$additions[] = $cast . $as;
				}		
			}
			
			$replace = preg_replace('#\bselect\s+' . preg_quote($clauses['SELECT'], '#') . '#i', 'SELECT ' . join(', ', array_merge($selections, $additions)), $select);
			$sql = str_replace($select, $replace, $sql);	
		}
		
		return $sql;
	}
	
	
	/**
	 * Translates Flourish SQL into the dialect for the current database
	 * 
	 * @internal
	 * 
	 * @param  string $sql  The SQL to translate
	 * @return string  The translated SQL
	 */
	public function translate($sql)
	{
		// Separate the SQL from quoted values
		preg_match_all("#(?:'(?:''|\\\\'|\\\\[^']|[^'\\\\]+)*')|(?:[^']+)#", $sql, $matches);
		
		$new_sql = '';
		foreach ($matches[0] as $match) {
			// This is a quoted string value, don't do anything to it
			if ($match[0] == "'") {
				$new_sql .= $match;
			
			// Raw SQL should be run through the fixes
			} else {
				$new_sql .= $this->translateBasicSyntax($match);
			}
		}
		
		// Fix stuff that includes sql and quotes values
		$new_sql = $this->translateDateFunctions($new_sql);
		$new_sql = $this->translateComplicatedSyntax($new_sql);
		$new_sql = $this->translateCreateTableStatements($new_sql);
		
		if ($this->database->getType() == 'mssql') {
			$new_sql = $this->fixMSSQLNationalColumns($new_sql);	
		}
		
		if ($sql != $new_sql) {
			fCore::debug(
				self::compose(
					"Original SQL:%s",
					"\n" .$sql
				),
				$this->debug
			);
			fCore::debug(
				self::compose(
					"Translated SQL:%s",
					"\n" . $new_sql
				),
				$this->debug
			);
		}
		
		return $new_sql;
	}
	
	
	/**
	 * Translates basic syntax differences of the current database
	 * 
	 * @param  string $sql  The SQL to translate
	 * @return string  The translated SQL
	 */
	private function translateBasicSyntax($sql)
	{
		// SQLite fixes
		if ($this->database->getType() == 'sqlite') {
			
			if ($this->database->getType() == 'sqlite' && $this->database->getExtension() == 'pdo') {
				static $regex_sqlite = array(
					'#\binteger\s+autoincrement\s+primary\s+key\b#i'  => 'INTEGER PRIMARY KEY AUTOINCREMENT',
					'#\bcurrent_timestamp\b#i'                        => "datetime(CURRENT_TIMESTAMP, 'localtime')",
					'#\btrue\b#i'                                     => "'1'",
					'#\bfalse\b#i'                                    => "'0'"
				);
			} else {
				static $regex_sqlite = array(
					'#\binteger\s+autoincrement\s+primary\s+key\b#i'  => 'INTEGER PRIMARY KEY',
					'#\bcurrent_timestamp\b#i'       => "datetime(CURRENT_TIMESTAMP, 'localtime')",
					'#\btrue\b#i'                    => "'1'",
					'#\bfalse\b#i'                   => "'0'"
				);
			}
			
			return preg_replace(array_keys($regex_sqlite), array_values($regex_sqlite), $sql);
		}
		
		// PostgreSQL fixes
		if ($this->database->getType() == 'postgresql') {
			static $regex_postgresql = array(
				'#\blike\b#i'                    => 'ILIKE',
				'#\bblob\b#i'                    => 'bytea',
				'#\binteger\s+autoincrement\b#i' => 'serial'
			);
			
			return preg_replace(array_keys($regex_postgresql), array_values($regex_postgresql), $sql);
		}
		
		// MySQL fixes
		if ($this->database->getType() == 'mysql') {
			static $regex_mysql = array(
				'#\brandom\(#i'                  => 'rand(',
				'#\btext\b#i'                    => 'MEDIUMTEXT',
				'#\bblob\b#i'                    => 'LONGBLOB',
				'#\btimestamp\b#i'               => 'DATETIME',
				'#\binteger\s+autoincrement\b#i' => 'INTEGER AUTO_INCREMENT'
			);
		
			return preg_replace(array_keys($regex_mysql), array_values($regex_mysql), $sql);
		}
		
		// MSSQL fixes
		if ($this->database->getType() == 'mssql') {
			static $regex_mssql = array(
				'#\bbegin\s*(?!tran)#i'          => 'BEGIN TRANSACTION ',
				'#\brandom\(#i'                  => 'RAND(',
				'#\batan2\(#i'                   => 'ATN2(',
				'#\bceil\(#i'                    => 'CEILING(',
				'#\bln\(#i'                      => 'LOG(',
				'#\blength\(#i'                  => 'LEN(',
				'#\bsubstr\(#i'					 => 'SUBSTRING(',
				'#\bblob\b#i'                    => 'IMAGE',
				'#\btimestamp\b#i'               => 'DATETIME',
				'#\btime\b#i'                    => 'DATETIME',
				'#\bdate\b#i'                    => 'DATETIME',
				'#\binteger\s+autoincrement\b#i' => 'INTEGER IDENTITY(1,1)',
				'#\bboolean\b#i'                 => 'BIT',
				'#\btrue\b#i'                    => "'1'",
				'#\bfalse\b#i'                   => "'0'",
				'#\|\|#i'                      => '+'
			);
		
			return preg_replace(array_keys($regex_mssql), array_values($regex_mssql), $sql);
		}
	}
	
	
	/**
	 * Translates more complicated inconsistencies
	 * 
	 * @param  string $sql  The SQL to translate
	 * @return string  The translated SQL
	 */
	private function translateComplicatedSyntax($sql)
	{
		if ($this->database->getType() == 'mssql') {
			
			$sql = $this->translateLimitOffsetToRowNumber($sql);
			
			static $regex_mssql = array(
				// These wrap multiple mssql functions to accomplish another function
				'#\blog\(\s*((?>[^()\',]+|\'[^\']*\'|\((?1)(?:,(?1))?\)|\(\))+)\s*,\s*((?>[^()\',]+|\'[^\']*\'|\((?2)(?:,(?2))?\)|\(\))+)\s*\)#i' => '(LOG(\1)/LOG(\2))',
				'#\btrim\(\s*((?>[^()\',]+|\'(?:\'\'|\\\\\'|\\\\[^\']|[^\'\\\\]+)*\'|\((?1)\)|\(\))+)\s*\)#i' => 'RTRIM(LTRIM(\1))'
			);
		
			$sql = preg_replace(array_keys($regex_mssql), array_values($regex_mssql), $sql);
			
			if (preg_match('#select(\s*(?:[^()\']+|\'(?>\'\'|\\\\\'|\\\\[^\']|[^\'\\\\]+)*\'|\((?1)\)|\(\))+\s*)\s+limit\s+(\d+)#i', $sql, $match)) {
				$sql = str_replace($match[0], 'SELECT TOP ' . $match[2] . $match[1], $sql);
			}
		}
		
		return $sql;
	}
	
	
	/**
	 * Translates the structure of `CREATE TABLE` statements to the database specific syntax
	 * 
	 * @param  string $sql  The SQL to translate
	 * @return string  The translated SQL
	 */
	private function translateCreateTableStatements($sql)
	{
		// Make sure MySQL uses InnoDB tables
		if ($this->database->getType() == 'mysql' && stripos($sql, 'CREATE TABLE') !== FALSE) {
			preg_match_all('#(?<=,|\()\s*(\w+)\s+(?:[a-z]+)(?:\(\d+\))?(?:(\s+NOT\s+NULL)|(\s+DEFAULT\s+(?:[^, \']*|\'(?:\'\'|[^\']+)*\'))|(\s+UNIQUE)|(\s+PRIMARY\s+KEY)|(\s+CHECK\s*\(\w+\s+IN\s+(\(\s*(?:(?:[^, \']+|\'(?:\'\'|[^\']+)*\')\s*,\s*)*\s*(?:[^, \']+|\'(?:\'\'|[^\']+)*\')\))\)))*(\s+REFERENCES\s+\w+\s*\(\s*\w+\s*\)\s*(?:\s+(?:ON\s+DELETE|ON\s+UPDATE)\s+(?:CASCADE|NO\s+ACTION|RESTRICT|SET\s+NULL|SET\s+DEFAULT))*(?:\s+(?:DEFERRABLE|NOT\s+DEFERRABLE))?)?\s*(?:,|\s*(?=\)))#mi', $sql, $matches, PREG_SET_ORDER);
			
			foreach ($matches as $match) {
				if (!empty($match[6])) {
					$sql = str_replace($match[0], "\n " . $match[1] . ' enum' . $match[7] . $match[2] . $match[3] . $match[4] . $match[5] . $match[8] . ', ', $sql);
				}
			}
			
			$sql = preg_replace('#\)\s*;?\s*$#D', ')ENGINE=InnoDB', $sql);
		
		
		// Create foreign key triggers for SQLite
		} elseif ($this->database->getType() == 'sqlite' && preg_match('#CREATE\s+TABLE\s+(\w+)#i', $sql, $table_matches) !== FALSE && stripos($sql, 'REFERENCES') !== FALSE) {
			
			$referencing_table = $table_matches[1];
			
			preg_match_all('#(?:(?<=,|\()\s*(\w+)\s+(?:[a-z]+)(?:\((?:\d+)\))?(?:(\s+NOT\s+NULL)|(?:\s+DEFAULT\s+(?:[^, \']*|\'(?:\'\'|[^\']+)*\'))|(?:\s+UNIQUE)|(?:\s+PRIMARY\s+KEY(?:\s+AUTOINCREMENT)?)|(?:\s+CHECK\s*\(\w+\s+IN\s+\(\s*(?:(?:[^, \']+|\'(?:\'\'|[^\']+)*\')\s*,\s*)*\s*(?:[^, \']+|\'(?:\'\'|[^\']+)*\')\)\)))*(\s+REFERENCES\s+(\w+)\s*\(\s*(\w+)\s*\)\s*(?:\s+(?:ON\s+DELETE\s+(CASCADE|NO\s+ACTION|RESTRICT|SET\s+NULL)))?(?:\s+(?:ON\s+UPDATE\s+(CASCADE|NO\s+ACTION|RESTRICT|SET\s+NULL)))?)?\s*(?:,|\s*(?=\)))|(?<=,|\()\s*FOREIGN\s+KEY\s*(?:(\w+)|\((\w+)\))\s+REFERENCES\s+(\w+)\s*\(\s*(\w+)\s*\)\s*(?:\s+(?:ON\s+DELETE\s+(CASCADE|NO\s+ACTION|RESTRICT|SET\s+NULL)))?(?:\s+(?:ON\s+UPDATE\s+(CASCADE|NO\s+ACTION|RESTRICT|SET\s+NULL)))?\s*(?:,|\s*(?=\))))#mi', $sql, $matches, PREG_SET_ORDER);
			
			// Make sure we have a semicolon so we can add triggers
			$sql = trim($sql);
			if (substr($sql, -1) != ';') {
				$sql .= ';';
			}
			
			$not_null_columns = array();
			foreach ($matches as $match) {
				// Find all of the not null columns
				if (!empty($match[2])) {
					$not_null_columns[] = $match[1];
				}
				
				// If neither of these fields is matched, we don't have a foreign key
				if (empty($match[3]) && empty($match[10])) {
					continue;
				}
				
				// 8 and 9 will be an either/or set, so homogenize
				if (empty($match[9]) && !empty($match[8])) { $match[9] = $match[8]; }
				
				// Handle column level foreign key inserts/updates
				if ($match[1]) {
					$sql .= $this->createSQLiteForeignKeyTriggerValidInsertUpdate($referencing_table, $match[1], $match[4], $match[5], in_array($match[1], $not_null_columns));
				
				// Handle table level foreign key inserts/update
				} elseif ($match[9]) {
					$sql .= $this->createSQLiteForeignKeyTriggerValidInsertUpdate($referencing_table, $match[9], $match[10], $match[11], in_array($match[9], $not_null_columns));
				}
				
				// If none of these fields is matched, we don't have on delete or on update clauses
				if (empty($match[6]) && empty($match[7]) && empty($match[12]) && empty($match[13])) {
					continue;
				}
				
				// Handle column level foreign key delete/update clauses
				if (!empty($match[3])) {
					if ($match[6]) {
						$sql .= $this->createSQLiteForeignKeyTriggerOnDelete($referencing_table, $match[1], $match[4], $match[5], $match[6]);
					}
					if ($match[7]) {
						$sql .= $this->createSQLiteForeignKeyTriggerOnUpdate($referencing_table, $match[1], $match[4], $match[5], $match[7]);
					}
					continue;
				}
				
				// Handle table level foreign key delete/update clauses
				if ($match[12]) {
					$sql .= $this->createSQLiteForeignKeyTriggerOnDelete($referencing_table, $match[9], $match[10], $match[11], $match[12]);
				}
				if ($match[13]) {
					$sql .= $this->createSQLiteForeignKeyTriggerOnUpdate($referencing_table, $match[9], $match[10], $match[11], $match[13]);
				}
			}
		}
		
		return $sql;
	}
	
	
	/**
	 * Translates custom date/time functions to the current database
	 * 
	 * @param  string $sql  The SQL to translate
	 * @return string  The translated SQL
	 */
	private function translateDateFunctions($sql)
	{
		// fix diff_seconds()
		preg_match_all("#diff_seconds\\(((?>(?:[^()',]+|'[^']+')|\\((?1)(?:,(?1))?\\)|\\(\\))+)\\s*,\\s*((?>(?:[^()',]+|'[^']+')|\\((?2)(?:,(?2))?\\)|\\(\\))+)\\)#ims", $sql, $diff_matches, PREG_SET_ORDER);
		foreach ($diff_matches as $match) {
			
			// SQLite
			if ($this->database->getType() == 'sqlite') {
				$sql = str_replace($match[0], "round((julianday(" . $match[2] . ") - julianday('1970-01-01 00:00:00')) * 86400) - round((julianday(" . $match[1] . ") - julianday('1970-01-01 00:00:00')) * 86400)", $sql);
			
			// PostgreSQL
			} elseif ($this->database->getType() == 'postgresql') {
				$sql = str_replace($match[0], "extract(EPOCH FROM age(" . $match[2] . ", " . $match[1] . "))", $sql);
			
			// MySQL
			} elseif ($this->database->getType() == 'mysql') {
				$sql = str_replace($match[0], "(UNIX_TIMESTAMP(" . $match[2] . ") - UNIX_TIMESTAMP(" . $match[1] . "))", $sql);
				
			// MSSQL
			} elseif ($this->database->getType() == 'mssql') {
				$sql = str_replace($match[0], "DATEDIFF(second, " . $match[1] . ", " . $match[2] . ")", $sql);
			}
		}
		
		// fix add_interval()
		preg_match_all("#add_interval\\(((?>(?:[^()',]+|'[^']+')|\\((?1)(?:,(?1))?\\)|\\(\\))+)\\s*,\\s*'([^']+)'\\s*\\)#i", $sql, $add_matches, PREG_SET_ORDER);
		foreach ($add_matches as $match) {
			
			// SQLite
			if ($this->database->getType() == 'sqlite') {
				preg_match_all("#(?:\\+|\\-)\\d+\\s+(?:year|month|day|hour|minute|second)(?:s)?#i", $match[2], $individual_matches);
				$strings = "'" . join("', '", $individual_matches[0]) . "'";
				$sql = str_replace($match[0], "datetime(" . $match[1] . ", " . $strings . ")", $sql);
			
			// PostgreSQL
			} elseif ($this->database->getType() == 'postgresql') {
				$sql = str_replace($match[0], "(" . $match[1] . " + INTERVAL '" . $match[2] . "')", $sql);
			
			// MySQL
			} elseif ($this->database->getType() == 'mysql') {
				preg_match_all("#(\\+|\\-)(\\d+)\\s+(year|month|day|hour|minute|second)(?:s)?#i", $match[2], $individual_matches, PREG_SET_ORDER);
				$intervals_string = '';
				foreach ($individual_matches as $individual_match) {
					$intervals_string .= ' ' . $individual_match[1] . ' INTERVAL ' . $individual_match[2] . ' ' . strtoupper($individual_match[3]);
				}
				$sql = str_replace($match[0], "(" . $match[1] . $intervals_string . ")", $sql);
			
			// MSSQL
			} elseif ($this->database->getType() == 'mssql') {
				preg_match_all("#(\\+|\\-)(\\d+)\\s+(year|month|day|hour|minute|second)(?:s)?#i", $match[2], $individual_matches, PREG_SET_ORDER);
				$date_add_string = '';
				$stack = 0;
				foreach ($individual_matches as $individual_match) {
					$stack++;
					$date_add_string .= 'DATEADD(' . $individual_match[3] . ', ' . $individual_match[1] . $individual_match[2] . ', ';
				}
				$sql = str_replace($match[0], $date_add_string . $match[1] . str_pad('', $stack, ')'), $sql);
			}
		}
		
		return $sql;
	}
	
	
	/**
	 * Translates `LIMIT x OFFSET x` to `ROW_NUMBER() OVER (ORDER BY)` syntax
	 * 
	 * @param  string $sql  The SQL to translate
	 * @return string  The translated SQL
	 */
	private function translateLimitOffsetToRowNumber($sql)
	{
		preg_match_all('#((select(?:\s*(?:[^()\']+|\'(?:\'\'|\\\\\'|\\\\[^\']|[^\'\\\\]+)*\'|\((?1)\)|\(\))+\s*))\s+limit\s+(\d+)\s+offset\s+(\d+))#i', $sql, $matches, PREG_SET_ORDER);
		
		foreach ($matches as $match) {
			$clauses = self::parseSelectSQL($match[1]);
			
			if ($clauses['ORDER BY'] == NULL) {
				$clauses['ORDER BY'] = '1 ASC';
			}
			
			$replacement = '';
			foreach ($clauses as $key => $value) {
				if (empty($value)) {
					continue;
				}
				
				if ($key == 'SELECT') {
					$replacement .= 'SELECT ' . $value . ', ROW_NUMBER() OVER (';
					$replacement .= 'ORDER BY ' . $clauses['ORDER BY'];
					$replacement .= ') AS __flourish_limit_offset_row_num ';
				} elseif ($key == 'LIMIT' || $key == 'ORDER BY') {
					// Skip this clause
				} else {
					$replacement .= $key . ' ' . $value . ' ';
				}
			}
			
			$replacement = 'SELECT * FROM (' . trim($replacement) . ') AS original_query WHERE __flourish_limit_offset_row_num > ' . $match[4] . ' AND __flourish_limit_offset_row_num <= ' . ($match[3] + $match[4]) . ' ORDER BY __flourish_limit_offset_row_num';
			
			$sql = str_replace($match[1], $replacement, $sql);
		}
		
		return $sql;
	}
}



/**
 * Copyright (c) 2007-2009 Will Bond <will@flourishlib.com>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */