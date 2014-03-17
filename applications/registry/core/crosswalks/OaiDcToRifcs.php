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
								"rights" => $rights,
								"contributors" => &$contributors
							)
						);
					}
				}
			}
			if ($citation_metadata->publisher === null && isset($this->oaiProviders[(string) $this->oaipmh->request])) {
				$citation_metadata->addChild("publisher", $this->oaiProviders[(string) $this->oaipmh->request]);
			}
			/* Processing contributor names is deferred till now to ensure
			 * they come after creator names.
			 */
			if (count($contributors) > 0) {
				foreach ($contributors as $author_name) {
					$ctrb = $citation_metadata->addChild("contributor");
					foreach ($author_name["parts"] as $partType => $part) {
						$ctrb_part = $ctrb->addChild("namePart", $part);
						if ($partType != "whole") {
							$ctrb_part->addAttribute("type", $partType);
						}
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
		if ($node->getName() == "coverage") {
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
	
	private function parseNames($authorString) {
		$authors = array();
		$names = array();
		if (strpos($authorString, ';')) {
			// Semicolon present, probably used as a separator
			$authors = explode(';', $authorString);
		} else {
			$authors[] = $authorString;
		}
		foreach($authors as $authorName) {
			if (preg_match("/(.+), ?([^,]+)(?:, ?([^,]+))?/", $value, $matches)) {
				$name_parts = array();
				$name_parts["family"] = $matches[1];
				$name_parts["given"] = $matches[2];
				if (isset($matches[3])) {
					$name_parts["suffix"] = $matches[3];
				}
				$name = array("type" => "person", "parts" => $name_parts);
				$names[] = $name;
			} elseif (strpos($trim($authorName), ' ') === FALSE || strpos($authorName, ' of ') !== FALSE || stripos($authorName, 'the ') !== FALSE ) {
				$name = array("type" => "group", "parts" => array("whole" => $authorName));
				$names[] = $name;
			} else {
				// This might be a person or a group; we assume a person but don't parse further.
				$name = array("type" => "person", "parts" => array("whole" => $authorName));
				$names[] = $name;
			}
		}
		return $names;
	}
	
	private function process_title($input_node, $output_nodes) {
		$title = (string) $input_node;
		$name = $output_nodes["collection"]->addChild("name");
		$name->addAttribute("type", "primary");
		$name->addChild("namePart", $title);
		$output_nodes["citation_metadata"]->addChild("title", $title);
	}
	
	private function process_creator($input_node, $output_nodes) {
		$names = $this->parseNames((string) $input_node);
		foreach ($names as $author_name) {
			$ctrb = $output_nodes["citation_metadata"]->addChild("contributor");
			$hashString = "";
			if (isset($author_name["parts"]["whole"])) {
				$ctrb->addChild("namePart", $author_name["parts"]["whole"]);
				$hashString = $author_name["parts"]["whole"];
			} else {
				$ctrb_given = $ctrb->addChild("namePart", $author_name["parts"]["given"]);
				$ctrb_given->addAttribute("type", "given");
				$hashString .= $author_name["parts"]["given"];
				$ctrb_family = $ctrb->addChild("namePart", $author_name["parts"]["family"]);
				$ctrb_family->addAttribute("type", "family");
				$hashString .= " " . $author_name["parts"]["family"];
				if (isset($author_name["parts"]["suffix"])) {
					$ctrb_suffix = $ctrb->addChild("namePart", $author_name["parts"]["suffix"]);
					$ctrb_suffix->addAttribute("type", "suffix");
					$hashString .= " " . $author_name["parts"]["suffix"];
				}
			}
			$id = sha1($hashString);
			// Is this a new or existing party?
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
			if ($new_ctrb) {
				$ctrb_obj = $this->rifcs->addChild("registryObject");
				$ctrb_obj->addAttribute("group", $output_nodes["registry_object"]->group);
				$ctrb_obj->addChild("key", $id);
				$ctrb_obj->addChild("originatingSource", $output_nodes["registry_object"]->originatingSource);
				$ctrb_party = $ctrb_obj->addChild("party");
				$ctrb_party->addAttribute("dateModified", date(DATE_W3C));
				$ctrb_party->addAttribute("type", $author_name["type"]);
				$ctrb_name = $ctrb_party->addChild("name");
				$ctrb_name->addAttribute("type", "primary");
				foreach ($author_name["parts"] as $partType => $part) {
					$ctrb_part = $ctrb_name->addChild("namePart", $part);
					if ($partType != "whole") {
						$ctrb_part->addAttribute("type", $partType);
					}
				}
			}
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
	
	private function process_subject($input_node, $output_nodes) {
		$subject = (string) $input_node;
		$subjects = array();
		$raw_subjects = array();
		if (strpos($subject, ';')) {
			// Semicolon present, probably used as a separator
			$raw_subjects = explode(';', $subject);
		} elseif (substr_count($subject, ',') > 1) {
			// More than one comma, so commas probably used as separators
			$raw_subjects = explode(',', $subject);
		} else {
			// Probably a single term
			$raw_subjects[] = $subject;
		}
		foreach ($raw_subjects as $term) {
			$subjects[] = trim($term);
		}
		foreach ($subjects as $term) {
			$keyword = $output_nodes["collection"]->addChild("subject", $term);
			$keyword->addAttribute("type", "local");
		}
	}
	
	private function process_description($input_node, $output_nodes) {
		$description = $output_nodes["collection"]->addChild("description", $input_node);
		$description->addAttribute("type", "full");
	}
	
	private function process_publisher($input_node, $output_nodes) {
		$output_nodes["citation_metadata"]->addChild("publisher", $input_node);
	}
	
	private function process_contributor($input_node, $output_nodes) {
		$output_nodes["contributors"] = $this->parseNames((string) $input_node);
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
		$id = (string) $input_node;
		$parsed_id = $id;
		if (preg_match('~(?:http://dx.doi.org/|doi:)(10\.\d+/.*)~', $id, $matches)) {
			$parsed_id = $matches[1];
		} elseif (preg_match('~(?:http://hdl.handle.net/)(1[^/]+/.*)~', $id, $matches)) {
			$parsed_id = $matches[1];
		}
		$source = null;
		foreach ($this->rifcs->children() as $object) {
			if ($object->collection !== null) {
				if ((string) $object->key == $id) {
					$source = $object;
					break;
				} else {
					foreach ($object->collection->identifier as $identifier) {
						if ($parsed_id == (string) $identifier) {
							$source = $object;
							break 2;
						}
					}
				}
			}
		}
		if ($source) {
			$related = $output_nodes["collection"]->addChild("relatedObject");
			$related->addChild("key", $source->key);
			$relation = $related->addChild("relation");
			$relation->addChild("type", "isDerivedFrom");
			$source_related = $source->collection->addChild("relatedObject");
			$source_related->addChild("key", $output_nodes["key"]);
			$source_relation = $source_related->addChild("relation");
			$source_relation->addChild("type", "hasDerivedCollection");
		}
	}
	
	private function process_relation($input_node, $output_nodes) {
		$id = (string) $input_node;
		$idType = "local";
		if (strpos($id, "info:") === 0) {
			$idType = "infouri";
		} elseif (strpos($id, "http://purl.org/") === 0) {
			$idType = "purl";
		} elseif (preg_match('~(?:http://dx.doi.org/|doi:)(10\.\d+/.*)~', $id, $matches)) {
			$id = $matches[1];
			$idType = "doi";
		} elseif (preg_match('~(?:http://hdl.handle.net/)(1[^/]+/.*)~', $id, $matches)) {
			$id = $matches[1];
			$idType = "handle";
		} elseif (CrosswalkHelper::isUrl($id)) {
			$idType = "uri";
		} elseif (CrosswalkHelper::isUri($id)) {
			$idType = "uri";
		}
		$related = $output_nodes["collection"]->addChild("relatedInfo");
		$identifier = $related->addChild("identifier", $id);
		$identifier->addAttribute("type", $idType);
		$relation = $related->addChild("relation");
		$relation->addAttribute("type", "hasAssociationWith");
		$relation->addChild("description", "Unknown");
	}
	
	private function process_coverage($input_node, $output_nodes) {
		$coverage = (string) $input_node;
		// Is this a date or date range?
		if ($divider = strpos($dateString, '/')) {
			$dateFromString = substr($dateString, 0, $divider);
			if ($dateFromDate = strtotime($dateFromString)) {
				// It's a date range
				$dateFrom = date(DATE_W3C, $dateFromDate);
				$dateToString = substr($dateString, $divider);
				if ($dateToDate = strtotime($dateToString)) {
					$dateTo = date(DATE_W3C, $dateToDate);
				} else {
					$dateTo = FALSE;
				}
				$this->addDate($output_nodes["coverage"], "extent", $dateFrom, $dateTo);
			} else {
				// It's probably a description of spatial coverage
				$spatial = $output_nodes["coverage"]->addChild("spatial", $coverage);
				$spatial->addAttribute("type", "text");
			}
		} elseif ($dateFromDate = strtotime($coverage)) {
			// It's a single date
			$dateFrom = date(DATE_W3C, $dateFromDate);
			$this->addDate($output_nodes["coverage"], "extent", $dateFrom);
		} else {
			// It's probably a description of spatial coverage
			$spatial = $output_nodes["coverage"]->addChild("spatial", $coverage);
			$spatial->addAttribute("type", "text");
		}
	}
	
	private function process_rights($input_node, $output_nodes) {
		$output_nodes["rights"]->addChild("rightsStatement", $input_node);
	}

}
?>