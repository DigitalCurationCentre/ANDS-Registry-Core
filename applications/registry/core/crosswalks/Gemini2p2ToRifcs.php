<?php

class Gemini2p2ToRifcs extends Crosswalk {

	private $gemini = null;
	private $rifcs = null;
	
	function __construct(){
		require_once(REGISTRY_APP_PATH . "core/crosswalks/_crosswalk_helper.php");
		$this->rifcs = simplexml_load_string(CrosswalkHelper::RIFCS_WRAPPER);
	}
	
	public function identify(){
		return "UK GEMINI 2.2 to RIF-CS (Experimental)";
	}
	
	public function metadataFormat(){
		return "gemini_2.2";
	}
	
	public function validate($payload){
		$this->load_payload($payload);
		if (!$this->gemini) {
			return false;
		}
		if ($this->gemini->getName() != "GetRecordByIdResponse") {
			return false;
		}
		foreach ($this->gemini->children('gmd', TRUE) as $record) {
			if (count($record->xpath('gmd:fileIdentifier/gco:CharacterString')) == 0) {
				return false;
			}
		}
		return true;
	}
	
	public function payloadToRIFCS($payload){
		$this->load_payload($payload);
		foreach($this->gemini->children('gmd', TRUE) as $record) {
			$reg_obj = $this->rifcs->addChild("registryObject");
			/* The following should probably be set in the user account, but is here
			 * for the purposes of the pilot.
			 */
			$reg_obj->addAttribute("group", "NERC Data Catalogue Service");
			$key = $reg_obj->addChild("key", $record->xpath('gmd:fileIdentifier/gco:CharacterString')[0]);
			$coll = $reg_obj->addChild("collection");
			$coll->addAttribute("type", "dataset");
			$coll->addAttribute("dateModified", date(DATE_W3C));
			$citation_metadata = $citation->addChild("citationMetadata");
			$coverage = $coll->addChild("coverage");
			$rights = $coll->addChild("rights");
			foreach ($record->children('gmd', TRUE) as $node){
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
							"rights" => $rights
						)
					);
				}
			}
		}
		return $this->rifcs->asXML();
	}
	
	private function load_payload($payload){
		if ($this->gemini == null) {
			$this->gemini = simplexml_load_string($payload);
		}
	}
	
	private function addDate($collection, $type, $valueFrom, $valueTo = FALSE) {
		$dates = $collection->addChild("dates");
		$dates->addAttribute("type", $type);
		$dates_dateFrom = $dates->addChild("date", $valueFrom);
		$dates_dateFrom->addAttribute("type", "dateFrom");
		$dates_dateFrom->addAttribute("dateFormat", "W3CDTF");
		if ($valueTo) {
			$dates_dateTo = $dates->addChild("date", $valueTo);
			$dates_dateTo->addAttribute("type", "dateTo");
			$dates_dateTo->addAttribute("dateFormat", "W3CDTF");
		}
	}
	
	private function addID($collection, $code, $codeSpace) {
		$id = $code;
		$idType = "local";
		if (strpos($code, "info:") == 0) {
			$idType = "infouri";
		} elseif (strpos($code, "http://purl.org/") == 0) {
			$idType = "purl";
		} elseif (preg_match('~(?:http://dx.doi.org/|doi:)(10\.\d+/.*)~', $code, $matches)) {
			$id = $matches[1];
			$idType = "doi";
		} elseif (preg_match('~(?:http://hdl.handle.net/)(1[^/]+/.*)~')) {
			$id = $matches[1];
			$idType = "handle";
		} elseif (CrosswalkHelper::isUrl($code)) {
			$idType = "uri";
		} elseif (preg_match('~http://www.([^\.]+).ac.uk/~', $codeSpace, $matches)) {
			$id = $matches[1] . ": " . $code;
		} elseif (preg_match('~^[-_\.:A-za-z0-9][^:]+$~', $codeSpace, $matches)) {
			$id = $matches[1] . ": " . $code;
		} elseif (preg_match('~^[-_\.:A-za-z0-9]:+$~', $codeSpace, $matches)) {
			$id = $matches[1] . " " . $code;
		}
		$identifier = $collection->addChild("identifier", $id);
		$identifier->addAttribute("type", $idType);
	}
	
	private function process_contact($input_node, $output_nodes) {
		// If this class is used more widely, a different fallback should be selected:
		$originatingSource = "http://www.nerc.ac.uk/";
		$sourceUrl = $input_node->xpath('gmd:CI_ResponsibleParty/gmd:contactInfo/gmd:CI_Contact/gmd:onlineResource/gmd:CI_OnlineResource/gmd:linkage/gmd:URL');
		if (count($sourceUrl > 0)) {
			$originatingSource = $reg_obj->addChild("originatingSource", $sourceUrl[0]);
		} else {
			$sourceEmail = $input_node->xpath('gmd:CI_ResponsibleParty/gmd:contactInfo/gmd:CI_Contact/gmd:address/gmd:CI_Address/gmd:electronicMailAddress/gco:CharacterString');
			if (count($sourceEmail > 0)) {
				if (preg_match('/@(.+)/', $sourceEmail[0], $host)) {
					$originatingSource = $reg_obj->addChild("originatingSource", "http://www." . $host[1] . "/");
				}
			}
		}
		$output_nodes["registry_object"]->addChild("originatingSource", $originatingSource);
	}
	
	private function process_identificationInfo($input_node, $output_nodes) {
		foreach ($input_node->children('gmd', TRUE)->MD_DataIdentification->children('gmd', TRUE) as $node){
			$func = "process_".$node->getName();
			if (is_callable(array($this, $func))){
				call_user_func(
					array($this, $func),
					$node,
					$output_nodes
				);
			}
		}
	}

	private function process_citation($input_node, $output_nodes) {
		foreach ($input_node->children('gmd', TRUE)->CI_Citation->children('gmd', TRUE) as $node) {
			switch ($node->getName()) {
			case "title":
				$name = $output_nodes["collection"]->addChild("name");
				$name->addAttribute("type", "primary");
				$name->addChild("namePart", $node->children('gco', TRUE)->CharacterString);
				$output_nodes["citation_metadata"]->addChild("title", $node->children('gco', TRUE)->CharacterString);
				break;
			case "alternateTitle":
				$altName = $output_nodes["collection"]->addChild("name");
				$altName->addAttribute("type", "alternative");
				$altName->addChild("namePart", $node->children('gco', TRUE)->CharacterString);
				break;
			case "date":
				$dateStamp = $node->xpath('gmd:CI_Date/gmd:date/gco:Date');
				if (count($dateStamp) > 0) {
					$dateFrom = $dateStamp[0];
					$dateTo = null;
					if (preg_match('~(\d{4}-\d{2}-\d{2})/(\d{4}-\d{2}-\d{2})~', $dateStamp[0], $dateStamps)) {
						// This is a date range
						$dateFrom = $dateStamps[1];
						$dateTo = $dateStamps[2];
					}
					$dateType = $node->xpath('gmd:CI_Date/gmd:dateType/gmd:CIDateTypeCode');
					if (count($dateType) > 0) {
						if ($dateType[0] == "creation") {
							$this->addDate($output_nodes["collection"], "dc.created", $dateFrom, $dateTo);
							$citeCreated = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
							$citeCreated->addAttribute("type", "created");
						} elseif ($dateType[0] == "publication") {
							if (count($dateStamp) > 0) {
								$this->addDate($output_nodes["collection"], "dc.issued", $dateFrom, $dateTo);
								if ($dateTo) {
									$citePublishedFrom = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
									$citePublishedFrom->addAttribute("type", "startPublicationDate");
									$citePublishedTo = $output_nodes["citation_metadata"]->addChild("date", $dateTo);
									$citePublishedTo->addAttribute("type", "endPublicationDate");
								} else {
									$citePublished = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
									$citePublished->addAttribute("type", "publicationDate");
								}
								$citeAvailable = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
								$citeAvailable->addAttribute("type", "available");
								$citeIssued = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
								$citeIssued->addAttribute("type", "issued");
							}
						} elseif ($dateType[0] == "revision") {
							if (count($dateStamp) > 0) {
								$citeModified = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
								$citeModified->addAttribute("type", "modified");
							}
						}
					}
				}
				break;
			case "identifier":
				$code = $node->xpath('gmd:RS_Identifier/gmd:code/gco:CharacterString');
				if (count($code) > 0) {
					$codeSpace = $node->xpath('gmd:RS_Identifier/gmd:codeSpace/gco:CharacterString');
					if (count($codeSpace) > 0) {
						$this->addID($code[0], $codeSpace[0]);
					}
				}
				break;
			}
		}
	}
	
	private function process_abstract($input_node, $output_nodes) {
	}
	
	private function process_pointOfContact($input_node, $output_nodes) {
	}
	
	private function process_resourceMaintenance($input_node, $output_nodes) {
	}
	
	private function process_descriptiveKeywords($input_node, $output_nodes) {
	}
	
	private function process_resourceConstraints($input_node, $output_nodes) {
	}
	
	private function process_spatialResolution($input_node, $output_nodes) {
	}
	
	private function process_language($input_node, $output_nodes) {
	}
	
	private function process_topicCategory($input_node, $output_nodes) {
	}
	
	private function process_extent($input_node, $output_nodes) {
	}
	
	private function process_supplementalInformation($input_node, $output_nodes) {
	}
	
	private function process_distributionInfo($input_node, $output_nodes) {
	}
	
	private function process_dataQualityInfo($input_node, $output_nodes) {
	}

}
?>