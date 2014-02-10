<?php

class EprintsToRifcs extends Crosswalk {

	const RIFCS_WRAPPER="<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n
		<registryObjects xmlns=\"http://ands.org.au/standards/rif-cs/registryObjects\"
		xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"
		xsi:schemaLocation=\"http://ands.org.au/standards/rif-cs/registryObjects
		http://services.ands.org.au/documentation/rifcs/schema/registryObjects.xsd\">
		</registryObjects>";
	private $eprints = null;
	private $rifcs = null;
	
	function __construct(){
		$this->rifcs = simplexml_load_string($this::RIFCS_WRAPPER);
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
					$key = $reg_obj->addChild("key",$this->escapeAmpersands($value));
					$reg_obj->addChild("originatingSource",$this->escapeAmpersands($value));
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
		$output_nodes["collection"]->addChild("identifier", $this->escapeAmpersands($input_node))->addAttribute("type", "local");
		if ($this->isUrl($input_node)) {
			$url = $output_nodes["collection"]->addChild("location")->addChild("address")->addChild("electronic");
			$url->addAttribute("type","url");
			$url->addChild("value",$this->escapeAmpersands($input_node));
			$output_nodes["citation_metadata"]->addChild("url", $this->escapeAmpersands($input_node));
		}
		$output_nodes["citation_metadata"]->addChild("identifier", $this->escapeAmpersands($input_node))->addAttribute("type", "local");
	}
	
	private function process_title($input_node,$output_nodes){
		$name = $output_nodes["collection"]->addChild("name");
		$name->addAttribute("type","primary");
		$name->addChild("namePart", $this->escapeAmpersands($input_node));
		$output_nodes["citation_metadata"]->addChild("title", $this->escapeAmpersands($input_node));
	}
	
	private function process_alt_title($input_node,$output_nodes){
		$name = $output_nodes["collection"]->addChild("name");
		$name->addAttribute("type","alternative");
		$name->addChild("namePart", $this->escapeAmpersands($input_node));
	}
	
	private function process_date_embargo($input_node,$output_nodes){
		$this->addDate($output_nodes["collection"],"dc.available",$input_node);
		$citation_date = $output_nodes["citation_metadata"]->addChild("date", $this->escapeAmpersands($input_node));
		$citation_date->setAttribute("type","available");
	}
	
	private function process_collection_date($input_node,$output_nodes){
		$this->addDate($output_nodes["collection"],"dc.created",$input_node);
	}
	
	private function process_revision($input_node,$output_nodes){
		$this->addDate($output_nodes["collection"],"dc.issued",$input_node);
		$output_nodes["citation_metadata"]->addChild("version", $this->escapeAmpersands($input_node));
		$pub_date = $output_nodes["citation_metadata"]->addChild("date", $this->escapeAmpersands($input_node));
		$pub_date->addAttribute("type","publicationDate,issued");
	}
	
	private function process_keywords($input_node,$output_nodes){
		$subject = $output_nodes["collection"]->addChild("subject", $this->escapeAmpersands($input_node));
		$subject->addAttribute("type","local");
	}
	
	private function process_subjects($input_node,$output_nodes){//items
		foreach ($input_node->children() as $item) {
			$subject = $output_nodes["collection"]->addChild("subject", $this->escapeAmpersands($item));
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
		$output_nodes["coverage"]->addChild("temporal")->addChild("date",$this->escapeAmpersands($input_node));
	}
	
	private function process_geographic_cover($input_node,$output_nodes){	
		$output_nodes["coverage"]->addChild("spatial",$this->escapeAmpersands($input_node))->addAttribute("type","text");
	}
	
	private function process_bounding_box($input_node,$output_nodes){
		$output_nodes["coverage"]->addChild("spatial",$this->escapeAmpersands($input_node))->addAttribute("type","iso19139dcmiBox");
	}
	
	private function process_related_resources($input_node,$output_nodes){
		$related_info = $output_nodes["collection"]->addChild("relatedInfo");
		$related_info->addAttribute("type","collection");
		$related_info->addChild("identifier",$this->escapeAmpersands($input_node))->addAttribute("type", "local");
		foreach ($input_node->children() as $child) {
			if ($child->getName() == "relationType") {
				$relation = $related_info->addChild("relation");
				$relation->addAttribute("type",$child->textContent);
			}
		}
	}
	
	private function process_security($input_node,$output_nodes){
		$output_nodes["collection"]->addChild("rights")->addChild("accessRights",$this->escapeAmpersands($input_node));
	}
	
	private function process_restrictions($input_node,$output_nodes){
		$output_nodes["collection"]->addChild("rights")->addChild("accessRights",$this->escapeAmpersands($input_node));
	}
	
	private function process_accessLimitations($input_node,$output_nodes){
		$output_nodes["collection"]->addChild("rights")->addChild("accessRights",$this->escapeAmpersands($input_node));
	}
	
	private function process_license($input_node,$output_nodes){
		$output_nodes["collection"]->addChild("rights")->addChild("licence",$this->escapeAmpersands($input_node));
	}
	
	private function process_creators($input_node,$output_nodes){
		foreach ($input_node->children() as $item) {
			$name = "";
			$id = null;
			foreach ($item->children() as $child) {
				if ($child->getName() == "name") {
					foreach ($child->children() as $name_part) {
						if ($name_part->getName() == "given") {
							$name = $this->escapeAmpersands($name_part)." ".$name;
						}
						elseif ($name_part->getName() == "family") {
							$name = $name.$this->escapeAmpersands($name_part);
						}
					}
					$output_nodes["citation_metadata"]->addChild("contributor")->addChild("namePart", $this->escapeAmpersands($name));
				}
				elseif ($child->getName() == "id") {
					$id = $this->escapeAmpersands($child);
				}
			}
			if ($id != null) {
				$reg_obj = $this->rifcs->addChild("registryObject");
				$reg_obj->addAttribute("group","EPrints");
				$reg_obj->key = $id;
				$reg_obj->originatingSource = $this->escapeAmpersands($output_nodes["key"]);
				$party = $reg_obj->addChild("party");
				$party->addAttribute("type","person");
				$party->addChild("name")->namePart = $this->escapeAmpersands($name);
				$rel_obj = $party->addChild("relatedObject");
				$rel_obj->key = $output_nodes["key"];
				$rel_obj->addChild("relation")->addAttribute("type","isCollectorOf");
			}
		}
	}
	
	private function process_publisher($input_node,$output_nodes){
		$output_nodes["citation_metadata"]->addChild("publisher",$this->escapeAmpersands($input_node));
	}
	
	private function addDate($collection,$type,$value){
		$dates = $collection->addChild("dates");
		$dates->addAttribute("type", $type);
		$dates_date = $dates->addChild("date",$this->escapeAmpersands($value));
		$dates_date->addAttribute("type", "dateFrom");
		$dates_date->addAttribute("dateFormat", "W3CDTF");
	}
	
	private function addDescription($collection,$type,$value){
		$description = $collection->addChild("description", $this->escapeAmpersands($value));
		$description->addAttribute("type",$type);
	}
	
	private function escapeAmpersands($string){
		return str_replace("&", "&amp;", $string);
	}
	
	private function isUrl($string){
		//regex from https://gist.github.com/gruber/8891611
		$match = preg_match(
			"#(?i)\b((?:https?:(?:/{1,3}|[a-z0-9%])|[a-z0-9.\-]+[.](?:com|net|org|edu|gov|mil|aero|asia|biz|cat|coop|info|int|jobs|mobi|museum|name|post|pro|tel|travel|xxx|ac|ad|ae|af|ag|ai|al|am|an|ao|aq|ar|as|at|au|aw|ax|az|ba|bb|bd|be|bf|bg|bh|bi|bj|bm|bn|bo|br|bs|bt|bv|bw|by|bz|ca|cc|cd|cf|cg|ch|ci|ck|cl|cm|cn|co|cr|cs|cu|cv|cx|cy|cz|dd|de|dj|dk|dm|do|dz|ec|ee|eg|eh|er|es|et|eu|fi|fj|fk|fm|fo|fr|ga|gb|gd|ge|gf|gg|gh|gi|gl|gm|gn|gp|gq|gr|gs|gt|gu|gw|gy|hk|hm|hn|hr|ht|hu|id|ie|il|im|in|io|iq|ir|is|it|je|jm|jo|jp|ke|kg|kh|ki|km|kn|kp|kr|kw|ky|kz|la|lb|lc|li|lk|lr|ls|lt|lu|lv|ly|ma|mc|md|me|mg|mh|mk|ml|mm|mn|mo|mp|mq|mr|ms|mt|mu|mv|mw|mx|my|mz|na|nc|ne|nf|ng|ni|nl|no|np|nr|nu|nz|om|pa|pe|pf|pg|ph|pk|pl|pm|pn|pr|ps|pt|pw|py|qa|re|ro|rs|ru|rw|sa|sb|sc|sd|se|sg|sh|si|sj|Ja|sk|sl|sm|sn|so|sr|ss|st|su|sv|sx|sy|sz|tc|td|tf|tg|th|tj|tk|tl|tm|tn|to|tp|tr|tt|tv|tw|tz|ua|ug|uk|us|uy|uz|va|vc|ve|vg|vi|vn|vu|wf|ws|ye|yt|yu|za|zm|zw)/)(?:[^\s()<>{}\[\]]+|\([^\s()]*?\([^\s()]+\)[^\s()]*?\)|\([^\s]+?\))+(?:\([^\s()]*?\([^\s()]+\)[^\s()]*?\)|\([^\s]+?\)|[^\s`!()\[\]{};:'\".,<>?«»“”‘’])|(?:(?<!@)[a-z0-9]+(?:[.\-][a-z0-9]+)*[.](?:com|net|org|edu|gov|mil|aero|asia|biz|cat|coop|info|int|jobs|mobi|museum|name|post|pro|tel|travel|xxx|ac|ad|ae|af|ag|ai|al|am|an|ao|aq|ar|as|at|au|aw|ax|az|ba|bb|bd|be|bf|bg|bh|bi|bj|bm|bn|bo|br|bs|bt|bv|bw|by|bz|ca|cc|cd|cf|cg|ch|ci|ck|cl|cm|cn|co|cr|cs|cu|cv|cx|cy|cz|dd|de|dj|dk|dm|do|dz|ec|ee|eg|eh|er|es|et|eu|fi|fj|fk|fm|fo|fr|ga|gb|gd|ge|gf|gg|gh|gi|gl|gm|gn|gp|gq|gr|gs|gt|gu|gw|gy|hk|hm|hn|hr|ht|hu|id|ie|il|im|in|io|iq|ir|is|it|je|jm|jo|jp|ke|kg|kh|ki|km|kn|kp|kr|kw|ky|kz|la|lb|lc|li|lk|lr|ls|lt|lu|lv|ly|ma|mc|md|me|mg|mh|mk|ml|mm|mn|mo|mp|mq|mr|ms|mt|mu|mv|mw|mx|my|mz|na|nc|ne|nf|ng|ni|nl|no|np|nr|nu|nz|om|pa|pe|pf|pg|ph|pk|pl|pm|pn|pr|ps|pt|pw|py|qa|re|ro|rs|ru|rw|sa|sb|sc|sd|se|sg|sh|si|sj|Ja|sk|sl|sm|sn|so|sr|ss|st|su|sv|sx|sy|sz|tc|td|tf|tg|th|tj|tk|tl|tm|tn|to|tp|tr|tt|tv|tw|tz|ua|ug|uk|us|uy|uz|va|vc|ve|vg|vi|vn|vu|wf|ws|ye|yt|yu|za|zm|zw)\b/?(?!@)))#",
			$string
		);
		if ($match == 1) {
			return true;
		}
		else {
			return false;
		}		
	}
}