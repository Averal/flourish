<?php
/**
 * Handles validation for fActiveRecord classes
 * 
 * @copyright  Copyright (c) 2007-2009 Will Bond
 * @author     Will Bond [wb] <will@flourishlib.com>
 * @license    http://flourishlib.com/license
 * 
 * @package    Flourish
 * @link       http://flourishlib.com/fORMValidation
 * 
 * @version    1.0.0b4
 * @changes    1.0.0b4  Fixed a bug in ::checkUniqueConstraints() related to case-insensitive columns [wb, 2009-02-15]
 * @changes    1.0.0b3  Implemented proper fix for ::addManyToManyValidationRule() [wb, 2008-12-12]
 * @changes    1.0.0b2  Fixed a bug with ::addManyToManyValidationRule() [wb, 2008-12-08]
 * @changes    1.0.0b   The initial implementation [wb, 2007-08-04]
 */
class fORMValidation
{
	// The following constants allow for nice looking callbacks to static methods
	const addConditionalValidationRule = 'fORMValidation::addConditionalValidationRule';
	const addManyToManyValidationRule  = 'fORMValidation::addManyToManyValidationRule';
	const addOneOrMoreValidationRule   = 'fORMValidation::addOneOrMoreValidationRule';
	const addOneToManyValidationRule   = 'fORMValidation::addOneToManyValidationRule';
	const addOnlyOneValidationRule     = 'fORMValidation::addOnlyOneValidationRule';
	const reorderMessages              = 'fORMValidation::reorderMessages';
	const reset                        = 'fORMValidation::reset';
	const setColumnCaseInsensitive     = 'fORMValidation::setColumnCaseInsensitive';
	const setMessageOrder              = 'fORMValidation::setMessageOrder';
	const validate                     = 'fORMValidation::validate';
	const validateRelated              = 'fORMValidation::validateRelated';	
	
	
	/**
	 * Columns that should be treated as case insensitive when checking uniqueness
	 * 
	 * @var array
	 */
	static private $case_insensitive_columns = array();
	
	/**
	 * Conditional validation rules
	 * 
	 * @var array
	 */
	static private $conditional_validation_rules = array();
	
	/**
	 * Ordering rules for validation messages
	 * 
	 * @var array
	 */
	static private $message_orders = array();
	
	/**
	 * One or more validation rules
	 * 
	 * @var array
	 */
	static private $one_or_more_validation_rules = array();
	
	/**
	 * Only one validation rules
	 * 
	 * @var array
	 */
	static private $only_one_validation_rules = array();
	
	/**
	 * Validation rules that require at least one or more *-to-many related records to be associated
	 * 
	 * @var array
	 */
	static private $related_one_or_more_validation_rules = array();
	
	
	/**
	 * Adds a conditional validation rule
	 * 
	 * If a non-empty value is found in the `$main_column`, or if specified, a 
	 * value from the `$conditional_values` array, all of the
	 * `$conditional_columns` will also be required to have a value.
	 *
	 * @param  mixed  $class                The class name or instance of the class this validation rule applies to
	 * @param  string $main_column          The column to check for a value
	 * @param  array  $conditional_values   If `NULL`, any value in the main column will trigger the conditional columns, otherwise the value must match one of these
	 * @param  array  $conditional_columns  The columns that are to be required
	 * @return void
	 */
	static public function addConditionalValidationRule($class, $main_column, $conditional_values, $conditional_columns)
	{
		$class = fORM::getClass($class);
		
		if (!isset(self::$conditional_validation_rules[$class])) {
			self::$conditional_validation_rules[$class] = array();
		}
		
		$rule = array();
		$rule['main_column']         = $main_column;
		$rule['conditional_values']  = $conditional_values;
		$rule['conditional_columns'] = $conditional_columns;
		
		self::$conditional_validation_rules[$class][] = $rule;
	}
	
	
	/**
	 * Add a many-to-many validation rule that requires at least one related record is associated with the current record
	 *
	 * @param  mixed  $class          The class name or instance of the class to add the rule for
	 * @param  string $related_class  The name of the related class
	 * @param  string $route          The route to the related class
	 * @return void
	 */
	static public function addManyToManyValidationRule($class, $related_class, $route=NULL)
	{
		$class = fORM::getClass($class);
		
		if (!isset(self::$related_one_or_more_validation_rules[$class])) {
			self::$related_one_or_more_validation_rules[$class] = array();
		}
		
		if (!isset(self::$related_one_or_more_validation_rules[$class][$related_class])) {
			self::$related_one_or_more_validation_rules[$class][$related_class] = array();
		}
		
		$route = fORMSchema::getRouteName(
			fORM::tablize($class),
			fORM::tablize($related_class),
			$route,
			'many-to-many'
		);
		
		self::$related_one_or_more_validation_rules[$class][$related_class][$route] = TRUE;
	}
	
	
	/**
	 * Adds a one-or-more validation rule that requires at least one of the columns specified has a value
	 *
	 * @param  mixed $class    The class name or instance of the class the columns exists in
	 * @param  array $columns  The columns to check
	 * @return void
	 */
	static public function addOneOrMoreValidationRule($class, $columns)
	{
		$class = fORM::getClass($class);
		
		settype($columns, 'array');
		
		if (!isset(self::$one_or_more_validation_rules[$class])) {
			self::$one_or_more_validation_rules[$class] = array();
		}
		
		$rule = array();
		$rule['columns'] = $columns;
		
		self::$one_or_more_validation_rules[$class][] = $rule;
	}
	
	
	/**
	 * Add a one-to-many validation rule that requires at least one related record is associated with the current record
	 *
	 * @param  mixed  $class          The class name or instance of the class to add the rule for
	 * @param  string $related_class  The name of the related class
	 * @param  string $route          The route to the related class
	 * @return void
	 */
	static public function addOneToManyValidationRule($class, $related_class, $route=NULL)
	{
		$class = fORM::getClass($class);
		
		if (!isset(self::$related_one_or_more_validation_rules[$class])) {
			self::$related_one_or_more_validation_rules[$class] = array();
		}
		
		if (!isset(self::$related_one_or_more_validation_rules[$class][$related_class])) {
			self::$related_one_or_more_validation_rules[$class][$related_class] = array();
		}
		
		$route = fORMSchema::getRouteName(
			fORM::tablize($class),
			fORM::tablize($related_class),
			$route,
			'one-to-many'
		);
		
		self::$related_one_or_more_validation_rules[$class][$related_class][$route] = TRUE;
	}
	
	
	/**
	 * Add an only-one validation rule that requires exactly one of the columns must have a value
	 *
	 * @param  mixed $class    The class name or instance of the class the columns exists in
	 * @param  array $columns  The columns to check
	 * @return void
	 */
	static public function addOnlyOneValidationRule($class, $columns)
	{
		$class = fORM::getClass($class);
		
		settype($columns, 'array');
		
		if (!isset(self::$only_one_validation_rules[$class])) {
			self::$only_one_validation_rules[$class] = array();
		}
		
		$rule = array();
		$rule['columns'] = $columns;
		
		self::$only_one_validation_rules[$class][] = $rule;
	}
	
	
	/**
	 * Validates a value against the database schema
	 *
	 * @param  fActiveRecord  $object       The instance of the class the column is part of
	 * @param  string         $column       The column to check
	 * @param  array          &$values      An associative array of all values going into the row (needs all for multi-field unique constraint checking)
	 * @param  array          &$old_values  The old values from the record
	 * @return string  A validation error message for the column specified
	 */
	static private function checkAgainstSchema($object, $column, &$values, &$old_values)
	{
		$class = fORM::getClass($object);
		$table = fORM::tablize($class);
		
		$column_info = fORMSchema::retrieve()->getColumnInfo($table, $column);
		// Make sure a value is provided for required columns
		if ($values[$column] === NULL && $column_info['not_null'] && $column_info['default'] === NULL && $column_info['auto_increment'] === FALSE) {
			return self::compose(
				'%s: Please enter a value',
				fORM::getColumnName($class, $column)
			);
		}
		
		$message = self::checkDataType($class, $column, $values[$column]);
		if ($message) { return $message; }
		
		// Make sure a valid value is chosen
		if (isset($column_info['valid_values']) && $values[$column] !== NULL && !in_array($values[$column], $column_info['valid_values'])) {
			return self::compose(
				'%1$s: Please choose from one of the following: %2$s',
				fORM::getColumnName($class, $column),
				join(', ', $column_info['valid_values'])
			);
		}
		
		// Make sure the value isn't too long
		if ($column_info['type'] == 'varchar' && isset($column_info['max_length']) && $values[$column] !== NULL && is_string($values[$column]) && fUTF8::len($values[$column]) > $column_info['max_length']) {
			return self::compose(
				'%1$s: Please enter a value no longer than %2$s characters',
				fORM::getColumnName($class, $column),
				$column_info['max_length']
			);
		}
		
		// Make sure the value is the proper length
		if ($column_info['type'] == 'char' && isset($column_info['max_length']) && $values[$column] !== NULL && is_string($values[$column]) && fUTF8::len($values[$column]) != $column_info['max_length']) {
			return self::compose(
				'%1$s: Please enter exactly %2$s characters',
				fORM::getColumnName($class, $column),
				$column_info['max_length']
			);
		}
		
		$message = self::checkUniqueConstraints($object, $column, $values, $old_values);
		if ($message) { return $message; }
		$message = self::checkForeignKeyConstraints($class, $column, $values);
		if ($message) { return $message; }
	}
	
	
	/**
	 * Validates against a conditional validation rule
	 *
	 * @param  mixed  $class                The class name or instance of the class this validation rule applies to
	 * @param  array  &$values              An associative array of all values for the record
	 * @param  string $main_column          The column to check for a value
	 * @param  array  $conditional_values   If `NULL`, any value in the main column will trigger the conditional columns, otherwise the value must match one of these
	 * @param  array  $conditional_columns  The columns that are to be required
	 * @return array  The validation error messages for the rule specified
	 */
	static private function checkConditionalRule($class, &$values, $main_column, $conditional_values, $conditional_columns)
	{
		if ($conditional_values !== NULL)  {
			settype($conditional_values, 'array');
		}
		settype($conditional_columns, 'array');
		
		if (($conditional_values !== NULL && in_array($values[$main_column], $conditional_values)) || ($conditional_values === NULL && strlen((string) $values[$main_column]))) {
			$messages = array();
			foreach ($conditional_columns as $conditional_column) {
				if ($values[$conditional_column] === NULL) {
					$messages[] = self::compose(
						'%s: Please enter a value',
						fORM::getColumnName($class, $conditional_column)
					);
				}
			}
			if (!empty($messages)) {
				return $messages;
			}
		}
	}
	
	
	/**
	 * Validates a value against the database data type
	 *
	 * @param  mixed  $class   The class name or instance of the class the column is part of
	 * @param  string $column  The column to check
	 * @param  mixed  $value   The value to check
	 * @return string  A validation error message for the column specified
	 */
	static private function checkDataType($class, $column, $value)
	{
		$table       = fORM::tablize($class);
		$column_info = fORMSchema::retrieve()->getColumnInfo($table, $column);
		
		if ($value !== NULL) {
			switch ($column_info['type']) {
				case 'varchar':
				case 'char':
				case 'text':
				case 'blob':
					if (!is_string($value) && !is_numeric($value)) {
						return self::compose(
							'%s: Please enter a string',
							fORM::getColumnName($class, $column)
						);
					}
					break;
				case 'integer':
				case 'float':
					if (!is_numeric($value)) {
						return self::compose(
							'%s: Please enter a number',
							fORM::getColumnName($class, $column)
						);
					}
					break;
				case 'timestamp':
					if (strtotime($value) === FALSE) {
						return self::compose(
							'%s: Please enter a date/time',
							fORM::getColumnName($class, $column)
						);
					}
					break;
				case 'date':
					if (strtotime($value) === FALSE) {
						return self::compose(
							'%s: Please enter a date',
							fORM::getColumnName($class, $column)
						);
					}
					break;
				case 'time':
					if (strtotime($value) === FALSE) {
						return self::compose(
							'%s: Please enter a time',
							fORM::getColumnName($class, $column)
						);
					}
					break;
				
			}
		}
	}
	
	
	/**
	 * Validates values against foreign key constraints
	 *
	 * @param  mixed  $class    The class name or instance of the class to check the foreign keys for
	 * @param  string $column   The column to check
	 * @param  array  &$values  The values to check
	 * @return string  A validation error message for the column specified
	 */
	static private function checkForeignKeyConstraints($class, $column, &$values)
	{
		if ($values[$column] === NULL) {
			return;
		}
		
		$table        = fORM::tablize($class);
		$foreign_keys = fORMSchema::retrieve()->getKeys($table, 'foreign');
		
		foreach ($foreign_keys AS $foreign_key) {
			if ($foreign_key['column'] == $column) {
				try {
					$sql  = "SELECT " . $foreign_key['foreign_column'];
					$sql .= " FROM " . $foreign_key['foreign_table'];
					$sql .= " WHERE ";
					$sql .= $column . fORMDatabase::escapeBySchema($table, $column, $values[$column], '=');
					$sql  = str_replace('WHERE ' . $column, 'WHERE ' . $foreign_key['foreign_column'], $sql);
					
					$result = fORMDatabase::retrieve()->translatedQuery($sql);
					$result->tossIfNoRows();
				} catch (fNoRowsException $e) {
					return self::compose(
						'%s: The value specified is invalid',
						fORM::getColumnName($class, $column)
					);
				}
			}
		}
	}
	
	
	/**
	 * Validates against a one-or-more validation rule
	 *
	 * @param  mixed  $class    The class name or instance of the class the columns are part of
	 * @param  array  &$values  An associative array of all values for the record
	 * @param  array  $columns  The columns to check
	 * @return string  A validation error message for the rule
	 */
	static private function checkOneOrMoreRule($class, &$values, $columns)
	{
		settype($columns, 'array');
		
		$found_value = FALSE;
		foreach ($columns as $column) {
			if ($values[$column] !== NULL) {
				$found_value = TRUE;
			}
		}
		
		if (!$found_value) {
			$column_names = array();
			foreach ($columns as $column) {
				$column_names[] = fORM::getColumnName($class, $column);
			}
			return self::compose(
				'%s: Please enter a value for at least one',
				join(', ', $column_names)
			);
		}
	}
	
	
	/**
	 * Validates against an only-one validation rule
	 *
	 * @param  mixed  $class    The class name or instance of the class the columns are part of
	 * @param  array  &$values  An associative array of all values for the record
	 * @param  array  $columns  The columns to check
	 * @return string  A validation error message for the rule
	 */
	static private function checkOnlyOneRule($class, &$values, $columns)
	{
		settype($columns, 'array');
		
		$found_value = FALSE;
		foreach ($columns as $column) {
			if ($values[$column] !== NULL) {
				if ($found_value) {
					$column_names = array();
					foreach ($columns as $column) {
						$column_names[] = fORM::getColumnName($class, $column);
					}
					return self::compose(
						'%s: Please enter a value for only one',
						join(', ', $column_names)
					);
				}
				$found_value = TRUE;
			}
		}
	}
	
	
	/**
	 * Makes sure a record with the same primary keys is not already in the database
	 *
	 * @param  fActiveRecord  $object       The instance of the class to check
	 * @param  array          &$values      An associative array of all values going into the row (needs all for multi-field unique constraint checking)
	 * @param  array          &$old_values  The old values for the record
	 * @return string  A validation error message
	 */
	static private function checkPrimaryKeys($object, &$values, &$old_values)
	{
		$class = fORM::getClass($object);
		$table = fORM::tablize($class);
		
		$primary_keys = fORMSchema::retrieve()->getKeys($table, 'primary');
		$columns      = array();
		
		$found_value  = FALSE;
		foreach ($primary_keys as $primary_key) {
			$columns[] = fORM::getColumnName($class, $primary_key);
			if ($values[$primary_key]) {
				$found_value = TRUE;	
			}
		}
		
		if (!$found_value) {
			return;	
		}
		
		$different = FALSE;
		foreach ($primary_keys as $primary_key) {
			if (!fActiveRecord::hasOld($old_values, $primary_key)) {
				continue;	
			}
			$old_value = fActiveRecord::retrieveOld($old_values, $primary_key);
			$value     = $values[$primary_key];
			if (self::isCaseInsensitive($class, $primary_key) && self::stringlike($value) && self::stringlike($old_value)) {
				if (strtolower($value) != strtolower($old_value)) {
					$different = TRUE;
				}	
			} elseif ($old_value != $value) {
				$different = TRUE;	
			}
		}
		
		if (!$different) {
			return;	
		}
		
		try {
			$sql    = "SELECT " . join(', ', $primary_keys) . " FROM " . $table . " WHERE ";
			$conditions = array();
			foreach ($primary_keys as $primary_key) {
				if (self::isCaseInsensitive($class, $primary_key) && self::stringlike($values[$primary_key])) {
					$conditions[] = 'LOWER(' . $primary_key . ')' . fORMDatabase::escapeBySchema($table, $primary_key, strtolower($values[$primary_key]), '=');
				} else {
					$conditions[] = $primary_key . fORMDatabase::escapeBySchema($table, $primary_key, $values[$primary_key], '=');
				} 
			}
			$sql .= join(' AND ', $conditions);
			
			$result = fORMDatabase::retrieve()->translatedQuery($sql);
			$result->tossIfNoRows();
			
			return self::compose(
				'Another %1$s with the same %2$s already exists',
				fORM::getRecordName($class),
				fGrammar::joinArray($columns, 'and')
			);
			
		} catch (fNoRowsException $e) { }
	}
	
	
	/**
	 * Validates against a *-to-many one or more validation rule
	 *
	 * @param  mixed  $class             The class name or instance of the class we are checking
	 * @param  array  &$related_records  The related records array to check
	 * @param  string $related_class     The name of the related class
	 * @param  string $route             The name of the route from the class to the related class
	 * @return string  A validation error message for the rule
	 */
	static private function checkRelatedOneOrMoreRule($class, &$related_records, $related_class, $route)
	{
		$related_table = fORM::tablize($related_class);
		if (isset($related_records[$related_table][$route]['record_set'])) {
			$record_set = $related_records[$related_table][$route]['record_set'];	
			if ($record_set->count() && $record_set->isFlaggedForAssociation()) {
				return;
			}
		}
		
		return self::compose(
			'%s: Please select at least one',
			fGrammar::pluralize(fORMRelated::getRelatedRecordName($class, $related_class, $route))
		);
	}
	
	
	/**
	 * Validates values against unique constraints
	 *
	 * @param  fActiveRecord  $object       The instance of the class to check
	 * @param  string         $column       The column to check
	 * @param  array          &$values      The values to check
	 * @param  array          &$old_values  The old values for the record
	 * @return string  A validation error message for the unique constraints
	 */
	static private function checkUniqueConstraints($object, $column, &$values, &$old_values)
	{
		$class = fORM::getClass($object);
		$table = fORM::tablize($class);
		
		$key_info = fORMSchema::retrieve()->getKeys($table);
		
		$primary_keys = $key_info['primary'];
		$unique_keys  = $key_info['unique'];
		
		foreach ($unique_keys AS $unique_columns) {
			settype($unique_columns, 'array');
			if (in_array($column, $unique_columns)) {
				// NULL values are unique
				$found_not_null = FALSE;
				foreach ($unique_columns as $unique_column) {
					if ($values[$unique_column] !== NULL) {
						$found_not_null = TRUE;
					}
				}
				if (!$found_not_null) {
					continue;
				}
				
				$sql = "SELECT " . join(', ', $key_info['primary']) . " FROM " . $table . " WHERE ";
				$first = TRUE;
				foreach ($unique_columns as $unique_column) {
					if ($first) { $first = FALSE; } else { $sql .= " AND "; }
					$value = $values[$unique_column];
					if (self::isCaseInsensitive($class, $column) && (is_string($value) || is_numeric($value))) {
						$sql .= 'LOWER(' . $unique_column . ')' . fORMDatabase::escapeBySchema($table, $unique_column, strtolower($value), '=');
					} else {
						$sql .= $unique_column . fORMDatabase::escapeBySchema($table, $unique_column, $value, '=');
					}
				}
				
				if ($object->exists()) {
					foreach ($primary_keys as $primary_key) {
						$value = fActiveRecord::retrieveOld($old_values, $primary_key, $values[$primary_key]);
						$sql  .= ' AND ' . $primary_key . fORMDatabase::escapeBySchema($table, $primary_key, $value, '<>');
					}
				}
				
				try {
					$result = fORMDatabase::retrieve()->translatedQuery($sql);
					$result->tossIfNoRows();
				
					// If an exception was not throw, we have existing values
					$column_names = array();
					foreach ($unique_columns as $unique_column) {
						$column_names[] = fORM::getColumnName($class, $unique_column);
					}
					if (sizeof($column_names) == 1) {
						return self::compose(
							'%s: The value specified must be unique, however it already exists',
							join('', $column_names)
						);
					} else {
						return self::compose(
							'%s: The values specified must be a unique combination, however the specified combination already exists',
							join(', ', $column_names)
						);
					}
				
				} catch (fNoRowsException $e) { }
			}
		}
	}
	
	
	/**
	 * Composes text using fText if loaded
	 * 
	 * @param  string  $message    The message to compose
	 * @param  mixed   $component  A string or number to insert into the message
	 * @param  mixed   ...
	 * @return string  The composed and possible translated message
	 */
	static private function compose($message)
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
	 * Makes sure each rule array is set to at least an empty array
	 *
	 * @internal
	 * 
	 * @param  mixed  $class  The class name or an instance of the class to initilize the arrays for
	 * @return void
	 */
	static private function initializeRuleArrays($class)
	{
		self::$conditional_validation_rules[$class]         = (isset(self::$conditional_validation_rules[$class]))         ? self::$conditional_validation_rules[$class]         : array();
		self::$one_or_more_validation_rules[$class]         = (isset(self::$one_or_more_validation_rules[$class]))         ? self::$one_or_more_validation_rules[$class]         : array();
		self::$only_one_validation_rules[$class]            = (isset(self::$only_one_validation_rules[$class]))            ? self::$only_one_validation_rules[$class]            : array();
		self::$related_one_or_more_validation_rules[$class] = (isset(self::$related_one_or_more_validation_rules[$class])) ? self::$related_one_or_more_validation_rules[$class] : array();
	}
	
	
	/**
	 * Checks to see if a column has been set as case insensitive
	 *
	 * @internal
	 * 
	 * @param  mixed  $class   The class to check
	 * @param  string $column  The column to check
	 * @return boolean  If the column is set to be case insensitive
	 */
	static private function isCaseInsensitive($class, $column)
	{
		if (!isset(self::$case_insensitive_columns[$class][$column])) {
			return FALSE;
		}	
		return TRUE;
	}
	
	
	/**
	 * Reorders list items in an html string based on their contents
	 * 
	 * @internal
	 * 
	 * @param  mixed $class                 The class name or an instance of the class to reorder messages for
	 * @param  array &$validation_messages  An array of one validation message per value
	 * @return void
	 */
	static public function reorderMessages($class, &$validation_messages)
	{
		$class = fORM::getClass($class);
		
		if (!isset(self::$message_orders[$class])) {
			return;
		}
			
		$matches = self::$message_orders[$class];
		
		$ordered_items = array_fill(0, sizeof($matches), array());
		$other_items   = array();
		
		foreach ($validation_messages as $validation_message) {
			foreach ($matches as $num => $match_string) {
				if (strpos($validation_message, $match_string) !== FALSE) {
					$ordered_items[$num][] = $validation_message;
					continue 2;
				}
			}
			
			$other_items[] = $validation_message;
		}
		
		$final_list = array();
		foreach ($ordered_items as $ordered_item) {
			$final_list = array_merge($final_list, $ordered_item);
		}
		$validation_messages = array_merge($final_list, $other_items);
	}
	
	
	/**
	 * Resets the configuration of the class
	 * 
	 * @internal
	 * 
	 * @return void
	 */
	static public function reset()
	{
		self::$case_insensitive_columns             = array();
		self::$conditional_validation_rules         = array();
		self::$message_orders                       = array();
		self::$one_or_more_validation_rules         = array();
		self::$only_one_validation_rules            = array();
		self::$related_one_or_more_validation_rules = array();
	}
	
	
	/**
	 * Sets a column to be compared in a case-insensitive manner when checking `UNIQUE` and `PRIMARY KEY` constraints
	 *
	 * @param  mixed  $class   The class name or instance of the class the column is located in
	 * @param  string $column  The column to set as case-insensitive
	 * @return void
	 */
	static public function setColumnCaseInsensitive($class, $column)
	{
		$class = fORM::getClass($class);
		$table = fORM::tablize($class);
		
		$type = fORMSchema::retrieve()->getColumnInfo($table, $column, 'type');
		$valid_types = array('varchar', 'char', 'text');
		if (!in_array($type, $valid_types)) {
			throw new fProgrammerException(
				'The column specified, %1$s, is of the data type %2$s. Must be one of %3$s to be treated as case insensitive.',
				$column,
				$type,
				join(', ', $valid_types)
			);
		}
		
		if (!isset(self::$case_insensitive_columns[$class])) {
			self::$case_insensitive_columns[$class] = array();
		}
		
		self::$case_insensitive_columns[$class][$column] = TRUE;
	}
	
	
	/**
	 * Allows setting the order that the list items in a validation message will be displayed
	 *
	 * @param  mixed $class    The class name or an instance of the class to set the message order for
	 * @param  array $matches  This should be an ordered array of strings. If a line contains the string it will be displayed in the relative order it occurs in this array.
	 * @return void
	 */
	static public function setMessageOrder($class, $matches)
	{
		$class = fORM::getClass($class);
		uasort($matches, array('self', 'sortMessageMatches'));
		self::$message_orders[$class] = $matches;
	}
	
	
	/**
	 * Compares the message matching strings by longest first so that the longest matches are made first
	 *
	 * @param  string $a  The first string to compare
	 * @param  string $b  The second string to compare
	 * @return integer  `-1` if `$a` is longer than `$b`, `0` if they are equal length, `1` if `$a` is shorter than `$b`
	 */
	static private function sortMessageMatches($a, $b)
	{
		if (strlen($a) == strlen($b)) {
			return 0;	
		}
		if (strlen($a) > strlen($b)) {
			return -1;	
		}
		return 1;
	}
	
	
	/**
	 * Returns `TRUE` for non-empty strings, numbers, objects, empty numbers and string-like numbers (such as `0`, `0.0`, `'0'`)
	 * 
	 * @param  mixed $value  The value to check
	 * @return boolean  If the value is string-like
	 */
	static private function stringlike($value)
	{
		if ((!is_string($value) && !is_object($value) && !is_numeric($value)) || !strlen(trim($value))) {
			return FALSE;	
		}
		
		return TRUE;
	}
	
	
	/**
	 * Validates values for an fActiveRecord object against the database schema and any additional validation rules that have been added
	 *
	 * @internal
	 * 
	 * @param  fActiveRecord  $object      The instance of the class to validate
	 * @param  array          $values      The values to validate
	 * @param  array          $old_values  The old values for the record
	 * @return array  An array of validation messages
	 */
	static public function validate($object, $values, $old_values)
	{
		$class = fORM::getClass($object);
		$table = fORM::tablize($class);
		
		self::initializeRuleArrays($class);
		
		$validation_messages = array();
		
		// Convert objects into values for validation
		foreach ($values as $column => $value) {
			$values[$column] = fORM::scalarize($class, $column, $value);
		}
		foreach ($old_values as $column => $column_values) {
			foreach ($column_values as $key => $value) {
				$old_values[$column][$key] = fORM::scalarize($class, $column, $value);
			}
		}
		
		$message = self::checkPrimaryKeys($object, $values, $old_values);
		if ($message) { $validation_messages[] = $message; }
		
		$column_info = fORMSchema::retrieve()->getColumnInfo($table);
		foreach ($column_info as $column => $info) {
			$message = self::checkAgainstSchema($object, $column, $values, $old_values);
			if ($message) { $validation_messages[] = $message; }
		}
		
		foreach (self::$conditional_validation_rules[$class] as $rule) {
			$messages = self::checkConditionalRule($class, $values, $rule['main_column'], $rule['conditional_values'], $rule['conditional_columns']);
			if ($messages) { $validation_messages = array_merge($validation_messages, $messages); }
		}
		
		foreach (self::$one_or_more_validation_rules[$class] as $rule) {
			$message = self::checkOneOrMoreRule($class, $values, $rule['columns']);
			if ($message) { $validation_messages[] = $message; }
		}
		
		foreach (self::$only_one_validation_rules[$class] as $rule) {
			$message = self::checkOnlyOneRule($class, $values, $rule['columns']);
			if ($message) { $validation_messages[] = $message; }
		}
		
		return $validation_messages;
	}
	
	
	/**
	 * Validates related records for an fActiveRecord object
	 *
	 * @internal
	 * 
	 * @param  mixed $class             The class name or instance of the class to validate
	 * @param  array &$related_records  The related records to validate
	 * @return array  An array of validation messages
	 */
	static public function validateRelated($class, &$related_records)
	{
		$class = fORM::getClass($class);
		$table = fORM::tablize($class);
		
		$validation_messages = array();
		
		// Check related validation rules 
		foreach (self::$related_one_or_more_validation_rules[$class] as $related_class => $routes) {
			foreach ($routes as $route => $enabled) {
				$message = self::checkRelatedOneOrMoreRule($class, $related_records, $related_class, $route);
				if ($message) { $validation_messages[] = $message; }
			}
		}
		
		// Find the record sets to validate
		foreach ($related_records as $related_table => $routes) {
			foreach ($routes as $route => $info) {
				$record_set = $info['record_set'];
				if (!$record_set || !$record_set->isFlaggedForAssociation()) {
					continue;
				}
				
				$related_class = fORM::classize($related_table);
				$relationship  = fORMSchema::getRoute($table, $related_table, $route);
																												
				if (isset($relationship['join_table'])) {
					$related_messages = self::validateManyToMany($class, $related_class, $route, $record_set);
				} else {
					$related_messages = self::validateOneToMany($class, $related_class, $route, $record_set);
				}
				
				$validation_messages = array_merge($validation_messages, $related_messages);
			}
		}
		
		return $validation_messages;
	}
	
	
	/**
	 * Validates one-to-many related records
	 *
	 * @internal
	 * 
	 * @param  mixed      $class          The class name or instance of the class these records are related to
	 * @param  string     $related_class  The name of the class for this record set
	 * @param  string     $route          The route between the table and related table
	 * @param  fRecordSet $record_set     The related records to validate
	 * @return array  An array of validation messages
	 */
	static private function validateOneToMany($class, $related_class, $route, $record_set)
	{
		$table               = fORM::tablize($class);
		$related_table       = fORM::tablize($related_class);
		$route_name          = fORMSchema::getRouteName($table, $related_table, $route, 'one-to-many');
		$filter              = fORMRelated::determineRequestFilter($class, $related_class, $route);
		$related_record_name = fORMRelated::getRelatedRecordName($class, $related_class, $route);
		
		$record_number = 1;
		
		$messages = array();
		
		foreach ($record_set as $record) {
			fRequest::filter($filter, $record_number-1);
			$record_messages = $record->validate(TRUE);
			foreach ($record_messages as $record_message) {
				// Ignore validation messages about the primary key since it will be added
				if (strpos($record_message, fORM::getColumnName($related_class, $route_name)) !== FALSE) {
					continue;
				}
				$messages[] = self::compose(
					'%1$s #%2$s %3$s',
					$related_record_name,
					$record_number,
					$record_message
				);
			}
			$record_number++;
			fRequest::unfilter();
		}
		
		return $messages;
	}
	
	
	/**
	 * Validates many-to-many related records
	 *
	 * @internal
	 * 
	 * @param  mixed      $class          The class name or instance of the class these records are related to
	 * @param  string     $related_class  The name of the class for this record set
	 * @param  string     $route          The route between the table and related table
	 * @param  fRecordSet $record_set     The related records to validate
	 * @return array  An array of validation messages
	 */
	static private function validateManyToMany($class, $related_class, $route, $record_set)
	{
		$related_record_name = fORMRelated::getRelatedRecordName($class, $related_class, $route);
		$record_number = 1;
		
		$messages = array();
		
		foreach ($record_set as $record) {
			if (!$record->exists()) {
				$messages[] = self::compose(
					'%1$s #%2$s: Please select a %3$s',
					$related_record_name,
					$record_number,
					$related_record_name
				);
			}
			$record_number++;
		}
		
		return $messages;
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