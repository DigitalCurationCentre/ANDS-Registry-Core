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
		return true
	}
	
	public function payloadToRIFCS($payload){
		$this->load_payload($payload);
		return $this->rifcs->asXML();
	}
	
	private function load_payload($payload){
		if ($this->eprints == null) {
			$this->eprints = simplexml_load_string($payload);
		}
	}

}
?>