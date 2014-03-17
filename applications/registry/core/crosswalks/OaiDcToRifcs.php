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
		
	}
	
	private function process_type($input_node, $output_nodes) {
		
	}
	
	private function process_format($input_node, $output_nodes) {
		
	}
	
	private function process_identifier($input_node, $output_nodes) {
		
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