<?php

class Ddi2p5ToRifcs extends Crosswalk {

	private $oaipmh = null;
	private $rifcs = null;
	private $ddiProviders = array(
		"http://oai.ukdataservice.ac.uk/oai/provider" => "UK Data Archive",
	);
	
	function __construct(){
		require_once(REGISTRY_APP_PATH . "core/crosswalks/_crosswalk_helper.php");
		$this->rifcs = simplexml_load_string(CrosswalkHelper::RIFCS_WRAPPER);
	}
	
	public function identify(){
		return "DDI v2.5 to RIF-CS (Experimental)";
	}
	
	public function metadataFormat(){
		return "ddi_2.5";
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
		foreach($this->oaipmh->ListRecords as $record) {
			if ($record->getName() == "record") {
				if (empty($record->header->identifier)) {
					return false;
				}
				if (empty($record->metadata->codeBook->stdyDscr)) {
					return false;
				}
			}
		}
		//More validation needed...
		return true;
	}
	
	public function payloadToRIFCS($payload){
		$this->load_payload($payload);
		foreach ($this->oaipmh->ListRecords->children() as $record){
			if ($record->getName() != "record") {
				continue;
			}
			$reg_obj = $this->rifcs->addChild("registryObject");
			if (array_key_exists((string) $this->oaipmh->request, $this->ddiProviders)) {
				$reg_obj->addAttribute("group", $this->ddiProviders[(string) $this->oaipmh->request]);
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
			foreach ($record->metadata->codeBook->stdyDscr->children() as $node){
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
	
	private function addDate($collection, $type, $value){
		$dates = $collection->addChild("dates");
		$dates->addAttribute("type", $type);
		$dates_date = $dates->addChild("date",CrosswalkHelper::escapeAmpersands($value));
		$dates_date->addAttribute("type", "dateFrom");
		$dates_date->addAttribute("dateFormat", "W3CDTF");
	}
	
	private function addDescription($collection, $type, $value){
		$description = $collection->addChild("description", CrosswalkHelper::escapeAmpersands($value));
		$description->addAttribute("type",$type);
	}
	
	private function addName($name, $value, $output_nodes = null) {
		// Passing $output_nodes means a relatedObject is required.
		$contributor = isset($output_nodes);
		$id = sha1($value);
		if ($contributor) {
			$ctrb_obj = null;
			$ctrb_party = null;
			$new_ctrb = true;
			foreach ($this->rifcs->children() as $object) {
				if ((string) $object->key == $id) {
					$ctrb_obj = $object;
					$ctrb_party = $object->party;
					$new_ctrb = false;
					break;
				}
			}
			if ($ctrb_obj === null) {
				$ctrb_obj = $this->rifcs->addChild("registryObject");
				if (array_key_exists((string) $this->oaipmh->request, $this->ddiProviders)) {
					$ctrb_obj->addAttribute("group", $this->ddiProviders[(string) $this->oaipmh->request]);
				}
				$ctrb_obj->addChild("key", $id);
				$ctrb_obj->addChild("originatingSource", $this->oaipmh->request);
				$ctrb_party = $ctrb_obj->addChild("party");
				$ctrb_party->addAttribute("dateModified", date(DATE_W3C));
			}
		}
		/*
		 * It is rare but not impossible to have a double-barrelled surname with a
		 * space instead of a dash (e.g. Ralph Vaughan Williams), so the following
		 * is not entirely robust.
		 */
		if (preg_match("|^([-A-Za-z]+), ((?:[A-Z]\.[- ]?)*)|", $value, $matches)) {
			$surname = $name->addChild("namePart", $matches[1]);
			$surname->addAttribute("type", "family");
			$initials = $name->addChild("namePart", $matches[2]);
			$initials->addAttribute("type", "given");
			if ($contributor && $new_ctrb) {
				$ctrb_party->addAttribute("type", "person");
				$ctrb_name = $ctrb_party->addChild("name");
				$ctrb_name->addAttribute("type", "primary");
				$ctrb_surname = $ctrb_name->addChild("namePart", $matches[1]);
				$ctrb_surname->addAttribute("type", "family");
				$ctrb_initials = $ctrb_name->addChild("namePart", $matches[2]);
				$ctrb_initials->addAttribute("type", "given");
			}
		} else {
			$name->addChild("namePart", $value);
			if ($contributor && $new_ctrb) {
				$ctrb_party->addAttribute("type", "group");
				$ctrb_name = $ctrb_party->addChild("name");
				$ctrb_name->addAttribute("type", "primary");
				$ctrb_name->addChild("namePart", $value);
			}
		}
		if ($contributor) {
			$ctrb_rel_obj = $ctrb_party->addChild("relatedObject");
			$ctrb_rel_obj->addChild("key", $output_nodes["key"]);
			$ctrb_rel_obj_type = $ctrb_rel_obj->addChild("relation");
			$ctrb_rel_obj_type->addAttribute("type", "isPrincipalInvestigatorOf");
			$ctrb_party["dateModified"] = date(DATE_W3C);
			$rel_obj = $output_nodes["collection"]->addChild("relatedObject");
			$rel_obj->addChild("key", $id);
			$rel_obj_type = $rel_obj->addChild("relation");
			$rel_obj_type->addAttribute("type", "hasPrincipalInvestigator");
		}
	}
	
	private function process_titlStmt($input_node, $output_nodes){
		foreach ($input_node->children() as $stmt) {
			switch ($stmt->getName()) {
			case "titl":
				$name = $output_nodes["collection"]->addChild("name");
				$name->addAttribute("type", "primary");
				$name->addChild("namePart", $stmt);
				$output_nodes["citation_metadata"]->addChild("title", $stmt);
				break;
			case "altTitl":
				$altName = $output_nodes["collection"]->addChild("name");
				$altName->addAttribute("type", "alternative");
				$altName->addChild("namePart", $stmt);
				break;
			case "IDNo":
				if (isset($stmt["agency"]) && (string) $stmt["agency"] == "datacite") {
					$idNo = $output_nodes["collection"]->addChild("identifier", $stmt);
					$idNo->addAttribute("type", "doi");
					$citIdNo = $output_nodes["citation_metadata"]->addChild("identifier", $stmt);
					$citIdNo->addAttribute("type", "doi");
				} elseif (isset($stmt["agency"]) && (string) $stmt["agency"] == "UKDA") {
					$idNo = $output_nodes["collection"]->addChild("identifier", "sn" . $stmt);
					$idNo->addAttribute("type", "local");
					$relInfo = $output_nodes["collection"]->addChild("relatedInfo");
					$relInfo->addAttribute("type", "metadata");
					$relIdNo = $relInfo->addChild("identifier", "http://esds.ac.uk/DDI25/" . $stmt . ".xml");
					$relIdNo->addAttribute("type", "uri");
					$relInfoFmt = $relInfo->addChild("format");
					$relInfoFmtIdNo = $relInfoFmt->addChild("identifier", "http://www.ddialliance.org/Specification/DDI-Codebook/2.5/XMLSchema/codebook.xsd");
					$relInfoFmtIdNo->addAttribute("type", "uri");
				} else {
					$idNo = $output_nodes["collection"]->addChild("identifier", $stmt);
					$idNo->addAttribute("type", "local");
				}
				break;
			}
		}
	}
	
	private function process_rspStmt($input_node, $output_nodes){
		foreach ($input_node->children() as $stmt) {
			switch ($stmt->getName()) {
			case "AuthEnty":
				$contrib = $output_nodes["citation_metadata"]->addChild("contributor");
				$this->addName($contrib, $stmt, $output_nodes);
				break;
			}
		}
	}
	
	private function process_prodStmt($input_node, $output_nodes){
		foreach ($input_node->children() as $stmt) {
			switch ($stmt->getName()) {
			case "copyright":
				$output_nodes["rights"]->addChild("rightsStatement", $stmt);
				break;
			}
		}
	}
	
	private function process_distStmt($input_node, $output_nodes){
		foreach ($input_node->children() as $stmt) {
			switch ($stmt->getName()) {
			case "depDate":
				$output_nodes["collection"]->addAttribute("dateAccessioned", $stmt["date"]);
				$this->addDate($output_nodes["collection"],"dc.dateSubmitted",$stmt["date"]);
				$citeSubmitted = $output_nodes["citation_metadata"]->addChild("date", $stmt);
				$citeSubmitted->addAttribute("type", "dateSubmitted");
				break;
			case "distDate":
				$this->addDate($output_nodes["collection"],"dc.available",$stmt["date"]);
				$this->addDate($output_nodes["collection"],"dc.issued",$stmt["date"]);
				$citePublished = $output_nodes["citation_metadata"]->addChild("date", $stmt);
				$citePublished->addAttribute("type", "publicationDate");
				$citeAvailable = $output_nodes["citation_metadata"]->addChild("date", $stmt);
				$citeAvailable->addAttribute("type", "available");
				$citeIssued = $output_nodes["citation_metadata"]->addChild("date", $stmt);
				$citeIssued->addAttribute("type", "issued");
				break;
			case "distrbtr":
				$output_nodes["citation_metadata"]->addChild("publisher", $stmt);
			}
		}
	}
	
	private function process_verStmt($input_node, $output_nodes){
		foreach ($input_node->children() as $stmt) {
			switch ($stmt->getName()) {
			case "version":
				$output_nodes["citation_metadata"]->addChild("version", $stmt);
				break;
			}
		}
	}
	
	private function process_holdings($input_node, $output_nodes){
		foreach ($input_node->attributes() as $attrib => $value) {
			if ($attrib == "URI") {
				$loc = $output_nodes["collection"]->addChild("location");
				$addr = $loc->addChild("address");
				$elec = $addr->addChild("electronic");
				$elec->addAttribute("type", "url");
				$elec->addChild("value", $value);
				$output_nodes["citation_metadata"]->addChild("url", $value);
				break;
			}
		}
	}
	
	private function process_subject($input_node, $output_nodes){
		foreach ($input_node->children() as $subj) {
			switch ($subj->getName()) {
			case "keyword":
				if (isset($subj["vocab"])) {
					if ((string) $subj["vocab"] == "S") {
						$term = $output_nodes["collection"]->addChild("subject", $subj);
						$term->addAttribute("type", "hassett");
						if (isset($subj["vocabURI"])) {
							$term->addAttribute("termIdentifier", $subj["vocabURI"]);
						}
					} elseif ((string) $subj["vocab"] == "G") {
						$spatial = $output_nodes["coverage"]->addChild("spatial", $subj);
						$spatial->addAttribute("type", "text");
					}
				}
				break;
			case "topcClas":
				$term = $output_nodes["collection"]->addChild("subject", $subj);
				$term->addAttribute("type", "ukdasc");
				break;
			}
		}
	}
	
	private function process_abstract($input_node, $output_nodes){
		$this->addDescription($output_nodes["collection"], "full", $input_node);
	}
	
	private function process_sumDscr($input_node, $output_nodes){
		//Dates need to be collected before written out to RIF-CS
		$periods = array();
		foreach ($input_node->children() as $dscr) {
			switch ($dscr->getName()) {
			case "collDate":
				if (isset($dscr["event"]) && isset($dscr["date"])) {
					switch ((string) $dscr["event"]) {
					case "single":
					case "start":
						$periods["collected"]["from"] = (string) $dscr["date"];
						break;
					case "end":
						$periods["collected"]["to"] = (string) $dscr["date"];
						break;
					}
				}
				break;
			case "timePrd":
				if (isset($dscr["event"]) && isset($dscr["date"])) {
					switch ((string) $dscr["event"]) {
					case "single":
					case "start":
						$periods["originated"]["from"] = (string) $dscr["date"];
						break;
					case "end":
						$periods["originated"]["to"] = (string) $dscr["date"];
						break;
					}
				}
				break;
			case "geogCover":
			case "geogUnit":
			case "nation":
				$spatial = $output_nodes["coverage"]->addChild("spatial", $dscr);
				$spatial->addAttribute("type", "text");
				break;
			}
		}
		if (isset($periods["collected"])) {
			$collected = $output_nodes["coverage"]->addChild("temporal");
			if (isset($periods["collected"]["from"])) {
				$collDateFrom = $collected->addChild("date", $periods["collected"]["from"]);
				$collDateFrom->addAttribute("type", "dateFrom");
				$collDateFrom->addAttribute("dateFormat", "W3CDTF");
			}
			if (isset($periods["collected"]["to"])) {
				$collDateTo = $collected->addChild("date", $periods["collected"]["to"]);
				$collDateTo->addAttribute("type", "dateTo");
				$collDateTo->addAttribute("dateFormat", "W3CDTF");
			}
		}
		if (isset($periods["originated"])) {
			$originated = $output_nodes["coverage"]->addChild("temporal");
			if (isset($periods["originated"]["from"])) {
				$origDateFrom = $originated->addChild("date", $periods["originated"]["from"]);
				$origDateFrom->addAttribute("type", "dateFrom");
				$origDateFrom->addAttribute("dateFormat", "W3CDTF");
			}
			if (isset($periods["originated"]["to"])) {
				$origDateTo = $originated->addChild("date", $periods["originated"]["to"]);
				$origDateTo->addAttribute("type", "dateTo");
				$origDateTo->addAttribute("dateFormat", "W3CDTF");
			}
		}
	}
	
	private function process_useStmt($input_node, $output_nodes){
		foreach ($input_node->children() as $stmt) {
			switch ($stmt->getName()) {
			case "restrctn":
			case "conditions":
				$output_nodes["rights"]->addChild("accessRights", $stmt);
				break;
			}
		}
	}
	
	private function process_relPubl($input_node, $output_nodes){
		//Need to write citation parser to interpret this...
	}
}

?>