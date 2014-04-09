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
			$idTypes = array("doi", "handle", "uri", "isbn", "issn", "istc", "upc", "local");
			$identifiers = $reg_obj->xpath("collection/identifier");
			foreach($idTypes as $idType) {
				foreach($identifiers as $identifier) {
					if ($identifier["type"] == $idType) {
						if ($idType == "doi") {
							$identifier = str_replace('http://dx.doi.org/', '', $identifier);
						}
						$id = $citation_metadata->addChild("identifier", $identifier);
						$id->addAttribute("type", $idType);
						break 2;
					}
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
		if (preg_match('~(\d{4})-(\d{4})~', $string, $matches)) {
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
	
	private function parseCoordinates($array) {
		$rawCoords = $array;
		$parsedCoords = array("type" => "text", "data" => "");
		// Single element: one coordinate or a whole set?
		if (count($array) == 1 && !preg_match("~(-\d\.)+ (-\d\.)+~", $array[0])) {
			// Probably a whole set. Assume they are space delimited.
			$rawCoords = explode(" ", $array[0]);
		}
		$countCoords = count($rawCoords);
		if ($countCoords == 1 && preg_match('~(-?\d+(?:\.\d+)?)[,\s](-?\d+(?:\.\d+)?)~', $rawCoords[0], $matches)) {
			$parsedCoords["type"] = "dcmiPoint";
			$parsedCoords["data"] = "north={$matches[1]}; east={$matches[2]}";
		} elseif ($countCoords > 2) {
			$coords = array();
			foreach ($rawCoords as $coord) {
				if (preg_match('~(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)(?:,(-?\d+(?:\.\d+)?))?~', $coord, $matches)) {
					if (isset($matches[3])) {
						$coords[] = "{$matches[1]},{$matches[2]},{$matches[3]}";
					} else {
						$coords[] = "{$matches[1]},{$matches[2]},0";
					}
				}
			}
			$parsedCoords["type"] = "kmlPolyCoords";
			$parsedCoords["data"] = implode(' ', $coords);
		}
		return $parsedCoords;
	}
	
	private function translateIdentifierType($string){
		$idType = "local";
		$knownTypes = array(
			"abn",
			"arc",
			"ark",
			"AU-PNL:PEAU",
			"doi",
			"ean13",
			"eissn",
			"infouri",
			"isbn",
			"isil",
			"issn",
			"istc",
			"lissn",
			"nhmrc",
			"orcid",
			"purl",
			"researcherID",
			"upc",
			"uri",
			"urn"
		);
		$idTypes = array(
			"hdl" => "handle",
		);
		if (in_array($string, $knownTypes)) {
			$idType = $string;
		} elseif (isset($idTypes[$string])) {
			$idType = $idTypes[$string];
		}
		return $idType;
	}
	
	private function process_titleInfo($input_node, $output_nodes) {
		// There should be just one title and at most one subtitle per info block
		$title = null;
		$subtitle = null;
		foreach ($input_node->children() as $node) {
			switch ($node->getName()) {
			case "title":
				$title = (string) $node;
				break;
			case "subTitle":
				$subtitle = (string) $node;
				break;
			}
		}
		if ($subtitle) {
			$title .= ": $subtitle";
		}
		if (empty($input_node->type)) {
			$name = $output_nodes["collection"]->addChild("name");
			$name->addAttribute("type", "primary");
			$name->addChild("namePart", $title);
			$output_nodes["citation_metadata"]->addChild("title", $title);
		} else {
			$name = $output_nodes["collection"]->addChild("name");
			$name->addAttribute("type", "alternative");
			$name->addChild("namePart", $title);
		}
	}

	private function process_name($input_node, $output_nodes) {
		// First we collect the information
		$partyArray = array();
		if (isset($input_node["type"])) {
			switch ($input_node["type"]) {
			case "personal":
				$partyArray["type"] = "person";
				break;
			case "corporate":
				$partyArray["type"] = "group";
				break;
			}
		}
		$partyArray["name"] = array();
		foreach ($input_node->children() as $node) {
			switch ($node->getName()) {
			case "namePart":
				$value = str_replace('~', ' ', (string) $node);
				if (isset($node["type"])) {
					$partyArray["name"][(string) $node["type"]] = $value;
				} elseif ($partyArray["type"] == "person") {
					if (preg_match("/(.+), ?([^,]+)(?:, ?([^,]+))?/", $value, $matches)) {
						$partyArray["name"]["family"] = $matches[1];
						$partyArray["name"]["given"] = $matches[2];
						if (isset($matches[3])) {
							$partyArray["name"]["suffix"] = $matches[3];
						}
					} else {
						$partyArray["name"]["whole"] = $value;
					}
				} else {
					$partyArray["name"]["whole"] = $value;
				}
				break;
			case "role":
				foreach($node->children() as $subnode) {
					if ($subnode->getName() == "roleTerm") {
						switch ((string) $subnode) {
						case "Author":
						case "author":
						case "aut":
							$partyArray["role"] = "author";
							break;
						case "Publisher":
						case "publisher":
						case "pbl":
							$partyArray["role"] = "publisher";
							break;
						// More cases may need to be added, depending on real world usage
						}
					}
				}
				break;
			}
		}
		$hashString = "";
		if (isset($partyArray["name"]["whole"])) {
			$hashString = $partyArray["name"]["whole"];
		} elseif (isset($partyArray["name"]["given"]) && isset($partyArray["name"]["family"])) {
			$hashString = "{$partyArray["name"]["given"]} {$partyArray["name"]["family"]}";
			if (isset($partyArray["name"]["suffix"])) {
				$hashString .= " {$partyArray["name"]["suffix"]}";
			}
		}
		$isRelatedObject = FALSE;
		$relation = array();
		if (isset($partyArray["role"])) {
			switch ($partyArray["role"]) {
			case "author":
				$ctrb = $output_nodes["citation_metadata"]->addChild("contributor");
				foreach($partyArray["name"] as $type => $part) {
					$namePart = $ctrb->addChild("namePart", $part);
					if ($type != "whole") {
						$namePart->addAttribute("type", $type);
					}
				}
				$isRelatedObject = TRUE;
				$relation["out"] = "hasPrincipalInvestigator";
				$relation["in"] = "isPrincipalInvestigatorOf";
				break;
			case "publisher":
				if (empty($output_nodes["citation_metadata"]->publisher)) {
					$output_nodes["citation_metadata"]->addChild("publisher", CrosswalkHelper::escapeAmpersands($hashString));
				}
				break;
			}
		}
		if ($isRelatedObject) {
			$id = sha1($hashString);
			if (isset($input_node["ID"])) {
				$id = $input_node["ID"];
			}
			// Is this a new or existing party?
			$ctrb_obj = null;
			$ctrb_party = null;
			$ctrb_name = null;
			$ctrb_part = null;
			$new_ctrb = true;
			foreach ($this->rifcs->children() as $object) {
				if ((string) $object->key == $id) {
					$ctrb_obj = $object;
					$ctrb_party = $object->party;
					$new_ctrb = false;
					break;
				}
			}
			if ($new_ctrb) {
				$ctrb_obj = $this->rifcs->addChild("registryObject");
				$ctrb_obj->addAttribute("group", $output_nodes["registry_object"]["group"]);
				$ctrb_obj->addChild("key", $id);
				$ctrb_obj->addChild("originatingSource", $output_nodes["registry_object"]->originatingSource);
				$ctrb_party = $ctrb_obj->addChild("party");
				$ctrb_party->addAttribute("dateModified", date(DATE_W3C));
				$ctrb_party->addAttribute("type", $partyArray["type"]);
				$ctrb_name = $ctrb_party->addChild("name");
				$ctrb_name->addAttribute("type", "primary");
				foreach ($partyArray["name"] as $partType => $part) {
					$ctrb_part = $ctrb_name->addChild("namePart", $part);
					if ($partType != "whole") {
						$ctrb_part->addAttribute("type", $partType);
					}
				}
			}
			$ctrb_rel_obj = $ctrb_party->addChild("relatedObject");
			$ctrb_rel_obj->addChild("key", $output_nodes["key"]);
			$ctrb_rel_obj_type = $ctrb_rel_obj->addChild("relation");
			$ctrb_rel_obj_type->addAttribute("type", $relation["in"]);
			$ctrb_party["dateModified"] = date(DATE_W3C);
			$rel_obj = $output_nodes["collection"]->addChild("relatedObject");
			$rel_obj->addChild("key", $id);
			$rel_obj_type = $rel_obj->addChild("relation");
			$rel_obj_type->addAttribute("type", $relation["out"]);
		}
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
			case "publisher":
				if (empty($output_nodes["citation_metadata"]->publisher)) {
					$output_nodes["citation_metadata"]->addChild("publisher", $node);
				}
				break;
			case "dateIssued":
				if (empty($node["point"])) {
					$parsedDate = $this->parseDateString((string) $node);
					if ($parsedDate["dateFrom"]) {
						$originDate = array(
							"dateFrom" => $parsedDate["dateFrom"],
							"dateTo" => $parsedDate["dateTo"]
						);
						$originDates["issued"] = $originDate;
					}
				} else {
					if ($node["point"] == "start") {
						$originDates["issued"]["dateFrom"] = (string) $node;
					} elseif ($node["point"] == "end") {
						$originDates["issued"]["dateTo"] = (string) $node;
					}
				}
				break;
			case "dateCreated":
				if (empty($node["point"])) {
					$parsedDate = $this->parseDateString((string) $node);
					if ($parsedDate["dateFrom"]) {
						$originDate = array(
							"dateFrom" => $parsedDate["dateFrom"],
							"dateTo" => $parsedDate["dateTo"]
						);
						$originDates["created"] = $originDate;
					}
				} else {
					if ($node["point"] == "start") {
						$originDates["created"]["dateFrom"] = (string) $node;
					} elseif ($node["point"] == "end") {
						$originDates["created"]["dateTo"] = (string) $node;
					}
				}
				break;
			case "dateValid":
				if (empty($node["point"])) {
					$parsedDate = $this->parseDateString((string) $node);
					if ($parsedDate["dateFrom"]) {
						$originDate = array(
							"dateFrom" => $parsedDate["dateFrom"],
							"dateTo" => $parsedDate["dateTo"]
						);
						$originDates["valid"] = $originDate;
					}
				} else {
					if ($node["point"] == "start") {
						$originDates["valid"]["dateFrom"] = (string) $node;
					} elseif ($node["point"] == "end") {
						$originDates["valid"]["dateTo"] = (string) $node;
					}
				}
				break;
			case "dateModified":
				if (empty($node["point"])) {
					$parsedDate = $this->parseDateString((string) $node);
					if ($parsedDate["dateFrom"]) {
						$originDate = array(
							"dateFrom" => $parsedDate["dateFrom"],
							"dateTo" => $parsedDate["dateTo"]
						);
						$originDates["modified"] = $originDate;
					}
				} else {
					if ($node["point"] == "start") {
						$originDates["modified"]["dateFrom"] = (string) $node;
					} elseif ($node["point"] == "end") {
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
					"dc.$type",
					$originDate["dateFrom"],
					$originDate["dateTo"]
				);
			}
			$date = $output_nodes["citation_metadata"]->addChild("date", $originDate["dateFrom"]);
			$date->addAttribute("type", $type);
			if ($type = "issued") {
				if ($originDate["dateTo"]) {
					$dateStart = $output_nodes["citation_metadata"]->addChild("date", $originDate["dateFrom"]);
					$dateStart->addAttribute("type", "startPublicationDate");
					$dateEnd = $output_nodes["citation_metadata"]->addChild("date", $originDate["dateTo"]);
					$dateEnd->addAttribute("type", "endPublicationDate");
				} else {
					$date = $output_nodes["citation_metadata"]->addChild("date", $originDate["dateFrom"]);
					$date->addAttribute("type", "publicationDate");
				}
			}
			
		}
	}

	private function process_abstract($input_node, $output_nodes) {
		if (isset($input_node["type"])) {
			switch ($input_node["type"]) {
			case "content":
				$desc = $output_nodes["collection"]->addChild("description", $input_node);
				$desc->addAttribute("type", "full");
				break;
			case "review":
				$desc = $output_nodes["collection"]->addChild("description", $input_node);
				$desc->addAttribute("type", "significanceStatement");
				break;
			}
		} else {
			$desc = $output_nodes["collection"]->addChild("description", $input_node);
			$desc->addAttribute("type", "full");
		}
	}

	private function process_note($input_node, $output_nodes) {
		if (isset($input_node["type"])) {
			switch ($input_node["type"]) {
			case "conservation history":
				$desc = $output_nodes["collection"]->addChild("description", $input_node);
				$desc->addAttribute("type", "lineage");
				break;
			case "version identification":
				if (empty($output_nodes["citation_metadata"]->version)) {
					$output_nodes["citation_metadata"]->addChild("version", $input_node);
				}
				break;
			}
		} else {
			$desc = $output_nodes["collection"]->addChild("description", $input_node);
			$desc->addAttribute("type", "note");
		}
	}

	private function process_subject($input_node, $output_nodes) {
		// For subjects
		$auth = "local";
		if (isset($input_node["authority"])) {
			$auth = $input_node["authority"];
		}
		// For temporal coverage
		$coverageDates = array("dateFrom" => null, "dateTo" => null);
		// For coordinates
		$coords = array();
		// Now we can begin
		foreach ($input_node->children() as $node) {
			switch ($node->getName()) {
			case "topic":
				$subject = $output_nodes["collection"]->addChild("subject", $node);
				$subjectType = $auth;
				if (isset($node["authority"])) {
					$subjectType = $node["authority"];
				}
				$subject->addAttribute("type", $subjectType);
				if (isset($node["valueURI"])) {
					$subject->addAttribute("termIdentifier", $node["valueURI"]);
				}
				break;
			case "geographic":
				$spatial = $output_nodes["coverage"]->addChild("spatial", $node);
				$spatial->addAttribute("type", "text");
				break;
			case "temporal":
				if (empty($node["point"])) {
					$coverageDates = $this->parseDateString((string) $node);
				} else {
					if ($node["point"] == "start") {
						$coverageDates["dateFrom"] = (string) $node;
					} elseif ($node["point"] == "end") {
						$coverageDates["dateTo"] = (string) $node;
					}
				}
				break;
			case "cartographics":
				foreach ($node->children() as $subnode) {
					if ($subnode->getName() == "coordinates") {
						$coords[] = (string) $subnode;
					}
				}
				break;
			case "geographicCode":
				if (isset($subnode["authority"]) && $subnode["authority"] == "iso3166") {
					$spatial = $output_nodes["coverage"]->addChild("spatial", $subnode);
					if (strpos((string) $subnode, '-') === FALSE) {
						$spatial->addAttribute("type", "iso31661");
					} else {
						$spatial->addAttribute("type", "iso31662");
					}
				}
				break;
			}
		}
		if (isset($coverageDates["dateFrom"])) {
			$this->addDate($output_nodes["coverage"], "extent", $coverageDates["dateFrom"], $coverageDates["dateTo"]);
		}
		if (count($coords) > 0) {
			$parsedCoords = $this->parseCoordinates($coords);
			$spatial = $output_nodes["coverage"]->addChild("spatial", $parsedCoords["data"]);
			$spatial->addAttribute("type", $parsedCoords["type"]);
		}
	}

	private function process_relatedItem($input_node, $output_nodes) {
		$relInfo = $output_nodes["collection"]->addChild("relatedInfo");
		$bad_node = TRUE;
		if (isset($input_node["type"])) {
			$relType = (string) $input_node["type"];
			$relTypes = array(
				"original" => "isDerivedFrom",
				"host" => "isPartOf",
				"constituent" => "hasPart",
				"otherVersion" => "hasDerivedVersion",
				"isReferencedBy" => "isReferencedBy"
			);
			$relation = $relInfo->addChild("relation");
			if (isset($relTypes[$relType])) {
				$relation->addAttribute("type", $relTypes[$relType]);
			} else {
				$relation->addAttribute("type", "hasAssociationWith");
				preg_replace('~([a-z])([A-Z])~', '\1 \2', $relType);
				$relType = ucfirst(strtolower($relType));
				$relation->addChild("description", $relType);
			}
		}
		foreach($input_node->children() as $node) {
			switch ($node->getName()) {
			case "identifier":
				$rel_id = $relInfo->addChild("identifier", $node);
				$rel_idType = "local";
				if (isset($node["type"])) {
					$rel_idType = $this->translateIdentifierType((string) $node["type"]);
				}
				$rel_id->addAttribute("type", $rel_idType);
				$bad_node = FALSE;
				break;
			case "titleInfo":
				$title = "";
				$subtitle = null;
				foreach($node->children() as $subnode) {
					switch ($subnode->getName()) {
					case "title":
						$title = (string) $subnode;
						break;
					case "subTitle":
						$subtitle = (string) $subnode;
						break;
					}
				}
				if ($subtitle) {
					$title .= ": $subtitle";
				}
				$relInfo->addChild("title", $title);
				$bad_node = FALSE;
				if ($relType == "Series") {
					$output_nodes["citation_metadata"]->addChild("context", $title);
				}
				break;
			case "physicalDescription":
				foreach($node->children() as $subnode) {
					if ($subnode->getName() == "internetMediaType") {
						$format = $relInfo->addChild("format");
						$fmt_id = $format->addChild("identifier", $subnode);
						$fmt_id->addAttribute("type", "mediaType");
						$bad_node = FALSE;
					}
				}
				break;
			}
		}
		if ($bad_node) {
			unset($relInfo[0]);
		}
	}

	private function process_identifier($input_node, $output_nodes) {
		$idType = "local";
		if (isset($input_node["type"])) {
			$idType = $this->translateIdentifierType((string) $input_node["type"]);
		}
		$identifier = (string) $input_node;
		if ($idType == "doi") {
			$identifier = str_replace('http://dx.doi.org/', '', $identifier);
		}
		$id = $output_nodes["collection"]->addChild("identifier", $identifier);
		$id->addAttribute("type", $idType);
	}

	private function process_location($input_node, $output_nodes) {
		$locationUrls = array();
		foreach ($input_node->children() as $node) {
			if ($node->getName() == "url") {
				if (empty($node->usage)) {
					$locationUrls["unknown"] = (string) $node;
				} else {
					$locationUrls[(string) $node->usage] = (string) $node;
				}
			}
			$loc = $output_nodes["collection"]->addChild("location");
			$addr = $loc->addChild("address");
			$elec = $addr->addChild("electronic");
			$elec->addAttribute("type", "url");
			$elec->addChild("value", (string) $node);
		}
		$url = null;
		$urlTypeArray = array("primary", "primary display", "unknown");
		foreach ($urlTypeArray as $urlType) {
			if (isset($urlArray[$urlType])) {
				$url = $urlArray[$urlType];
				break;
			}
		}
		if ($url) {
			$output_nodes["citation_metadata"]->addChild("url", $url);
		}
	}

	private function process_accessCondition($input_node, $output_nodes) {
		if (isset($input_node["type"]) && $input_node["type"] == "restriction on access") {
			$rights = $output_nodes["collection"]->addChild("rights");
			$rights->addChild("accessRights", $input_node);
		} else {
			$rights = $output_nodes["collection"]->addChild("rights");
			$rights->addChild("rightsStatement", $input_node);
		}
		// Here we recognise CC licences if they occur by URL in a licence statement.
		if (preg_match('~(http://creativecommons.org/licenses/([^/]+)(?:[^\b]*)?)~', (string) $input_node, $matches)) {
			$licence = $rights->addChild("licence");
			$licence->addAttribute("rightsUri", $matches[1]);
			$licence->addAttribute("type", strtoupper($matches[2]));
		}
	}

}
?>