<?php

class Gemini2p2ToRifcs extends Crosswalk {

	private $gemini = null;
	private $rifcs = null;
	
	private $thesauri = array(
		"SeaDataNet PDV" => "seadatanet",
		"GEMET - INSPIRE themes, version 1.0" => "gemet"
	);
	
	private $codeSpaces = array(
		"ISO 3166" => "iso31661",
		"ISO-3166-2" => "iso31662",
		"ISO 3166-2" => "iso31662",
	);
	
	private $roles = array(
		"custodian" => array("collection" => "isManagedBy", "party" => "isManagerOf"),
		"owner" => array("collection" => "isOwnedBy", "party" => "isOwnerOf"),
		"originator" => array("collection" => "hasPrincipalInvestigator", "party" => "isPrincipalInvestigatorOf"),
		"principalInvestigator" => array("collection" => "hasPrincipalInvestigator", "party" => "isPrincipalInvestigatorOf"),
		"author" => array("collection" => "hasPrincipalInvestigator", "party" => "isPrincipalInvestigatorOf"),
		"processor" => array("collection" => "isEnrichedBy", "party" => "enriches"),
	);
	
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
			/* Some citation information needs to be sorted out once the whole record
			 * has been processed.
			 */
			$responsibilities = array(
				"author" => array(),
				"originator" => array(),
				"principalInvestigator" => array(),
				"owner" => array(),
				"publisher" => array(),
				"distributor" => array(),
				"resourceProvider" => array()
			);
			$reg_obj = $this->rifcs->addChild("registryObject");
			/* The following should probably be set in the user account, but is here
			 * for the purposes of the pilot.
			 */
			$reg_obj->addAttribute("group", "NERC Data Catalogue Service");
			$key = $reg_obj->addChild("key", $record->xpath('gmd:fileIdentifier/gco:CharacterString')[0]);
			// If this class is used more widely, a different fallback should be selected:
			$reg_obj->addChild("originatingSource", "http://www.nerc.ac.uk/");
			$coll = $reg_obj->addChild("collection");
			$coll->addAttribute("type", "dataset");
			$coll->addAttribute("dateModified", date(DATE_W3C));
			// The related info node here is specific to the NERC DCS.
			// TODO: Find way of inserting source-specific metadata URLs.
			$related_info = $coll->addChild("relatedInfo");
			$related_info->addAttribute("type", "metadata");
			$metadata_url = "http://csw1.cems.rl.ac.uk/geonetwork-NERC/srv/eng/csw?SERVICE=CSW&VERSION=2.0.2&REQUEST=GetRecordById&ElementSetName=full&outputSchema=http://www.isotc211.org/2005/gmd&Id=" . (string) $reg_obj->key;
			$metadata_url = CrosswalkHelper::escapeAmpersands($metadata_url);
			$related_info_id = $related_info->addChild("identifier", $metadata_url);
			$related_info_id->addAttribute("type", "uri");
			$related_info_format = $related_info->addChild("format");
			$related_info_format_id = $related_info_format->addChild("identifier", "http://www.agi.org.uk/storage/standards/uk-gemini/");
			$related_info_format_id->addAttribute("type", "uri");
			$citation = $coll->addChild("citationInfo");
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
							"rights" => $rights,
							"responsibilities" => &$responsibilities
						)
					);
				}
			}
			// Now we look for contributors
			foreach (array("author", "originator", "principalInvestigator", "owner") as $contributorType) {
				if (count($responsibilities[$contributorType]) > 0) {
					$i = 1;
					foreach ($responsibilities[$contributorType] as $contributorName) {
						$contrib = $citation_metadata->addChild("contributor");
						if ($contributorType == "author") {
							$contrib->addAttribute("seq", $i++);
						}
						if (is_array($contributorName)) {
							foreach ($contributorName as $namePartType => $namePartString) {
								$namePart = $contrib->addChild("namePart", $namePartString);
								$namePart->addAttribute("type", $namePartType);
							}
						} else {
							$contrib->addChild("namePart", $contributorName);
						}
					}
					break;
				}
			}
			// Now we look for publishers
			foreach(array("publisher", "distributor", "resourceProvider") as $publisherType) {
				if (count($responsibilities[$publisherType]) > 0) {
					foreach ($responsibilities[$publisherType] as $publisherName) {
						if (is_array($publisherName)) {
							// This shouldn't happen, but just in case...
							$publisher = "{$publisherName["given"]} {$publisherName["family"]}";
							if (isset($publisherName["suffix"])) {
								$publisher .= " {$publisherName["suffix"]}";
							}
						} else {
							$publisher = $publisherName;
						}
						$citation_metadata->addChild("publisher", $publisher);
					}
					break;
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
	
	private function addDate($collection, $type, $valueFrom, $valueTo = FALSE) {
		if ($type == "extent") {
			$dates = $collection->addChild("temporal");
		} else {
			$dates = $collection->addChild("dates");
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
	
	private function addID($collection, $code, $codeSpace) {
		$id = $code;
		$idType = "local";
		if (strpos($code, "info:") === 0) {
			$idType = "infouri";
		} elseif (strpos($code, "http://purl.org/") === 0) {
			$idType = "purl";
			if ($collection->getName() == "collection") {
				$this->addLocationUrl($collection, $code);
			} elseif ($collection->getName() == "citationMetadata" && $collection->url === null) {
				$collection->addChild("url", $code);
			}
		} elseif (preg_match('~(?:http://dx.doi.org/|doi:)(10\.\d+/.*)~', $code, $matches)) {
			$id = $matches[1];
			$idType = "doi";
			if ($collection->getName() == "collection") {
				$this->addLocationUrl($collection, "http://dx.doi.org/" . $id);
			} elseif ($collection->getName() == "citationMetadata" && $collection->url === null) {
				$collection->addChild("url", "http://dx.doi.org/" . $id);
			}
		} elseif (preg_match('~(?:http://hdl.handle.net/)(1[^/]+/.*)~', $code, $matches)) {
			$id = $matches[1];
			$idType = "handle";
			if ($collection->getName() == "collection") {
				$this->addLocationUrl($collection, "http://hdl.handle.net/" . $id);
			} elseif ($collection->getName() == "citationMetadata" && $collection->url === null) {
				$collection->addChild("url", "http://hdl.handle.net/" . $id);
			}
		} elseif (CrosswalkHelper::isUrl($code)) {
			$idType = "uri";
			if ($collection->getName() == "collection") {
				$this->addLocationUrl($collection, $code);
			} elseif ($collection->getName() == "citationMetadata" && $collection->url === null) {
				$collection->addChild("url", $code);
			}
		} elseif (CrosswalkHelper::isUri($code)) {
			$idType = "uri";
		}
		$identifier = $collection->addChild("identifier", $id);
		$identifier->addAttribute("type", $idType);
	}
	
	private function process_contact($input_node, $output_nodes) {
		// If this class is used more widely, a different fallback should be selected:
		$originatingSource = "http://www.nerc.ac.uk/";
		$sourceUrl = $input_node->xpath('gmd:CI_ResponsibleParty/gmd:contactInfo/gmd:CI_Contact/gmd:onlineResource/gmd:CI_OnlineResource/gmd:linkage/gmd:URL');
		if (count($sourceUrl > 0)) {
			$originatingSource = $sourceUrl[0];
		} else {
			$sourceEmail = $input_node->xpath('gmd:CI_ResponsibleParty/gmd:contactInfo/gmd:CI_Contact/gmd:address/gmd:CI_Address/gmd:electronicMailAddress/gco:CharacterString');
			if (count($sourceEmail > 0)) {
				if (preg_match('/@(.+)/', (string) $sourceEmail[0], $host)) {
					$originatingSource = "http://www." . $host[1] . "/";
				}
			}
		}
		$output_nodes["registry_object"]->originatingSource = $originatingSource;
	}
	
	private function process_identificationInfo($input_node, $output_nodes) {
		foreach ($input_node->children('gmd', TRUE)->MD_DataIdentification->children('gmd', TRUE) as $node){
			$func = "process_".$node->getName();
			if (is_callable(array($this, $func))){
				call_user_func(
					array($this, $func),
					$node,
					$output_nodes
				);
			}
		}
	}

	private function process_citation($input_node, $output_nodes) {
		$idCount = 0;
		foreach ($input_node->children('gmd', TRUE)->CI_Citation->children('gmd', TRUE) as $node) {
			switch ($node->getName()) {
			case "title":
				$name = $output_nodes["collection"]->addChild("name");
				$name->addAttribute("type", "primary");
				$name->addChild("namePart", $node->children('gco', TRUE)->CharacterString);
				$output_nodes["citation_metadata"]->addChild("title", $node->children('gco', TRUE)->CharacterString);
				break;
			case "alternateTitle":
				$altName = $output_nodes["collection"]->addChild("name");
				$altName->addAttribute("type", "alternative");
				$altName->addChild("namePart", $node->children('gco', TRUE)->CharacterString);
				break;
			case "date":
				$dateStamp = $node->xpath('gmd:CI_Date/gmd:date/gco:Date');
				if (count($dateStamp) > 0) {
					$dateFrom = $dateStamp[0];
					$dateTo = null;
					if (preg_match('~(\d{4}-\d{2}-\d{2})/(\d{4}-\d{2}-\d{2})~', $dateStamp[0], $dateStamps)) {
						// This is a date range
						$dateFrom = $dateStamps[1];
						$dateTo = $dateStamps[2];
					}
					$dateType = $node->xpath('gmd:CI_Date/gmd:dateType/gmd:CIDateTypeCode');
					if (count($dateType) > 0) {
						if ($dateType[0] == "creation") {
							$this->addDate($output_nodes["collection"], "dc.created", $dateFrom, $dateTo);
							$citeCreated = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
							$citeCreated->addAttribute("type", "created");
						} elseif ($dateType[0] == "publication") {
							if (count($dateStamp) > 0) {
								$this->addDate($output_nodes["collection"], "dc.issued", $dateFrom, $dateTo);
								if ($dateTo) {
									$citePublishedFrom = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
									$citePublishedFrom->addAttribute("type", "startPublicationDate");
									$citePublishedTo = $output_nodes["citation_metadata"]->addChild("date", $dateTo);
									$citePublishedTo->addAttribute("type", "endPublicationDate");
								} else {
									$citePublished = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
									$citePublished->addAttribute("type", "publicationDate");
								}
								$citeAvailable = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
								$citeAvailable->addAttribute("type", "available");
								$citeIssued = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
								$citeIssued->addAttribute("type", "issued");
							}
						} elseif ($dateType[0] == "revision") {
							if (count($dateStamp) > 0) {
								$citeModified = $output_nodes["citation_metadata"]->addChild("date", $dateFrom);
								$citeModified->addAttribute("type", "modified");
							}
						}
					}
				}
				break;
			case "identifier":
				$code = $node->xpath('gmd:RS_Identifier/gmd:code/gco:CharacterString');
				if (count($code) > 0) {
					$codeSpace = $node->xpath('gmd:RS_Identifier/gmd:codeSpace/gco:CharacterString');
					if (count($codeSpace) > 0) {
						$this->addID($output_nodes["collection"], $code[0], $codeSpace[0]);
						if ($idCount == 0) {
							$this->addID($output_nodes["citation_metadata"], $code[0], $codeSpace[0]);
						}
						$idCount++;
					}
				}
				break;
			}
		}
	}
	
	private function process_abstract($input_node, $output_nodes) {
		foreach ($input_node->children('gco', TRUE) as $node) {
			if ($node->getName() == "CharacterString") {
				$abstract = $output_nodes["collection"]->addChild("description", CrosswalkHelper::escapeAmpersands($node));
				$abstract->addAttribute("type", "full");
			}
		}
	}
	
	private function process_pointOfContact($input_node, $output_nodes) {
		// First we collect all the information...
		$partyArray = array();
		foreach($input_node->children('gmd', TRUE)->CI_ResponsibleParty->children('gmd', TRUE) as $node) {
			switch ($node->getName()) {
			case "individualName":
				$nameString = (string) $node->children('gco', TRUE)->CharacterString;
				$name = array();
				if (preg_match("/(.+), ?([^,]+)(?:, ?([^,]+))?/", $nameString, $matches)) {
					// Name probably in inverted form
					// TODO handle case of "GivenName Surname, Suffix"
					$name["family"] = $matches[1];
					$name["given"] = $matches[2];
					if (isset($matches[3])) {
						$name["suffix"] = $matches[3];
					}
				} else {
					/* 
					* Name in normal order, or "Unknown".
					* 
					* It is rare but not impossible to have a double-barrelled surname with a
					* space instead of a dash (e.g. Ralph Vaughan Williams), so the following
					* is not entirely robust.
					*/
					$nameWords = explode(" ", $nameString);
					// We filter out "Unknown"
					if (count($nameWords) > 1) {
						$name["family"] = array_pop($nameWords);
						$name["given"] = implode(" ", $nameWords);
					}
				}
				if (count($name) > 0) {
					$partyArray["person"] = $name;
				}
				break;
			case "organisationName":
				$partyArray["group"] = (string) $node->children('gco', TRUE)->CharacterString;
				break;
			case "contactInfo":
				$physical = array();
				$electronic = null;
				foreach($node->xpath("gmd:CI_Contact/gmd:address/gmd:CI_Address") as $line) {
					switch ($line->getName()) {
					case "deliveryPoint":
						$physical[1] = $line->children('gco', TRUE)->CharacterString;
						break;
					case "city":
						$physical[2] = $line->children('gco', TRUE)->CharacterString;
						break;
					case "administrativeArea":
						$physical[3] = $line->children('gco', TRUE)->CharacterString;
						break;
					case "postalCode":
						$physical[4] = $line->children('gco', TRUE)->CharacterString;
						break;
					case "country":
						$physical[5] = $line->children('gco', TRUE)->CharacterString;
						break;
					case "electronicMailAddress":
						$electronic = $line->children('gco', TRUE)->CharacterString;
						break;
					}
				}
				$address = array();
				if (count($physical) > 0) {
					$address["physical"] = $physical;
				}
				if ($electronic) {
					$address["electronic"] = $electronic;
				}
				if (count($address) > 0) {
					$partyArray["address"] = $address;
				}
				break;
			case "role":
				$partyArray["role"] = (string) $node->children('gmd', TRUE)->CI_RoleCode;
				break;
			}
		}
		if (count($partyArray) == 0) {
			return null;
		}
		// We only need to create related objects for certain roles.
		if (array_key_exists($partyArray["role"], $this->roles)) {
			/* We cannot rely on each individual having a unique email address, so we
			* have to concoct an identifier.
			*/
			if (isset($partyArray["person"])) {
				$hashString = "{$partyArray["person"]["given"]} {$partyArray["person"]["family"]}";
				if (isset($partyArray["person"]["suffix"])) {
					$hashString .= " {$partyArray["person"]["suffix"]}";
				}
			} else {
				$hashString = $partyArray["group"];
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
				$ctrb_obj->addAttribute("group", "NERC Data Catalogue Service");
				$ctrb_obj->addChild("key", $id);
				$originatingSource = "http://www.nerc.ac.uk/";
				if (isset($output_nodes["registry_object"]->originatingSource)) {
					$originatingSource = $output_nodes["registry_object"]->originatingSource;
				}
				$ctrb_obj->addChild("originatingSource", $originatingSource);
				$ctrb_party = $ctrb_obj->addChild("party");
				$ctrb_party->addAttribute("dateModified", date(DATE_W3C));
				$ctrb_name = $ctrb_party->addChild("name");
				$ctrb_name->addAttribute("type", "primary");
				if (isset($partyArray["person"])) {
					foreach ($partyArray["person"] as $namePartType => $namePartString) {
						$ctrb_namepart = $ctrb_name->addChild("namePart", $namePartString);
						$ctrb_namepart->addAttribute("type", $namePartString);
					}
					$ctrb_party->addAttribute("type", "person");
				} else {
					$ctrb_name->addChild("namePart", $partyArray["group"]);
					$ctrb_party->addAttribute("type", "group");
				}
				if (isset($partyArray["address"])) {
					$ctrb_location = $ctrb_party->addChild("location");
					$ctrb_address = $ctrb_location->addChild("address");
					foreach ($partyArray["address"] as $addressType => $addressInfo) {
						switch ($addressType) {
						case "electronic":
							$email = $ctrb_address->addChild("electronic");
							$email->addAttribute("type", "email");
							$email->addChild("value", $addressInfo);
							break;
						case "physical":
							$postal = $ctrb_address->addChild("physical");
							$postal->addAttribute("type", "postalAddress");
							foreach ($addressInfo as $addressLine) {
								$postalLine = $postal->addChild("addressPart", $addressLine);
								$postalLine->addAttribute("type", "addressLine");
							}
							break;
						}
					}
				}
			}
			$ctrb_rel_obj = $ctrb_party->addChild("relatedObject");
			$ctrb_rel_obj->addChild("key", $output_nodes["key"]);
			$ctrb_rel_obj_type = $ctrb_rel_obj->addChild("relation");
			$ctrb_rel_obj_type->addAttribute("type", $this->roles[$partyArray["role"]]["party"]);
			$ctrb_party["dateModified"] = date(DATE_W3C);
			$rel_obj = $output_nodes["collection"]->addChild("relatedObject");
			$rel_obj->addChild("key", $id);
			$rel_obj_type = $rel_obj->addChild("relation");
			$rel_obj_type->addAttribute("type", $this->roles[$partyArray["role"]]["collection"]);
		}
		// Then we put the party forward for inclusion in the citation information, if appropriate.
		if (array_key_exists($partyArray["role"], $output_nodes["responsibilities"])) {
			if (isset($partyArray["person"])) {
				$output_nodes["responsibilities"][$partyArray["role"]][] = $partyArray["person"];
			} else {
				$output_nodes["responsibilities"][$partyArray["role"]][] = $partyArray["group"];
			}
		}
	}
	
	private function process_descriptiveKeywords($input_node, $output_nodes) {
		$keywordArray = array();
		$keywordType = "local";
		foreach ($input_node->children('gmd', TRUE)->MD_Keywords->children('gmd', TRUE) as $node) {
			switch ($node->getName()) {
			case "keyword":
				if (count($node->children('gmx', TRUE)) > 0 && $node->children('gmx', TRUE)->Anchor->attributes('xlink', TRUE)->href !== null) {
					$kwd = (string) $node->children('gmx', TRUE)->Anchor;
					$kwdId = $node->children('gmx', TRUE)->Anchor->attributes('xlink', TRUE)->href;
					$keywordArray[$kwd] = $kwdId;
				} elseif (count($node->children('gco', TRUE)) > 0) {
					$kwd = (string) $node->children('gco', TRUE)->CharacterString;
					$keywordArray[$kwd] = null;
				}
				break;
			case "thesaurusName":
				$thesaurusTitles = $node->xpath("gmd:CI_Citation/gmd:title/gco:CharacterString");
				if (count($thesaurusTitles) > 0) {
					$thesaurusTitle = (string) $thesaurusTitles[0];
					if (isset($this->thesauri[$thesaurusTitle])) {
						$keywordType = $this->thesauri[$thesaurusTitle];
					}
				}
				break;
			}
		}
		foreach ($keywordArray as $keyword => $keywordID) {
			$subject = $output_nodes["collection"]->addChild("subject", $keyword);
			$subject->addAttribute("type", $keywordType);
			if ($keywordID) {
				$subject->addAttribute("termIdentifier", $keywordID);
			}
		}
	}
	
	private function process_resourceConstraints($input_node, $output_nodes) {
		foreach ($input_node->children('gmd', TRUE)->MD_LegalConstraints->children('gmd', TRUE) as $node) {
			if ($node->getName() == "useLimitation" || $node->getName() == "otherConstraints") {
				$constraint = $node->children('gco', TRUE)->CharacterString;
				$output_nodes["rights"]->addChild("accessRights", CrosswalkHelper::escapeAmpersands($constraint));
			}
		}
	}
	
	private function process_topicCategory($input_node, $output_nodes) {
		foreach ($input_node->children('gmd', TRUE) as $node) {
			if ($node->getName() == "MD_TopicCategoryCode") {
				$topic = $output_nodes["collection"]->addChild("subject", CrosswalkHelper::escapeAmpersands($node));
				$topic->addAttribute("type", "iso19115topic");
			}
		}
	}
	
	private function process_extent($input_node, $output_nodes) {
		foreach ($input_node->children('gmd', TRUE)->EX_Extent->children('gmd', TRUE) as $node) {
			switch ($node->getName()) {
			case "temporalElement":
				$timePeriod = $node->xpath("gmd:EX_TemporalExtent/gmd:extent/gml:TimePeriod");
				if (count($timePeriod) > 0) {
					$dateFrom = null;
					$dateTo= null;
					foreach ($timePeriod[0]->children('gml', TRUE) as $boundary) {
						switch ($boundary->getName()) {
						case "beginPosition":
							if (preg_match('~^\d\d$~', $boundary, $matches)) {
								$dateFrom = $matches[0] . "00";
							} elseif (preg_match('~(\d{4}(?:-\d\d){0,2})~', $boundary, $matches)) {
								$dateFrom = $matches[1];
							}
							break;
						case "endPosition":
							if (preg_match('~^\d\d$~', $boundary, $matches)) {
								$dateTo = $matches[0] . "99";
							} elseif (preg_match('~(\d{4}(?:-\d\d){0,2})~', $boundary, $matches)) {
								$dateTo = $matches[1];
							}
							break;
						}
					}
					if ($dateFrom) {
						$this->addDate($output_nodes["coverage"], "extent", $dateFrom, $dateTo);
					}
				}
				break;
			case "geographicElement":
				foreach ($node->children('gmd', TRUE) as $subnode) {
					switch ($subnode->getName()) {
					case "EX_GeographicBoundingBox":
						$boundingBoxArray = array(
							"westBoundLongitude" => null,
							"eastBoundLongitude" => null,
							"southBoundLatitude" => null,
							"northBoundLatitude" => null
						);
						foreach($subnode->children('gmd', TRUE) as $boundary) {
							if (array_key_exists($boundary->getName(), $boundingBoxArray)) {
								$boundingBoxArray[$boundary->getName()] = $boundary->children('gco', TRUE)->Decimal;
							}
						}
						if (!in_array(null, $boundingBoxArray, TRUE)) {
							$dcmiBox =
								"northlimit={$boundingBoxArray["northBoundLatitude"]}; " .
								"eastlimit={$boundingBoxArray["eastBoundLongitude"]}; " .
								"southlimit={$boundingBoxArray["southBoundLatitude"]}; " .
								"westlimit={$boundingBoxArray["westBoundLongitude"]}";
							$spatialBox = $output_nodes["coverage"]->addChild("spatial", $dcmiBox);
							$spatialBox->addAttribute("type", "iso19139dcmiBox");
						}
						break;
					case "EX_GeographicDescription":
						foreach ($subnode->children('gmd', TRUE)->geographicIdentifier->children('gmd', TRUE) as $subsubnode) {
							switch ($subsubnode->getName()) {
							case "RS_Identifier":
								$code = null;
								$codeSpace = "text";
								foreach($subsubnode->children('gmd', TRUE) as $datum) {
									switch ($datum->getName()) {
									case "code":
										$code = $datum->children('gco', TRUE)->CharacterString;
										break;
									case "codeSpace":
										$codeSpaceString = $datum->children('gco', TRUE)->CharacterString;
										if (isset($this->codeSpaces[$codeSpaceString])) {
											$codeSpace = $this->codeSpaces[$codeSpaceString];
										}
										break;
									}
								}
								if ($code) {
									$geoKey = $output_nodes["coverage"]->addChild("spatial", $code);
									$geokey->addAttribute("type", $codeSpace);
								}
								break;
							case "MD_Identifier":
								$code = null;
								$codeSpace = "text";
								foreach($subsubnode->children('gmd', TRUE) as $datum) {
									switch ($datum->getName()) {
									case "code":
										$code = $datum->children('gco', TRUE)->CharacterString;
										break;
									case "authority":
										$codeSpaceStrings = $datum->xpath("gmd:CI_Citation/gmd:title/gco:CharacterString");
										if (count($codeSpaceStrings) > 0) {
											$codeSpaceString = (string) $codeSpaceStrings[0];
											if (isset($this->codeSpaces[$codeSpaceString])) {
												$codeSpace = $this->codeSpaces[$codeSpaceString];
											}
										}
										break;
									}
								}
								if ($code) {
									$geoKey = $output_nodes["coverage"]->addChild("spatial", $code);
									$geoKey->addAttribute("type", $codeSpace);
								}
								break;
							}
						}
						break;
					}
				}
				break;
			}
		}
	}
	
	private function process_distributionInfo($input_node, $output_nodes) {
		/* On the matter of URLs, we rely here on distributionInfo being processed
		 * after identificationInfo. Thus there is room for improvement!
		 */
		$urlArray = array();
		foreach ($input_node->children('gmd', TRUE)->MD_Distribution->children('gmd', TRUE) as $node) {
			switch ($node->getName()) {
			case "distributor":
				foreach ($node->xpath("gmd:MD_Distributor/gmd:distributorContact/gmd:CI_ResponsibleParty/gmd:organisationName/gco:CharacterString") as $subnode) {
					$output_nodes["responsibilities"]["distributor"][] = (string) $subnode;
				}
				break;
			case "transferOptions":
				$thisUrl = null;
				$thisUrlType = "none";
				$onlineResources = $node->xpath("gmd:MD_DigitalTransferOptions/gmd:onLine/gmd:CI_OnlineResource");
				if (count($onlineResources) > 0) {
					foreach ($onlineResources[0]->children('gmd', TRUE) as $subnode) {
						switch ($subnode->getName()) {
						case "linkage":
							$thisUrl = $subnode->children('gmd', TRUE)->URL;
							break;
						case "function":
							$thisUrlType = (string) $subnode->children('gmd', TRUE)->CI_OnLineFunctionCode;
							break;
						}
					}
					if ($thisUrl) {
						$urlArray[$thisUrlType] = $thisUrl;
					}
				}
				break;
			}
		}
		$url = null;
		$urlTypeArray = array("download", "order", "offlineAccess", "information", "search", "none");
		foreach ($urlTypeArray as $urlType) {
			if (isset($urlArray[$urlType])) {
				$url = $urlArray[$urlType];
				break;
			}
		}
		if ($url) {
 			if (!$output_nodes["collection"]->xpath("location/address/electronic/value")) {
				$this->addLocationUrl($output_nodes["collection"], $url);
			}
			if (!$output_nodes["citation_metadata"]->url) {
				$output_nodes["citation_metadata"]->addChild("url", $url);
			}
		}
	}
	
	private function process_dataQualityInfo($input_node, $output_nodes) {
		foreach ($input_node->children('gmd', TRUE)->DQ_DataQuality->children('gmd', TRUE) as $node) {
			if ($node->getName() == "lineage") {
				$lineages = $node->xpath("gmd:LI_Lineage/gmd:statement/gco:CharacterString");
				if (count($lineages) > 0) {
					$lineage = $output_nodes["collection"]->addChild("description", $lineages[0]);
					$lineage->addAttribute("type", "lineage");
				}
			}
		}
	}

}
?>