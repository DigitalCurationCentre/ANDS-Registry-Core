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
	
	private function process_titleInfo($input_node, $output_nodes) {
		
	}

	private function process_name($input_node, $output_nodes) {
		
	}

	private function process_typeOfResource($input_node, $output_nodes) {
		
	}

	private function process_genre($input_node, $output_nodes) {
		
	}

	private function process_originInfo($input_node, $output_nodes) {
		
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