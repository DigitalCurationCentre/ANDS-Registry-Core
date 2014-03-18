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
			$coverage = $coll->addChild("coverage");
			$rights = $coll->addChild("rights");
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
		
	}
	
	private function process_contributors($input_node, $output_nodes) {
		
	}
	
	private function process_dates($input_node, $output_nodes) {
		
	}
	
	private function process_language($input_node, $output_nodes) {
		
	}
	
	private function process_resourceType($input_node, $output_nodes) {
		
	}
	
	private function process_alternateIdentifiers($input_node, $output_nodes) {
		
	}
	
	private function process_relatedIdentifiers($input_node, $output_nodes) {
		
	}
	
	private function process_sizes($input_node, $output_nodes) {
		
	}
	
	private function process_formats($input_node, $output_nodes) {
		
	}
	
	private function process_version($input_node, $output_nodes) {
		
	}
	
	private function process_rightsList($input_node, $output_nodes) {
		
	}
	
	private function process_descriptions($input_node, $output_nodes) {
		
	}
	
	private function process_geoLocations($input_node, $output_nodes) {
		
	}

}

?>