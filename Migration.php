<?php
	/**
	 * Created by PhpStorm
	 * User: Danny Kopping
	 * Date: 31 Aug 2010
	 *
	 * http://blog.flexologist.com
	 */
	class Migration
	{
		private $config;

		private $fromConn;
		private $toConn;

		public function __construct()
		{
			$this->config = $this->readConfig("config.xml");
			$this->config = $this->parse($this->config);

			$from = $this->config["from"];
			$this->fromConn = $this->getConnection($from["host"], $from["username"],
													$from["password"], $from["database"], "mysql");

			$to = $this->config["to"];
			$this->toConn = $this->getConnection($to["host"], $to["username"],
													$to["password"], $to["database"], "mysql");
		}

		private function readConfig($path)
		{
			$f = fopen($path, "r");
			$contents = fread($f, filesize($path));
			fclose($f);
			return $contents;
		}

		private function parse($config)
		{
			$config = new SimpleXMLElement($config);
			$parsed = array();

			$fromNodes = $config->xpath("databases/from");
			$parsed["from"] = $this->childrenToAssoc($fromNodes[0]);

			$toNodes = $config->xpath("databases/to");
			$parsed["to"] = $this->childrenToAssoc($toNodes[0]);

			$migrationNodes = $config->xpath("migrations//migration");
			foreach($migrationNodes as $migrationNode)
			{
				$migration = array();

				$fromNode = $migrationNode->xpath("from");
				$migration["from"] = (string) $fromNode[0]["table"];

				$toNode = $migrationNode->xpath("to");
				$migration["to"] = (string) $toNode[0]["table"];

				$migration["transformations"] = array();
				$transformations = $migrationNode->xpath("transformations//field");
				foreach($transformations as $transform)
				{
					$migration["transformations"][] = array(
								"from" => (string) $transform["from"],
								"to" => (string) $transform["to"],
								"type" => strtolower((string) $transform["type"])
					);
				}

				$parsed["migrations"][] = $migration;
			}

			return $parsed;
		}

		private function childrenToAssoc($node)
		{
			$node = new SimpleXMLElement($node->asXML());

			$assoc = array();

			$children = $node->children();
			foreach($children as $key => $val)
				$assoc[(string) $key] = (string) $val;

			return $assoc;
		}

		private function getConnection($hostname, $username, $password, $database, $engine="mysql")
		{
			try
			{
				$connection = new PDO("$engine:host=$hostname;dbname=$database", $username, $password);
				$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

				return $connection;
			}
			catch(Exception $e)
			{
				die($e->getMessage());
			}
		}

		public function migrate()
		{
			foreach($this->config["migrations"] as $migration)
			{
				$fromTable = $migration["from"];
				$toTable = $migration["to"];

				$transformFields = array();

				foreach($migration["transformations"] as $transform)
					$transformFields[$transform["from"]] = array(
						"field" => $transform["to"], "type" => $transform["type"]
					);

				$select = "SELECT ".join(", ", array_keys($transformFields))." FROM $fromTable";
				$stmt = $this->fromConn->query($select);

				$fromRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

				foreach($fromRecords as $record)
					$this->transformAndInsert($toTable, $record, $transformFields);
			}
		}

		private function transformAndInsert($table, $record, $transformFields)
		{
			$fields = array();
			$bindings = array();

			foreach($transformFields as $key => $value)
			{
				$oldField = $key;
				$newField = $transformFields[$key]["field"];

				$fields[] = $newField;
				$bindings[] = ":$oldField";
			}

			$bindings = join(", ", $bindings);
			$fields = join(", ", $fields);

			$insert = "INSERT INTO $table ($fields) VALUES ($bindings)";
			$stmt = $this->toConn->prepare($insert);

			foreach($transformFields as $key => $value)
			{
				$oldField = $key;
				$type = $transformFields[$key]["type"];

				$stmt->bindParam(":$oldField", $record[$oldField], $this->getPDOType($type));
			}

			$stmt->execute();
		}

		private function getPDOType($type)
		{
			switch($type)
			{
				case "string":
					$type = PDO::PARAM_STR;
				default:
					break;
				case "int":
					$type = PDO::PARAM_INT;
					break;
				case "boolean":
					$type = PDO::PARAM_BOOL;
					break;
				case "blob":
				case "clob":
					$type = PDO::PARAM_LOB;
					break;
			}

			return $type;
		}
	}

	$migration = new Migration();
	$migration->migrate();
?>