<?php

class EprintsToRifcs {

	const  RIFCS_WRAPPER="<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n
		<registryObjects xmlns=\"http://ands.org.au/standards/rif-cs/registryObjects\"
		xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
		xsi:schemaLocation=\"http://ands.org.au/standards/rif-cs/registryObjects
		http://services.ands.org.au/documentation/rifcs/schema/registryObjects.xsd\">
		</registryObjects>";
	private $eprints = null;
	private $rifcs = null;
	
	function __construct(){
		$this->rifcs =  simplexml_load_string($this::RIFCS_WRAPPER);
	}

	public function identify(){
		return "EPrints ReCollect to RIF-CS (Experimental)";
	}
	
	public function metadataFormat(){
		return "eprints_recollect";
	}
	
	public function validate($payload){
		$this->load_payload($payload);
		if (!$this->eprints){
			return false;
		}
		if ($this->eprints->getName() != "eprints") {
			return false;
		}
		foreach ($this->eprints->children() as $node){
			if ($node->getName() != "eprint"){
				return false;
			}
			//More validation here...
		}
		return true;
	}
	
	public function payloadToRIFCS($payload){
		$this->load_payload($payload);
		$reg_obj = $this->rifcs->addChild("registryObject");
		$reg_obj->addAttribute("group", "EPrints");
		$reg_obj->addChild("key",$this->generateRandomString());
		$reg_obj->addChild("originatingSource",$this->generateRandomString());
		$repo = $reg_obj->addChild("collection");
		$repo->addAttribute("type", "repository");
		foreach ($this->eprints->children() as $eprint){
			$reg_obj = $this->rifcs->addChild("registryObject");
			$reg_obj->addAttribute("group", "EPrints");
			$rel_obj = $repo->addChild("relatedObject");
			foreach ($eprint->attributes() as $attribute => $value){
				if ($attribute == "id") {
					$rel_obj->addChild("key",$value);
					$reg_obj->addChild("key",$value);
					$reg_obj->addChild("originatingSource",$value);
					break;
				}
			}
			$coll = $reg_obj->addChild("collection");
			$coll->addAttribute("type", "dataset");
			$citation = $coll->addChild("citationInfo");
			$citation_metadata = $citation->addChild("citationMetadata");
			$coverage = $coll->addChild("coverage");
			$rel_obj->addChild("relation")->addAttribute("type", "isLocatedIn");
			foreach ($eprint->children() as $node){
				$func = "process_".$node->getName();
				if (is_callable(array($this, $func))){
					call_user_func(array($this, $func),$node,$coll,$citation_metadata,$coverage);
				}
			}
		}
		return $this->rifcs->asXML();
	}
	
	private function load_payload($payload){
		if ($this->eprints == null) {
			$this -> eprints =  simplexml_load_string($payload);
		}
	}
	
	private function process_datestamp($node,$coll,$citation_metadata,$coverage){
		$coll->addAttribute("dateAccessioned", $node);
		$this->addDate($coll,"dc.dateSubmitted",$node);
	}
	
	private function process_id_number($node,$coll,$citation_metadata,$coverage){
		$coll->addChild("identifier", $node)->addAttribute("type", "local");
		$url = $coll->addChild("location")->addChild("address")->addChild("electronic");
		$url->addAttribute("type","url");
		$url->addChild("value",$node);
		$citation_metadata->addChild("identifier", $node);
		$citation_metadata->addChild("url", $node);
	}
	
	private function process_title($node,$coll,$citation_metadata,$coverage){
		$name = $coll->addChild("name");
		$name->addAttribute("type","primary");
		$name->addChild("namePart",$node);
		$citation_metadata->addChild("title",$node);
	}
	
	private function process_alt_title($node,$coll,$citation_metadata,$coverage){
		$name = $coll->addChild("name");
		$name->addAttribute("type","alternative");
		$name->addChild("namePart",$node);
	}
	
	private function process_date_embargo($node,$coll,$citation_metadata,$coverage){
		$this->addDate($coll,"dc.available",$node);
		$citation_date = $citation_metadata->addChild("date",$node);
		$citation_date->setAttribute("type","available");
	}
	
	private function process_collection_date($node,$coll,$citation_metadata,$coverage){
		$this->addDate($coll,"dc.created",$node);
	}
	
	private function process_revision($node,$coll,$citation_metadata,$coverage){
		$this->addDate($coll,"dc.issued",$node);
		$citation_metadata->addChild("version",$node);
		$pub_date = $citation_metadata->addChild("date",$node);
		$pub_date->addAttribute("type","publicationDate,issued");
	}
	
	private function process_keywords($node,$coll,$citation_metadata,$coverage){
		$subject = $coll->addChild("subject", $node);
		$subject->addAttribute("type","local");
	}
	
	private function process_subjects($node,$coll,$citation_metadata,$coverage){//items
		foreach ($node->children() as $item) {
			$subject = $coll->addChild("subject", $item->textContent);
			$subject->addAttribute("type","local");
		}
	}
	
	private function process_abstract($node,$coll,$citation_metadata,$coverage){
		$this->addDescription($coll,"full",$node);
	}
	
	private function process_provenance($node,$coll,$citation_metadata,$coverage){
		$this->addDescription($coll,"lineage",$node);
	}
	
	private function process_note($node,$coll,$citation_metadata,$coverage){
		$this->addDescription($coll,"note",$node);
	}
	
	private function process_temporal_cover($node,$coll,$citation_metadata,$coverage){
		$coverage->addChild("temporal")->addChild("date",$node);
	}
	
	private function process_geographic_cover($node,$coll,$citation_metadata,$coverage){	
		$coverage->addChild("spatial",$node)->addAttribute("type","text");
	}
	
	private function process_bounding_box($node,$coll,$citation_metadata,$coverage){
		$coverage->addChild("spatial",$node)->addAttribute("type","iso19139dcmiBox");
	}
	
	private function process_related_resources($node,$coll){
		$related_info = $coll->addChild("relatedInfo");
		$related_info->addAttribute("type","collection");
		$related_info->addChild("identifier",$node);
		foreach ($node->children() as $child) {
			if ($child->getName() == "relationType") {
				$relation = $related_info->addChild("relation");
				$relation->addAttribute("type",$child->textContent);
			}
		}
	}
	
	private function process_security($node,$coll,$citation_metadata,$coverage){
		$coll->addChild("rights")->addChild("accessRights",$node);
	}
	
	private function process_restrictions($node,$coll,$citation_metadata,$coverage){
		$coll->addChild("rights")->addChild("accessRights",$node);
	}
	
	private function process_accessLimitations($node,$coll,$citation_metadata,$coverage){
		$coll->addChild("rights")->addChild("accessRights",$node);
	}
	
	private function process_license($node,$coll,$citation_metadata,$coverage){
		$coll->addChild("rights")->addChild("licence",$node);
	}
	
	private function process_creators($node,$coll,$citation_metadata,$coverage){
		foreach ($node->children() as $item) {
			foreach ($item->children() as $child) {
				if ($child->getName() == "name") {
					$name = "";
					foreach ($child->children() as $name_part) {
						$name = $name.$name_part." ";
					}
					$name = trim($name);
					$citation_metadata->addChild("contributor")->addChild("namePart", $name);
				}
			}
		}
	}
	
	private function process_publisher($node,$coll,$citation_metadata,$coverage){
		$citation_metadata->addChild("publisher",$node);
	}
	
	private function addDate($coll,$type,$value){
		$dates = $coll->addChild("dates");
		$dates->addAttribute("type", $type);
		$dates_date = $dates->addChild("date",$value);
		$dates_date->addAttribute("type", "dateFrom");
		$dates_date->addAttribute("dateFormat", "W3CDTF");
	}
	
	private function addDescription($coll,$type,$value){
		$description = $coll->addChild("description", $value);
		$description->addAttribute("type",$type);
	}
	
	private function generateRandomString($length = 16) {
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $randomString;
	}
}