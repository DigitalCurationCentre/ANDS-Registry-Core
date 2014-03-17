<?php

class OaiDcToRifcs extends Crosswalk {

	private $oaipmh = null;
	private $rifcs = null;
	private $oaiProviders = array(
		"https://radar.brookes.ac.uk/radar/oai" => "Oxford Brookes University",
	);
	
	function __construct(){
		require_once(REGISTRY_APP_PATH . "core/crosswalks/_crosswalk_helper.php");
		$this->rifcs = simplexml_load_string(CrosswalkHelper::RIFCS_WRAPPER);
	}
	
	public function identify(){
		return "OAI-PMH Dublin Core to RIF-CS (Experimental)";
	}
	
	public function metadataFormat(){
		return "oai_dc";
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
			if (isset($this->oaiProviders[(string) $this->oaipmh->request])) {
				$reg_obj->addAttribute("group", $this->oaiProviders[(string) $this->oaipmh->request]);
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
			foreach ($record->metadata->children('oai_dc', TRUE)->dc->children('dc', TRUE) as $node) {
				foreach ($node->children() as $subnode) {
					$func = "process_".$subnode->getName();
					if (is_callable(array($this, $func))){
						call_user_func(
							array($this, $func),
							$subnode,
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
		}
		return $this->rifcs->asXML();
	}
	
	private function load_payload($payload){
		if ($this->oaipmh == null) {
			$this->oaipmh = simplexml_load_string($payload);
		}
	}
	
	private function addDate($node, $type, $valueFrom, $valueTo = FALSE) {
		if ($node.getName() == "coverage") {
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
	
	private function addLocationUrl($collection, $url) {
		$loc = $collection->addChild("location");
		$addr = $loc->addChild("address");
		$elec = $addr->addChild("electronic");
		$elec->addAttribute("type", "url");
		$elec->addChild("value", $url);
	}
	
	private function process_title($input_node, $output_nodes) {
		
	}
	
	private function process_creator($input_node, $output_nodes) {
		
	}
	
	private function process_subject($input_node, $output_nodes) {
		
	}
	
	private function process_description($input_node, $output_nodes) {
		
	}
	
	private function process_publisher($input_node, $output_nodes) {
		
	}
	
	private function process_contributor($input_node, $output_nodes) {
		
	}
	
	private function process_date($input_node, $output_nodes) {
		$dateString = (string) $input_node;
		if ($divider = strpos($dateString, '/')) {
			$dateFrom = substr($dateString, 0, $divider);
			$dateTo = substr($dateString, $divider);
			$this->addDate($output_nodes["collection"], "dc.issued", $dateFrom, $dateTo);
			$cite_start_published = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
			$cite_start_published->addAttribute("type", "startPublicationDate");
			$cite_end_published = $output_nodes["citation_metadata"]->addChild("date", $dateTo);
			$cite_end_published->addAttribute("type", "endPublicationDate");
		} else {
			$dateString
			$this->addDate($output_nodes["collection"], "dc.issued", $dateString);
			$cite_published = $output_nodes["citation_metadata"]->addChild("date", $dateString);
		}
		$cite_available = $output_nodes["citation_metadata"]->addChild("date", $dateString);
		$cite_available->addAttribute("type", "available");
		$cite_issued = $output_nodes["citation_metadata"]->addChild("date", $dateString);
		$cite_issued->addAttribute("type", "issued");
	}
	
	private function process_type($input_node, $output_nodes) {
		
	}
	
	private function process_format($input_node, $output_nodes) {
		
	}
	
	private function process_identifier($input_node, $output_nodes) {
		$id = (string) $input_node;
		$idType = "local";
		if (strpos($id, "info:") === 0) {
			$idType = "infouri";
		} elseif (strpos($id, "http://purl.org/") === 0) {
			$idType = "purl";
			$this->addLocationUrl($output_nodes["collection"], $id);
			if ($output_nodes["citation_metadata"]->url === null) {
				$output_nodes["citation_metadata"]->addChild("url", $id);
			}
		} elseif (preg_match('~(?:http://dx.doi.org/|doi:)(10\.\d+/.*)~', $id, $matches)) {
			$id = $matches[1];
			$idType = "doi";
			$this->addLocationUrl($output_nodes["collection"], "http://dx.doi.org/" . $id);
			if ($output_nodes["citation_metadata"]->url === null) {
				$output_nodes["citation_metadata"]->addChild("url", "http://dx.doi.org/" . $id);
			}
		} elseif (preg_match('~(?:http://hdl.handle.net/)(1[^/]+/.*)~', $id, $matches)) {
			$id = $matches[1];
			$idType = "handle";
			$this->addLocationUrl($output_nodes["collection"], "http://hdl.handle.net/" . $id);
			if ($output_nodes["citation_metadata"]->url === null) {
				$output_nodes["citation_metadata"]->addChild("url", "http://hdl.handle.net/" . $id);
			}
		} elseif (CrosswalkHelper::isUrl($id)) {
			$idType = "uri";
			$this->addLocationUrl($output_nodes["collection"], $id);
			if ($output_nodes["citation_metadata"]->url === null) {
				$output_nodes["citation_metadata"]->addChild("url", $id);
			}
		} elseif (CrosswalkHelper::isUri($id)) {
			$idType = "uri";
		}
		$identifier = $output_nodes["collection"]->addChild("identifier", $id);
		$identifier->addAttribute("type", $idType);
		if ($output_nodes["citation_metadata"]->identifier === null) {
			$cite_identifier = $output_nodes["citation_metadata"]->addChild("identifier", $id);
			$cite_identifier->addAttribute("type", $idType);
		}
	}
	
	private function process_source($input_node, $output_nodes) {
		
	}
	
	private function process_language($input_node, $output_nodes) {
		
	}
	
	private function process_relation($input_node, $output_nodes) {
		
	}
	
	private function process_coverage($input_node, $output_nodes) {
		
	}
	
	private function process_rights($input_node, $output_nodes) {
		
	}

}
?>