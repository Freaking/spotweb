<?php

abstract class dbeng_abs {
	private $_error	= '';
	
	/*
	 * Connects to the database
	 */
	abstract function connect();
	
	/*
	 * Executes the query and discards any output. Returns true of no
	 * error was found. No handling of the SQL statement is done
	 */
	abstract function rawExec($sql);
	
	/*
	 * Executes the query with $params as parameters. All parameters are 
	 * parsed through sthe safe() function to prevent SQL injection.
	 *
	 * Returns a single associative array when query succeeds, returns 
	 * an exception when the query fails.
	 */
	abstract function singleQuery($sql, $params = array());

	/*
	 * Executes the query with $params as parameters. All parameters are 
	 * parsed through sthe safe() function to prevent SQL injection.
	 *
	 *
	 * Returns an array of associative arrays when query succeeds, returns 
	 * an exception when the query fails.
	 */
	abstract function arrayQuery($sql, $params = array());

	/*
	 * Database specific 'escape' or 'safe' function to escape strings
	 */
	abstract function safe($s);	
	
	/*
	 * Returns a database specific representation of a boolean value
	 */
	abstract function bool2dt($b);

	/*
	 * Returns the amount of effected rows
	 */
	abstract function rows();
	
	/* 
	 * Begins an transaction
	 */
	abstract function beginTransaction();
	
	/* 
	 * Commits an transaction
	 */
	abstract function commit();
	
	/* 
	 * Rolls back an transaction
	 */
	abstract function rollback();
	
	/* 
	 * Returns the last insertid
	 */
	abstract function lastInsertId($tableName);
	

	/*
	 * Prepares the query string by running vsprintf() met safe() erover heen te gooien
	 */
	function prepareSql($s, $p) {
		/*
		 * When no parameters are given, we don't run vsprintf(). This makes sure
		 * we can use arrayQuery() and singleQuery() with for example LIKE statements 
		 */
		if (empty($p)) {
			return $s;
		} else {
			$p = array_map(array($this, 'safe'), $p);
			return vsprintf($s, $p);
		} # else
	} # prepareSql()

	/*
	 * Executes the query and returns the (resource or handle)
	 */
	function exec($s, $p = array()) {
		return $this->rawExec($this->prepareSql($s, $p));
	} # exec()

	/*
	 * INSERT or UPDATE statement, doesn't return anything. Exception 
	 * thrown if a error occurs
	 */
	abstract function modify($s, $p = array());

} # dbeng_abs
