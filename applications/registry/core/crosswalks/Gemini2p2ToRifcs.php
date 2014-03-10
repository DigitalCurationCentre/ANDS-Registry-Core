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

	private function process_contact($input_node, $output_nodes) {
	}
	
	private function process_dateStamp($input_node, $output_nodes) {
	}
	
	private function process_metadataStandardName($input_node, $output_nodes) {
	}
	
	private function process_metadataStandardVersion($input_node, $output_nodes) {
	}
	
	private function process_referenceSystemInfo($input_node, $output_nodes) {
	}
	
	private function process_identificationInfo($input_node, $output_nodes) {
	}
	
	private function process_distributionInfo($input_node, $output_nodes) {
	}
	
	private function process_dataQualityInfo($input_node, $output_nodes) {
	}

}
?>