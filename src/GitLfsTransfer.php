<?php

namespace wycomco\GitLfsPhp;

class GitLfsTransfer {
    
    /** @var GitLfsToken Token associated with current request */
    private $token = null;
    
    /** @var string Name of target repository */
    private $repo = '';
    
    /** @var string Path to data store */
    private $directory = '';
    
    /** @var GitLfsDataStore Handler for DataStore */
    private $dataStore = null;
    
    /** @var string Requested operation: upload or download */
    private $operation = '';
    
    /** @var string OID of requested object */
    private $oid = null;
    
    /** @var int Byte size of requested object */
    private $size = null;
    
    /** @var array List with valid batch api operations */
    private $validOperations = array(
        'upload',
        'download',
        'verify',
    );
    
    public function __construct($directory = null) {
        
        if(!is_null($directory)) {
            $this->directory = $directory;
        }elseif(defined('GIT_LFS_DATA_DIR')) {
            $this->directory = GIT_LFS_DATA_DIR;
        }else {
            throw new \Exception('No data directory given');
        }

        if(substr($this->directory, -1) != DIRECTORY_SEPARATOR) {
            $this->directory .= DIRECTORY_SEPARATOR;
        }

        if(!file_exists($this->directory)) {
            if(!mkdir($this->directory, 0777, true)) {
                throw new \Exception('Could not create data store directory.');
            }

            if(chmod($this->directory, 0777) === false) {
                throw new \Exception('Could not set permission on data store directory.');
            }
        }

        if(!is_writable($this->directory)) {
            throw new \Exception('Data store directory is not writable');
        }
        
        $this->dataStore = new GitLfsDataStore($this->directory);

        $this->parse_request();
        $this->authenticate();
        $this->authorize();
        $this->process_transfer();
        
        return $this;
    }
    
    /**
    * Return the API response
    *
    * @return void
    */
    private function return_json_response() {
        
        $response = array(
            'transfer' => 'basic',
            'objects' => $this->objects,
        );
        
        header('HTTP/1.1 200 Ok');
        
        $this->return_as_json($response);
    }
    
    /**
    * Authenticates user with HTTP basic auth
    *
    * @return bool True on success
    */
    private function authenticate() {
        
        if(!isset($_SERVER['PHP_AUTH_USER']) || empty($_SERVER['PHP_AUTH_USER'])) {
            $this->return_response_error(401, 'Username not given or empty');
        }
        
        if(!isset($_SERVER['PHP_AUTH_PW']) || empty($_SERVER['PHP_AUTH_PW'])) {
            $this->return_response_error(401, 'Password not given or empty');
        }
        
        $token = GitLfsAuthToken::load($_SERVER['PHP_AUTH_USER']);
        
        if(!$token->check_password($_SERVER['PHP_AUTH_PW'])) {
            $this->return_response_error(401, 'Passwords do not match');
        }
        
        $this->token = $token;
        
        return true; 
    }
    
    /**
    * Authorizes the current request
    *
    * @return bool True on success
    */
    private function authorize() {
        
        if(!isset($this->repo) || empty($this->repo)) {
            throw new \Exception('Error authorizing request. Repo not given.');
        }
        
        if(!isset($this->operation) || empty($this->operation)) {
            throw new \Exception('Error authorizing request. Operation not given.');
        }
        
        if(!isset($this->token) || empty($this->token)) {
            throw new \Exception('Error authorizing request. Token not given.');
        }
        
        switch($this->operation) {
            case 'verify':
                $operation = 'upload';
                break;
            default:
                $operation = $this->operation;
                break;
        }
        
        if(!$this->token->has_privilege($this->repo, $operation)) {
            if($operation == 'upload') {
                $this->return_response_error(403);
            }else{
                $this->return_response_error(404);
            }
        }
        
        return true;
    }
    
    /**
    * Get name of Git repository, which is targeted by this request
    *
    * @return string Name of Git repository
    */
    private function parse_request() {
        
        $requestUri = $_SERVER['REQUEST_URI'];
        
        // Omit query string
        $requestUri = explode('?', $requestUri);
        $requestUri = $requestUri[0];
        
        foreach($this->validOperations AS $operation) {
            $substr = substr($requestUri, -strlen($operation));
            if($substr == $operation) {
                $this->operation = $operation;
            }
        }
        
        if(empty($this->operation)) {
            throw new \Exception('No valid operation provided');
        }
        
        $requestUri = substr($requestUri, 0, -strlen($this->operation));
        
        $apiEndPointString = '/info/lfs/objects/';
        
        // If the request does not end with the API endpoint string, something strange has happened
        if(substr($requestUri, -(strlen($apiEndPointString))) != $apiEndPointString) {
            throw new \Exception('API endpoint was not addressed correctly');
        }
        
        $repo = trim(substr($requestUri, 0, -strlen($apiEndPointString)), '/');
        
        $repo = GitLfsAuthenticator::prepare_repo_name($repo);
        
        if(defined('GIT_LFS_REPOS')){
            if(!in_array($repo, unserialize(GIT_LFS_REPOS))) {
                $this->return_response_error(404, 'Repository is not listed in configured repositories');
            }
        }
        
        $this->dataStore->set_repository($repo);

        $this->repo = $repo;
        
        switch($this->operation) {
            case 'upload':
                $requestMethod = 'PUT';
                break;
            case 'verify':
                $requestMethod = 'POST';
                break;
            case 'download':
            default:
                $requestMethod = 'GET';
                break;
        }
        
        if($_SERVER['REQUEST_METHOD'] != $requestMethod) {
            error_log('Git LFS file '.$this->operation.' requires HTTP '.$requestMethod.' request. Instead received a request of type '.$_SERVER['REQUEST_METHOD']);
            return false;
        }
        
        if($this->operation == 'verify') {
            $requestBody = file_get_contents('php://input');
            
            if($requestBody == '') {
                header('HTTP/1.1 422 Unprocessable Entity');
                exit;
            }
            
            // Decode json and return contents as associative array
            $contents = json_decode($requestBody, true);
    
            if(is_null($contents)) {
                header('HTTP/1.1 422 Unprocessable Entity');
                exit;
            }
    
            if(!isset($contents['oid']) || !is_string($contents['oid']) || $contents['oid'] == '' || !isset($contents['size']) || !is_numeric($contents['size'])) {
                header('HTTP/1.1 422 Unprocessable Entity');
                exit;
            }
            
            $this->oid = $contents['oid'];
            
            $this->size = $contents['size'];
            
        }else {
            if(isset($_GET['oid']) && $_GET['oid'] != '') {
                $this->oid = $_GET['oid'];
            }else {
                throw new \Exception('Did not get OID');
            }
            
            if(isset($_GET['size']) && $_GET['size'] != '') {
                $this->size = $_GET['size'];
            }
        }
    }
    
    /**
    * Processes the transfer request
    *
    * @return $this
    */
    private function process_transfer() {
        
        if(in_array($this->operation, $this->validOperations)) {
            if(!is_callable(array($this, 'process_transfer_'.$this->operation))) {
                throw new \Exception('Tried to call an object processor which is not available.');
            }
            
            return call_user_func(array($this, 'process_transfer_'.$this->operation));
        }else {
            throw new \Exception('Tried to call an unknown object processor.');
        }
    }
    
    /**
    * Processes the upload request
    *
    * @return $this
    */
    private function process_transfer_upload() {
        
        $dataStore = $this->dataStore;
        
        $dataStore->set_repository($this->repo);
        
        // HTTP PUT content comes from php://input
        $putdata = fopen("php://input","rb");

        // Open file to write
        $fp = $dataStore->fopen($this->oid,'wb');
        
        // Read input and write to file, 1k at a time
        while ($data = fread($putdata,1024)) {
            set_time_limit(300);
            fwrite($fp,$data);
        }
        
        // Close the streams
        fclose($fp);
        fclose($putdata);
        
        return $this;
    }
    
    /**
    * Processes the listed objects for upload and modifies the class property correspondingly
    *
    * @return $this
    */
    private function process_transfer_verify() {
        
        $dataStore = $this->dataStore;
        
        $dataStore->set_repository($this->repo);
        
        $oid = $this->oid;
        $size = $this->size;
        
        if($dataStore->file_exists($oid, $size)) {
            header('HTTP/1.1 200 OK');
            echo 'File exists';
            exit;
        }else {
            header('HTTP/1.1 404 Not Found');
            echo 'File does not exist';
            exit;
        }
    }
    
    /**
    * Processes the listed objects for download and modifies the class property correspondingly
    *
    * @return void
    */
    private function process_transfer_download() {
        
        $dataStore = $this->dataStore;
        
        $dataStore->set_repository($this->repo);
        
        $oid = $this->oid;
        $size = $this->size;
        
        if($dataStore->file_exists($oid, $size)) {

            header('X-Accel-Buffering: no');
            header('Content-Type: application/octet-stream');
                        
            if(!empty($size)){
                header('Content-Length: ' . $size);
            }
            ob_end_flush();
            
            $return = $dataStore->readfile($oid, $size);
            
            exit;
        }else{
            header('HTTP/1.1 404 Not Found');
            echo 'File does not exist';
            exit;
        }
    }
}