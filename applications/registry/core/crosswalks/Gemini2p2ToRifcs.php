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
		if (strpos($code, "info:") == 0) {
			$idType = "infouri";
		} elseif (strpos($code, "http://purl.org/") == 0) {
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
		} elseif (preg_match('~(?:http://hdl.handle.net/)(1[^/]+/.*)~')) {
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
			$originatingSource = $reg_obj->addChild("originatingSource", $sourceUrl[0]);
		} else {
			$sourceEmail = $input_node->xpath('gmd:CI_ResponsibleParty/gmd:contactInfo/gmd:CI_Contact/gmd:address/gmd:CI_Address/gmd:electronicMailAddress/gco:CharacterString');
			if (count($sourceEmail > 0)) {
				if (preg_match('/@(.+)/', $sourceEmail[0], $host)) {
					$originatingSource = $reg_obj->addChild("originatingSource", "http://www." . $host[1] . "/");
				}
			}
		}
		$output_nodes["registry_object"]->addChild("originatingSource", $originatingSource);
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
		foreach($input_node->children('gmd', TRUE)->CI_ResponsibleParty->children('gmd', TRUE) as $node) {
			switch ($node->getName()) {
			case "individualName":
				
				break;
			case "organisationName":
				
				break;
			case "contactInfo":
				
				break;
			case "role":
				
				break;
			}
		}
	}
	
	private function process_resourceMaintenance($input_node, $output_nodes) {
	}
	
	private function process_descriptiveKeywords($input_node, $output_nodes) {
		$keywordArray = array();
		$keywordType = "local";
		foreach ($input_node->children('gmd', TRUE)->MD_Keywords->children('gmd', TRUE) as $node) {
			switch ($node->getName()) {
			case "keyword":
				if (count($node->children('gmx', TRUE)) > 0 && $node->children('gmx', TRUE)->Anchor->attributes('xlink', TRUE)->href !== null) {
					$kwd = $node->children('gmx', TRUE)->Anchor;
					$kwdId = $node->children('gmx', TRUE)->Anchor->attributes('xlink', TRUE)->href;
					$keywordArray[$kwd] = $kwdId;
				} elseif (count($node->children('gco', TRUE)) > 0) {
					$kwd = $node->children('gco', TRUE)->CharacterString;
					$keywordArray[$kwd] = null;
				}
				break;
			case "thesaurusName":
				$thesaurusTitles = $node->xpath("gmd:CI_Citation/gmd:title/gco:CharacterString");
				if (count($thesaurusTitles) > 0 && array_key_exists($thesaurusTitles[0], $this->thesauri)) {
					$keywordType = $this->thesauri[$thesaurusTitles[0]];
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
	
	private function process_spatialResolution($input_node, $output_nodes) {
	}
	
	private function process_language($input_node, $output_nodes) {
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
					foreach ($timePeriod->children('gml', TRUE) as $boundary) {
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
							if (array_key_exists($boundary->getName())) {
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
										if (array_key_exists($codeSpaceString, $this->codeSpaces)) {
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
										$codeSpaceString = $datum->xpath("gmd:CI_Citation/gmd:title/gco:CharacterString");
										if (count($codeSpaceString) > 0 && array_key_exists($codeSpaceString[0], $this->codeSpaces)) {
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
							}
						}
						break;
					}
				}
				break;
			}
		}
	}
	
	private function process_supplementalInformation($input_node, $output_nodes) {
	}
	
	private function process_distributionInfo($input_node, $output_nodes) {
		/* On the matter of URLs, we rely here on distributionInfo being processed
		 * after identificationInfo. Thus there is room for improvement!
		 */
		$urlArray = array();
		foreach ($input_node->children('gmd', TRUE)->MD_Distribution->children('gmd', TRUE) as $node) {
			switch ($node->getName()) {
			case "distributionFormat":
				break;
			case "distributor":
				break;
			case "transferOptions":
				$thisUrl = null;
				$thisUrlType = "none";
				foreach($node->xpath("gmd:MD_DigitalTransferOptions/gmd:onLine/gmd:CI_OnlineResource") as $subnode) {
					switch ($subnode->getName()) {
					case "linkage":
						$thisUrl = $subnode->children('gmd', TRUE)->URL;
						break;
					case "function":
						$thisUrlType = $subnode->children('gmd', TRUE)->CI_OnLineFunctionCode;
						break;
					}
				}
				if ($thisUrl) {
					$urlArray[$thisUrlType] = $thisUrl;
				}
				break;
			}
		}
		$url = null;
		$urlTypeArray = array("download", "order", "offlineAccess", "information", "search", "none");
		foreach ($urlTypeArray as $urlType) {
			if (array_key_exists($urlType, $urlArray)) {
				$url = $urlArray[$urlType];
				break;
			}
		}
		if ($url) {
			if (!$output_nodes["collection"]->location->address->electronic->value) {
				$this->addLocationUrl($output_nodes["collection"], $url);
			}
			if ($output_nodes["citation_metadata"]->url === null) {
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