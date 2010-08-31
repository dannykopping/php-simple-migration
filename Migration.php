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
		private $fromConn;
		private $toConn;

		public function __construct()
		{
			$config = $this->readConfig("config.xml");
			$parsed = $this->parse($config);

			$from = $parsed["from"];
			$this->fromConn = $this->getConnection($from["host"], $from["username"],
													$from["password"], $from["database"], "mysql");

			$to = $parsed["to"];
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
								"to" => (string) $transform["to"]	
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
			
		}
	}

	$migration = new Migration();
	$migration->migrate();
?>