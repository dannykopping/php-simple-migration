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

		private $successCount = 0;
		private $failCount = 0;

		/**
		 * Initiate the migration by connection to "from" and "to" databases
		 *
		 * @return void
		 */
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

		/**
		 * Reads the configuration file
		 *
		 * @param  $path
		 * @return string
		 */
		private function readConfig($path)
		{
			$f = fopen($path, "r");
			$contents = fread($f, filesize($path));
			fclose($f);
			return $contents;
		}

		/**
		 * Parses the configuration file
		 *
		 * @param  $config
		 * @return array
		 */
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

		/**
		 * Converts XML child nodes to an associative array
		 *
		 * @param  $node
		 * @return array
		 */
		private function childrenToAssoc($node)
		{
			$node = new SimpleXMLElement($node->asXML());

			$assoc = array();

			$children = $node->children();
			foreach($children as $key => $val)
				$assoc[(string) $key] = (string) $val;

			return $assoc;
		}

		/**
		 * Returns a PDO connection based on connection details
		 *
		 * @param  $hostname
		 * @param  $username
		 * @param  $password
		 * @param  $database
		 * @param string $engine
		 * @return PDO
		 */
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

		/**
		 * Performs the migration
		 *
		 * @return void
		 */
		public function migrate()
		{
			$this->successCount = 0;
			$this->failCount = 0;

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

			echo "-------------------------";
			echo "Migration details:\n";
			echo "{$this->successCount} rows successfully migrated\n";
			echo "{$this->failCount} rows failed to migrate\n";
			echo "-------------------------";
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

			try
			{
				$stmt->execute();
				$this->successCount++;
			}
			catch(PDOException $error)
			{
				echo $error->getMessage()."\n";
				$this->failCount++;
			}
		}

		/**
		 * Gets the correct PDO type
		 *
		 * @param  $type
		 * @return int
		 */
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

		/**
		 * Closes connections to databases
		 *
		 * @return void
		 */
		public function closeConnections()
		{
			$this->fromConn = null;
			$this->toConn = null;
		}
	}

	// IMPLEMENTATION

	$migration = new Migration();
	$migration->migrate();
	$migration->closeConnections();
?>