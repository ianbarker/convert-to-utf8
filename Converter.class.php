<?php

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

require_once $_SERVER['DOCUMENT_ROOT'] . '/includes/db/database.php';

class Converter {

	protected $tables = array();
	protected $dbName;

	public function __construct() {

		$db = new Database();

		// get db name;
		$query = " SELECT DATABASE() ";
		$this->dbName = $db->query($query, 'cell');

		// get all the tables
		$query = " SHOW TABLES ";
		$data = $db->query($query, 'table');
		if ($data) {
			foreach ($data as $row) {
				// get the first element in the array
				$this->tables[] = new Table(reset($row));
			}
		}

	}

	public function getTablesArray() {

		return $this->tables;
	}

	public function convert() {

		$db = new Database();
		// convert all text fields to blobs
		foreach ($this->tables as $table) {
			try {
				$table->blobFields();
			} catch (Exception $e) {
				die('Failed converting fields to blob');
			}
		}
		// convert db to utf8
		$query = " ALTER DATABASE `" . $this->dbName . "` CHARSET=utf8";
		$db->execute_query($query);

		// convert all text fields to utf8 and their original type
		foreach ($this->tables as $table) {
			try {
				$table->utf8Fields();
			} catch (Exception $e) {
				die('Failed converting fields back to their own type');
			}
		}

	}
}

class Table {

	protected $name;
	protected $fields = array();

	public function __construct($name) {

		$this->name = $name;
		$this->populateFields();
	}

	public function populateFields() {

		$db = new Database();

		$query = " SHOW KEYS IN `{$this->name}` ";
		$data = $db->query($query, 'table');
		if (!empty($data)) {
			$keys = array();
			foreach ($data as $row) {
				$keys[$row['Column_name']] = $row;
			}
		}

		$query = " SHOW FIELDS IN `{$this->name}` ";
		$data = $db->query($query, 'table');

		foreach ($data as $row) {
			$this->fields[] = new Field($row['Field'], $row['Type'], $this->name, $keys[$row['Field']]);
		}


	}

	public function blobFields() {

		foreach ($this->fields as $field) {
			if ($field->isText()) {
				try {
					$field->makeBlob();
				} catch (Exception $e) {
					throw $e;
				}
			}
		}
	}
	public function utf8Fields() {

		foreach ($this->fields as $field) {
			if ($field->isText()) {
				try {
					$field->makeUtf8();
				} catch (Exception $e) {
					throw $e;
				}
			}
		}
	}

}

class Field {

	protected $name;
	protected $type;
	protected $table;
	protected $key;
	protected $text = false;

	public function __construct($name, $type, $table, $key = false) {

		$this->name = $name;
		$this->type = $type;
		$this->table = $table;
		$this->key = $key;
		if ($this->type == 'text' || stristr($this->type, 'varchar')) {
			$this->text = true;
		}
	}

	public function makeBlob() {

		$db = new Database();

		if ($this->key) {
			// remove the key as blob likely break it
			$query = " DROP INDEX `".$key['Key_name']."` ON `".$this->table."` ";
			if (!$db->execute_query($query)) throw new Exception('Failed to remove index ' . $key['Key_name']);
		}

		$query = " ALTER TABLE `" . $this->table . "` MODIFY `" . $this->name . "` blob";
		if (!$db->execute_query($query)) throw new Exception('Failed to convert field ' . $this->name . ' to BLOB');

	}

	public function makeUtf8() {

		$db = new Database();
		$query = " ALTER TABLE `" . $this->table . "` MODIFY `" . $this->name . "` " . $this->type . " CHARACTER SET UTF8 ";
		if (!$db->execute_query($query)) throw new Exception('Failed to convert field ' . $this->name . ' to ' . $this->type);

		if ($this->key) {
			// restore the key
			$query = " CREATE INDEX `".$key['Key_name']."` ON `".$this->table."` ";
			if (!$db->execute_query($query)) throw new Exception('Failed to add index ' . $key['Key_name']);
		}
	}

	public function isText() {

		return $this->text;
	}

}
