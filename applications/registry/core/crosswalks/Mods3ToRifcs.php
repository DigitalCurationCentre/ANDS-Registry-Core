<?php

class Mods3ToRifcs extends Crosswalk {

	private $oaipmh = null;
	private $rifcs = null;
	
	function __construct(){
		require_once(REGISTRY_APP_PATH . "core/crosswalks/_crosswalk_helper.php");
		$this->rifcs = simplexml_load_string(CrosswalkHelper::RIFCS_WRAPPER);
	}
	
	public function identify(){
		return "MODS 3.5 to RIF-CS (Experimental)";
	}
	
	public function metadataFormat(){
		return "mods";
	}
	
	public function validate($payload){
		$this->load_payload($payload);
		if (!$this->oaipmh){
			return false;
		}
		if ($this->oaipmh->getName() != "OAI-PMH") {
			return false;
		}
		if (empty($this->oaipmh->request)) {
			return false;
		}
		if (empty($this->oaipmh->ListRecords)) {
			return false;
		}
		return true;
	}
	
	public function payloadToRIFCS($payload){
		$this->load_payload($payload);
		foreach ($this->oaipmh->ListRecords->children() as $record){
			if ($record->getName() != "record") {
				continue;
			}
			$reg_obj = $this->rifcs->addChild("registryObject");
			if (isset(CrosswalkHelper::$oaipmhProviders[(string) $this->oaipmh->request])) {
				$reg_obj->addAttribute("group", CrosswalkHelper::$oaipmhProviders[(string) $this->oaipmh->request]);
			} else {
				$reg_obj->addAttribute("group", $this->oaipmh->request);
			}
			$key = $reg_obj->addChild("key", $record->header->identifier);
			$originatingSource = $reg_obj->addChild("originatingSource", $this->oaipmh->request);
			$coll = $reg_obj->addChild("collection");
			$coll->addAttribute("type", "dataset");
			$coll->addAttribute("dateModified", date(DATE_W3C));
			$citation = $coll->addChild("citationInfo");
			$citation_metadata = $citation->addChild("citationMetadata");
			$coverage = $coll->addChild("coverage");
			$rights = $coll->addChild("rights");
			$contributors = array();
			foreach ($record->metadata->mods->children() as $node) {
				$func = "process_".$node->getName();
				if (is_callable(array($this, $func))){
					call_user_func(
						array($this, $func),
						$node,
						array(
							"registry_object" => $reg_obj,
							"key" => $key,
							"collection" => $coll,
							"citation_metadata" => $citation_metadata,
							"coverage" => $coverage,
							"rights" => $rights,
							"contributors" => &$contributors
						)
					);
				}
			}
		}
		return $this->rifcs->asXML();
	}
	
	private function load_payload($payload){
		if ($this->oaipmh == null) {
			$this->oaipmh = simplexml_load_string($payload);
		}
	}
	
	private function parseDateString($string) {
		$dateFrom = null;
		$dateTo = null;
		if (preg_match('~\d{4}-\d{4}~', $string, $matches)) {
			$dateFrom = $matches[1];
			$dateTo = $matches[2];
		} elseif (preg_match('~(\d{4}(?:-\d{2}(?:-\d{2})?)?)/(\d{4}(?:-\d{2}(?:-\d{2})?)?)~', $string, $matches)) {
			$dateFrom = $matches[1];
			$dateTo = $matches[2];
		} else {
			$dateFrom = $string;
		}
		return array("dateFrom" => $dateFrom, "dateTo" => $dateTo);
	}
	
	private function addDate($node, $type, $valueFrom, $valueTo = FALSE) {
		if ($node->getName() == "coverage") {
			$dates = $node->addChild("temporal");
		} else {
			$dates = $node->addChild("dates");
			$dates->addAttribute("type", $type);
		}
		$dates_dateFrom = $dates->addChild("date", $valueFrom);
		$dates_dateFrom->addAttribute("type", "dateFrom");
		$dates_dateFrom->addAttribute("dateFormat", "W3CDTF");
		if ($valueTo) {
			$dates_dateTo = $dates->addChild("date", $valueTo);
			$dates_dateTo->addAttribute("type", "dateTo");
			$dates_dateTo->addAttribute("dateFormat", "W3CDTF");
		}
	}
	
	private function process_titleInfo($input_node, $output_nodes) {
		foreach ($input_node->children() as $node) {
			$i = 0;
			if ($node->getName() == "title") {
				if ($i == 0) {
					$name = $output_nodes["collection"]->addChild("name");
					$name->addAttribute("type", "primary");
					$name->addChild("namePart", $node);
					$output_nodes["citation_metadata"]->addChild("title", $node);
				} else {
					$name = $output_nodes["collection"]->addChild("name");
					$name->addAttribute("type", "alternative");
					$name->addChild("namePart", $node);
				}
				$i++;
			}
		}
	}

	private function process_name($input_node, $output_nodes) {
		
	}

	private function process_typeOfResource($input_node, $output_nodes) {
		
	}

	private function process_genre($input_node, $output_nodes) {
		
	}

	private function process_originInfo($input_node, $output_nodes) {
		// Dates collected first and used later
		$originDates = array();
		foreach($input_node->children() as $node) {
			switch ($node->getName()) {
			case "place":
				if (empty($input_node->eventType) || $input_node->eventType == "publication") {
					foreach($node->children() as $subnode) {
						if ($subnode->getName() == "placeTerm") {
							if (empty($subnode->type) || $subnode->type == "text") {
								$output_nodes["citation_metadata"]->addChild("placePublished", $subnode);
							}
						}
					}
				}
				break;
			case "dateIssued":
				if (empty($node->point)) {
					$parsedDate = $this->parseDateString((string) $node);
					$originDate = array(
						"dateFrom" => $parsedDate["dateFrom"],
						"dateTo" => $parsedDate["dateTo"]
					);
					$originDates["issued"] = $originDate;
				} else {
					if ($node->point == "start") {
						$originDates["issued"]["dateFrom"] = (string) $node;
					} elseif ($node->point == "end") {
						$originDates["issued"]["dateTo"] = (string) $node;
					}
				}
				break;
			case "dateCreated":
				if (empty($node->point)) {
					$parsedDate = $this->parseDateString((string) $node);
					$originDate = array(
						"dateFrom" => $parsedDate["dateFrom"],
						"dateTo" => $parsedDate["dateTo"]
					);
					$originDates["created"] = $originDate;
				} else {
					if ($node->point == "start") {
						$originDates["created"]["dateFrom"] = (string) $node;
					} elseif ($node->point == "end") {
						$originDates["created"]["dateTo"] = (string) $node;
					}
				}
				break;
			case "dateValid":
				if (empty($node->point)) {
					$parsedDate = $this->parseDateString((string) $node);
					$originDate = array(
						"dateFrom" => $parsedDate["dateFrom"],
						"dateTo" => $parsedDate["dateTo"]
					);
					$originDates["valid"] = $originDate;
				} else {
					if ($node->point == "start") {
						$originDates["valid"]["dateFrom"] = (string) $node;
					} elseif ($node->point == "end") {
						$originDates["valid"]["dateTo"] = (string) $node;
					}
				}
				break;
			case "dateModified":
				if (empty($node->point)) {
					$parsedDate = $this->parseDateString((string) $node);
					$originDate = array(
						"dateFrom" => $parsedDate["dateFrom"],
						"dateTo" => $parsedDate["dateTo"]
					);
					$originDates["modified"] = $originDate;
				} else {
					if ($node->point == "start") {
						$originDates["modified"]["dateFrom"] = (string) $node;
					} elseif ($node->point == "end") {
						$originDates["modified"]["dateTo"] = (string) $node;
					}
				}
				break;
			case "edition":
				if (empty($output_nodes["citation_metadata"]->version)) {
					$output_nodes["citation_metadata"]->addChild("version", $node);
				}
				break;
			}
		}
		foreach ($originDates as $type => $originDate) {
			if ($type != "modified") {
				$this->addDate(
					$output_nodes["collection"],
					"dc." + $type,
					$originDate["dateFrom"],
					$originDate["dateTo"]
				);
			}
			$date = $output_nodes["citation_metadata"]->addChild("date", $originDate["dateFrom"]);
			$date->addAttribute("type", $type);
		}
	}

	private function process_language($input_node, $output_nodes) {
		
	}

	private function process_physicalDescription($input_node, $output_nodes) {
		
	}

	private function process_abstract($input_node, $output_nodes) {
		
	}

	private function process_tableOfContents($input_node, $output_nodes) {
		
	}

	private function process_targetAudience($input_node, $output_nodes) {
		
	}

	private function process_note($input_node, $output_nodes) {
		
	}

	private function process_subject($input_node, $output_nodes) {
		
	}

	private function process_classification($input_node, $output_nodes) {
		
	}

	private function process_relatedItem($input_node, $output_nodes) {
		
	}

	private function process_identifier($input_node, $output_nodes) {
		
	}

	private function process_location($input_node, $output_nodes) {
		
	}

	private function process_accessCondition($input_node, $output_nodes) {
		
	}

	private function process_part($input_node, $output_nodes) {
		
	}

	private function process_extension($input_node, $output_nodes) {
		
	}

	private function process_recordInfo($input_node, $output_nodes) {
		
	}

}
?>