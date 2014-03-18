<?php

class DataCite3ToRifcs extends Crosswalk {

	private $oaipmh = null;
	private $rifcs = null;
	
	function __construct(){
		require_once(REGISTRY_APP_PATH . "core/crosswalks/_crosswalk_helper.php");
		$this->rifcs = simplexml_load_string(CrosswalkHelper::RIFCS_WRAPPER);
	}
	
	public function identify(){
		return "DataCite (up to version 3) to RIF-CS (Experimental)";
	}
	
	public function metadataFormat(){
		return "oai_datacite";
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
			$contributors = array();
			foreach ($record->metadata->oai_datacite->payload->resource->children() as $node) {
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
	
	private function addDate($node, $type, $valueFrom, $valueTo = FALSE) {
		$dates = $node->addChild("dates");
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
	
	private function translateIdentifierType($string){
		$idType = "local";
		$idTypes = array(
			"ARK" => "ark",
			"DOI" => "doi",
			"EAN13" => "ean13",
			"EISSN" => "eissn",
			"Handle" => "handle",
			"ISBN" => "isbn",
			"ISSN" => "issn",
			"ISTC" => "istc",
			"LISSN" => "lissn",
			"LSID" => "urn",
			"PURL" => "purl",
			"UPC" => "upc",
			"URL" => "uri",
			"URN" => "urn",
		);
		if (isset($idTypes[$string])) {
			$idType = $idTypes[$string];
		}
		return $idType;
	}
	
	private function translateSchemeType($string) {
		$scheme = "local";
		// This will need to be adapted in response to usage in the wild.
		$schemeTypes = array(
			"ACM Computing Classification System" => "acmccs",
			"AGRICOLA" => "agricola",
			"AGRIS" => "agrissc",
			"The Alpha-Numeric System for Classification of Recordings" => "anscr",
			"British Catalogue of Music Classification" => "bcmc",
			"BISAC" => "bisacsh",
			"BLISS" => "bliss",
			"CELEX" => "celex",
			"Cutter" => "cutterec",
			"DDC" => "ddc",
			"International Federation of Film Archives" => "fiaf",
			"GFDC" => "gfdc",
			"International Classification for Standards" => "ics",
			"INSPEC" => "inspec",
			"International Patent Classification" => "ipc",
			"JEL" => "jelc",
			"Library of Congress Classification" => "lcc",
			"LCSH" => "lcsh",
			"Moys" => "moys",
			"Mathematical Subject Classification" => "msc",
			"MESH" => "mesh",
			"NH Classification for Photography" => "nhcp",
			"NICEM" => "nicem",
			"NLM" => "nlm",
			"RILM" => "rilm",
			"UDC" => "udc",
			"UK Standard Library Categories" => "ukslc",
			"UNESCO Thesaurus" => "unescot",
		);
		if (isset($schemeTypes[$string])) {
			$scheme = $schemeTypes[$string];
		}
		return $scheme;
	}
	
	private function translateSchemeUri($string) {
		$scheme = "local";
		// This will need to be adapted in response to usage in the wild.
		$schemeUris = array(
			"http://id.loc.gov/authorities/subjects" => "lcsh",
		);
		if (isset($schemeUris[$string])) {
			$scheme = $schemeUris[$string];
		}
		return $scheme;
	}
	
	private function process_identifier($input_node, $output_nodes) {
		$id = $output_nodes["collection"]->addChild("identifier", (string) $input_node);
		$idType = $this->translateIdentifierType((string) $input_node["identifierType"]);
		$id->addAttribute("type", $idType);
		
		$cite_id = $output_nodes["citation_metadata"]->addChild("identifier", (string) $input_node);
		$cite_id->addAttribute("type", $idType);
		
		if ($idType == "doi") {
			$location = $output_nodes["collection"]->addChild("location");
			$address = $location->addChild("address");
			$electronic = $address->addChild("electronic");
			$electronic->addAttribute("url");
			$electronic->addChild("value", "http://dx.doi.org/" . (string) $input_node);
			
			$output_nodes["citation_metadata"]->addChild("url", "http://dx.doi.org/" . (string) $input_node);
		}
	}
	
	private function process_creators($input_node, $output_nodes) {
		
	}
	
	private function process_titles($input_node, $output_nodes) {
		foreach ($input_node->children() as $title) {
			if (isset($title["titleType"])) {
				if ($title["titleType"] == "AlternativeTitle") {
					$altTitle = $output_nodes["collection"]->addChild("name");
					$altTitle->addAttribute("type", "alternative");
					$altTitle->addChild("namePart", $title);
				}
			} else {
				$primaryTitle = $output_nodes["collection"]->addChild("name");
				$primaryTitle->addAttribute("type", "primary");
				$primaryTitle->addChild("namePart", $title);
				
				$output_nodes["citation_metadata"]->addChild("title", $title);
			}
		}
	}
	
	private function process_publisher($input_node, $output_nodes) {
		
	}
	
	private function process_publicationYear($input_node, $output_nodes) {
		$published = $output_nodes["citation_metadata"]->addChild("date", (string) $input_node);
		$published->addAttribute("type", "publicationDate");
	}
	
	private function process_subjects($input_node, $output_nodes) {
		foreach ($input_node->children() as $subject) {
			$term = $output_nodes["collection"]->addChild("subject", (string) $subject);
			$scheme = "local";
			if (isset($subject["schemeURI"])) {
				$scheme = $this->translateSchemeUri((string) $subject["schemeURI"]);
			} elseif (isset($subject["subjectScheme"])) {
				$scheme = $this->translateSchemeType((string) $subject["subjectScheme"]);
			}
			$term->addAttribute("type", $scheme);
		}
	}
	
	private function process_contributors($input_node, $output_nodes) {
		
	}
	
	private function process_dates($input_node, $output_nodes) {
		$dateTypes = array(
			"Available" => "available",
			"Created" => "created",
			"Accepted" => "dateAccepted",
			"Submitted" => "dateSubmitted",
			"Issued" => "issued",
			"Updated" => "modified",
			"Valid" => "valid",
		);
		foreach ($input_node->children() as $node) {
			$dateTypeString = (string) $node["dateType"];
			$dateFrom = FALSE;
			$dateTo = FALSE;
			// Single date or range?
			if ($divider = strpos((string) $node, '/')) {
				$dateFromString = substr((string) $node, 0, $divider);
				$dateFromStamp = strtotime($dateFromString);
				$dateToString = substr((string) $node, $divider);
				$dateToStamp = strtotime($dateToString);
				if ($dateToStamp) {
					$dateTo = date(DATE_W3C, $dateFromStamp);
				}
			} else {
				$dateFromStamp = strtotime((string) $node);
			}
			if ($dateFromStamp) {
				$dateFrom = date(DATE_W3C, $dateFromStamp);
			}
			if ($dateFrom) {
				if (isset($dateTypes[$dateTypeString])) {
					if ($dateTypes[$dateTypeString] != "modified") {
						$this->addDate($output_nodes["collection"], "dc." . $dateTypes[$dateTypeString], $dateFrom, $dateTo);
					}
					$cite_date = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
					$cite_date->addAttribute("type", $dateTypes[$dateTypeString]);
				}
			}
		}
	}
	
	private function process_language($input_node, $output_nodes) {
		
	}
	
	private function process_resourceType($input_node, $output_nodes) {
		
	}
	
	private function process_alternateIdentifiers($input_node, $output_nodes) {
		foreach ($input_node->children() as $node) {
			$idType = $this->translateIdentifierType((string) $node["alternateIdentifierType"]);
			$id = $output_nodes["collection"]->addChild("identifier", (string) $node);
			$id->addAttribute("type", $idType);
		}
	}
	
	private function process_relatedIdentifiers($input_node, $output_nodes) {
		
	}
	
	private function process_sizes($input_node, $output_nodes) {
		
	}
	
	private function process_formats($input_node, $output_nodes) {
		
	}
	
	private function process_version($input_node, $output_nodes) {
		$output_nodes["citation_metadata"]->addChild("version", (string) $input_node);
	}
	
	private function process_rightsList($input_node, $output_nodes) {
		foreach ($input_node->children() as $node) {
			$this->process_rights($node, $output_nodes);
		}
	}
	
	private function process_rights($input_node, $output_nodes) {
		$rightsString = (string) $input_node;
		$rightsUri = null;
		if (isset($input_node["rightsURI"])) {
			$rightsUri = $input_node["rightsURI"];
		} elseif (CrosswalkHelper::isUrl($rightsString)) {
			$rightsUri = $rightsString;
		}
		$rights = $output_nodes["collection"]->addChild("rights");
		$rightsStmt = $rights->addChild("rightsStatement", $rightsString);
		if ($rightsUri) {
			$rightsStmt->addAttribute("rightsUri", $rightsUri);
		}
	}
	
	private function process_descriptions($input_node, $output_nodes) {
		foreach ($input_node->children() as $node) {
			$descType = $node["descriptionType"];
			switch ($descType) {
			case "Abstract":
				$description = $output_nodes["collection"]->addChild("description", (string) $node);
				$description->addAttribute("type", "full");
				break;
			case "Methods":
				$description = $output_nodes["collection"]->addChild("description", (string) $node);
				$description->addAttribute("type", "lineage");
				break;
			case "Other":
				$description = $output_nodes["collection"]->addChild("description", (string) $node);
				$description->addAttribute("type", "brief");
				break;
			}
		}
	}
	
	private function process_geoLocations($input_node, $output_nodes) {
		foreach ($input_node->children() as $node) {
			$coverage = $output_nodes["collection"]->addChild("coverage");
			foreach ($node->children() as $subnode) {
				switch ($subnode->getName()) {
				case "geoLocationPoint":
					if (preg_match('~(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)~', (string) $subnode, $matches)) {
						$dcmiPoint =
							"north={$matches[1]}; " .
							"east={$matches[2]}";
						$spatial = $coverage->addChild("spatial", $dcmiPoint);
						$spatial->addAttribute("type", "dcmiPoint");
					}
					break;
				case "geoLocationBox":
					if (preg_match('~(-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?) (-?\d+(?:\.\d+)?)~', (string) $subnode, $matches)) {
						$dcmiBox =
							"northlimit={$matches[3]}; " .
							"eastlimit={$matches[4]}; " .
							"southlimit={$matches[1]}; " .
							"westlimit={$matches[2]}";
						$spatial = $coverage->addChild("spatial", $dcmiBox);
						$spatial->addAttribute("type", "iso19139dcmiBox");
					}
					break;
				case "geoLocationPlace":
					$spatial = $coverage->addChild("spatial", $subnode);
					$spatial->addAttribute("type", "text");
					break;
				}
			}
		}
	}
	
}

?>