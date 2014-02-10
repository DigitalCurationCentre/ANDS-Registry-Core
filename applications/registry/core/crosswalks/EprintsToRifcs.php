<?php

class EprintsToRifcs extends Crosswalk {

	private $eprints = null;
	private $rifcs = null;
	
	function __construct(){
		require_once(REGISTRY_APP_PATH . "core/crosswalks/_crosswalk_helper.php");
		$this->rifcs = simplexml_load_string(CrosswalkHelper::RIFCS_WRAPPER);
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
		foreach ($this->eprints->children() as $eprint){
			$reg_obj = $this->rifcs->addChild("registryObject");
			$reg_obj->addAttribute("group", "EPrints");
			$key = null;
			foreach ($eprint->attributes() as $attribute => $value){
				if ($attribute == "id") {
					$key = $reg_obj->addChild("key",CrosswalkHelper::escapeAmpersands($value));
					$reg_obj->addChild("originatingSource",CrosswalkHelper::escapeAmpersands($value));
					break;
				}
			}
			$coll = $reg_obj->addChild("collection");
			$coll->addAttribute("type", "dataset");
			$citation = $coll->addChild("citationInfo");
			$citation_metadata = $citation->addChild("citationMetadata");
			$coverage = $coll->addChild("coverage");
			foreach ($eprint->children() as $node){
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
							"coverage" => $coverage
						)
					);
				}
			}
		}
		return $this->rifcs->asXML();
	}
	
	private function load_payload($payload){
		if ($this->eprints == null) {
			$this->eprints = simplexml_load_string($payload);
		}
	}
	
	private function process_datestamp($input_node,$output_nodes){
		$output_nodes["collection"]->addAttribute("dateAccessioned", $input_node);
		$this->addDate($output_nodes["collection"],"dc.dateSubmitted",$input_node);
	}
	
	private function process_id_number($input_node,$output_nodes){
		$output_nodes["collection"]->addChild("identifier", CrosswalkHelper::escapeAmpersands($input_node))->addAttribute("type", "local");
		if (CrosswalkHelper::isUrl($input_node)) {
			$url = $output_nodes["collection"]->addChild("location")->addChild("address")->addChild("electronic");
			$url->addAttribute("type","url");
			$url->addChild("value",CrosswalkHelper::escapeAmpersands($input_node));
			$output_nodes["citation_metadata"]->addChild("url", CrosswalkHelper::escapeAmpersands($input_node));
		}
		$output_nodes["citation_metadata"]->addChild("identifier", CrosswalkHelper::escapeAmpersands($input_node))->addAttribute("type", "local");
	}
	
	private function process_title($input_node,$output_nodes){
		$name = $output_nodes["collection"]->addChild("name");
		$name->addAttribute("type","primary");
		$name->addChild("namePart", CrosswalkHelper::escapeAmpersands($input_node));
		$output_nodes["citation_metadata"]->addChild("title", CrosswalkHelper::escapeAmpersands($input_node));
	}
	
	private function process_alt_title($input_node,$output_nodes){
		$name = $output_nodes["collection"]->addChild("name");
		$name->addAttribute("type","alternative");
		$name->addChild("namePart", CrosswalkHelper::escapeAmpersands($input_node));
	}
	
	private function process_date_embargo($input_node,$output_nodes){
		$this->addDate($output_nodes["collection"],"dc.available",$input_node);
		$citation_date = $output_nodes["citation_metadata"]->addChild("date", CrosswalkHelper::escapeAmpersands($input_node));
		$citation_date->setAttribute("type","available");
	}
	
	private function process_collection_date($input_node,$output_nodes){
		$this->addDate($output_nodes["collection"],"dc.created",$input_node);
	}
	
	private function process_revision($input_node,$output_nodes){
		$this->addDate($output_nodes["collection"],"dc.issued",$input_node);
		$output_nodes["citation_metadata"]->addChild("version", CrosswalkHelper::escapeAmpersands($input_node));
		$pub_date = $output_nodes["citation_metadata"]->addChild("date", CrosswalkHelper::escapeAmpersands($input_node));
		$pub_date->addAttribute("type","publicationDate,issued");
	}
	
	private function process_keywords($input_node,$output_nodes){
		$subject = $output_nodes["collection"]->addChild("subject", CrosswalkHelper::escapeAmpersands($input_node));
		$subject->addAttribute("type","local");
	}
	
	private function process_subjects($input_node,$output_nodes){//items
		foreach ($input_node->children() as $item) {
			$subject = $output_nodes["collection"]->addChild("subject", CrosswalkHelper::escapeAmpersands($item));
			$subject->addAttribute("type","local");
		}
	}
	
	private function process_abstract($input_node,$output_nodes){
		$this->addDescription($output_nodes["collection"],"full",$input_node);
	}
	
	private function process_provenance($input_node,$output_nodes){
		$this->addDescription($output_nodes["collection"],"lineage",$input_node);
	}
	
	private function process_note($input_node,$output_nodes){
		$this->addDescription($output_nodes["collection"],"note",$input_node);
	}
	
	private function process_temporal_cover($input_node,$output_nodes){
		$output_nodes["coverage"]->addChild("temporal")->addChild("date",CrosswalkHelper::escapeAmpersands($input_node));
	}
	
	private function process_geographic_cover($input_node,$output_nodes){	
		$output_nodes["coverage"]->addChild("spatial",CrosswalkHelper::escapeAmpersands($input_node))->addAttribute("type","text");
	}
	
	private function process_bounding_box($input_node,$output_nodes){
		$output_nodes["coverage"]->addChild("spatial",CrosswalkHelper::escapeAmpersands($input_node))->addAttribute("type","iso19139dcmiBox");
	}
	
	private function process_related_resources($input_node,$output_nodes){
		$related_info = $output_nodes["collection"]->addChild("relatedInfo");
		$related_info->addAttribute("type","collection");
		$related_info->addChild("identifier",CrosswalkHelper::escapeAmpersands($input_node))->addAttribute("type", "local");
		foreach ($input_node->children() as $child) {
			if ($child->getName() == "relationType") {
				$relation = $related_info->addChild("relation");
				$relation->addAttribute("type",$child->textContent);
			}
		}
	}
	
	private function process_security($input_node,$output_nodes){
		$output_nodes["collection"]->addChild("rights")->addChild("accessRights",CrosswalkHelper::escapeAmpersands($input_node));
	}
	
	private function process_restrictions($input_node,$output_nodes){
		$output_nodes["collection"]->addChild("rights")->addChild("accessRights",CrosswalkHelper::escapeAmpersands($input_node));
	}
	
	private function process_accessLimitations($input_node,$output_nodes){
		$output_nodes["collection"]->addChild("rights")->addChild("accessRights",CrosswalkHelper::escapeAmpersands($input_node));
	}
	
	private function process_license($input_node,$output_nodes){
		$output_nodes["collection"]->addChild("rights")->addChild("licence",CrosswalkHelper::escapeAmpersands($input_node));
	}
	
	private function process_creators($input_node,$output_nodes){
		foreach ($input_node->children() as $item) {
			$name = "";
			$id = null;
			foreach ($item->children() as $child) {
				if ($child->getName() == "name") {
					foreach ($child->children() as $name_part) {
						if ($name_part->getName() == "given") {
							$name = CrosswalkHelper::escapeAmpersands($name_part)." ".$name;
						}
						elseif ($name_part->getName() == "family") {
							$name = $name.CrosswalkHelper::escapeAmpersands($name_part);
						}
					}
					$output_nodes["citation_metadata"]->addChild("contributor")->addChild("namePart", CrosswalkHelper::escapeAmpersands($name));
				}
				elseif ($child->getName() == "id") {
					$id = CrosswalkHelper::escapeAmpersands($child);
				}
			}
			if ($id != null) {
				$reg_obj = $this->rifcs->addChild("registryObject");
				$reg_obj->addAttribute("group","EPrints");
				$reg_obj->key = $id;
				$reg_obj->originatingSource = CrosswalkHelper::escapeAmpersands($output_nodes["key"]);
				$party = $reg_obj->addChild("party");
				$party->addAttribute("type","person");
				$party->addChild("name")->namePart = CrosswalkHelper::escapeAmpersands($name);
				$rel_obj = $party->addChild("relatedObject");
				$rel_obj->key = $output_nodes["key"];
				$rel_obj->addChild("relation")->addAttribute("type","isCollectorOf");
			}
		}
	}
	
	private function process_publisher($input_node,$output_nodes){
		$output_nodes["citation_metadata"]->addChild("publisher",CrosswalkHelper::escapeAmpersands($input_node));
	}
	
	private function addDate($collection,$type,$value){
		$dates = $collection->addChild("dates");
		$dates->addAttribute("type", $type);
		$dates_date = $dates->addChild("date",CrosswalkHelper::escapeAmpersands($value));
		$dates_date->addAttribute("type", "dateFrom");
		$dates_date->addAttribute("dateFormat", "W3CDTF");
	}
	
	private function addDescription($collection,$type,$value){
		$description = $collection->addChild("description", CrosswalkHelper::escapeAmpersands($value));
		$description->addAttribute("type",$type);
	}
}