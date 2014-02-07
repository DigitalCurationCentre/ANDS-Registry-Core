<?php

abstract class Crosswalk
{
    // Force Extending class to define this method
    abstract public function identify(); //A brief description of this crosswalk e.g. "Format X to RIF-CS"
    abstract public function payloadToRIFCS($payload); //Takes a string in [input format], returns a RIF-CS XML string
    abstract public function validate($payload); //Takes a string in [input format], returns true if string appears to be valid example of that format, otherwise returns false
    abstract public function metadataFormat(); //A short string identifying this crosswalk (no spaces) e.g. "format_x"
}