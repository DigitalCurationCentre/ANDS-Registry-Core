<?php

class DataciteToRifcs extends Crosswalk {

	private $datacite = null;
	private $rifcs = null;
	private $doi = null;
	private $registryObject = null;
	private $collection = null;
	private $coverage = null;
	private $citationMetadata = null;
	private $vocabulary_mapping = array(
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
		"PMID" => "local",
		"PURL" => "purl",
		"UPC" => "upc",
		"URL" => "uri",
		"URN" => "urn",
		"IsCitedBy" => array("publication", "isCitedBy"),
		"IsSupplementedBy" => array("publication", "isSupplementedBy"),
		"IsSupplementTo" => array("publication", "isSupplementTo"),
		"IsPartOf" => array("collection", "isPartOf"),
		"HasPart" => array("collection", "hasPart"),
		"IsReferencedBy" => array("publication", "isReferencedBy"),
		"IsDocumentedBy" => array("publication", "isDocumentedBy"),
		"IsCompiledBy" => array("collection", "isDerivedFrom"),
		"Compiles" => array("collection", "hasDerivedCollection"),
	);
	
	function __construct(){
		require_once(REGISTRY_APP_PATH . "core/crosswalks/_crosswalk_helper.php");
		$this->rifcs = simplexml_load_string(CrosswalkHelper::RIFCS_WRAPPER);
		$this->registryObject = $this->rifcs->addChild("registryObject");
		$this->registryObject->addAttribute("group", "Datacite");
	}
	
	public function identify(){
		return "DataCite to RIF-CS: single record (Experimental)";
	}
	
	public function metadataFormat(){
		return "datacite";
	}
	
	public function validate($payload){
		$this->load_payload($payload);
		if (!$this->datacite){
			return false;
		}
		if ($this->datacite->getName() != "resource") {
			return false;
		}
		//More validation here...
		return true;
	}
	
	public function payloadToRIFCS($payload){
		$this->load_payload($payload);
		$this->traverse_children($this->datacite);
		return $this->rifcs->asXML();
	}
	
	private function load_payload($payload){
		if ($this->datacite == null) {
			$this->datacite = simplexml_load_string($payload);
		}
	}
	
	private function traverse_children($node) {
		foreach ($node->children() as $child) {
			$func = "process_".strtolower($child->getName());
			if (is_callable(array($this, $func))){
				call_user_func(array($this, $func), $child);
			}
			$this->traverse_children($child);
		}
	}
	
	private function process_identifier($doi) {
		//Assuming that identifier is DOI - only kind currently permitted in DataCite
		$this->doi = $doi;
		$this->registryObject->addChild("key", CrosswalkHelper::escapeAmpersands($doi));
		$this->registryObject->addChild("originatingSource", "http://dx.doi.org/".CrosswalkHelper::escapeAmpersands($doi));
		$identifier = $this->getCollection()->addChild("identifier", CrosswalkHelper::escapeAmpersands($doi));
		$identifier->addAttribute("type", "doi");
		$electronic = $this->getCollection()->addChild("location")->addChild("address")->addChild("electronic");
		$electronic->addAttribute("type", "url");
		$electronic->addChild("value", "http://dx.doi.org/".CrosswalkHelper::escapeAmpersands($doi));
		$this->getCitationMetadata()->addChild("identifier", CrosswalkHelper::escapeAmpersands($doi))->addAttribute("type", "doi");
		$this->getCitationMetadata()->addChild("url", "http://dx.doi.org/".CrosswalkHelper::escapeAmpersands($doi));
	}
	
	private function process_creator($creator) {
		$name = null;
		$identifier = null;
		$identifier_scheme = null;
		foreach ($creator->children() as $child) {
			if ($child->getName() == "creatorName") {
				$name = CrosswalkHelper::escapeAmpersands($child);
				$this->getCitationMetadata()->addChild("contributor")->addChild("namePart", $name);
			}
			elseif ($child->getName() == "nameIdentifier") {
				$identifier = CrosswalkHelper::escapeAmpersands($child);
				$identifier_scheme = $child["nameIdentifierScheme"];
			}
		}
		if ($identifier != null) {
			$reg_obj = $this->rifcs->addChild("registryObject");
			$reg_obj->addAttribute("group","DataCite");
			$reg_obj->addChild("key", $identifier);
			$reg_obj->addChild("originatingSource", CrosswalkHelper::escapeAmpersands($this->doi));
			$party = $reg_obj->addChild("party");
			$party->addAttribute("type","person");
			$party->addChild("name")->addChild("namePart", $name);
			$rel_obj = $party->addChild("relatedObject");
			$rel_obj->addChild("key", CrosswalkHelper::escapeAmpersands($this->doi));
			$rel_obj->addChild("relation")->addAttribute("type","isPrincipalInvestigatorOf");
		}
	}
	
	private function process_date($date) {
		$type = $date["dateType"];
		if ($type == "Accepted") {
			$this->getCollection()->addAttribute("dateAccessioned", $date);
		}
		if ($type == "Accepted" || $type == "Submitted") {
			$type = "date".$type;
		}
		elseif ($type == "Updated") {
			$type = "modified";
		}
		else {
			$type = strtolower($type);
		}
		if ($type != "modified") {
			$dates = $this->getCollection()->addChild("dates");
			$dates->addAttribute("type", "dc.".$type);
			$date = $dates->addChild("date", CrosswalkHelper::escapeAmpersands($date));
			$date->addAttribute("type", "dateFrom");
			$date->addAttribute("dateFormat", "W3CDTF");
		}
		$this->getCitationMetadata()->addChild("date", CrosswalkHelper::escapeAmpersands($date))->addAttribute("type", $type);
	}
	
	private function process_alternativeidentifier($alternative_identifier) {
		$this->getCollection()->addChild("identifier", CrosswalkHelper::escapeAmpersands($alternative_identifier))->addAttribute("type", "local");
	}
	
	private function process_title($title) {
		$name = $this->getCollection()->addChild("name");
		if ($title["titleType"] == "AlternativeTitle") {
			$name->addAttribute("type", "alternative");
		}
		else {
			$name->addAttribute("type", "primary");
		}
		$name->addChild("namePart", CrosswalkHelper::escapeAmpersands($title));
		$this->getCitationMetadata()->addChild("title", CrosswalkHelper::escapeAmpersands($title));
	}
	
	private function process_subject($subject) {
		$this->getCollection()->addChild("subject", CrosswalkHelper::escapeAmpersands($subject))->addAttribute("type", "local");
	}
	
	private function process_description($description) {
		$rifcs_description = $this->getCollection()->addChild("description", CrosswalkHelper::escapeAmpersands($description));
		if ($description["descriptionType"] == "Abstract") {
			$rifcs_description->addAttribute("type", "full");
		}
		elseif ($description["descriptionType"] == "Methods") {
			$rifcs_description->addAttribute("type", "lineage");
		}
		else {
			$rifcs_description->addAttribute("type", "note");
		}
	}
	
	private function process_geolocationbox($geolocationbox) {
		$this->getCoverage()->addChild("spatial", CrosswalkHelper::escapeAmpersands($geolocationbox))->addAttribute("type", "iso19139dcmibox");
	}
	
	private function process_geolocationpoint($geolocationpoint) {
		$this->getCoverage()->addChild("spatial", CrosswalkHelper::escapeAmpersands($geolocationpoint))->addAttribute("type", "dcmiPoint");
	}
	
	private function process_geolocationplace($geolocationplace) {
		$this->getCoverage()->addChild("spatial", CrosswalkHelper::escapeAmpersands($geolocationplace))->addAttribute("type", "text");
	}
	
	private function process_relatedidentifier($relatedidentifier) {
		$relatedInfo = $this->getCollection()->addChild("relatedInfo");
		$identifier = $relatedInfo->addChild("identifier", CrosswalkHelper::escapeAmpersands($relatedidentifier));
		if (isset($this->vocabulary_mapping[(string)$relatedidentifier["relatedIdentifierType"]])) {
			$identifier->addAttribute("type", $this->vocabulary_mapping[(string)$relatedidentifier["relatedIdentifierType"]]);
		}
		else {
			$identifier->addAttribute("type", "local");
		}
		$relation = $relatedInfo->addChild("relation");
		if (isset($this->vocabulary_mapping[(string)$relatedidentifier["relationType"]])) {
			$relation->addAttribute("type", $this->vocabulary_mapping[(string)$relatedidentifier["relationType"]][1]);
			$relatedInfo->addAttribute("type", $this->vocabulary_mapping[(string)$relatedidentifier["relationType"]][0]);
		}
		else {
			$relation->addAttribute("type", "hasAssociationWith");
			$relation->addChild("description", CrosswalkHelper::escapeAmpersands(preg_replace('/(?!^)[A-Z]{2,}(?=[A-Z][a-z])|[A-Z][a-z]/', ' $0', $relatedidentifier["relationType"])));
			if ($relatedidentifier["relationType"] == "Cites" || $relatedidentifier["relationType"] == "References") {
				$relatedInfo->addAttribute("type", "publication");
			}
			else {
				$relatedInfo->addAttribute("type", "collection");
			}
		}
		$format = null;
		if ($relatedidentifier["schemeURI"] != null) {
			if ($format == null) {
				$format = $relatedInfo->addChild("format");
			}
			$format->addChild("identifier", $relatedidentifier["schemeURI"])->addAttribute("type", "uri");
		}
// 		if ($relatedidentifier["relatedMetadataScheme"] != null) {
// 			if ($format == null) {
// 				$format = $relatedInfo->addChild("format");
// 			}
// 			$format->addChild("title", $relatedidentifier["relatedMetadataScheme"]);
// 		}
	}
	
	private function process_rights($rights) {
		$rights_statement = $this->getCollection()->addChild("rights")->addChild("rightsStatement", CrosswalkHelper::escapeAmpersands($rights));
		if ($rights["rightsURI"] != null) {
			$rights_statement->addAttribute("rightsUri", $rights["rightsURI"]);
		}
	}
	
	private function process_version($version) {
		$this->getCitationMetadata()->addChild("version", CrosswalkHelper::escapeAmpersands($version));
	}
	
	private function process_publisher($publisher) {
		$this->getCitationMetadata()->addChild("publisher", CrosswalkHelper::escapeAmpersands($publisher));
	}
	
	private function process_publicationyear($publication_year) {
		$this->getCitationMetadata()->addChild("date", CrosswalkHelper::escapeAmpersands($publication_year))->addAttribute("type", "publicationDate");
	}
	
	private function getCollection() {
		if ($this->collection == null ) {
			$this->collection = $this->registryObject->addChild("collection");
			$this->collection->addAttribute("type", "dataset");
		}
		return $this->collection;
	}
	
	private function getCoverage() {
		if ($this->coverage == null ) {
			$this->coverage = $this->getCollection()->addChild("coverage");
		}
		return $this->coverage;
	}
	
	private function getCitationMetadata() {
		if ($this->citationMetadata == null) {
			$this->citationMetadata = $this->getCollection()->addChild("citationInfo")->addChild("citationMetadata");
		}
		return $this->citationMetadata;
	}
}