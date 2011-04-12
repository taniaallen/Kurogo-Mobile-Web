<?php
/**
  * @package Directory
  */

if (!function_exists('ldap_connect')) {
    die('LDAP Functions not available');
}

// common ldap error codes
define("LDAP_TIMELIMIT_EXCEEDED", 0x03);
define("LDAP_SIZELIMIT_EXCEEDED", 0x04);
define("LDAP_PARTIAL_RESULTS", 0x09);
define("LDAP_INSUFFICIENT_ACCESS", 0x32);

/**
  * @package Directory
  */
class LDAPPeopleController extends PeopleController {
    protected $personClass = 'LDAPPerson';
    protected $host;
    protected $port=389;
    protected $ldapResource;
    protected $searchBase;
    protected $adminDN;
    protected $adminPassword;
    protected $filter;
    protected $errorNo;
    protected $errorMsg;
    protected $searchTimelimit=30;
    protected $readTimelimit=30;
    protected $attributes=array();
    protected $fieldMap=array();
  
    public function debugInfo() {
        return   sprintf("Using LDAP Server: %s", $this->host);
    }

    protected function connectToServer() {
        if (!$this->ldapResource) {
            if ($this->ldapResource = ldap_connect($this->host, $this->port)) {
                ldap_set_option($this->ldapResource, LDAP_OPT_PROTOCOL_VERSION, 3);
                ldap_set_option($this->ldapResource, LDAP_OPT_REFERRALS, 0);
            } else {
                error_log("Error connecting to LDAP Server $this->host using port $this->port");
            }
        }

        return $this->ldapResource;
    }


    public function setAttributes($attribs) {
        if (is_array($attribs)) {
            $this->attributes =$attribs;
        } elseif ($attribs) {
            throw new Exception('Invalid attributes');
        } else {
            $this->attributes = array();
        }
    }

    public function search($searchString) {
        $this->buildQuery($searchString);
        return $this->doQuery();
    }

    protected function getField($_field) {
        if (array_key_exists($_field, $this->fieldMap)) {
            return $this->fieldMap[$_field];
        }

        return $_field;
    }

    protected function nameFilter($firstName, $lastName) {
        $givenNameFilter = new LDAPFilter($this->getField('givenname'), $firstName, LDAPFilter::FILTER_OPTION_WILDCARD_TRAILING); 
        $snFilter = new LDAPFilter($this->getField('sn'), $lastName, LDAPFilter::FILTER_OPTION_WILDCARD_TRAILING); 
        $filter = new LDAPCompoundFilter(LDAPCompoundFilter::JOIN_TYPE_AND, $givenNameFilter, $snFilter);
        return $filter;
    }

    protected function buildQuery($searchString) {

        $this->filter = $filter = false;
        $this->errorNo = $this->errorMsg = null;

        $objectClassQuery = new LDAPFilter('objectClass', 'person');

        if (empty($searchString)) {
            $this->errorMsg = "Query was blank";
        } elseif (Validator::isValidEmail($searchString)) {
            $filter = new LDAPFilter($this->getField('mail'), $searchString);
        } elseif (Validator::isValidPhone($searchString, $phone_bits)) {
            array_shift($phone_bits);
            $searchString = implode("", $phone_bits); // remove any separators. This might be an issue for people with formatted numbers in their directory
            $filter = new LDAPFilter($this->getField('phone'), $searchString);
        } elseif (preg_match('/[A-Za-z]+/', $searchString)) { // assume search by name

            $names = preg_split("/\s+/", $searchString);
            $nameCount = count($names);
            switch ($nameCount)
            {
                case 1:
                    //try first name, last name and email
                    $snFilter = new LDAPFilter($this->getField('sn'), $searchString, LDAPFilter::FILTER_OPTION_WILDCARD_TRAILING); 
                    $givenNameFilter = new LDAPFilter($this->getField('givenname'), $searchString, LDAPFilter::FILTER_OPTION_WILDCARD_TRAILING); 
                    $mailFilter = new LDAPFilter($this->getField('mail'), $searchString, LDAPFilter::FILTER_OPTION_WILDCARD_TRAILING); 

                    $filter = new LDAPCompoundFilter(LDAPCompoundFilter::JOIN_TYPE_OR, $givenNameFilter, $snFilter, $mailFilter);
                    break;
                case 2:
                    $filter1 = $this->nameFilter($names[0], $names[1]);
                    $filter2 = $this->nameFilter($names[1], $names[0]);
                    $filter = new LDAPCompoundFilter(LDAPCompoundFilter::JOIN_TYPE_OR, $filter1, $filter2);
                    break;                
                default:

                    $filters = array();
                    // Either the first word is the first name, or it's a title and the 
                    // second word is the first name.
                    $possibleFirstNames = array($names[0], $names[1]);
                    
                    // Either the last word is the last name, or the last two words taken
                    // together are the last name.
                    $possibleLastNames = array($names[$nameCount - 1],
                                               $names[$nameCount - 2] . " " . $names[$nameCount - 1]);
                    
                    foreach ($possibleFirstNames as $i => $firstName) {
                        foreach ($possibleLastNames as $j => $lastName) {
                            $filters[] = $this->nameFilter($firstName, $lastName);
                        }
                    }

                    // Kitchen sink -- just string them all together with wildcards
                    // and hope that it's a match on the common name.
                    $filters[] = new LDAPFilter('cn', implode("*", array_map(array('LDAPFilter', 'ldapEscape'), $names)), LDAPFilter::FILTER_OPTION_NO_ESCAPE);
                    
                    $filter = new LDAPCompoundFilter(LDAPCompoundFilter::JOIN_TYPE_OR, $filters);
            }

        } else {
            $this->errorMsg = "Invalid query";
        }

        if ($filter) {
            $this->filter = new LDAPCompoundFilter(LDAPCompoundFilter::JOIN_TYPE_AND, $objectClassQuery, $filter);
        }

        return $this->filter;
    }

    public function getErrorNo() {
        return $this->errorNo;
    }

    public function getError() {
        return $this->errorMsg;
    }

    /* return results, or FALSE on error.
    */
    protected function doQuery() {

        if (!$this->filter) {
            return FALSE;
        }

        $ds = $this->connectToServer();
        if (!$ds) {
            $this->errorMsg = "Could not connect to LDAP server";
            return FALSE;
        }

        if ($this->adminDN) {
            if (!ldap_bind($ds, $this->adminDN, $this->adminPassword)) {
                error_log("Error binding to LDAP Server $this->host for $this->adminDN: " . ldap_error($ds));
                return false;
            }
        }

        // suppress warnings on non-dev servers
        // about searches that go over the result limit
        if (!Kurogo::getSiteVar('DATA_DEBUG')) {
            $error_reporting = ini_get('error_reporting');
            error_reporting($error_reporting & ~E_WARNING);
        }
        
        $filter = strval($this->filter);
        $sr = ldap_search($ds, $this->searchBase,
            $filter, $this->attributes, 0, 0, 
            $this->searchTimelimit);
        
        if (!$sr) {
            if($ds) {
                $this->errorMsg = self::generateErrorMessage($ds);
            }
            return FALSE;
        }
        
        if ($this->errorNo = ldap_errno($ds)) {
            $this->errorMsg = $this->generateErrorMessage($ds);
        }
        
        if (!Kurogo::getSiteVar('DATA_DEBUG')) {
            error_reporting($error_reporting);
        }
        
        $entries = ldap_get_entries($ds, $sr);
        
        if (!$entries) {
            $this->errorMsg = "Could not get result entries";
            return FALSE;
        }
        
        $results = array();
        for ($i = 0; $i < $entries["count"]; $i++) {
            if ($person = new $this->personClass($entries[$i])) {
                $results[] = $person;
            }
        }
    
        return $results;
    
    } 

    /* returns a person object on success
    * FALSE on failure
    */
    public function lookupUser($id) {
        if (strstr($id, '=')) { 
            // assume we're looking up person by "dn" (distinct ldap name)

            $ds = $this->connectToServer();
            if (!$ds) {
                $this->errorMsg = "Could not connect to LDAP server";
                return FALSE;
            }

            if ($this->adminDN) {
                if (!ldap_bind($ds, $this->adminDN, $this->adminPassword)) {
                    error_log("Error binding to LDAP Server $this->host for $this->adminDN: " . ldap_error($ds));
                    return false;
                }
            }

            // get all attributes of the person identified by $id
            $sr = ldap_read($ds, $id, "(objectclass=*)", $this->attributes, 0, 0, $this->readTimelimit);

            if (!$sr) {
                $this->errorMsg = ldap_error($ds);
                return FALSE;
            }

            $entries = ldap_get_entries($ds, $sr);
            if (!$entries) {
                $this->errorMsg = "Could not get result entries";
                return FALSE;
            }
    
            return new $this->personClass($entries[0]);

        } else {

            $this->filter = new LDAPFilter($this->getField('uid'), $id);
            if (!$result = $this->doQuery()) {
                return FALSE;
            } else {
                return $result[0];
            }
        }

    }

    protected function generateErrorMessage($ldap_resource) {
        $error_code = ldap_errno($ldap_resource);
        $error_name = ldap_error($ldap_resource);
        $error_codes = array(
            // LDAP error codes.
            LDAP_SIZELIMIT_EXCEEDED => "There are more results than can be displayed. Please refine your search.",
            LDAP_PARTIAL_RESULTS => "There are more results than can be displayed. Please refine your search.",
            /*       	LDAP_INSUFFICIENT_ACCESS => "Too many results to display (more than 50). Please refine your search.", */
            LDAP_TIMELIMIT_EXCEEDED => "The directory service is not responding. Please try again later.",
        );

        if(isset($error_codes[$error_code])) {
            return $error_codes[$error_code];
        } else { // return a generic error message
            return "Your request cannot be processed at this time.";
        }
    }

    protected function init($args) {
        parent::init($args);
        
        if (isset($args['HOST'])) {
            $this->setHost($args['HOST']);
        }
        
        $this->port = isset($args['PORT']) ? $args['PORT'] : 389;
        $this->searchBase = isset($args['SEARCH_BASE']) ? $args['SEARCH_BASE'] : '';
        $this->adminDN = isset($args['ADMIN_DN']) ? $args['ADMIN_DN'] : null;
        $this->adminPassword = isset($args['ADMIN_PASSWORD']) ? $args['ADMIN_PASSWORD'] : null;
        $this->searchTimelimit = isset($args['SEARCH_TIMELIMIT']) ? $args['SEARCH_TIMELIMIT'] : 30;
        $this->readTimelimit = isset($args['READ_TIMELIMIT']) ? $args['READ_TIMELIMIT'] : 30;
    }
}

/**
* @package Directory
*/
class LDAPFilter
{
    const FILTER_OPTION_WILDCARD_TRAILING=1;
    const FILTER_OPTION_WILDCARD_LEADING=2;
    const FILTER_OPTION_NO_ESCAPE=4;
    const FILTER_OPTION_WILDCARD_SURROUND=8;
    protected $field;
    protected $value;
    protected $options;

    public function __construct($field, $value, $options=0)
    {
        $this->field = $field;
        $this->value = $value;
        $this->options = intval($options);
    }
    
    public static function ldapEscape($str) 
    { 
        // see RFC2254 
        // http://msdn.microsoft.com/en-us/library/ms675768(VS.85).aspx 
        // http://www-03.ibm.com/systems/i/software/ldap/underdn.html        
        
        $metaChars = array('*', '(', ')', '\\', chr(0));
        $quotedMetaChars = array(); 
        foreach ($metaChars as $key => $value) {
            $quotedMetaChars[$key] = '\\'.str_pad(dechex(ord($value)), 2, '0'); 
        }
        $str = str_replace($metaChars, $quotedMetaChars, $str); 
        return ($str); 
    }
    
    public function __toString()
    {
        if ($this->options & self::FILTER_OPTION_NO_ESCAPE) {
            $value = $this->value;
        } else {
            $value = self::ldapEscape($this->value);
        }
    
        if ($this->options & self::FILTER_OPTION_WILDCARD_LEADING) {
            $value  = '*' . $value;
        }
    
        if ($this->options & self::FILTER_OPTION_WILDCARD_TRAILING) {
            $value  .= '*';
        }
    
        if ($this->options & self::FILTER_OPTION_WILDCARD_SURROUND) {
            $value  = '*' . $value . "*";
        }
    
        return sprintf("(%s=%s)", $this->field, $value);
    }
}
    
/**
* @package Directory
*/
class LDAPCompoundFilter extends LDAPFilter
{
    const JOIN_TYPE_AND='&';
    const JOIN_TYPE_OR='|';
    protected $joinType;
    protected $filters=array();
    
    public function __construct($joinType, $filter1, $filter2=null) {

        switch ($joinType)
        {
            case self::JOIN_TYPE_AND:
            case self::JOIN_TYPE_OR:
                $this->joinType = $joinType;
                break;
            default:
                throw new Exception("Invalid join type $joinType");                
        }
    
        for ($i=1; $i < func_num_args(); $i++) {
            $filter = func_get_arg($i);
            if ($filter instanceOF LDAPFilter) { 
                $this->filters[] = $filter;
            } elseif (is_array($filter)) {
                foreach ($filter as $_filter) {
                    if (!($_filter instanceOf LDAPFilter)) {
                        throw new Exception("Invalid filter for in array");
                    }
                }
                $this->filters = $filter;
            } else {
                throw new Exception("Invalid filter for argument $i");
            }
        }
    
        if (count($this->filters)<2) {
            throw new Exception(sprintf("Only %d filters found (2 minimum)", count($filters)));
        }
    
    }
    
    function __toString() {

        $stringValue = sprintf("(%s%s)", $this->joinType, implode("", $this->filters));
        return $stringValue;
    }
}
    
class LDAPPerson extends Person {
    
    protected $dn;
    
    public function getDn() {
        return $this->dn;
    }
    
    public function getId() {
        $uid = $this->getFieldSingle('uid');
        return $uid ? $uid : $this->getDn();
    }
    
    protected function getFieldSingle($field) {
        $values = $this->getField($field);
        if ($values) {
            return $values[0];
        }
        return NULL;
    }
    
    public function __construct($ldapEntry) {
        $this->dn = $ldapEntry['dn'];
        $this->attributes = array();
    
        for ($i=0; $i<$ldapEntry['count']; $i++) {
            $attribute = $ldapEntry[$i];
            $count = $ldapEntry[$attribute]['count'];
            $this->attributes[$attribute] = array();
            for ($j=0; $j<$count; $j++) {
                if (!in_array($ldapEntry[$attribute][$j], $this->attributes[$attribute])) {
                    $this->attributes[$attribute][] = $ldapEntry[$attribute][$j];
                }
            }
        }
    }
}
