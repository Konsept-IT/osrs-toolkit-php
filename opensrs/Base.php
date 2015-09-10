<?php

namespace OpenSRS;
use OpenSRS\Exception;

defined('OPENSRSURI') or die;


/**
 * OpenSRS Base class
 *
 * @package     OpenSRS
 * @subpackage  Base
 * @since       3.4
 */

class Base 
{
	private $_socket = false;
	private $_socketErrorNum = false;
	private $_socketErrorMsg = false;
	private $_socketTimeout = 120;				// seconds
	private $_socketReadTimeout = 120;			// seconds

	protected $_opsHandler;

    protected $defaultTlds = array('.com', '.net', '.org');
    protected $dataObject;
    protected $dataFormat;

	/**
	 * openSRS_base object constructor
	 *
	 * Closes an existing socket connection, if we have one
	 * 
	 * @since   3.1
	 */
	public function __construct () {
		$this->_verifySystemProperties ();
		$this->_opsHandler = new Ops;
	}

	/**
	 * openSRS_base object destructor
	 *
	 * Closes an existing socket connection, if we have one
	 *
	 * @since   3.4
	 */
	public function __destruct () {
		if (is_resource($this->_socket))
		{
			fclose($this->_socket);
		}
	}

	/**
	 * Method to check the PHP version and OpenSSL PHP lib installation
	 *
	 * @since   3.1
	 */	
	private function _verifySystemProperties () {
		if (!function_exists('version_compare') || version_compare('4.3', phpversion(), '>=')) {
			$error_message = "PHP version must be v4.3+ (current version is ". phpversion() .") to use \"SSL\" encryption";
			throw new Exception ($error_message);
			die();
		} elseif (!function_exists('openssl_open')) {
			$error_message = "PHP must be compiled using --with-openssl to use \"SSL\" encryption";
			throw new Exception ($error_message);
			die();
		}
	}

	/**
	 * Method to send a command to the server
	 *
	 * @param 	string 	$request Raw XML request
	 *
	 * @return 	string 	$data 	Raw XML response
	 *  
	 * @since   3.1
	 */	
	public function send_cmd($request) {
		// make or get the socket filehandle
		if (!$this->init_socket() ) {
			throw new Exception ("oSRS Error - Unable to establish socket: (". $this->_socketErrorNum .") ". $this->_socketErrorMsg);
			die();
		}

		$this->send_data($request);
		$data = $this->read_data();

		$num_matches = preg_match('/<item key="response_code">401<\/item>/', $data, $matches);

		if ($num_matches > 0)
			throw new Exception("oSRS Error - Reseller username or OSRS_KEY is incorrect, please check your config file.");

		return $data;
	}

	/**
	 * Method to initialize a socket connection to the OpenSRS server
	 *
	 * @return 	boolean  True if connected
	 *  
	 * @since   3.1
	 */	
	private function init_socket() {
		if ($this->is_connected()) return true;
		$this->_socket = fsockopen(CRYPT_TYPE . '://' . OSRS_HOST, OSRS_SSL_PORT, $this->_socketErrorNum, $this->_socketErrorMsg, $this->_socketTimeout);
		if (!$this->_socket) {
			return false;
		} else {
			return true;
		}
	}
	
	/**
	 * Method to check if a socket connection exists
	 *
	 * @return 	boolean  True if connected
	 *  
	 * @since   3.4
	 */	
	public function is_connected() {
		return (is_resource($this->_socket)) ? true : false;
	}

	/**
	 * Method to close the socket connection
	 *  
	 * @since   3.4
	 */	
	private function close_socket() {
		if (is_resource($this->_socket))
		{
			fclose($this->_socket);
		}
	}


	/**
	 * Method to read data from the buffer stream
	 *  
	 * @return 	string 	XML response
	 * @since   3.1
	 */	
	private function read_data() {
		$buf = $this->readData($this->_socket, $this->_socketReadTimeout);
		if (!$buf) {
			throw new Exception ("oSRS Error - Read buffer is empty.  Please make sure IP is whitelisted in RWI. Check the OSRS_KEY and OSRS_USERNAME in the config file as well.");
			$data = "";
		} else {
			$data = $buf;
		}
		if (!empty($this->osrs_debug)) print_r("<pre>" . htmlentities($data) . "</pre>");
		
		return $data;
	}

	/**
	 * Method to send data
	 *  
	 * @param 	string 	$message 	XML request
	 * @return 	string  $message	XML response
	 * @since   3.1
	 */	
	private function send_data($message) {
		if (!empty($this->osrs_debug)) print_r("<pre>" . htmlentities($message) . "</pre>");		
		return $this->writeData( $this->_socket, $message );
	}

	/**
	* Writes a message to a socket (buffered IO)
	*
	* @param	int 	&$fh 	socket handle
	* @param	string 	$msg    message to write
	*/
	private function writeData(&$fh,$msg) {
		$header = "";
		$len = strlen($msg);

		$signature = md5(md5($msg.OSRS_KEY).OSRS_KEY);
		$header .= "POST / HTTP/1.0". CRLF;
		$header .= "Content-Type: text/xml" . CRLF;
		$header .= "X-Username: " . OSRS_USERNAME . CRLF;
		$header .= "X-Signature: " . $signature . CRLF;
		$header .= "Content-Length: " . $len . CRLF . CRLF;
		
		fputs($fh, $header);
		fputs($fh, $msg, $len );
	}


	/**
	* Reads header data
	* @param	int 	socket handle
	* @param	int 	timeout for read
	* @return	hash	hash containing header key/value pairs
	*/
	private function readHeader($fh, $timeout=5) {
		$header = array();
		/* HTTP/SSL connection method */
		$http_log ='';
		$line = fgets($fh, 4000);
		$http_log .= $line;
		if (!preg_match('/^HTTP\/1.1 ([0-9]{0,3}) (.*)\r\n$/',$line, $matches)) {
			throw new Exception ("oSRS Error - UNEXPECTED READ: Unable to parse HTTP response code. Please make sure IP is whitelisted in RWI.");
			return false;
		}
		$header['http_response_code'] = $matches[1];
		$header['http_response_text'] = $matches[2];

		while ($line != CRLF) {
			$line = fgets($fh, 4000);
			$http_log .= $line;
			if (feof($fh)) {
				throw new Exception ("oSRS Error - UNEXPECTED READ: Error reading HTTP header.");
				return false;
			}
			$matches = explode(': ', $line, 2);
			if (sizeof($matches) == 2) {
				$header[trim(strtolower($matches[0]))] = $matches[1];
			}
		}
		$header['full_header'] = $http_log;

		return $header;
	}

	/**
	* Reads data from a socket
	* @param	int 	socket handle
	* @param	int 	timeout for read
	* @return	mixed	buffer with data, or an error for a short read
	*/
	private function readData(&$fh, $timeout=5) {
		$len = 0;
		/* PHP doesn't have timeout for fread ... we just set the timeout for the socket */
		socket_set_timeout($fh, $timeout);
		$header = $this->readHeader($fh, $timeout);
		if (!$header || !isset($header{'content-length'}) || (empty($header{'content-length'}))) {
			throw new Exception ("oSRS Error - UNEXPECTED ERROR: No Content-Length header provided! Please make sure IP is whitelisted in RWI.");
		}

		$len = (int)$header{'content-length'};
		$line = '';
		while (strlen($line) < $len) {
			$line .= fread($fh, $len);
			if ($this->_opsHandler->socketStatus($fh)) {
				return false;
			}
		}

		if ($line) {
			$buf = $line;
		} else {
			$buf = false;
		}

		$this->close_socket();
		return $buf;
	}

    public function convertArray2Formatted($type = '', $data = '')
    {
        $resultString = '';
        if ($type == 'json') {
            $resultString = json_encode($data);
        }
        if ($type == 'yaml') {
            $resultString = Spyc::YAMLDump($data);
        }

        return $resultString;
    }
    
    /**
     * Get configured tlds for domain call 
     * Will use (in order of preference)... 
     * 1. selected tlds 
     * 2. supplied default tlds 
     * 3. included default tlds
     * 
     * @return array tlds 
     */
    public function getConfiguredTlds()
    {
        $selected = array();
        $suppliedDefaults = array();

        // Select non empty one
        if (isset($this->dataObject->data->selected) && $this->dataObject->data->selected != '') {
            $selected = explode(';', $this->dataObject->data->selected);
        }
        if (isset($this->dataObject->data->defaulttld) && $this->dataObject->data->defaulttld != '') {
            $suppliedDefaults = explode(';', $this->dataObject->data->defaulttld);
        }

        // use selected
        if (count($selected) > 0) {
            return $selected; 
        }

        // use supplied defaults
        if (count($suppliedDefaults) > 0) {
            return $suppliedDefaults; 
        }

        // use included defaults
        return $this->defaultTlds;
    }

    public function setDataObject($format, $dataObject)
    {
        $this->dataObject = $dataObject;
        $this->dataFormat = $format;
    }

    /**
     * Does the dataObject have a domain set?
     * 
     * @return bool 
     */
    public function hasDomain()
    {
        return isset($this->dataObject->data->domain);
    }

    /**
     * Get the domain from the dataObject
     * 
     * @return void
     */
    public function getDomain()
    {
        return $this->dataObject->data->domain; 
    }
}
